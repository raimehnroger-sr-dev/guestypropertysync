<?php
/**
 * Exact quote service. Frontend quote requests occur only after explicit
 * booking/date interaction and are cached briefly by stay/guest breakdown.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Guesty_Optimized_Quote_Service {

    private $api;
    private $cache;

    public function __construct( ?Guesty_API $api = null, ?Guesty_Optimized_Pricing_Cache $cache = null ) {
        $this->api   = $api ?: new Guesty_API();
        $this->cache = $cache ?: new Guesty_Optimized_Pricing_Cache();
    }

    public function get_quote( array $request ) {
        $normalized = $this->normalize( $request );
        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }

        $transient_key = Guesty_Transient_Cache::quote_key(
            $normalized['listingId'],
            $normalized['checkInDateLocalized'],
            $normalized['checkOutDateLocalized'],
            $normalized['numberOfGuests'],
            $normalized['couponCode']
        );
        $transient = Guesty_Transient_Cache::get( $transient_key );
        if ( false !== $transient && is_array( $transient ) ) {
            $transient['_guesty_transient_cache'] = true;
            return $transient;
        }

        $cached = $this->cache->get(
            $normalized['listingId'],
            $normalized['checkInDateLocalized'],
            $normalized['checkOutDateLocalized'],
            $normalized['numberOfGuests']['numberOfAdults'],
            $normalized['numberOfGuests']['numberOfChildren'],
            $normalized['numberOfGuests']['numberOfInfants'],
            $normalized['numberOfGuests']['numberOfPets']
        );

        if ( $cached && empty( $normalized['couponCode'] ) ) {
            $cached['quote_data']['_guesty_optimized_cache'] = true;
            Guesty_Transient_Cache::set(
                'quote',
                $normalized['listingId'],
                $transient_key,
                $cached['quote_data'],
                max( 5, min( 60, (int) get_option( 'guesty_quote_cache_minutes', 15 ) ) ) * MINUTE_IN_SECONDS
            );
            return $cached['quote_data'];
        }

        $lock_key = 'guesty_quote_lock_' . sha1( wp_json_encode( $normalized ) );
        $lock_acquired = add_option( $lock_key, time(), '', false );
        if ( ! $lock_acquired ) {
            $lock_time = (int) get_option( $lock_key, 0 );
            if ( $lock_time && time() - $lock_time > 45 ) {
                delete_option( $lock_key );
                $lock_acquired = add_option( $lock_key, time(), '', false );
            }
        }

        if ( ! $lock_acquired ) {
            for ( $i = 0; $i < 5; $i++ ) {
                usleep( 150000 );

                // Coupon and non-coupon quotes both use the transient key, so
                // concurrent identical requests can reuse the first response.
                $concurrent = Guesty_Transient_Cache::get( $transient_key );
                if ( false !== $concurrent && is_array( $concurrent ) ) {
                    $concurrent['_guesty_transient_cache'] = true;
                    return $concurrent;
                }

                if ( empty( $normalized['couponCode'] ) ) {
                    $cached = $this->cache->get(
                        $normalized['listingId'],
                        $normalized['checkInDateLocalized'],
                        $normalized['checkOutDateLocalized'],
                        $normalized['numberOfGuests']['numberOfAdults'],
                        $normalized['numberOfGuests']['numberOfChildren'],
                        $normalized['numberOfGuests']['numberOfInfants'],
                        $normalized['numberOfGuests']['numberOfPets']
                    );
                    if ( $cached ) {
                        $cached['quote_data']['_guesty_optimized_cache'] = true;
                        return $cached['quote_data'];
                    }
                }
            }
            return new WP_Error( 'guesty_quote_in_progress', 'An identical quote is already being calculated. Please retry.' );
        }

        try {
            $response = $this->api->request( 'quotes', 'POST', $normalized, array( 'timeout' => 25, 'retries' => 1 ) );
            if ( is_wp_error( $response ) ) {
                // Never use an expired quote for checkout. Price and availability
                // must be current when the guest proceeds to booking.
                return $response;
            }

            $quote_minutes = max( 5, min( 60, (int) get_option( 'guesty_quote_cache_minutes', 15 ) ) );
            Guesty_Transient_Cache::set(
                'quote',
                $normalized['listingId'],
                $transient_key,
                $response,
                $quote_minutes * MINUTE_IN_SECONDS
            );

            if ( empty( $normalized['couponCode'] ) ) {
                $this->cache->set(
                    $normalized['listingId'],
                    $normalized['checkInDateLocalized'],
                    $normalized['checkOutDateLocalized'],
                    $normalized['numberOfGuests']['numberOfAdults'],
                    $normalized['numberOfGuests']['numberOfChildren'],
                    $normalized['numberOfGuests']['numberOfInfants'],
                    $normalized['numberOfGuests']['numberOfPets'],
                    $response,
                    $quote_minutes
                );
            }

            return $response;
        } finally {
            if ( $lock_acquired ) {
                delete_option( $lock_key );
            }
        }
    }

    /**
     * Quote a small visible set using one quotes/multiple request.
     */
    public function get_visible_quotes( array $listing_ids, $check_in, $check_out, $guests = 1 ) {
        $listing_ids = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $listing_ids ) ) ) ), 0, 12 );
        $guests = max( 1, (int) $guests );
        $results = array();
        $payload = array();

        foreach ( $listing_ids as $id ) {
            $cached = $this->cache->get( $id, $check_in, $check_out, $guests, 0, 0, 0 );
            if ( $cached ) {
                $results[ $id ] = $this->format_quote_result( $id, $cached['quote_data'], true );
                continue;
            }

            $payload[] = array(
                'applyPromotions'       => true,
                'checkInDateLocalized'  => $check_in,
                'checkOutDateLocalized' => $check_out,
                'unitTypeId'            => $id,
                'source'                => 'website',
                'guestsCount'           => $guests,
                'count'                 => 1,
                'numberOfGuests'        => array(
                    'numberOfAdults'   => $guests,
                    'numberOfChildren' => 0,
                    'numberOfInfants'  => 0,
                    'numberOfPets'     => 0,
                ),
            );
        }

        if ( ! empty( $payload ) ) {
            $pending_ids = array_map( static function( $item ) { return $item['unitTypeId']; }, $payload );
            sort( $pending_ids );
            $lock_key = 'guesty_multi_quote_lock_' . sha1( implode( '|', $pending_ids ) . '|' . $check_in . '|' . $check_out . '|' . $guests );
            $lock_acquired = add_option( $lock_key, time(), '', false );
            if ( ! $lock_acquired ) {
                $lock_time = (int) get_option( $lock_key, 0 );
                if ( $lock_time && time() - $lock_time > 45 ) {
                    delete_option( $lock_key );
                    $lock_acquired = add_option( $lock_key, time(), '', false );
                }
            }

            if ( ! $lock_acquired ) {
                for ( $i = 0; $i < 4; $i++ ) {
                    usleep( 125000 );
                    foreach ( $pending_ids as $id ) {
                        if ( isset( $results[ $id ] ) ) {
                            continue;
                        }
                        $cached = $this->cache->get( $id, $check_in, $check_out, $guests, 0, 0, 0 );
                        if ( $cached ) {
                            $results[ $id ] = $this->format_quote_result( $id, $cached['quote_data'], true );
                        }
                    }
                    if ( count( array_intersect( $pending_ids, array_keys( $results ) ) ) === count( $pending_ids ) ) {
                        break;
                    }
                }
            } else {
                try {
                    $response = $this->api->request( 'quotes/multiple', 'POST', array( 'quotes' => $payload ), array( 'timeout' => 25, 'retries' => 1 ) );
                    if ( ! is_wp_error( $response ) ) {
                        $items = ! empty( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
                        foreach ( $items as $item ) {
                            $id = isset( $item['unitTypeId'] ) ? sanitize_text_field( $item['unitTypeId'] ) : '';
                            if ( ! $id ) {
                                continue;
                            }
                            $this->cache->set( $id, $check_in, $check_out, $guests, 0, 0, 0, $item, max( 5, min( 60, (int) get_option( 'guesty_quote_cache_minutes', 15 ) ) ) );
                            $results[ $id ] = $this->format_quote_result( $id, $item, false );
                        }
                    }
                } finally {
                    delete_option( $lock_key );
                }
            }
        }

        return $results;
    }

    private function format_quote_result( $id, array $quote, $from_cache ) {
        $prices = $this->cache->extract_prices( $quote );
        return array(
            'guesty_id'      => $id,
            'total_price'    => null === $prices['total'] ? 0 : round( $prices['total'], 2 ),
            'total_adjusted' => null === $prices['adjusted'] ? 0 : round( $prices['adjusted'], 2 ),
            'currency'       => $prices['currency'],
            'is_discounted'  => null !== $prices['total'] && null !== $prices['adjusted'] && $prices['adjusted'] < $prices['total'],
            'from_cache'     => (bool) $from_cache,
        );
    }

    private function normalize( array $request ) {
        $check_in  = sanitize_text_field( isset( $request['checkInDateLocalized'] ) ? $request['checkInDateLocalized'] : '' );
        $check_out = sanitize_text_field( isset( $request['checkOutDateLocalized'] ) ? $request['checkOutDateLocalized'] : '' );
        $listing   = sanitize_text_field( isset( $request['listingId'] ) ? $request['listingId'] : '' );
        $start = DateTimeImmutable::createFromFormat( '!Y-m-d', $check_in );
        $end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $check_out );

        if ( ! $listing || ! $start || ! $end || $start >= $end ) {
            return new WP_Error( 'guesty_invalid_quote_request', 'Listing, check-in, and check-out are required.' );
        }

        $breakdown = isset( $request['numberOfGuests'] ) && is_array( $request['numberOfGuests'] ) ? $request['numberOfGuests'] : array();
        $adults   = max( 1, (int) ( isset( $breakdown['numberOfAdults'] ) ? $breakdown['numberOfAdults'] : 1 ) );
        $children = max( 0, (int) ( isset( $breakdown['numberOfChildren'] ) ? $breakdown['numberOfChildren'] : 0 ) );
        $infants  = max( 0, (int) ( isset( $breakdown['numberOfInfants'] ) ? $breakdown['numberOfInfants'] : 0 ) );
        $pets     = max( 0, (int) ( isset( $breakdown['numberOfPets'] ) ? $breakdown['numberOfPets'] : 0 ) );

        return array(
            'checkInDateLocalized'  => $check_in,
            'checkOutDateLocalized' => $check_out,
            'guestsCount'           => max( 1, $adults + $children ),
            'listingId'             => $listing,
            'source'                => sanitize_text_field( isset( $request['source'] ) ? $request['source'] : 'website' ),
            'couponCode'            => sanitize_text_field( isset( $request['couponCode'] ) ? $request['couponCode'] : '' ),
            'numberOfGuests'        => array(
                'numberOfChildren' => $children,
                'numberOfInfants'  => $infants,
                'numberOfAdults'   => $adults,
                'numberOfPets'     => $pets,
            ),
        );
    }
}
