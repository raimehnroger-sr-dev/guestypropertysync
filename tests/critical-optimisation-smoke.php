<?php
/**
 * Standalone checks for the critical optimisation plan requirements.
 * Run: php tests/critical-optimisation-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['critical_options'] = array();
$GLOBALS['critical_transients'] = array();

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct( $code = '', $message = '', $data = array() ) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
}

class WP_REST_Request {
    private $body;
    private $headers;
    public function __construct( $body, array $headers = array() ) {
        $this->body = $body;
        $this->headers = array_change_key_case( $headers, CASE_LOWER );
    }
    public function get_header( $name ) {
        return $this->headers[ strtolower( $name ) ] ?? '';
    }
    public function get_body() {
        return $this->body;
    }
}

function get_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['critical_options'] ) ? $GLOBALS['critical_options'][ $key ] : $default;
}
function add_option( $key, $value ) {
    if ( array_key_exists( $key, $GLOBALS['critical_options'] ) ) {
        return false;
    }
    $GLOBALS['critical_options'][ $key ] = $value;
    return true;
}
function update_option( $key, $value ) {
    $GLOBALS['critical_options'][ $key ] = $value;
    return true;
}
function delete_option( $key ) {
    unset( $GLOBALS['critical_options'][ $key ] );
    return true;
}
function get_transient( $key ) {
    return array_key_exists( $key, $GLOBALS['critical_transients'] ) ? $GLOBALS['critical_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
    $GLOBALS['critical_transients'][ $key ] = $value;
    return true;
}
function delete_transient( $key ) {
    unset( $GLOBALS['critical_transients'][ $key ] );
    return true;
}
function sanitize_key( $value ) {
    return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}
function sanitize_text_field( $value ) {
    return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) );
}
function wp_unslash( $value ) {
    return $value;
}

function critical_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$root = dirname( __DIR__ );
require_once $root . '/includes/cache/class-guesty-transient-cache.php';
require_once $root . '/includes/class-guesty-property-sync.php';

// Calendar keys must be deterministic and isolated by listing/date/view.
$calendar_key = Guesty_Transient_Cache::calendar_key( 'listing-1', '2026-09-01', '2026-09-30' );
critical_assert( $calendar_key === Guesty_Transient_Cache::calendar_key( 'listing-1', '2026-09-01', '2026-09-30' ), 'Calendar transient key should be deterministic.' );
critical_assert( $calendar_key !== Guesty_Transient_Cache::calendar_key( 'listing-2', '2026-09-01', '2026-09-30' ), 'Calendar transient key should be isolated by listing.' );

// Quote keys must include dates, guest composition, and coupon.
$guest_mix = array( 'numberOfAdults' => 2, 'numberOfChildren' => 1, 'numberOfInfants' => 0, 'numberOfPets' => 0 );
$quote_key = Guesty_Transient_Cache::quote_key( 'listing-1', '2026-09-10', '2026-09-13', $guest_mix, '' );
critical_assert( $quote_key !== Guesty_Transient_Cache::quote_key( 'listing-1', '2026-09-10', '2026-09-13', array_merge( $guest_mix, array( 'numberOfAdults' => 3 ) ), '' ), 'Quote transient key should vary by guests.' );
critical_assert( $quote_key !== Guesty_Transient_Cache::quote_key( 'listing-1', '2026-09-10', '2026-09-13', $guest_mix, 'SAVE10' ), 'Quote transient key should vary by coupon.' );

Guesty_Transient_Cache::set( 'calendar', 'listing-1', $calendar_key, array( 'days' => array() ), 3600 );
Guesty_Transient_Cache::set( 'quote', 'listing-1', $quote_key, array( 'total' => 500 ), 900 );
critical_assert( false !== get_transient( $calendar_key ) && false !== get_transient( $quote_key ), 'Listing cache entries should be stored.' );
Guesty_Transient_Cache::invalidate_listing( 'listing-1' );
critical_assert( false === get_transient( $calendar_key ) && false === get_transient( $quote_key ), 'Listing invalidation should remove calendar and quote transients.' );

// Verify HMAC-SHA256 acceptance and rejection using the raw request body.
$secret = 'critical-test-secret';
$body = '{"event":"listing.updated","listingId":"listing-1"}';
$GLOBALS['critical_options']['guesty_webhook_secret'] = $secret;
$plugin = ( new ReflectionClass( 'Guesty_Property_Sync' ) )->newInstanceWithoutConstructor();
$hex_signature = hash_hmac( 'sha256', $body, $secret );
$valid = $plugin->verify_webhook_signature( new WP_REST_Request( $body, array( 'X-Guesty-Signature' => 'sha256=' . $hex_signature ) ) );
critical_assert( true === $valid, 'Valid hexadecimal HMAC signature should be accepted.' );
$base64_signature = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
$valid_base64 = $plugin->verify_webhook_signature( new WP_REST_Request( $body, array( 'X-Guesty-Signature' => $base64_signature ) ) );
critical_assert( true === $valid_base64, 'Valid base64 HMAC signature should be accepted.' );
$invalid = $plugin->verify_webhook_signature( new WP_REST_Request( $body, array( 'X-Guesty-Signature' => str_repeat( '0', 64 ) ) ) );
critical_assert( $invalid instanceof WP_Error && 401 === (int) ( $invalid->data['status'] ?? 0 ), 'Invalid HMAC signature should return 401.' );

// Static regression guards for lazy loading and optimisation-summary features.
$shortcode = file_get_contents( $root . '/includes/class-guesty-property-short-code.php' );
$calendar_start = strpos( $shortcode, 'function property_calendar()' );
$calendar_end = strpos( $shortcode, 'public function guesty_load_calendar()', $calendar_start );
$calendar_renderer = substr( $shortcode, $calendar_start, $calendar_end - $calendar_start );
critical_assert( false !== strpos( $calendar_renderer, 'guesty-calendar-lazy' ), 'Initial calendar output should be a lazy-load shell.' );
critical_assert( false === strpos( $calendar_renderer, '->get_calendar(' ), 'Initial calendar renderer should not call Guesty.' );

$calendar_js = file_get_contents( $root . '/includes/js/guesty-calendar.js' );
critical_assert( false !== strpos( $calendar_js, "on('click', '.guesty-calendar-open'" ), 'Calendar should load from an explicit click event.' );
critical_assert( false !== strpos( $calendar_js, "on('focus click', '#arrival, #departure" ), 'Calendar should load from explicit date-field interaction.' );

$search_js = file_get_contents( $root . '/includes/js/guesty-search.js' );
critical_assert( false === strpos( $search_js, 'guesty_check_availability' ) && false === strpos( $search_js, 'guesty_booking_data' ), 'Search-page JavaScript should not auto-request calendars or quotes.' );

$sync_manager = file_get_contents( $root . '/includes/class-guesty-property-sync-manager.php' );
foreach ( array( '_guesty_base_price', '_guesty_min_nights', '_guesty_property_type', '_guesty_max_guests', '_guesty_bedrooms', '_guesty_bathrooms', 'property_cancellation_policy', 'property_house_rules' ) as $meta_key ) {
    critical_assert( false !== strpos( $sync_manager, $meta_key ), "Sync should store {$meta_key}." );
}

$settings = file_get_contents( $root . '/admin/settings.php' );
critical_assert( false !== strpos( $settings, 'guesty_calendar_cache_minutes' ), 'Calendar cache TTL should be configurable.' );
critical_assert( false !== strpos( $settings, 'guesty_quote_cache_minutes' ), 'Quote cache TTL should be configurable.' );
critical_assert( false !== strpos( $settings, 'guesty_webhook_secret' ), 'Webhook secret should be configurable.' );

$dashboard = file_get_contents( $root . '/admin/dashboard.php' );
critical_assert( false !== strpos( $dashboard, 'Revenue & Booking Stats' ), 'Dashboard statistics should be present.' );
critical_assert( is_file( $root . '/admin/activity-log.php' ), 'Activity log admin page should be present.' );

echo "CRITICAL_OPTIMISATION_SMOKE_OK\n";
