<?php
/**
 * Standalone smoke tests for the optimized cache-first service layer.
 * Run: php tests/service-layer-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
    public $code;
    public $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code = $code;
        $this->message = $message;
    }
}

$GLOBALS['test_options'] = array();
$GLOBALS['test_transients'] = array();
$GLOBALS['test_scheduled'] = array();
$GLOBALS['test_posts'] = array();
$GLOBALS['test_meta'] = array();

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $key ] : $default; }
function add_option( $key, $value ) { if ( array_key_exists( $key, $GLOBALS['test_options'] ) ) { return false; } $GLOBALS['test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['test_options'][ $key ] ); return true; }
function update_option( $key, $value ) { $GLOBALS['test_options'][ $key ] = $value; return true; }
function get_transient( $key ) { return array_key_exists( $key, $GLOBALS['test_transients'] ) ? $GLOBALS['test_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['test_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['test_transients'][ $key ] ); return true; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { $GLOBALS['test_scheduled'][] = array( $timestamp, $hook, $args ); return true; }
function get_posts( $args = array() ) { return $GLOBALS['test_posts']; }
function get_post_meta( $post_id, $key, $single = true ) { return $GLOBALS['test_meta'][ $post_id ][ $key ] ?? ''; }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }

require_once dirname( __DIR__ ) . '/includes/cache/class-guesty-transient-cache.php';
require_once dirname( __DIR__ ) . '/includes/api/class-guesty-api.php';
require_once dirname( __DIR__ ) . '/includes/cache/class-guesty-pricing-cache.php';
require_once dirname( __DIR__ ) . '/includes/cache/class-guesty-availability-cache.php';
require_once dirname( __DIR__ ) . '/includes/services/class-guesty-calendar-service.php';
require_once dirname( __DIR__ ) . '/includes/services/class-guesty-search-service.php';
require_once dirname( __DIR__ ) . '/includes/services/class-guesty-quote-service.php';

function test_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

function quote_payload( $id, $total = 300.0, $adjusted = 270.0 ) {
    return array(
        'unitTypeId' => $id,
        'rates' => array(
            'ratePlans' => array(
                array(
                    'money' => array(
                        'money' => array(
                            'fareAccommodation' => $total,
                            'fareAccommodationAdjusted' => $adjusted,
                            'currency' => 'USD',
                        ),
                    ),
                ),
            ),
        ),
    );
}

class Test_Guesty_API extends Guesty_API {
    public $request_calls = array();
    public $available_calls = 0;
    public $calendar_calls = 0;
    public function __construct() {}
    public function request( $endpoint, $method = 'GET', $body = null, $options = array() ) {
        $this->request_calls[] = $endpoint;
        if ( 'quotes/multiple' === $endpoint ) {
            $results = array();
            foreach ( $body['quotes'] as $item ) {
                $results[] = quote_payload( $item['unitTypeId'] );
            }
            return array( 'results' => $results );
        }
        return quote_payload( $body['listingId'] ?? 'unknown' );
    }
    public function get_available_listings( $check_in, $check_out, $guests = 1, $limit = 100, $skip = 0 ) {
        $this->available_calls++;
        return array( 'results' => array( array( '_id' => 'listing-1' ), array( '_id' => 'listing-2' ) ) );
    }
    public function get_calendar( $listing_id, $start_date, $end_date ) {
        $this->calendar_calls++;
        $days = array();
        $cursor = new DateTimeImmutable( $start_date );
        $end = new DateTimeImmutable( $end_date );
        while ( $cursor <= $end ) {
            $days[] = array( 'date' => $cursor->format( 'Y-m-d' ), 'status' => 'available', 'price' => 100, 'currency' => 'USD' );
            $cursor = $cursor->modify( '+1 day' );
        }
        return array( 'data' => array( 'days' => $days, 'currency' => 'USD' ) );
    }
    public function get_calendar_compact( $listing_id, $start_date, $end_date ) {
        return $this->get_calendar( $listing_id, $start_date, $end_date );
    }
}

class Test_Pricing_Cache extends Guesty_Optimized_Pricing_Cache {
    private $items = array();
    private function test_key( $id, $in, $out, $a, $c, $i, $p ) { return implode( '|', array( $id, $in, $out, $a, $c, $i, $p ) ); }
    public function get( $id, $in, $out, $a = 1, $c = 0, $i = 0, $p = 0, $allow_stale = false ) {
        $key = $this->test_key( $id, $in, $out, $a, $c, $i, $p );
        return isset( $this->items[ $key ] ) ? array( 'quote_data' => $this->items[ $key ] ) : null;
    }
    public function set( $id, $in, $out, $a, $c, $i, $p, $quote, $ttl = 15 ) {
        $this->items[ $this->test_key( $id, $in, $out, $a, $c, $i, $p ) ] = $quote;
        return true;
    }
}

class Test_Availability_Cache extends Guesty_Optimized_Availability_Cache {
    public $summary;
    public $calendars = array();
    public function __construct() {
        $this->summary = array( 'covered' => array(), 'stale' => array(), 'available' => array(), 'missing' => array(), 'prices' => array() );
    }
    public function get_range_summary( array $ids, $check_in, $check_out, $max_age_hours = 0 ) { return $this->summary; }
    public function get_calendar( $id, $start, $end, $max_age_hours = 0 ) { return $this->calendars[ $id ] ?? null; }
    public function save_calendar( $id, $calendar ) { $this->calendars[ $id ] = $calendar; return count( $calendar['data']['days'] ?? array() ); }
}

// Exact quote: first request is live, second is served from cache.
$GLOBALS['test_options']['guesty_quote_cache_minutes'] = 15;
$quote_api = new Test_Guesty_API();
$quote_cache = new Test_Pricing_Cache();
$quote_service = new Guesty_Optimized_Quote_Service( $quote_api, $quote_cache );
$request = array(
    'listingId' => 'listing-1',
    'checkInDateLocalized' => '2026-09-10',
    'checkOutDateLocalized' => '2026-09-13',
    'numberOfGuests' => array( 'numberOfAdults' => 2 ),
);
$first_quote = $quote_service->get_quote( $request );
$second_quote = $quote_service->get_quote( $request );
test_assert( ! is_wp_error( $first_quote ), 'First exact quote should succeed.' );
test_assert( 1 === count( $quote_api->request_calls ), 'Second exact quote should avoid another API call.' );
test_assert( ! empty( $second_quote['_guesty_transient_cache'] ) || ! empty( $second_quote['_guesty_optimized_cache'] ), 'Second exact quote should be marked as cached.' );

// Visible pricing: uncached properties are combined into one quotes/multiple call.
$visible = $quote_service->get_visible_quotes( array( 'listing-1', 'listing-2' ), '2026-10-01', '2026-10-04', 2 );
test_assert( 2 === count( $visible ), 'Visible quote batch should return both listings.' );
test_assert( 1 === count( array_filter( $quote_api->request_calls, static function( $endpoint ) { return 'quotes/multiple' === $endpoint; } ) ), 'Visible listings should use one batch quote request.' );
$visible_cached = $quote_service->get_visible_quotes( array( 'listing-1', 'listing-2' ), '2026-10-01', '2026-10-04', 2 );
test_assert( 2 === count( $visible_cached ), 'Cached visible quote batch should return both listings.' );
test_assert( 1 === count( array_filter( $quote_api->request_calls, static function( $endpoint ) { return 'quotes/multiple' === $endpoint; } ) ), 'Cached visible quotes should not repeat the batch API request.' );

// Search: a fully covered calendar cache performs zero availability API calls.
$GLOBALS['test_transients'] = array();
$GLOBALS['test_options']['guesty_calendar_cache_hours'] = 6;
$GLOBALS['test_options']['guesty_search_cache_coverage'] = 0.9;
$GLOBALS['test_options']['guesty_search_cache_minutes'] = 5;
set_transient( 'guesty_optimized_listing_map', array(
    'guesty_ids' => array( 'listing-1', 'listing-2' ),
    'post_map' => array( 'listing-1' => 101, 'listing-2' => 102 ),
), 3600 );
$search_api = new Test_Guesty_API();
$availability = new Test_Availability_Cache();
$availability->summary = array(
    'covered' => array( 'listing-1', 'listing-2' ),
    'stale' => array(),
    'available' => array( 'listing-1' ),
    'missing' => array(),
    'prices' => array( 'listing-1' => array( 'total' => 300, 'currency' => 'USD' ) ),
);
$calendar_service = new Guesty_Optimized_Calendar_Service( $search_api, $availability );
$search_service = new Guesty_Optimized_Search_Service( $search_api, $availability, $calendar_service );
$warm_results = $search_service->search( '2026-11-01', '2026-11-04', 2 );
test_assert( 1 === count( $warm_results ), 'Warm search should return the cached available listing.' );
test_assert( 0 === $search_api->available_calls, 'Warm search should use zero Guesty availability calls.' );

// Search: insufficient local coverage falls back to one available-listings request.
$GLOBALS['test_transients'] = array();
set_transient( 'guesty_optimized_listing_map', array(
    'guesty_ids' => array( 'listing-1', 'listing-2' ),
    'post_map' => array( 'listing-1' => 101, 'listing-2' => 102 ),
), 3600 );
$availability->summary = array(
    'covered' => array(),
    'stale' => array(),
    'available' => array(),
    'missing' => array( 'listing-1', 'listing-2' ),
    'prices' => array(),
);
$cold_results = $search_service->search( '2026-12-01', '2026-12-04', 2 );
test_assert( 2 === count( $cold_results ), 'Cold search should return available local listings discovered by Guesty.' );
test_assert( 1 === $search_api->available_calls, 'Cold search should use one available-listings request for this inventory size.' );

// Calendar: cached reads avoid Guesty; cold reads make one request and save locally.
$calendar_api = new Test_Guesty_API();
$calendar_cache = new Test_Availability_Cache();
$calendar_service = new Guesty_Optimized_Calendar_Service( $calendar_api, $calendar_cache );
$cached_days = $calendar_api->get_calendar( 'listing-cached', '2027-01-01', '2027-01-03' );
$calendar_api->calendar_calls = 0;
$calendar_cache->calendars['listing-cached'] = $cached_days;
$cached_calendar = $calendar_service->get_calendar( 'listing-cached', '2027-01-01', '2027-01-03' );
test_assert( ! is_wp_error( $cached_calendar ), 'Cached calendar should succeed.' );
test_assert( 0 === $calendar_api->calendar_calls, 'Cached calendar should avoid Guesty.' );
$cold_calendar = $calendar_service->get_calendar( 'listing-cold', '2027-02-01', '2027-02-03', false );
test_assert( ! is_wp_error( $cold_calendar ), 'Cold calendar should succeed.' );
test_assert( 1 === $calendar_api->calendar_calls, 'Cold calendar should use one Guesty request.' );
test_assert( isset( $calendar_cache->calendars['listing-cold'] ), 'Cold calendar should be saved to the local cache.' );

echo "SERVICE_LAYER_SMOKE_OK\n";
