<?php
/**
 * Optimization coordinator: cache tables, cron warming, cache invalidation,
 * activity cleanup, and diagnostics.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Guesty_Optimization {

    private $api;
    private $availability_cache;
    private $pricing_cache;
    private $calendar_service;
    private $search_service;
    private $quote_service;

    public function __construct() {
        self::set_defaults();
        Guesty_Optimized_Availability_Cache::maybe_upgrade();
        Guesty_Optimized_Pricing_Cache::maybe_upgrade();

        $this->api                = new Guesty_API();
        $this->availability_cache = new Guesty_Optimized_Availability_Cache();
        $this->pricing_cache      = new Guesty_Optimized_Pricing_Cache();
        $this->calendar_service   = new Guesty_Optimized_Calendar_Service( $this->api, $this->availability_cache );
        $this->search_service     = new Guesty_Optimized_Search_Service( $this->api, $this->availability_cache, $this->calendar_service );
        $this->quote_service      = new Guesty_Optimized_Quote_Service( $this->api, $this->pricing_cache );

        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        add_action( 'guesty_optimized_refresh_calendar', array( $this, 'refresh_calendar' ), 10, 3 );
        add_action( 'guesty_optimized_warm_calendars', array( $this, 'warm_calendars' ) );
        add_action( 'guesty_optimized_cleanup', array( $this, 'cleanup' ) );

        add_action( 'admin_post_guesty_clear_optimized_cache', array( $this, 'admin_clear_cache' ) );
        add_action( 'admin_post_guesty_warm_optimized_cache', array( $this, 'admin_warm_cache' ) );
        add_action( 'admin_post_guesty_clear_activity_log', array( $this, 'admin_clear_activity_log' ) );

        add_action( 'save_post_property', array( $this, 'invalidate_listing_map' ), 10, 3 );
        add_action( 'before_delete_post', array( $this, 'invalidate_deleted_property' ) );

        if ( ! wp_next_scheduled( 'guesty_optimized_warm_calendars' ) ) {
            wp_schedule_event( time() + 60, 'guesty_fifteen_minutes', 'guesty_optimized_warm_calendars' );
        }
        if ( ! wp_next_scheduled( 'guesty_optimized_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'guesty_optimized_cleanup' );
        }
    }

    public static function activate() {
        add_filter( 'cron_schedules', array( __CLASS__, 'activation_cron_schedules' ) );
        Guesty_Optimized_Availability_Cache::create_table();
        Guesty_Optimized_Pricing_Cache::create_table();

        if ( ! wp_next_scheduled( 'guesty_optimized_warm_calendars' ) ) {
            wp_schedule_event( time() + 60, 'guesty_fifteen_minutes', 'guesty_optimized_warm_calendars' );
        }
        if ( ! wp_next_scheduled( 'guesty_optimized_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'guesty_optimized_cleanup' );
        }

        self::set_defaults();
        remove_filter( 'cron_schedules', array( __CLASS__, 'activation_cron_schedules' ) );
    }

    public static function activation_cron_schedules( $schedules ) {
        if ( empty( $schedules['guesty_fifteen_minutes'] ) ) {
            $schedules['guesty_fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 minutes', 'guesty-properties-sync' ),
            );
        }
        return $schedules;
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'guesty_optimized_warm_calendars' );
        wp_clear_scheduled_hook( 'guesty_optimized_cleanup' );
        wp_clear_scheduled_hook( 'guesty_optimized_refresh_calendar' );
    }

    public static function set_defaults() {
        $defaults = array(
            'guesty_calendar_cache_minutes'  => 60,
            'guesty_calendar_cache_hours'    => 1,
            'guesty_calendar_sync_days'      => 365,
            'guesty_calendar_sync_batch'     => 4,
            'guesty_search_cache_minutes'    => 5,
            'guesty_search_cache_coverage'   => 0.90,
            'guesty_quote_cache_minutes'     => 15,
            'guesty_debug_logging'           => '0',
            'guesty_activity_log_retention_days' => 30,
            'guesty_google_maps_api_key'     => '',
            // Fail closed until the Guesty-provided signing secret is configured.
            // Do not call wp_generate_password() during plugin bootstrap because
            // pluggable.php may not be loaded yet when active plugins are included.
            'guesty_webhook_secret'          => '',
            'guesty_brand_name'              => get_bloginfo( 'name' ),
            'guesty_contact_email'           => get_option( 'admin_email', '' ),
            'guesty_contact_phone'           => '',
            'guesty_booking_page_url'        => site_url( '/booking/' ),
            'guesty_terms_url'               => function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '',
            'guesty_default_currency'        => 'GBP',
        );

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key, false ) ) {
                add_option( $key, $value, '', false );
            }
        }
    }

    public function cron_schedules( $schedules ) {
        if ( empty( $schedules['guesty_fifteen_minutes'] ) ) {
            $schedules['guesty_fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 minutes', 'guesty-properties-sync' ),
            );
        }
        return $schedules;
    }

    public function refresh_calendar( $listing_id, $start_date, $end_date ) {
        $this->calendar_service->refresh_calendar(
            sanitize_text_field( $listing_id ),
            sanitize_text_field( $start_date ),
            sanitize_text_field( $end_date ),
            true
        );
    }

    /**
     * Warm a rotating property batch so the cache remains hot without bursts.
     */
    public function warm_calendars() {
        if ( get_transient( 'guesty_optimized_warm_lock' ) ) {
            return;
        }
        set_transient( 'guesty_optimized_warm_lock', 1, 10 * MINUTE_IN_SECONDS );

        try {
            $map = $this->search_service->get_listing_map();
            $ids = isset( $map['guesty_ids'] ) ? array_values( array_unique( $map['guesty_ids'] ) ) : array();
            if ( empty( $ids ) ) {
                return;
            }

            $batch_size = max( 1, min( 10, (int) get_option( 'guesty_calendar_sync_batch', 4 ) ) );
            $cursor = max( 0, (int) get_option( 'guesty_calendar_sync_cursor', 0 ) );
            if ( $cursor >= count( $ids ) ) {
                $cursor = 0;
            }

            $batch = array_slice( $ids, $cursor, $batch_size );
            if ( count( $batch ) < $batch_size && count( $ids ) > count( $batch ) ) {
                $batch = array_merge( $batch, array_slice( $ids, 0, $batch_size - count( $batch ) ) );
            }

            $start = gmdate( 'Y-m-d' );
            $days  = max( 30, min( 730, (int) get_option( 'guesty_calendar_sync_days', 365 ) ) );
            $end   = gmdate( 'Y-m-d', time() + ( $days * DAY_IN_SECONDS ) );

            foreach ( $batch as $id ) {
                $this->calendar_service->refresh_calendar( $id, $start, $end, true );
            }

            update_option( 'guesty_calendar_sync_cursor', ( $cursor + count( $batch ) ) % count( $ids ), false );
            update_option( 'guesty_calendar_last_warm', current_time( 'mysql', true ), false );
        } finally {
            delete_transient( 'guesty_optimized_warm_lock' );
        }
    }

    public function cleanup() {
        $this->availability_cache->cleanup( 14 );
        $this->pricing_cache->cleanup();
        Guesty_Activity_Log::cleanup();
    }

    public function invalidate_listing_map( $post_id, $post, $update ) {
        delete_transient( 'guesty_optimized_listing_map' );
        delete_transient( 'guesty_id_map' );
        $this->search_service->clear_search_cache();
    }

    public function invalidate_deleted_property( $post_id ) {
        if ( 'property' !== get_post_type( $post_id ) ) {
            return;
        }
        $id = get_post_meta( $post_id, 'guesty_id', true );
        if ( $id ) {
            Guesty_Transient_Cache::invalidate_listing( $id );
            $this->availability_cache->delete_property( $id );
        }
        $this->invalidate_listing_map( $post_id, null, true );
    }

    public function invalidate_booking( $listing_id, $check_in, $check_out ) {
        if ( ! $listing_id ) {
            return;
        }
        $start = $check_in ?: gmdate( 'Y-m-d' );
        $end   = $check_out ?: gmdate( 'Y-m-d', time() + 365 * DAY_IN_SECONDS );

        // Guesty calendar changes can affect adjacent days through advance notice,
        // preparation time, minimum stays, and smart rules. Refresh a small buffer.
        $start_dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $start );
        $end_dt   = DateTimeImmutable::createFromFormat( '!Y-m-d', $end );
        if ( $start_dt && $end_dt ) {
            $start = $start_dt->modify( '-2 days' )->format( 'Y-m-d' );
            $end   = $end_dt->modify( '+2 days' )->format( 'Y-m-d' );
        }

        Guesty_Transient_Cache::invalidate_listing( $listing_id );
        $this->availability_cache->invalidate_range( $listing_id, $start, $end );
        $this->pricing_cache->invalidate_overlapping( $listing_id, $start, $end );
        $this->search_service->clear_search_cache();
        $this->calendar_service->queue_refresh( $listing_id, $start, $end );
    }

    private function allow_public_request( $bucket, $limit, $window ) {
        $address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $key = 'guesty_rate_' . md5( $bucket . '|' . $address . '|' . wp_salt( 'nonce' ) );
        $count = (int) get_transient( $key );
        if ( $count >= max( 1, (int) $limit ) ) {
            return false;
        }
        set_transient( $key, $count + 1, max( 30, (int) $window ) );
        return true;
    }

    public function admin_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'guesty-properties-sync' ) );
        }
        check_admin_referer( 'guesty_clear_optimized_cache' );

        Guesty_Transient_Cache::clear_all();
        $this->availability_cache->clear();
        $this->pricing_cache->clear();
        $this->search_service->clear_search_cache();
        delete_transient( 'guesty_optimized_listing_map' );

        wp_safe_redirect( add_query_arg( array( 'page' => 'guesty-properties-sync', 'guesty_cache_cleared' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function admin_warm_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'guesty-properties-sync' ) );
        }
        check_admin_referer( 'guesty_warm_optimized_cache' );
        delete_transient( 'guesty_optimized_warm_lock' );
        $this->warm_calendars();
        wp_safe_redirect( add_query_arg( array( 'page' => 'guesty-properties-sync', 'guesty_cache_warmed' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }


    public function admin_clear_activity_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'guesty-properties-sync' ) );
        }
        check_admin_referer( 'guesty_clear_activity_log' );
        Guesty_Activity_Log::clear();
        wp_safe_redirect( add_query_arg( array( 'page' => 'guesty-properties-sync-log', 'guesty_log_cleared' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function diagnostics() {
        return array(
            'availability' => $this->availability_cache->stats(),
            'quotes'       => $this->pricing_cache->stats(),
            'api'          => get_option( 'guesty_api_health', array() ),
            'last_warm'    => get_option( 'guesty_calendar_last_warm', '' ),
            'next_warm'    => wp_next_scheduled( 'guesty_optimized_warm_calendars' ),
        );
    }
}
