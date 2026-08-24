<?php
/**
 * Cache-first calendar service.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Guesty_Optimized_Calendar_Service {

    private $api;
    private $cache;

    public function __construct( ?Guesty_API $api = null, ?Guesty_Optimized_Availability_Cache $cache = null ) {
        $this->api   = $api ?: new Guesty_API();
        $this->cache = $cache ?: new Guesty_Optimized_Availability_Cache();
    }

    /**
     * Return a Guesty-compatible calendar response without blocking on Guesty
     * when a usable local copy exists.
     */
    public function get_calendar( $listing_id, $start_date, $end_date, $allow_stale = true ) {
        if ( ! $this->valid_request( $listing_id, $start_date, $end_date ) ) {
            return new WP_Error( 'guesty_invalid_calendar_request', 'Invalid listing or calendar dates.' );
        }

        $fresh_hours = max( 1, (int) get_option( 'guesty_calendar_cache_hours', 1 ) );
        $fresh = $this->cache->get_calendar( $listing_id, $start_date, $end_date, $fresh_hours );
        if ( $this->has_complete_range( $fresh, $start_date, $end_date ) ) {
            return $fresh;
        }

        $stale = $allow_stale ? $this->cache->get_calendar( $listing_id, $start_date, $end_date, 0 ) : null;
        if ( $allow_stale && $this->has_complete_range( $stale, $start_date, $end_date ) ) {
            $this->queue_refresh( $listing_id, $start_date, $end_date );
            $stale['data']['stale'] = true;
            return $stale;
        }

        $live = $this->refresh_calendar( $listing_id, $start_date, $end_date );
        if ( ! is_wp_error( $live ) ) {
            return $live;
        }

        // A partial cache is preferable to a blank frontend during an API incident.
        if ( $allow_stale && is_array( $stale ) ) {
            $stale['data']['stale'] = true;
            $stale['data']['partial'] = true;
            return $stale;
        }

        return $live;
    }

    public function refresh_calendar( $listing_id, $start_date, $end_date, $compact = false ) {
        if ( ! $this->valid_request( $listing_id, $start_date, $end_date ) ) {
            return new WP_Error( 'guesty_invalid_calendar_request', 'Invalid listing or calendar dates.' );
        }

        $lock_key = 'guesty_calendar_refresh_' . md5( $listing_id . '|' . $start_date . '|' . $end_date );
        if ( get_transient( $lock_key ) ) {
            $cached = $this->cache->get_calendar( $listing_id, $start_date, $end_date, 0 );
            return $cached ?: new WP_Error( 'guesty_calendar_refresh_locked', 'Calendar refresh is already running.' );
        }

        set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );
        try {
            $calendar = $compact
                ? $this->api->get_calendar_compact( $listing_id, $start_date, $end_date )
                : $this->api->get_calendar( $listing_id, $start_date, $end_date );

            if ( is_wp_error( $calendar ) ) {
                return $calendar;
            }

            $this->cache->save_calendar( $listing_id, $calendar );
            $cached = $this->cache->get_calendar( $listing_id, $start_date, $end_date, 0 );
            return $cached ?: $calendar;
        } finally {
            delete_transient( $lock_key );
        }
    }

    public function is_range_available( $listing_id, $check_in, $check_out ) {
        $summary = $this->cache->get_range_summary(
            array( $listing_id ),
            $check_in,
            $check_out,
            max( 1, (int) get_option( 'guesty_calendar_cache_hours', 1 ) )
        );

        if ( in_array( $listing_id, $summary['covered'], true ) && ! in_array( $listing_id, $summary['stale'], true ) ) {
            return in_array( $listing_id, $summary['available'], true );
        }

        $calendar = $this->get_calendar( $listing_id, $check_in, $check_out, true );
        if ( is_wp_error( $calendar ) ) {
            return $calendar;
        }

        $summary = $this->cache->get_range_summary( array( $listing_id ), $check_in, $check_out, 0 );
        return in_array( $listing_id, $summary['available'], true );
    }

    public function queue_refresh( $listing_id, $start_date, $end_date ) {
        $args = array( $listing_id, $start_date, $end_date );
        if ( ! wp_next_scheduled( 'guesty_optimized_refresh_calendar', $args ) ) {
            wp_schedule_single_event( time() + 5, 'guesty_optimized_refresh_calendar', $args );
        }
    }

    private function has_complete_range( $calendar, $start_date, $end_date ) {
        if ( ! is_array( $calendar ) || empty( $calendar['data']['days'] ) || ! is_array( $calendar['data']['days'] ) ) {
            return false;
        }

        try {
            $start = new DateTimeImmutable( $start_date );
            $end   = new DateTimeImmutable( $end_date );
        } catch ( Exception $e ) {
            return false;
        }

        // Guesty calendar endpoints normally include both boundary dates.
        $expected = max( 1, (int) $start->diff( $end )->days + 1 );
        return count( $calendar['data']['days'] ) >= $expected;
    }

    private function valid_request( $listing_id, $start_date, $end_date ) {
        if ( empty( $listing_id ) ) {
            return false;
        }
        $start = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $start_date );
        $end   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $end_date );
        return $start && $end && $start < $end;
    }
}
