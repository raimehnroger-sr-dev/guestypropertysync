<?php
/**
 * Availability discovery service.
 *
 * Search does not request a quote for every listing. It uses the local calendar
 * cache first, then Guesty's available-listings filter as a single discovery
 * request when local coverage is insufficient.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Guesty_Optimized_Search_Service {

    private $api;
    private $availability_cache;
    private $calendar_service;

    public function __construct( ?Guesty_API $api = null, ?Guesty_Optimized_Availability_Cache $cache = null, ?Guesty_Optimized_Calendar_Service $calendar_service = null ) {
        $this->api                = $api ?: new Guesty_API();
        $this->availability_cache = $cache ?: new Guesty_Optimized_Availability_Cache();
        $this->calendar_service   = $calendar_service ?: new Guesty_Optimized_Calendar_Service( $this->api, $this->availability_cache );
    }

    public function search( $check_in, $check_out, $guests = 1 ) {
        if ( ! $this->valid_dates( $check_in, $check_out ) ) {
            return array();
        }

        $guests = max( 1, (int) $guests );
        $cache_key = 'guesty_optimized_search_' . md5( implode( '|', array( $check_in, $check_out, $guests ) ) );
        $stale_key = $cache_key . '_stale';
        $cached = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }
        $stale_result = get_transient( $stale_key );

        $map = $this->get_listing_map();
        if ( empty( $map['guesty_ids'] ) ) {
            return array();
        }

        $ids = array_values( array_unique( $map['guesty_ids'] ) );
        $fresh_hours = max( 1, (int) get_option( 'guesty_calendar_cache_hours', 1 ) );
        $summary = $this->availability_cache->get_range_summary( $ids, $check_in, $check_out, $fresh_hours );

        $fresh_covered = array_values( array_diff( $summary['covered'], $summary['stale'] ) );
        $coverage = count( $ids ) ? count( $fresh_covered ) / count( $ids ) : 0;
        $minimum_coverage = min( 1, max( 0, (float) get_option( 'guesty_search_cache_coverage', 0.90 ) ) );

        $available_ids = array();
        $source = 'calendar-cache';

        if ( $coverage >= $minimum_coverage ) {
            $available_ids = array_values( array_intersect( $summary['available'], $fresh_covered ) );
        } else {
            $source = 'available-listings';
            $lock_key = 'guesty_search_lock_' . md5( $check_in . '|' . $check_out . '|' . $guests );
            $lock_acquired = add_option( $lock_key, time(), '', false );
            if ( ! $lock_acquired ) {
                $lock_time = (int) get_option( $lock_key, 0 );
                if ( $lock_time && time() - $lock_time > 45 ) {
                    delete_option( $lock_key );
                    $lock_acquired = add_option( $lock_key, time(), '', false );
                }
            }

            if ( ! $lock_acquired && is_array( $stale_result ) ) {
                return $stale_result;
            }

            if ( $lock_acquired ) {
                try {
                    $live_ids = $this->discover_available_listings( $check_in, $check_out, $guests, count( $ids ) );
                } finally {
                    delete_option( $lock_key );
                }
            } else {
                $live_ids = new WP_Error( 'guesty_search_in_progress', 'An identical availability search is already running.' );
            }

            if ( is_wp_error( $live_ids ) ) {
                // Stale local availability keeps the site functional during rate limits/outages.
                $available_ids = array_values( array_intersect( $summary['available'], $ids ) );
                $source = 'stale-calendar-cache';
            } else {
                $available_ids = array_values( array_intersect( $live_ids, $ids ) );
            }

            $refresh_ids = array_values( array_unique( array_merge( $summary['missing'], $summary['stale'] ) ) );
            $this->queue_background_refreshes( $refresh_ids, $check_in, $check_out );
        }

        $nights = max( 1, (int) ( new DateTimeImmutable( $check_in ) )->diff( new DateTimeImmutable( $check_out ) )->days );
        $results = array();

        foreach ( $available_ids as $guesty_id ) {
            if ( empty( $map['post_map'][ $guesty_id ] ) ) {
                continue;
            }

            $post_id = (int) $map['post_map'][ $guesty_id ];
            $estimate = isset( $summary['prices'][ $guesty_id ]['total'] ) ? (float) $summary['prices'][ $guesty_id ]['total'] : 0;
            $currency = isset( $summary['prices'][ $guesty_id ]['currency'] ) ? $summary['prices'][ $guesty_id ]['currency'] : '';

            if ( $estimate <= 0 ) {
                $base = $this->base_price( $post_id );
                $estimate = $base > 0 ? $base * $nights : 0;
            }

            $results[] = array(
                'post_id'          => $post_id,
                'guesty_id'        => $guesty_id,
                'total_price'      => round( $estimate, 2 ),
                'total_adjusted'   => round( $estimate, 2 ),
                'is_discounted'    => false,
                'promo_name'       => '',
                'startDate'        => $check_in,
                'endDate'          => $check_out,
                'currency'         => $currency,
                'price_is_estimate'=> true,
                'availability_source' => $source,
            );
        }

        $fresh_ttl = max( 60, (int) get_option( 'guesty_search_cache_minutes', 5 ) * MINUTE_IN_SECONDS );
        set_transient( $cache_key, $results, $fresh_ttl );
        set_transient( $stale_key, $results, max( 30 * MINUTE_IN_SECONDS, $fresh_ttl ) );
        return $results;
    }

    public function get_listing_map( $force = false ) {
        $key = 'guesty_optimized_listing_map';
        if ( ! $force ) {
            $cached = get_transient( $key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $posts = get_posts(
            array(
                'post_type'      => 'property',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );

        $map = array( 'guesty_ids' => array(), 'post_map' => array() );
        foreach ( $posts as $post_id ) {
            $id = trim( (string) get_post_meta( $post_id, 'guesty_id', true ) );
            if ( '' !== $id ) {
                $map['guesty_ids'][] = $id;
                $map['post_map'][ $id ] = (int) $post_id;
            }
        }

        set_transient( $key, $map, 12 * HOUR_IN_SECONDS );
        return $map;
    }

    public function clear_search_cache() {
        global $wpdb;
        $pattern = $wpdb->esc_like( '_transient_guesty_optimized_search_' ) . '%';
        $timeout = $wpdb->esc_like( '_transient_timeout_guesty_optimized_search_' ) . '%';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $pattern, $timeout ) );
    }

    private function discover_available_listings( $check_in, $check_out, $guests, $local_count ) {
        $ids = array();
        $limit = min( 100, max( 1, $local_count ) );
        $max_pages = max( 1, min( 5, (int) ceil( max( 1, $local_count ) / 100 ) ) );

        for ( $page = 0; $page < $max_pages; $page++ ) {
            $response = $this->api->get_available_listings( $check_in, $check_out, $guests, $limit, $page * $limit );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $items = $this->extract_listings( $response );
            foreach ( $items as $item ) {
                $id = isset( $item['_id'] ) ? $item['_id'] : ( isset( $item['id'] ) ? $item['id'] : '' );
                if ( $id ) {
                    $ids[] = sanitize_text_field( $id );
                }
            }

            if ( count( $items ) < $limit ) {
                break;
            }
        }

        return array_values( array_unique( $ids ) );
    }

    private function extract_listings( $response ) {
        foreach ( array( 'results', 'data', 'listings' ) as $key ) {
            if ( ! empty( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
                if ( isset( $response[ $key ]['results'] ) && is_array( $response[ $key ]['results'] ) ) {
                    return $response[ $key ]['results'];
                }
                return $response[ $key ];
            }
        }
        return isset( $response[0] ) ? $response : array();
    }

    private function queue_background_refreshes( array $ids, $check_in, $check_out ) {
        $ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, 20 );
        foreach ( $ids as $index => $id ) {
            $args = array( $id, $check_in, $check_out );
            if ( ! wp_next_scheduled( 'guesty_optimized_refresh_calendar', $args ) ) {
                wp_schedule_single_event( time() + 10 + ( $index * 2 ), 'guesty_optimized_refresh_calendar', $args );
            }
        }
    }

    private function base_price( $post_id ) {
        foreach ( array( 'property_base_price', 'property_price', 'base_price', 'price' ) as $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( is_numeric( $value ) && (float) $value > 0 ) {
                return (float) $value;
            }
        }
        return 0;
    }

    private function valid_dates( $check_in, $check_out ) {
        $start = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $check_in );
        $end   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $check_out );
        return $start && $end && $start < $end;
    }
}
