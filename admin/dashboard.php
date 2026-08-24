<?php
/**
 * Guesty Property Sync dashboard.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

$property_count = wp_count_posts( 'property' );
$published_properties = isset( $property_count->publish ) ? (int) $property_count->publish : 0;
$draft_properties = isset( $property_count->draft ) ? (int) $property_count->draft : 0;

$today = current_time( 'timestamp' );
$default_start = wp_date( 'Y-m-01', $today );
$default_end = wp_date( 'Y-m-t', $today );
$range_start = isset( $_GET['stats_start'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['stats_start'] )
    ? sanitize_text_field( wp_unslash( $_GET['stats_start'] ) )
    : $default_start;
$range_end = isset( $_GET['stats_end'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['stats_end'] )
    ? sanitize_text_field( wp_unslash( $_GET['stats_end'] ) )
    : $default_end;

$force_refresh = isset( $_GET['guesty_stats_refresh'] )
    && current_user_can( 'manage_options' )
    && isset( $_GET['_wpnonce'] )
    && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'guesty_refresh_dashboard_stats' );

$stats_cache_key = 'guesty_dashboard_stats_' . md5( $range_start . '|' . $range_end );
if ( $force_refresh ) {
    delete_transient( $stats_cache_key );
}

$stats_payload = get_transient( $stats_cache_key );
$stats_error = '';
if ( false === $stats_payload || ! is_array( $stats_payload ) ) {
    $api = new Guesty_API();
    $reservations_response = $api->get_reservations( array(), 100, 0, '-checkInDateLocalized', $force_refresh );
    if ( is_wp_error( $reservations_response ) ) {
        $stats_error = $reservations_response->get_error_message();
        $reservations = array();
    } else {
        if ( isset( $reservations_response['results'] ) && is_array( $reservations_response['results'] ) ) {
            $reservations = $reservations_response['results'];
        } elseif ( isset( $reservations_response['data']['results'] ) && is_array( $reservations_response['data']['results'] ) ) {
            $reservations = $reservations_response['data']['results'];
        } elseif ( isset( $reservations_response[0] ) && is_array( $reservations_response[0] ) ) {
            $reservations = $reservations_response;
        } else {
            $reservations = array();
        }
    }

    $read_path = static function ( array $row, array $paths, $default = '' ) {
        foreach ( $paths as $path ) {
            $value = $row;
            foreach ( explode( '.', $path ) as $segment ) {
                if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                    $value = null;
                    break;
                }
                $value = $value[ $segment ];
            }
            if ( null !== $value && '' !== $value ) {
                return $value;
            }
        }
        return $default;
    };

    $range_start_ts = strtotime( $range_start . ' 00:00:00' );
    $range_end_ts = strtotime( $range_end . ' 23:59:59' );
    $filtered = array();
    $total_revenue = 0.0;
    $total_nights = 0;
    $chart = array();

    foreach ( $reservations as $reservation ) {
        if ( ! is_array( $reservation ) ) {
            continue;
        }
        $check_in = (string) $read_path( $reservation, array( 'checkInDateLocalized', 'checkIn', 'arrivalDate' ), '' );
        $date_ts = $check_in ? strtotime( substr( $check_in, 0, 10 ) . ' 12:00:00' ) : false;
        if ( ! $date_ts || $date_ts < $range_start_ts || $date_ts > $range_end_ts ) {
            continue;
        }

        $status = strtolower( (string) $read_path( $reservation, array( 'status' ), '' ) );
        if ( in_array( $status, array( 'canceled', 'cancelled', 'declined' ), true ) ) {
            continue;
        }

        $revenue = (float) $read_path(
            $reservation,
            array( 'money.hostPayout', 'money.totalPrice', 'money.total', 'totalPrice', 'totalPaid', 'money.totalPaid' ),
            0
        );
        $check_out = (string) $read_path( $reservation, array( 'checkOutDateLocalized', 'checkOut', 'departureDate' ), '' );
        $nights = 0;
        if ( $check_in && $check_out ) {
            try {
                $nights = ( new DateTimeImmutable( substr( $check_in, 0, 10 ) ) )->diff( new DateTimeImmutable( substr( $check_out, 0, 10 ) ) )->days;
            } catch ( Exception $e ) {
                $nights = 0;
            }
        }

        $total_revenue += $revenue;
        $total_nights += max( 0, (int) $nights );
        $day_key = substr( $check_in, 0, 10 );
        $chart[ $day_key ] = ( $chart[ $day_key ] ?? 0 ) + $revenue;

        $filtered[] = array(
            'property' => (string) $read_path( $reservation, array( 'listing.title', 'listing.nickname', 'listing.name' ), 'Unknown property' ),
            'guest'    => trim( (string) $read_path( $reservation, array( 'guest.fullName', 'guest.name' ), '' ) ) ?: trim( (string) $read_path( $reservation, array( 'guest.firstName' ), '' ) . ' ' . (string) $read_path( $reservation, array( 'guest.lastName' ), '' ) ),
            'check_in' => substr( $check_in, 0, 10 ),
            'amount'   => $revenue,
            'currency' => strtoupper( (string) $read_path( $reservation, array( 'money.currency', 'currency' ), get_option( 'guesty_default_currency', 'GBP' ) ) ),
            'status'   => (string) $read_path( $reservation, array( 'status' ), 'unknown' ),
        );
    }

    usort( $filtered, static function ( $a, $b ) {
        return strcmp( $b['check_in'], $a['check_in'] );
    } );
    ksort( $chart );

    $stats_payload = array(
        'total_bookings' => count( $filtered ),
        'total_revenue' => $total_revenue,
        'average_nightly_rate' => $total_nights > 0 ? $total_revenue / $total_nights : 0,
        'recent_bookings' => array_slice( $filtered, 0, 10 ),
        'chart_labels' => array_keys( $chart ),
        'chart_values' => array_values( $chart ),
        'currency' => ! empty( $filtered[0]['currency'] ) ? $filtered[0]['currency'] : get_option( 'guesty_default_currency', 'GBP' ),
        'sample_limit' => 100,
    );
    if ( '' === $stats_error ) {
        set_transient( $stats_cache_key, $stats_payload, 4 * HOUR_IN_SECONDS );
    }
}

$optimization = isset( $GLOBALS['guesty_optimization'] ) && $GLOBALS['guesty_optimization'] instanceof Guesty_Optimization
    ? $GLOBALS['guesty_optimization']
    : new Guesty_Optimization();
$diagnostics = $optimization->diagnostics();
$availability_stats = $diagnostics['availability'];
$quote_stats = $diagnostics['quotes'];
$api_health = $diagnostics['api'];
$token_api = isset( $api ) && $api instanceof Guesty_API ? $api : new Guesty_API();
$token_diag = $token_api->get_token_diagnostics();
$token_expires_text = ! empty( $token_diag['expires_at'] )
    ? wp_date( 'Y-m-d H:i:s T', (int) $token_diag['expires_at'] )
    : 'No token stored';
$token_remaining_text = ! empty( $token_diag['expires_in'] )
    ? human_time_diff( time(), time() + (int) $token_diag['expires_in'] )
    : 'Expired / unavailable';
$currency = esc_html( $stats_payload['currency'] ?? get_option( 'guesty_default_currency', 'GBP' ) );
$refresh_url = wp_nonce_url(
    add_query_arg(
        array(
            'page' => 'guesty-properties-sync',
            'stats_start' => $range_start,
            'stats_end' => $range_end,
            'guesty_stats_refresh' => 1,
        ),
        admin_url( 'admin.php' )
    ),
    'guesty_refresh_dashboard_stats'
);
?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <?php if ( $stats_error ) : ?>
        <div class="notice notice-warning inline"><p><?php echo esc_html( 'Reservation statistics could not be refreshed: ' . $stats_error ); ?></p></div>
    <?php elseif ( $force_refresh ) : ?>
        <div class="notice notice-success inline"><p><?php esc_html_e( 'Dashboard statistics refreshed.', 'guesty-properties-sync' ); ?></p></div>
    <?php endif; ?>

    <div class="card" style="max-width:100%;">
        <h2><?php esc_html_e( 'Revenue & Booking Stats', 'guesty-properties-sync' ); ?></h2>
        <form method="get" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin-bottom:18px;">
            <input type="hidden" name="page" value="guesty-properties-sync">
            <label><?php esc_html_e( 'Start date', 'guesty-properties-sync' ); ?><br><input type="date" name="stats_start" value="<?php echo esc_attr( $range_start ); ?>"></label>
            <label><?php esc_html_e( 'End date', 'guesty-properties-sync' ); ?><br><input type="date" name="stats_end" value="<?php echo esc_attr( $range_end ); ?>"></label>
            <button class="button button-primary" type="submit"><?php esc_html_e( 'Apply Range', 'guesty-properties-sync' ); ?></button>
            <a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh Guesty Data', 'guesty-properties-sync' ); ?></a>
        </form>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;max-width:1000px;">
            <div class="card"><strong><?php esc_html_e( 'Total Properties', 'guesty-properties-sync' ); ?></strong><div style="font-size:30px;margin-top:8px;"><?php echo esc_html( number_format_i18n( $published_properties ) ); ?></div></div>
            <div class="card"><strong><?php esc_html_e( 'Total Bookings', 'guesty-properties-sync' ); ?></strong><div style="font-size:30px;margin-top:8px;"><?php echo esc_html( number_format_i18n( $stats_payload['total_bookings'] ?? 0 ) ); ?></div></div>
            <div class="card"><strong><?php esc_html_e( 'Total Revenue', 'guesty-properties-sync' ); ?></strong><div style="font-size:30px;margin-top:8px;"><?php echo esc_html( $currency . ' ' . number_format_i18n( (float) ( $stats_payload['total_revenue'] ?? 0 ), 2 ) ); ?></div></div>
            <div class="card"><strong><?php esc_html_e( 'Average Nightly Rate', 'guesty-properties-sync' ); ?></strong><div style="font-size:30px;margin-top:8px;"><?php echo esc_html( $currency . ' ' . number_format_i18n( (float) ( $stats_payload['average_nightly_rate'] ?? 0 ), 2 ) ); ?></div></div>
        </div>
        <p class="description"><?php esc_html_e( 'Reservation data is cached for four hours and the dashboard reads up to the 100 most recent Guesty reservations returned by the API.', 'guesty-properties-sync' ); ?></p>

        <div style="max-width:1000px;height:300px;margin:24px 0;"><canvas id="guesty-revenue-chart"></canvas></div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('guesty-revenue-chart');
            if (!canvas || typeof Chart === 'undefined') return;
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: <?php echo wp_json_encode( $stats_payload['chart_labels'] ?? array() ); ?>,
                    datasets: [{ label: <?php echo wp_json_encode( sprintf( 'Revenue (%s)', $currency ) ); ?>, data: <?php echo wp_json_encode( $stats_payload['chart_values'] ?? array() ); ?> }]
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        });
        </script>

        <h3><?php esc_html_e( 'Recent Bookings', 'guesty-properties-sync' ); ?></h3>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'Property', 'guesty-properties-sync' ); ?></th><th><?php esc_html_e( 'Guest', 'guesty-properties-sync' ); ?></th><th><?php esc_html_e( 'Check-in', 'guesty-properties-sync' ); ?></th><th><?php esc_html_e( 'Amount', 'guesty-properties-sync' ); ?></th><th><?php esc_html_e( 'Status', 'guesty-properties-sync' ); ?></th></tr></thead>
            <tbody>
            <?php if ( empty( $stats_payload['recent_bookings'] ) ) : ?>
                <tr><td colspan="5"><?php esc_html_e( 'No reservations were found for this date range.', 'guesty-properties-sync' ); ?></td></tr>
            <?php else : foreach ( $stats_payload['recent_bookings'] as $booking ) : ?>
                <tr><td><?php echo esc_html( $booking['property'] ); ?></td><td><?php echo esc_html( $booking['guest'] ?: '—' ); ?></td><td><?php echo esc_html( $booking['check_in'] ); ?></td><td><?php echo esc_html( $booking['currency'] . ' ' . number_format_i18n( (float) $booking['amount'], 2 ) ); ?></td><td><?php echo esc_html( $booking['status'] ); ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card" style="max-width:100%;">
        <h2><?php esc_html_e( 'Optimisation Health', 'guesty-properties-sync' ); ?></h2>
        <?php if ( isset( $_GET['guesty_cache_cleared'] ) ) : ?><div class="notice notice-success inline"><p><?php esc_html_e( 'Optimised caches cleared.', 'guesty-properties-sync' ); ?></p></div><?php endif; ?>
        <?php if ( isset( $_GET['guesty_cache_warmed'] ) ) : ?><div class="notice notice-success inline"><p><?php esc_html_e( 'Calendar warmup completed.', 'guesty-properties-sync' ); ?></p></div><?php endif; ?>
        <table class="widefat striped" style="max-width:900px;"><tbody>
            <tr><th><?php esc_html_e( 'Published properties', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( number_format_i18n( $published_properties ) ); ?></td></tr>
            <tr><th><?php esc_html_e( 'Draft properties', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( number_format_i18n( $draft_properties ) ); ?></td></tr>
            <tr><th><?php esc_html_e( 'Cached calendar days', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( number_format_i18n( $availability_stats['rows'] ?? 0 ) ); ?></td></tr>
            <tr><th><?php esc_html_e( 'Cached properties', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( number_format_i18n( $availability_stats['properties'] ?? 0 ) ); ?></td></tr>
            <tr><th><?php esc_html_e( 'Valid exact quotes', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( number_format_i18n( $quote_stats['valid'] ?? 0 ) ); ?></td></tr>
            <tr><th><?php esc_html_e( 'Last calendar warmup', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( $diagnostics['last_warm'] ?: 'Not run yet' ); ?></td></tr>
            <tr><th><?php esc_html_e( 'Last Guesty response', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( isset( $api_health['status'] ) ? $api_health['status'] . ' in ' . ( $api_health['elapsed_ms'] ?? 0 ) . 'ms' : 'No request recorded' ); ?></td></tr>
            <tr><th><?php esc_html_e( 'Last Guesty endpoint', 'guesty-properties-sync' ); ?></th><td><code><?php echo esc_html( $api_health['endpoint'] ?? '—' ); ?></code></td></tr>
            <tr><th><?php esc_html_e( 'Hourly API remaining', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( $api_health['remaining_hour'] ?? 'Not provided' ); ?></td></tr>
            <tr><th><?php esc_html_e( 'OAuth token status', 'guesty-properties-sync' ); ?></th><td><?php echo ! empty( $token_diag['usable'] ) ? '<strong>Reusable</strong>' : '<strong>Expired / unavailable</strong>'; ?></td></tr>
            <tr><th><?php esc_html_e( 'OAuth token expires', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( $token_expires_text . ' (' . $token_remaining_text . ')' ); ?></td></tr>
            <tr><th><?php esc_html_e( 'Plugin token generations (24h)', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( (int) $token_diag['plugin_used_24h'] . ' / ' . (int) $token_diag['plugin_guard_cap'] . ' safety cap' ); ?></td></tr>
            <tr><th><?php esc_html_e( 'Guesty token quota remaining', 'guesty-properties-sync' ); ?></th><td><?php echo esc_html( null !== $token_diag['guesty_remaining'] ? $token_diag['guesty_remaining'] . ' / ' . ( $token_diag['guesty_limit_day'] ?? 5 ) : 'Will be captured on next token generation' ); ?></td></tr>
        </tbody></table>
        <p>
            <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=guesty_warm_optimized_cache' ), 'guesty_warm_optimized_cache' ) ); ?>"><?php esc_html_e( 'Warm Next Calendar Batch', 'guesty-properties-sync' ); ?></a>
            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=guesty_clear_optimized_cache' ), 'guesty_clear_optimized_cache' ) ); ?>" onclick="return confirm('Clear calendar and quote caches?');"><?php esc_html_e( 'Clear Optimised Caches', 'guesty-properties-sync' ); ?></a>
        </p>
    </div>

    <div class="card" style="max-width:100%;">
        <h2><?php esc_html_e( 'Shortcodes', 'guesty-properties-sync' ); ?></h2>
        <p><code>[property_search_filter results_page="/search-results"]</code> — <?php esc_html_e( 'property search form', 'guesty-properties-sync' ); ?></p>
        <p><code>[property_search_results]</code> — <?php esc_html_e( 'search result cards', 'guesty-properties-sync' ); ?></p>
        <p><code>[property_calendar]</code> — <?php esc_html_e( 'lazy-loaded availability calendar', 'guesty-properties-sync' ); ?></p>
        <p><code>[property_amenities]</code> — <?php esc_html_e( 'property amenities', 'guesty-properties-sync' ); ?></p>
        <p><code>[property_gallery show=6]</code> — <?php esc_html_e( 'property gallery', 'guesty-properties-sync' ); ?></p>
    </div>
</div>
