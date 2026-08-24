<?php
/**
 * Settings admin page
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Include the main plugin class to access the schedule_sync_event method
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/class-guesty-property-sync.php';

// Save settings if the form was submitted
if ( isset( $_POST['guesty_settings_submit'] ) && check_admin_referer( 'guesty_settings_nonce' ) ) {
    $api_key = sanitize_text_field( $_POST['guesty_api_key'] );
    $api_secret = sanitize_text_field( $_POST['guesty_api_secret'] );

    $stripe_key = sanitize_text_field( $_POST['stripe_publishable_key'] );
    $stripe_secret = sanitize_text_field( $_POST['stripe_secret_key'] );

    $sync_interval = sanitize_text_field( $_POST['guesty_sync_interval'] );
    $auto_sync_limit = intval( $_POST['guesty_auto_sync_limit'] );

    $calendar_cache_minutes = max( 5, min( 1440, intval( $_POST['guesty_calendar_cache_minutes'] ?? 60 ) ) );
    $calendar_cache_hours   = max( 1, (int) ceil( $calendar_cache_minutes / 60 ) );
    $calendar_sync_days   = max( 30, min( 730, intval( $_POST['guesty_calendar_sync_days'] ?? 365 ) ) );
    $calendar_sync_batch  = max( 1, min( 10, intval( $_POST['guesty_calendar_sync_batch'] ?? 4 ) ) );
    $quote_cache_minutes  = max( 5, min( 60, intval( $_POST['guesty_quote_cache_minutes'] ?? 15 ) ) );
    $activity_log_retention_days = max( 1, min( 365, intval( $_POST['guesty_activity_log_retention_days'] ?? 30 ) ) );
    $google_maps_key      = sanitize_text_field( $_POST['guesty_google_maps_api_key'] ?? '' );
    $webhook_secret       = sanitize_text_field( $_POST['guesty_webhook_secret'] ?? '' );
    $debug_logging        = isset( $_POST['guesty_debug_logging'] ) ? '1' : '0';
    $brand_name           = sanitize_text_field( $_POST['guesty_brand_name'] ?? get_bloginfo( 'name' ) );
    $contact_email        = sanitize_email( $_POST['guesty_contact_email'] ?? get_option( 'admin_email', '' ) );
    $contact_phone        = sanitize_text_field( $_POST['guesty_contact_phone'] ?? '' );
    $booking_page_url     = esc_url_raw( $_POST['guesty_booking_page_url'] ?? site_url( '/booking/' ) );
    $terms_url            = esc_url_raw( $_POST['guesty_terms_url'] ?? ( function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '' ) );
    $default_currency     = strtoupper( sanitize_text_field( $_POST['guesty_default_currency'] ?? 'GBP' ) );
    
    update_option( 'guesty_api_key', $api_key );
    update_option( 'guesty_api_secret', $api_secret );

    update_option( 'stripe_publishable_key', $stripe_key );
    update_option( 'stripe_secret_key', $stripe_secret );

    update_option( 'guesty_sync_interval', $sync_interval );
    update_option( 'guesty_auto_sync_limit', $auto_sync_limit );

    update_option( 'guesty_calendar_cache_minutes', $calendar_cache_minutes );
    update_option( 'guesty_calendar_cache_hours', $calendar_cache_hours );
    update_option( 'guesty_calendar_sync_days', $calendar_sync_days );
    update_option( 'guesty_calendar_sync_batch', $calendar_sync_batch );
    update_option( 'guesty_quote_cache_minutes', $quote_cache_minutes );
    update_option( 'guesty_activity_log_retention_days', $activity_log_retention_days );
    update_option( 'guesty_google_maps_api_key', $google_maps_key );
    // Store only the actual Guesty-provided signing secret. An empty value keeps
    // webhook processing disabled/fail-closed until a valid secret is configured.
    update_option( 'guesty_webhook_secret', $webhook_secret );
    update_option( 'guesty_debug_logging', $debug_logging );
    update_option( 'guesty_brand_name', $brand_name ?: get_bloginfo( 'name' ) );
    update_option( 'guesty_contact_email', $contact_email ?: get_option( 'admin_email', '' ) );
    update_option( 'guesty_contact_phone', $contact_phone );
    update_option( 'guesty_booking_page_url', $booking_page_url ?: site_url( '/booking/' ) );
    update_option( 'guesty_terms_url', $terms_url ?: ( function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '' ) );
    update_option( 'guesty_default_currency', preg_match( '/^[A-Z]{3}$/', $default_currency ) ? $default_currency : 'GBP' );
    
    // Reschedule the sync event with the new interval
    $plugin = new Guesty_Property_Sync();
    $plugin->schedule_sync_event();
    
    add_settings_error( 'guesty_settings', 'settings_updated', __( 'Settings saved successfully.', 'guesty-properties-sync' ), 'updated' );
}

// Get saved settings
$api_key = get_option( 'guesty_api_key', '' );
$api_secret = get_option( 'guesty_api_secret', '' );

$stripe_key = get_option( 'stripe_publishable_key', '' );
$stripe_secret = get_option( 'stripe_secret_key', '' );

$sync_interval = get_option( 'guesty_sync_interval', 'daily' );
$auto_sync_limit = get_option( 'guesty_auto_sync_limit', 50 );

$calendar_cache_minutes = get_option( 'guesty_calendar_cache_minutes', 60 );
$calendar_cache_hours = max( 1, (int) ceil( $calendar_cache_minutes / 60 ) );
$calendar_sync_days = get_option( 'guesty_calendar_sync_days', 365 );
$calendar_sync_batch = get_option( 'guesty_calendar_sync_batch', 4 );
$quote_cache_minutes = get_option( 'guesty_quote_cache_minutes', 15 );
$activity_log_retention_days = get_option( 'guesty_activity_log_retention_days', 30 );
$google_maps_key = get_option( 'guesty_google_maps_api_key', '' );
$webhook_secret = get_option( 'guesty_webhook_secret', '' );
$debug_logging = get_option( 'guesty_debug_logging', '0' );
$brand_name = get_option( 'guesty_brand_name', get_bloginfo( 'name' ) );
$contact_email = get_option( 'guesty_contact_email', get_option( 'admin_email', '' ) );
$contact_phone = get_option( 'guesty_contact_phone', '' );
$booking_page_url = get_option( 'guesty_booking_page_url', site_url( '/booking/' ) );
$terms_url = get_option( 'guesty_terms_url', ( function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '' ) );
$default_currency = get_option( 'guesty_default_currency', 'GBP' );
$webhook_url = rest_url( 'guesty/v1/webhook' );

// Display settings errors
settings_errors( 'guesty_settings' );
?>

<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    
    <form method="post" action="">
        <?php wp_nonce_field( 'guesty_settings_nonce' ); ?>
        
        <h2><?php _e( 'API Settings', 'guesty-properties-sync' ); ?></h2>
        <p><?php _e( 'Configure your Guesty API credentials. You can find these in your Guesty account.', 'guesty-properties-sync' ); ?></p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="guesty_api_key"><?php _e( 'API Key', 'guesty-properties-sync' ); ?></label>
                </th>
                <td>
                    <input type="text" id="guesty_api_key" name="guesty_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
                    <p class="description"><?php _e( 'Enter your Guesty API Key', 'guesty-properties-sync' ); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="guesty_api_secret"><?php _e( 'API Secret', 'guesty-properties-sync' ); ?></label>
                </th>
                <td>
                    <input type="password" id="guesty_api_secret" name="guesty_api_secret" value="<?php echo esc_attr( $api_secret ); ?>" class="regular-text" />
                    <p class="description"><?php _e( 'Enter your Guesty API Secret', 'guesty-properties-sync' ); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php _e( 'Sync Settings', 'guesty-properties-sync' ); ?></h2>
        <p><?php _e( 'Configure automatic synchronization of properties from Guesty.', 'guesty-properties-sync' ); ?></p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="guesty_sync_interval"><?php _e( 'Sync Interval', 'guesty-properties-sync' ); ?></label>
                </th>
                <td>
                    <select id="guesty_sync_interval" name="guesty_sync_interval">
                        <option value="hourly" <?php selected( $sync_interval, 'hourly' ); ?>><?php _e( 'Hourly', 'guesty-properties-sync' ); ?></option>
                        <option value="twicedaily" <?php selected( $sync_interval, 'twicedaily' ); ?>><?php _e( 'Twice Daily', 'guesty-properties-sync' ); ?></option>
                        <option value="daily" <?php selected( $sync_interval, 'daily' ); ?>><?php _e( 'Daily', 'guesty-properties-sync' ); ?></option>
                    </select>
                    <p class="description"><?php _e( 'Select how often to sync properties from Guesty', 'guesty-properties-sync' ); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="guesty_auto_sync_limit"><?php _e( 'Auto-Sync Limit', 'guesty-properties-sync' ); ?></label>
                </th>
                <td>
                    <input type="number" id="guesty_auto_sync_limit" name="guesty_auto_sync_limit" value="<?php echo esc_attr( $auto_sync_limit ); ?>" min="1" max="150" class="small-text" />
                    <p class="description"><?php _e( 'Maximum number of properties to sync automatically (max: 150)', 'guesty-properties-sync' ); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php _e( 'Frontend Performance', 'guesty-properties-sync' ); ?></h2>
        <p><?php _e( 'These settings control the cache-first availability and quote system.', 'guesty-properties-sync' ); ?></p>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="guesty_calendar_cache_minutes"><?php _e( 'Calendar Cache TTL', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="number" id="guesty_calendar_cache_minutes" name="guesty_calendar_cache_minutes" value="<?php echo esc_attr( $calendar_cache_minutes ); ?>" min="5" max="1440" class="small-text" /> minutes
                <p class="description"><?php _e( 'Default: 60 minutes. Identical property/date calendar requests are served from WordPress transients.', 'guesty-properties-sync' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_calendar_sync_days"><?php _e( 'Calendar Window', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="number" id="guesty_calendar_sync_days" name="guesty_calendar_sync_days" value="<?php echo esc_attr( $calendar_sync_days ); ?>" min="30" max="730" class="small-text" /> days</td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_calendar_sync_batch"><?php _e( 'Warmup Batch', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="number" id="guesty_calendar_sync_batch" name="guesty_calendar_sync_batch" value="<?php echo esc_attr( $calendar_sync_batch ); ?>" min="1" max="10" class="small-text" /> properties every 15 minutes
                <p class="description"><?php _e( 'A rotating batch avoids API bursts while keeping future availability local.', 'guesty-properties-sync' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_quote_cache_minutes"><?php _e( 'Exact Quote Cache', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="number" id="guesty_quote_cache_minutes" name="guesty_quote_cache_minutes" value="<?php echo esc_attr( $quote_cache_minutes ); ?>" min="5" max="60" class="small-text" /> minutes
                <p class="description"><?php _e( 'Default: 15 minutes. Keyed by listing, dates, guest counts, and coupon.', 'guesty-properties-sync' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_activity_log_retention_days"><?php _e( 'Activity Log Retention', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="number" id="guesty_activity_log_retention_days" name="guesty_activity_log_retention_days" value="<?php echo esc_attr( $activity_log_retention_days ); ?>" min="1" max="365" class="small-text" /> days
                <p class="description"><?php _e( 'Default: 30 days. Older sync, webhook, and API events are removed during cleanup.', 'guesty-properties-sync' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_brand_name"><?php _e( 'Brand Name', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="text" id="guesty_brand_name" name="guesty_brand_name" value="<?php echo esc_attr( $brand_name ); ?>" class="regular-text" />
                <p class="description"><?php _e( 'Used in favourites emails and frontend support messages.', 'guesty-properties-sync' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_contact_email"><?php _e( 'Contact Email', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="email" id="guesty_contact_email" name="guesty_contact_email" value="<?php echo esc_attr( $contact_email ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_contact_phone"><?php _e( 'Contact Phone', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="text" id="guesty_contact_phone" name="guesty_contact_phone" value="<?php echo esc_attr( $contact_phone ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_booking_page_url"><?php _e( 'Booking Page URL', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="url" id="guesty_booking_page_url" name="guesty_booking_page_url" value="<?php echo esc_attr( $booking_page_url ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_terms_url"><?php _e( 'Terms and Conditions URL', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="url" id="guesty_terms_url" name="guesty_terms_url" value="<?php echo esc_attr( $terms_url ); ?>" class="regular-text" />
                <p class="description"><?php _e( 'Linked from the required checkout consent checkbox.', 'guesty-properties-sync' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_default_currency"><?php _e( 'Default Currency', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="text" id="guesty_default_currency" name="guesty_default_currency" value="<?php echo esc_attr( $default_currency ); ?>" maxlength="3" class="small-text code" />
                <p class="description"><?php _e( 'Three-letter ISO code, used only when Guesty does not return a currency.', 'guesty-properties-sync' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_google_maps_api_key"><?php _e( 'Google Maps API Key', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="password" id="guesty_google_maps_api_key" name="guesty_google_maps_api_key" value="<?php echo esc_attr( $google_maps_key ); ?>" class="regular-text" autocomplete="off" />
                <p class="description"><?php _e( 'Optional. The old hard-coded key has been removed.', 'guesty-properties-sync' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="guesty_webhook_secret"><?php _e( 'Webhook Secret', 'guesty-properties-sync' ); ?></label></th>
                <td><input type="text" id="guesty_webhook_secret" name="guesty_webhook_secret" value="<?php echo esc_attr( $webhook_secret ); ?>" class="regular-text code" />
                <p class="description"><?php _e( 'Webhook endpoint:', 'guesty-properties-sync' ); ?> <code><?php echo esc_html( $webhook_url ); ?></code><br><?php _e( 'Guesty must send an X-Guesty-Signature header containing the HMAC-SHA256 signature of the raw request body using this secret.', 'guesty-properties-sync' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><?php _e( 'Debug Logging', 'guesty-properties-sync' ); ?></th>
                <td><label><input type="checkbox" name="guesty_debug_logging" value="1" <?php checked( $debug_logging, '1' ); ?> /> <?php _e( 'Log Guesty integration errors when WP_DEBUG is enabled', 'guesty-properties-sync' ); ?></label></td>
            </tr>
        </table>

        <h2><?php _e( 'API Configuration Instructions', 'guesty-properties-sync' ); ?></h2>
        <div class="card">
            <p><?php _e( 'To set up the Guesty API integration, follow these steps:', 'guesty-properties-sync' ); ?></p>
            <ol>
                <li><?php _e( 'Log in to your Guesty account', 'guesty-properties-sync' ); ?></li>
                <li><?php _e( 'Navigate to Settings > API', 'guesty-properties-sync' ); ?></li>
                <li><?php _e( 'Create a new API client and note the Client ID and Client Secret', 'guesty-properties-sync' ); ?></li>
                <li><?php _e( 'Enter these credentials in the fields above:', 'guesty-properties-sync' ); ?>
                    <ul>
                        <li><?php _e( 'API Key: Enter your Guesty Client ID', 'guesty-properties-sync' ); ?></li>
                        <li><?php _e( 'API Secret: Enter your Guesty Client Secret', 'guesty-properties-sync' ); ?></li>
                    </ul>
                </li>
                <li><?php _e( 'Click "Save Settings" to store your credentials', 'guesty-properties-sync' ); ?></li>
            </ol>
        </div>

        <h2><?php _e( 'Stripe API keys', 'guesty-properties-sync' ); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="stripe_publishable_key"><?php _e( 'Publishable key', 'guesty-properties-sync' ); ?></label>
                </th>
                <td>
                    <input type="text" id="stripe_publishable_key" name="stripe_publishable_key" value="<?php echo esc_attr( $stripe_key ); ?>" class="regular-text" />
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="stripe_secret_key"><?php _e( 'Secret key', 'guesty-properties-sync' ); ?></label>
                </th>
                <td>
                    <input type="password" id="stripe_secret_key" name="stripe_secret_key" value="<?php echo esc_attr( $stripe_secret ); ?>" class="regular-text" />
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <input type="submit" name="guesty_settings_submit" class="button-primary" value="<?php _e( 'Save Settings', 'guesty-properties-sync' ); ?>" />
        </p>
    </form>
</div> 