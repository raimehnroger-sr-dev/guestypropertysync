<?php
/**
 * WordPress transient cache required by the optimisation plan.
 *
 * The persistent calendar/quote tables remain as a secondary cache, while this
 * class provides the fast, object-cache-friendly transient layer requested for
 * frontend calendar and quote reads.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Guesty_Transient_Cache {

    const LISTINGS_OPTION = 'guesty_transient_cache_listings';

    public static function calendar_key( $listing_id, $start_date, $end_date, $view = 'full' ) {
        $seed = sprintf(
            'calendar_%s_%s_%s%s',
            trim( (string) $listing_id ),
            trim( (string) $start_date ),
            trim( (string) $end_date ),
            'full' === $view ? '' : '_' . sanitize_key( $view )
        );

        return 'guesty_calendar_' . md5( $seed );
    }

    public static function quote_key( $listing_id, $start_date, $end_date, array $guests, $coupon_code = '' ) {
        $seed = implode(
            '_',
            array(
                'quote',
                trim( (string) $listing_id ),
                trim( (string) $start_date ),
                trim( (string) $end_date ),
                max( 1, (int) ( $guests['numberOfAdults'] ?? 1 ) ),
                max( 0, (int) ( $guests['numberOfChildren'] ?? 0 ) ),
                max( 0, (int) ( $guests['numberOfInfants'] ?? 0 ) ),
                max( 0, (int) ( $guests['numberOfPets'] ?? 0 ) ),
                strtolower( trim( (string) $coupon_code ) ),
            )
        );

        return 'guesty_quote_' . md5( $seed );
    }

    public static function get( $key ) {
        return get_transient( $key );
    }

    public static function set( $type, $listing_id, $key, $value, $ttl ) {
        $ttl = max( MINUTE_IN_SECONDS, (int) $ttl );
        set_transient( $key, $value, $ttl );
        self::register_key( $type, $listing_id, $key );
    }

    public static function invalidate_listing( $listing_id ) {
        $listing_id = trim( (string) $listing_id );
        if ( '' === $listing_id ) {
            return;
        }

        foreach ( array( 'calendar', 'quote' ) as $type ) {
            $option = self::keys_option( $type, $listing_id );
            $keys   = get_option( $option, array() );
            if ( is_array( $keys ) ) {
                foreach ( array_keys( $keys ) as $key ) {
                    delete_transient( $key );
                }
            }
            delete_option( $option );
        }
    }

    public static function clear_all() {
        $listing_ids = get_option( self::LISTINGS_OPTION, array() );
        if ( is_array( $listing_ids ) ) {
            foreach ( array_keys( $listing_ids ) as $listing_id ) {
                self::invalidate_listing( $listing_id );
            }
        }
        delete_option( self::LISTINGS_OPTION );
    }

    private static function register_key( $type, $listing_id, $key ) {
        $type       = in_array( $type, array( 'calendar', 'quote' ), true ) ? $type : 'calendar';
        $listing_id = trim( (string) $listing_id );
        if ( '' === $listing_id || '' === $key ) {
            return;
        }

        $option = self::keys_option( $type, $listing_id );
        $keys   = get_option( $option, array() );
        $keys   = is_array( $keys ) ? $keys : array();
        $keys[ $key ] = time();

        if ( false === get_option( $option, false ) ) {
            add_option( $option, $keys, '', false );
        } else {
            update_option( $option, $keys, false );
        }

        $listings = get_option( self::LISTINGS_OPTION, array() );
        $listings = is_array( $listings ) ? $listings : array();
        $listings[ $listing_id ] = time();
        if ( false === get_option( self::LISTINGS_OPTION, false ) ) {
            add_option( self::LISTINGS_OPTION, $listings, '', false );
        } else {
            update_option( self::LISTINGS_OPTION, $listings, false );
        }
    }

    private static function keys_option( $type, $listing_id ) {
        return 'guesty_' . sanitize_key( $type ) . '_transient_keys_' . md5( (string) $listing_id );
    }
}
