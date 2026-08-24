<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$events = Guesty_Activity_Log::recent( 100 );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Guesty Activity Log', 'guesty-properties-sync' ); ?></h1>
    <p><?php esc_html_e( 'The 100 most recent sync, webhook, and API events are shown below.', 'guesty-properties-sync' ); ?></p>
    <p>
        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=guesty_clear_activity_log' ), 'guesty_clear_activity_log' ) ); ?>" onclick="return confirm('Clear the Guesty activity log?');">
            <?php esc_html_e( 'Clear Log', 'guesty-properties-sync' ); ?>
        </a>
    </p>
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'Time (UTC)', 'guesty-properties-sync' ); ?></th>
            <th><?php esc_html_e( 'Status', 'guesty-properties-sync' ); ?></th>
            <th><?php esc_html_e( 'Event', 'guesty-properties-sync' ); ?></th>
            <th><?php esc_html_e( 'Listing ID', 'guesty-properties-sync' ); ?></th>
            <th><?php esc_html_e( 'Message', 'guesty-properties-sync' ); ?></th>
        </tr></thead>
        <tbody>
        <?php if ( empty( $events ) ) : ?>
            <tr><td colspan="5"><?php esc_html_e( 'No events have been recorded yet.', 'guesty-properties-sync' ); ?></td></tr>
        <?php else : ?>
            <?php foreach ( $events as $event ) :
                $status = in_array( $event['status'], array( 'success', 'warning', 'error' ), true ) ? $event['status'] : 'success';
                $badge_color = 'success' === $status ? '#1d7f39' : ( 'warning' === $status ? '#996800' : '#b32d2e' );
            ?>
                <tr>
                    <td><?php echo esc_html( $event['created_at'] ); ?></td>
                    <td><span style="display:inline-block;padding:2px 8px;border-radius:12px;color:#fff;background:<?php echo esc_attr( $badge_color ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
                    <td><?php echo esc_html( $event['event_type'] ); ?></td>
                    <td><code><?php echo esc_html( $event['listing_id'] ); ?></code></td>
                    <td><?php echo esc_html( $event['message'] ); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
