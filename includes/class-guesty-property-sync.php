<?php
/**
 * The main plugin class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 */

class Guesty_Property_Sync {

    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Register custom post type
        add_action( 'init', array( $this, 'register_custom_post_type' ) );
        
        // Add admin menu
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        
        // Add cron schedule for auto-sync
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
        
        // Schedule cron job for auto-sync
        add_action( 'guesty_property_sync_cron', array( $this, 'auto_sync_properties' ) );

        add_action('rest_api_init', [$this, 'register_routes']);

    }

    /**
     * Run the plugin
     */
    public function run() {
        // Initialize plugin components
        
        // Load admin metaboxes for property post type
        if ( is_admin() ) {
            require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'admin/class-property-metabox.php';
        }
        
        // Load Elementor integration if Elementor is active
        if ( $this->is_elementor_active() ) {
            $this->load_elementor_integration();
        }
    }
    
    /**
     * Check if Elementor is active
     * 
     * @return bool True if Elementor is active
     */
    private function is_elementor_active() {
        return did_action( 'elementor/loaded' ) || defined( 'ELEMENTOR_VERSION' );
    }
    
    /**
     * Load Elementor integration
     */
    private function load_elementor_integration() {
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/class-elementor-integration.php';
        new Guesty_Elementor_Integration();
    }
    
    /**
     * Plugin activation hook
     */
    public function activate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Schedule auto-sync event if settings are configured
        $this->schedule_sync_event();
    }
    
    /**
     * Plugin deactivation hook
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Unschedule auto-sync event
        wp_clear_scheduled_hook( 'guesty_property_sync_cron' );
    }
    
    /**
     * Schedule sync event based on settings
     */
    public function schedule_sync_event() {
        // Unschedule any existing events
        wp_clear_scheduled_hook( 'guesty_property_sync_cron' );
        
        // Get sync interval from settings
        $sync_interval = get_option( 'guesty_sync_interval', 'daily' );
        
        // Schedule new event
        if ( ! empty( $sync_interval ) ) {
            wp_schedule_event( time(), $sync_interval, 'guesty_property_sync_cron' );
        }
    }
    
    /**
     * Add custom cron schedule
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_schedule( $schedules ) {
        // Add custom intervals if needed
        return $schedules;
    }
    
    /**
     * Auto sync properties via cron
     */
    public function auto_sync_properties() {
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/class-guesty-property-sync-manager.php';
        
        $sync_manager = new Guesty_Property_Sync_Manager();
        $sync_limit = get_option( 'guesty_auto_sync_limit', 50 );
        
        $results = $sync_manager->sync_properties( $sync_limit );
        
        if ( get_option( 'guesty_debug_logging', '0' ) === '1' ) {
            if ( isset( $results['success'] ) && $results['success'] ) {
                error_log( 'Guesty auto-sync completed: ' . $results['message'] );
            } else {
                error_log( 'Guesty auto-sync failed: ' . ( isset( $results['message'] ) ? $results['message'] : 'Unknown error' ) );
            }
        }
    }

    /**
     * Register REST endpoint
     */
    public function register_routes() {
        register_rest_route('guesty/v1', '/webhook', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => [$this, 'verify_webhook_signature']
        ]);
    }
    /**
     * Authenticate Guesty webhooks using HMAC-SHA256 of the unmodified body.
     * The configured secret is never accepted in the URL or as a plain header.
     */
    public function verify_webhook_signature( WP_REST_Request $request ) {
        $secret    = trim( (string) get_option( 'guesty_webhook_secret', '' ) );
        $signature = trim( (string) $request->get_header( 'x-guesty-signature' ) );
        $raw_body  = (string) $request->get_body();

        if ( 0 === stripos( $signature, 'sha256=' ) ) {
            $signature = substr( $signature, 7 );
        }

        $valid = false;
        if ( '' !== $secret && '' !== $signature && '' !== $raw_body ) {
            $expected_hex = hash_hmac( 'sha256', $raw_body, $secret );
            if ( 64 === strlen( $signature ) && ctype_xdigit( $signature ) ) {
                $valid = hash_equals( $expected_hex, strtolower( $signature ) );
            } else {
                $decoded = base64_decode( $signature, true );
                if ( false !== $decoded ) {
                    $valid = hash_equals( hash_hmac( 'sha256', $raw_body, $secret, true ), $decoded );
                }
            }
        }

        if ( ! $valid ) {
            $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
            error_log( sprintf( '[Guesty Webhook] Rejected invalid HMAC signature from %s.', $remote ) );
            if ( class_exists( 'Guesty_Activity_Log' ) ) {
                Guesty_Activity_Log::add( 'webhook', '', 'Rejected webhook with invalid HMAC signature.', 'error' );
            }
            return new WP_Error( 'guesty_webhook_unauthorized', 'Invalid webhook signature.', array( 'status' => 401 ) );
        }

        return true;
    }

    public function handle_webhook( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        if ( ! is_array( $data ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'Invalid JSON payload.' ), 400 );
        }

        $event = sanitize_text_field( $data['event'] ?? ( $data['eventType'] ?? ( $data['type'] ?? '' ) ) );
        if ( '' === $event && $this->extract_webhook_listing_id( $data ) && ( ! empty( $data['dateRange'] ) || ! empty( $data['dates'] ) ) ) {
            // Some calendar.updated.v2 deliveries expose the listing and dateRange
            // but omit a conventional event field in the payload body.
            $event = 'calendar.updated.v2';
        }
        if ( '' === $event ) {
            return new WP_REST_Response( array( 'success' => true, 'ignored' => true ), 200 );
        }

        $hash = md5( wp_json_encode( $data ) );
        $dedupe_key = 'guesty_webhook_' . $hash;
        if ( get_transient( $dedupe_key ) ) {
            return new WP_REST_Response( array( 'success' => true, 'duplicate' => true ), 200 );
        }
        set_transient( $dedupe_key, 1, 5 * MINUTE_IN_SECONDS );

        $listing = isset( $data['listing'] ) && is_array( $data['listing'] ) ? $data['listing'] : array();
        $listing_id = $this->extract_webhook_listing_id( $data );
        $event_lc = strtolower( $event );

        try {
            if ( $listing_id ) {
                Guesty_Transient_Cache::invalidate_listing( $listing_id );
            }

            if ( false !== strpos( $event_lc, 'listing.' ) && false === strpos( $event_lc, 'calendar' ) ) {
                require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/class-guesty-property-sync-manager.php';
                $sync_manager = new Guesty_Property_Sync_Manager();

                if ( false !== strpos( $event_lc, 'removed' ) || false !== strpos( $event_lc, 'deleted' ) ) {
                    if ( $listing_id ) {
                        $sync_manager->delete_property( $listing_id );
                    }
                } elseif ( ! empty( $listing ) ) {
                    $sync_manager->process_property( $listing );
                } elseif ( $listing_id ) {
                    // Lightweight fallback: fetch only this listing when the webhook
                    // contains an ID but not a full listing object.
                    $api = new Guesty_API();
                    $remote = $api->request( 'listings/' . rawurlencode( $listing_id ), 'GET', array(), array( 'timeout' => 20, 'retries' => 1 ) );
                    if ( ! is_wp_error( $remote ) && is_array( $remote ) ) {
                        $sync_manager->process_property( $remote );
                    }
                }
            }

            if ( false !== strpos( $event_lc, 'calendar' ) || false !== strpos( $event_lc, 'reservation' ) ) {
                $dates = $this->extract_webhook_dates( $data );
                if ( $listing_id && ! empty( $GLOBALS['guesty_optimization'] ) && $GLOBALS['guesty_optimization'] instanceof Guesty_Optimization ) {
                    $GLOBALS['guesty_optimization']->invalidate_booking( $listing_id, $dates['start'], $dates['end'] );
                }
            }

            if ( class_exists( 'Guesty_Activity_Log' ) ) {
                Guesty_Activity_Log::add( 'webhook', $listing_id, sprintf( 'Processed %s webhook.', $event ), 'success' );
            }

            return new WP_REST_Response(
                array(
                    'success'    => true,
                    'event'      => $event,
                    'listing_id' => $listing_id,
                ),
                200
            );
        } catch ( Exception $e ) {
            if ( get_option( 'guesty_debug_logging', '0' ) === '1' ) {
                error_log( '[Guesty Webhook] ' . $e->getMessage() );
            }
            if ( class_exists( 'Guesty_Activity_Log' ) ) {
                Guesty_Activity_Log::add(
                    'webhook',
                    $listing_id,
                    sprintf( 'Failed to process %s webhook: %s', $event, $e->getMessage() ),
                    'error'
                );
            }
            return new WP_REST_Response( array( 'success' => false, 'error' => $e->getMessage() ), 500 );
        }
    }

    // public function handle_webhook( WP_REST_Request $request ) {

    //     $data = $request->get_params();
    //     $event = isset($data['event']) ? $data['event'] : null;
    //     $subscribed_events = ['listing.new', 'listing.removed', 'listing.updated'];

    //     // error_log('Parsed Data: ' . print_r($data, true));

    //     if ( $event && in_array($event, $subscribed_events) ) {
    //         $this->auto_sync_properties();
    //     } else {
    //         error_log('Ignored event: ' . $event);
    //     }

    //     // ✅ Always respond (important for webhook providers)
    //     return new WP_REST_Response([
    //         'success' => true
    //     ], 200);
    
    // }

    private function extract_webhook_listing_id( array $data ) {
        $paths = array(
            array( 'listingId' ),
            array( 'listing', '_id' ),
            array( 'listing', 'id' ),
            array( 'reservation', 'listingId' ),
            array( 'reservation', 'listing', '_id' ),
            array( 'calendar', 'listingId' ),
            array( 'data', 'listingId' ),
            array( 'data', 'listing', '_id' ),
        );
        foreach ( $paths as $path ) {
            $value = $data;
            foreach ( $path as $key ) {
                if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                    $value = '';
                    break;
                }
                $value = $value[ $key ];
            }
            if ( is_scalar( $value ) && '' !== (string) $value ) {
                return sanitize_text_field( (string) $value );
            }
        }
        return '';
    }

    private function extract_webhook_dates( array $data ) {
        $start = '';
        $end   = '';
        $start_keys = array( 'checkInDateLocalized', 'checkIn', 'startDate', 'from' );
        $end_keys   = array( 'checkOutDateLocalized', 'checkOut', 'endDate', 'to' );
        $containers = array( $data, $data['dateRange'] ?? array(), $data['reservation'] ?? array(), $data['calendar'] ?? array(), $data['data'] ?? array() );

        foreach ( $containers as $container ) {
            if ( ! is_array( $container ) ) {
                continue;
            }
            foreach ( $start_keys as $key ) {
                if ( ! $start && ! empty( $container[ $key ] ) ) {
                    $start = substr( sanitize_text_field( $container[ $key ] ), 0, 10 );
                }
            }
            foreach ( $end_keys as $key ) {
                if ( ! $end && ! empty( $container[ $key ] ) ) {
                    $end = substr( sanitize_text_field( $container[ $key ] ), 0, 10 );
                }
            }
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
            $start = gmdate( 'Y-m-d' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) || $end <= $start ) {
            $end = gmdate( 'Y-m-d', strtotime( $start . ' +365 days' ) );
        }
        return array( 'start' => $start, 'end' => $end );
    }

    /**
     * Register the custom post type for properties
     */
    public function register_custom_post_type() {
        $labels = array(
            'name'                  => _x( 'Properties', 'Post type general name', 'guesty-properties-sync' ),
            'singular_name'         => _x( 'Property', 'Post type singular name', 'guesty-properties-sync' ),
            'menu_name'             => _x( 'Properties', 'Admin Menu text', 'guesty-properties-sync' ),
            'name_admin_bar'        => _x( 'Property', 'Add New on Toolbar', 'guesty-properties-sync' ),
            'add_new'               => __( 'Add New', 'guesty-properties-sync' ),
            'add_new_item'          => __( 'Add New Property', 'guesty-properties-sync' ),
            'new_item'              => __( 'New Property', 'guesty-properties-sync' ),
            'edit_item'             => __( 'Edit Property', 'guesty-properties-sync' ),
            'view_item'             => __( 'View Property', 'guesty-properties-sync' ),
            'all_items'             => __( 'All Properties', 'guesty-properties-sync' ),
            'search_items'          => __( 'Search Properties', 'guesty-properties-sync' ),
            'parent_item_colon'     => __( 'Parent Properties:', 'guesty-properties-sync' ),
            'not_found'             => __( 'No properties found.', 'guesty-properties-sync' ),
            'not_found_in_trash'    => __( 'No properties found in Trash.', 'guesty-properties-sync' ),
            'featured_image'        => _x( 'Property Cover Image', 'Overrides the "Featured Image" phrase', 'guesty-properties-sync' ),
            'set_featured_image'    => _x( 'Set cover image', 'Overrides the "Set featured image" phrase', 'guesty-properties-sync' ),
            'remove_featured_image' => _x( 'Remove cover image', 'Overrides the "Remove featured image" phrase', 'guesty-properties-sync' ),
            'use_featured_image'    => _x( 'Use as cover image', 'Overrides the "Use as featured image" phrase', 'guesty-properties-sync' ),
            'archives'              => _x( 'Property archives', 'The post type archive label used in nav menus', 'guesty-properties-sync' ),
            'insert_into_item'      => _x( 'Insert into property', 'Overrides the "Insert into post"/"Insert into page" phrase', 'guesty-properties-sync' ),
            'uploaded_to_this_item' => _x( 'Uploaded to this property', 'Overrides the "Uploaded to this post"/"Uploaded to this page" phrase', 'guesty-properties-sync' ),
            'filter_items_list'     => _x( 'Filter properties list', 'Screen reader text for the filter links heading on the post type listing screen', 'guesty-properties-sync' ),
            'items_list_navigation' => _x( 'Properties list navigation', 'Screen reader text for the pagination heading on the post type listing screen', 'guesty-properties-sync' ),
            'items_list'            => _x( 'Properties list', 'Screen reader text for the items list heading on the post type listing screen', 'guesty-properties-sync' ),
        );
     
        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'property' ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-building',
            'supports'           => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'custom-fields', 'comments' ),
            'show_in_rest'       => true, // Enable Gutenberg editor and REST API
        );
     
        register_post_type( 'property', $args );
        
        // Register custom taxonomy for property categories
        $taxonomy_labels = array(
            'name'              => _x( 'Property Categories', 'taxonomy general name', 'guesty-properties-sync' ),
            'singular_name'     => _x( 'Property Category', 'taxonomy singular name', 'guesty-properties-sync' ),
            'search_items'      => __( 'Search Property Categories', 'guesty-properties-sync' ),
            'all_items'         => __( 'All Property Categories', 'guesty-properties-sync' ),
            'parent_item'       => __( 'Parent Property Category', 'guesty-properties-sync' ),
            'parent_item_colon' => __( 'Parent Property Category:', 'guesty-properties-sync' ),
            'edit_item'         => __( 'Edit Property Category', 'guesty-properties-sync' ),
            'update_item'       => __( 'Update Property Category', 'guesty-properties-sync' ),
            'add_new_item'      => __( 'Add New Property Category', 'guesty-properties-sync' ),
            'new_item_name'     => __( 'New Property Category Name', 'guesty-properties-sync' ),
            'menu_name'         => __( 'Categories', 'guesty-properties-sync' ),
        );
    
        $taxonomy_args = array(
            'labels'            => $taxonomy_labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'property-category' ),
            'show_in_rest'      => true,
        );
    
        register_taxonomy( 'property_category', array( 'property' ), $taxonomy_args );
        
        // Register additional taxonomy for property amenities
        $amenity_labels = array(
            'name'              => _x( 'Property Amenities', 'taxonomy general name', 'guesty-properties-sync' ),
            'singular_name'     => _x( 'Property Amenity', 'taxonomy singular name', 'guesty-properties-sync' ),
            'search_items'      => __( 'Search Property Amenities', 'guesty-properties-sync' ),
            'all_items'         => __( 'All Property Amenities', 'guesty-properties-sync' ),
            'parent_item'       => __( 'Parent Property Amenity', 'guesty-properties-sync' ),
            'parent_item_colon' => __( 'Parent Property Amenity:', 'guesty-properties-sync' ),
            'edit_item'         => __( 'Edit Property Amenity', 'guesty-properties-sync' ),
            'update_item'       => __( 'Update Property Amenity', 'guesty-properties-sync' ),
            'add_new_item'      => __( 'Add New Property Amenity', 'guesty-properties-sync' ),
            'new_item_name'     => __( 'New Property Amenity Name', 'guesty-properties-sync' ),
            'menu_name'         => __( 'Amenities', 'guesty-properties-sync' ),
        );
        
        $amenity_args = array(
            'labels'            => $amenity_labels,
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => false,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'property-amenity' ),
            'show_in_rest'      => true,
        );
        
        register_taxonomy( 'property_amenity', array( 'property' ), $amenity_args );

        // Register additional taxonomy for property tags
        $tag_labels = array(
            'name'                       => _x( 'Property Tags', 'taxonomy general name', 'guesty-properties-sync' ),
            'singular_name'              => _x( 'Property Tag', 'taxonomy singular name', 'guesty-properties-sync' ),
            'search_items'               => __( 'Search Property Tags', 'guesty-properties-sync' ),
            'popular_items'              => __( 'Popular Property Tags', 'guesty-properties-sync' ),
            'all_items'                  => __( 'All Property Tags', 'guesty-properties-sync' ),
            'edit_item'                  => __( 'Edit Property Tag', 'guesty-properties-sync' ),
            'update_item'                => __( 'Update Property Tag', 'guesty-properties-sync' ),
            'add_new_item'               => __( 'Add New Property Tag', 'guesty-properties-sync' ),
            'new_item_name'              => __( 'New Property Tag Name', 'guesty-properties-sync' ),
            'separate_items_with_commas' => __( 'Separate tags with commas', 'guesty-properties-sync' ),
            'add_or_remove_items'        => __( 'Add or remove tags', 'guesty-properties-sync' ),
            'choose_from_most_used'      => __( 'Choose from the most used tags', 'guesty-properties-sync' ),
            'menu_name'                  => __( 'Tags', 'guesty-properties-sync' ),
        );

        $tag_args = array(
            'labels'            => $tag_labels,
            'hierarchical'      => false, // behaves like post tags
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true, // shows column in admin table
            'update_count_callback' => '_update_post_term_count',
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'property-tag' ),
            'show_in_rest'      => true, // Gutenberg + REST API
        );

        register_taxonomy( 'property_tag', array( 'property' ), $tag_args );
        
        // Register meta fields for properties
        $this->register_property_meta_fields();
    }
    
    /**
     * Register meta fields for properties to expose them in the REST API and admin
     */
    private function register_property_meta_fields() {
        // Register Guesty ID field
        register_post_meta( 'property', 'guesty_id', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property ID from Guesty',
        ));
        
        // Basic property information
        register_post_meta( 'property', 'property_bedrooms', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'integer',
            'description' => 'Number of bedrooms',
        ));
        
        register_post_meta( 'property', 'property_bathrooms', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'number',
            'description' => 'Number of bathrooms',
        ));
        
        register_post_meta( 'property', 'property_accommodates', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'integer',
            'description' => 'Number of guests accommodated',
        ));
        
        register_post_meta( 'property', 'property_base_price', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'number',
            'description' => 'Base price per night',
        ));
        
        register_post_meta( 'property', 'property_currency', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Currency for pricing',
        ));
        
        // Address information
        register_post_meta( 'property', 'property_address', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string', // serialized array
            'description' => 'Raw property address array (serialized)',
        ));

        register_post_meta( 'property', 'property_street', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property street',
        ));

        register_post_meta( 'property', 'property_city', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property city',
        ));

        register_post_meta( 'property', 'property_state', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property state/region',
        ));

        register_post_meta( 'property', 'property_zipcode', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property zipcode/postal code',
        ));

        register_post_meta( 'property', 'property_country', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property country',
        ));

        register_post_meta( 'property', 'property_latitude', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'number',
            'description' => 'Property latitude',
        ));

        register_post_meta( 'property', 'property_longitude', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'number',
            'description' => 'Property longitude',
        ));

        // FIX: should be string, not number
        register_post_meta( 'property', 'property_latlang', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property latitude and longitude (lat, lng)',
        ));

        register_post_meta( 'property', 'property_apt', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property apartment/apt',
        ));

        register_post_meta( 'property', 'property_apartment', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property apartment (alternate field)',
        ));

        register_post_meta( 'property', 'property_unit', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property unit',
        ));

        register_post_meta( 'property', 'property_floor', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property floor',
        ));

        register_post_meta( 'property', 'property_county', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property county',
        ));

        register_post_meta( 'property', 'property_neighborhood', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property neighborhood',
        ));

        register_post_meta( 'property', 'property_building_name', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property building name',
        ));

        register_post_meta( 'property', 'property_full_address', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Full property address',
        ));
        
        // Other property details
        register_post_meta( 'property', 'property_type', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property type',
        ));
        
        register_post_meta( 'property', 'property_status', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property status',
        ));
        
        register_post_meta( 'property', 'property_main_image', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Main property image URL',
        ));
        
        register_post_meta( 'property', 'property_360_video_link', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property Custom Field - 360 video link',
        ));
        
        register_post_meta( 'property', 'property_floorPlan_img', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property Floor Plan Image',
        ));
        
        register_post_meta( 'property', 'property_description_neighborhood', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property Neighborhood',
        ));
        
        // Listing identifiers
        register_post_meta( 'property', 'property_listing_id', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property listing ID',
        ));
        
        register_post_meta( 'property', 'property_account_id', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'description' => 'Property account ID',
        ));
    }

    /**
     * Add admin menu and sub-menu items
     */
    public function add_admin_menu() {
        // Add main menu
        add_menu_page(
            __( 'Guesty', 'guesty-properties-sync' ),
            __( 'Guesty', 'guesty-properties-sync' ),
            'manage_options',
            'guesty-properties-sync',
            array( $this, 'display_dashboard_page' ),
            'dashicons-admin-multisite',
            30
        );
        
        // Add sub-menu items
        add_submenu_page(
            'guesty-properties-sync',
            __( 'Dashboard', 'guesty-properties-sync' ),
            __( 'Dashboard', 'guesty-properties-sync' ),
            'manage_options',
            'guesty-properties-sync',
            array( $this, 'display_dashboard_page' )
        );
        
        add_submenu_page(
            'guesty-properties-sync',
            __( 'Settings', 'guesty-properties-sync' ),
            __( 'Settings', 'guesty-properties-sync' ),
            'manage_options',
            'guesty-properties-sync-settings',
            array( $this, 'display_settings_page' )
        );
        
        add_submenu_page(
            'guesty-properties-sync',
            __( 'Sync Properties', 'guesty-properties-sync' ),
            __( 'Sync Properties', 'guesty-properties-sync' ),
            'manage_options',
            'guesty-properties-sync-sync',
            array( $this, 'display_sync_page' )
        );

        add_submenu_page(
            'guesty-properties-sync',
            __( 'Activity Log', 'guesty-properties-sync' ),
            __( 'Activity Log', 'guesty-properties-sync' ),
            'manage_options',
            'guesty-properties-sync-log',
            array( $this, 'display_activity_log_page' )
        );
    }

    /**
     * Display the dashboard page
     */
    public function display_dashboard_page() {
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'admin/dashboard.php';
    }

    /**
     * Display the settings page
     */
    public function display_settings_page() {
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'admin/settings.php';
    }

    /**
     * Display the sync properties page
     */
    public function display_sync_page() {
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'admin/sync.php';
    }


    public function display_activity_log_page() {
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'admin/activity-log.php';
    }
} 