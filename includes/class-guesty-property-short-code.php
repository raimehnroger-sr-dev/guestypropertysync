<?php
/**
 * Property Sync Manager Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes
 */

class Guesty_Property_Short_Code {

    private $api;
    private $stripe_key;
    private $stripe_secret;
    private $calendar_service;
    private $search_service;
    private $quote_service;
    private $availability_cache;
    private $pricing_cache;
    private $available_pricing_data = array();

    /**
     * Initialize the plugin
     */
    public function __construct() {
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/api/class-guesty-api.php';
        $this->api              = new Guesty_API();
        $this->stripe_key       = get_option( 'stripe_publishable_key', '' );
        $this->stripe_secret    = get_option( 'stripe_secret_key', '' );
        $this->availability_cache = new Guesty_Optimized_Availability_Cache();
        $this->pricing_cache      = new Guesty_Optimized_Pricing_Cache();
        $this->calendar_service   = new Guesty_Optimized_Calendar_Service( $this->api, $this->availability_cache );
        $this->search_service     = new Guesty_Optimized_Search_Service( $this->api, $this->availability_cache, $this->calendar_service );
        $this->quote_service      = new Guesty_Optimized_Quote_Service( $this->api, $this->pricing_cache );

        // Add Form Short Code
        add_shortcode('property_search_filter', array( $this, 'property_search_form') );
        add_shortcode('property_search_results', array( $this, 'property_search_results') );
        add_shortcode('property_amenities', array( $this, 'property_amenities') );
        add_shortcode('property_featured_amenities', array( $this, 'property_featured_amenities') );
        add_shortcode('property_single_amenities', array( $this, 'property_single_amenities') );
        add_shortcode('property_gallery', array( $this, 'property_gallery') );
        add_shortcode('property_calendar', array( $this, 'property_calendar') );
        add_shortcode('favorites_list', array( $this, 'property_favorites'));
        add_shortcode('favorites_single', array( $this, 'guesty_single_favorite_shortcode'));

        add_action('wp_enqueue_scripts', array( $this, 'guesty_enqueue_search_script') );

        add_action('wp_ajax_nopriv_guesty_load_calendar', array( $this, 'guesty_load_calendar') );
        add_action('wp_ajax_guesty_load_calendar', array( $this, 'guesty_load_calendar') ); 

        add_action('wp_ajax_guesty_load_more', array( $this, 'guesty_load_more') );
        add_action('wp_ajax_nopriv_guesty_load_more', array( $this, 'guesty_load_more') );

        add_action('wp_ajax_guesty_booking_data', array( $this, 'guesty_booking_data') );
        add_action('wp_ajax_nopriv_guesty_booking_data', array( $this, 'guesty_booking_data') );

        add_action('wp_ajax_guesty_booking_reservation', array( $this, 'guesty_booking_reservation') );
        add_action('wp_ajax_nopriv_guesty_booking_reservation', array( $this, 'guesty_booking_reservation') );

        add_action('wp_ajax_guesty_create_guest', array( $this, 'guesty_create_guest') );
        add_action('wp_ajax_nopriv_guesty_create_guest', array( $this, 'guesty_create_guest') );

        add_action('wp_ajax_guesty_create_payment_intent', array( $this, 'guesty_create_payment_intent') );
        add_action('wp_ajax_nopriv_guesty_create_payment_intent', array( $this, 'guesty_create_payment_intent') );

        add_action('wp_ajax_guesty_create_setup_intent', array( $this, 'guesty_create_setup_intent') );
        add_action('wp_ajax_nopriv_guesty_create_setup_intent', array( $this, 'guesty_create_setup_intent') );

        add_action('wp_ajax_guesty_create_guesty_payment', array( $this, 'guesty_create_guesty_payment') );
        add_action('wp_ajax_nopriv_guesty_create_guesty_payment', array( $this, 'guesty_create_guesty_payment') );

        add_action('wp_ajax_guesty_check_availability', array( $this, 'guesty_check_availability') );
        add_action('wp_ajax_nopriv_guesty_check_availability', array( $this, 'guesty_check_availability') );

        add_action('wp_ajax_guesty_additional_fees_get', array( $this, 'guesty_additional_fees_get') );
        add_action('wp_ajax_nopriv_guesty_additional_fees_get', array( $this, 'guesty_additional_fees_get') );

        add_action('wp_ajax_guesty_additional_fees_post', array( $this, 'guesty_additional_fees_post') );
        add_action('wp_ajax_nopriv_guesty_additional_fees_post', array( $this, 'guesty_additional_fees_post') );

        add_action('wp_ajax_payment_provider', array( $this, 'payment_provider') );
        add_action('wp_ajax_nopriv_payment_provider', array( $this, 'payment_provider') );

        add_action('wp_ajax_guesty_payment_method', array( $this, 'guesty_payment_method') );
        add_action('wp_ajax_nopriv_guesty_payment_method', array( $this, 'guesty_payment_method') );

        add_action('wp_ajax_get_favorite_posts', array( $this, 'get_favorite_posts') );
        add_action('wp_ajax_nopriv_get_favorite_posts', array( $this, 'get_favorite_posts') );

        add_action('wp_ajax_send_favorites_email', array( $this, 'send_favorites_email') );
        add_action('wp_ajax_nopriv_send_favorites_email', array( $this, 'send_favorites_email') );

        add_action('wp', array( $this, 'floorplan') );
        add_action('wp', array( $this, 'threesixty') );

        add_filter('posts_search', function ($search, \WP_Query $query) {
            global $wpdb;

            if (!is_admin() && $query->get('s') && $query->get('post_type') === 'property') {
                $search = '';
                $search_terms = array_filter(explode(' ', $query->get('s')));

                foreach ($search_terms as $term) {
                    $term = esc_sql($wpdb->esc_like($term));
                    $search .= " AND ({$wpdb->posts}.post_title LIKE '%{$term}%')";
                }
            }

            return $search;
        }, 10, 2);
    }

    /**
     * Register CSS and JS file
     */
    function guesty_enqueue_search_script() {
        if ( ! $this->should_enqueue_frontend_assets() ) {
            return;
        }

        $is_booking = $this->is_booking_page();
        $is_property = is_singular( 'property' );
        $has_search = $this->page_has_any_shortcode( array( 'property_search_filter', 'property_search_results', 'favorites_list' ) )
            || is_page( array( 'search-results', 'favorites' ) );
        $has_calendar = $is_property || $is_booking || $this->page_has_any_shortcode( array( 'property_calendar' ) );

        if ( $has_search ) {
            wp_enqueue_style( 'flatpickr-style', 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css', array(), '4.6.13' );
            wp_enqueue_script( 'flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js', array(), '4.6.13', true );
            wp_enqueue_style( 'nouislider-css', 'https://cdn.jsdelivr.net/npm/nouislider@15.7.0/dist/nouislider.min.css', array(), '15.7.0' );
            wp_enqueue_script( 'nouislider-js', 'https://cdn.jsdelivr.net/npm/nouislider@15.7.0/dist/nouislider.min.js', array(), '15.7.0', true );
        }

        $google_maps_key = trim( (string) get_option( 'guesty_google_maps_api_key', '' ) );
        if ( $has_search && $google_maps_key ) {
            wp_enqueue_script( 'google-maps', add_query_arg( 'key', $google_maps_key, 'https://maps.googleapis.com/maps/api/js' ), array(), null, true );
        }

        // The search script also controls favourites on single-property pages.
        wp_enqueue_script(
            'guesty-search',
            plugin_dir_url( __FILE__ ) . 'js/guesty-search.js',
            array( 'jquery' ),
            GUESTY_PROPERTY_SYNC_VERSION,
            true
        );
        wp_enqueue_style(
            'guesty-search-style',
            plugin_dir_url( __FILE__ ) . 'css/guesty-search.css',
            array(),
            GUESTY_PROPERTY_SYNC_VERSION
        );
        wp_localize_script(
            'guesty-search',
            'guesty_ajax_search',
            array(
                'ajax_url'         => admin_url( 'admin-ajax.php' ),
                'nonce'            => wp_create_nonce( 'guesty_nonce' ),
                'default_currency' => get_option( 'guesty_default_currency', 'GBP' ),
                'stored_base_price' => $is_property ? (float) get_post_meta( get_queried_object_id(), 'property_base_price', true ) : 0,
                'stored_currency'   => $is_property ? (string) get_post_meta( get_queried_object_id(), 'property_currency', true ) : '',
            )
        );

        if ( $has_search ) {
            // Search cards use stored post_meta pricing. Exact Guesty quotes are
            // intentionally not hydrated on page load.
            wp_enqueue_style(
                'guesty-optimized-style',
                GUESTY_PROPERTY_SYNC_PLUGIN_URL . 'assets/css/guesty-optimized.css',
                array( 'guesty-search-style' ),
                GUESTY_PROPERTY_SYNC_VERSION
            );
        }

        if ( $has_calendar ) {
            wp_enqueue_script(
                'guesty-calendar',
                plugin_dir_url( __FILE__ ) . 'js/guesty-calendar.js',
                array( 'jquery' ),
                GUESTY_PROPERTY_SYNC_VERSION,
                true
            );
            wp_enqueue_style(
                'guesty-calendar-style',
                plugin_dir_url( __FILE__ ) . 'css/guesty-calendar.css',
                array(),
                GUESTY_PROPERTY_SYNC_VERSION
            );
            wp_localize_script(
                'guesty-calendar',
                'guesty_ajax',
                array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'         => wp_create_nonce( 'guesty_nonce' ),
                    'booking_url'   => get_option( 'guesty_booking_page_url', site_url( '/booking/' ) ),
                    'contact_phone' => get_option( 'guesty_contact_phone', '' ),
                    'contact_email' => get_option( 'guesty_contact_email', get_option( 'admin_email', '' ) ),
                )
            );
        }

        if ( $is_booking ) {
            $booking_listing_id = isset( $_GET['listing_id'] ) ? sanitize_text_field( wp_unslash( $_GET['listing_id'] ) ) : '';
            $booking_property_id = $booking_listing_id ? $this->find_property_id_by_guesty_id( $booking_listing_id ) : 0;
            $cancellation_policy = $booking_property_id ? (string) get_post_meta( $booking_property_id, 'property_cancellation_policy', true ) : '';
            $house_rules = $booking_property_id ? (string) get_post_meta( $booking_property_id, 'property_house_rules', true ) : '';

            wp_enqueue_script( 'stripe', 'https://js.stripe.com/v3/', array(), null, false );
            wp_enqueue_script( 'guesty-tokenization', 'https://pay.guesty.com/tokenization/v1/init.js', array(), null, true );
            wp_enqueue_script( 'normalize-country', plugin_dir_url( __FILE__ ) . 'js/normalizeCountry.js', array(), GUESTY_PROPERTY_SYNC_VERSION, true );
            wp_enqueue_script(
                'guesty-booking',
                plugin_dir_url( __FILE__ ) . 'js/guesty-booking.js',
                array( 'jquery', 'normalize-country' ),
                GUESTY_PROPERTY_SYNC_VERSION,
                true
            );
            wp_enqueue_style(
                'guesty-booking-style',
                plugin_dir_url( __FILE__ ) . 'css/guesty-booking.css',
                array(),
                GUESTY_PROPERTY_SYNC_VERSION
            );
            wp_localize_script(
                'guesty-booking',
                'guesty_ajax_booking',
                array(
                    'ajax_url'   => admin_url( 'admin-ajax.php' ),
                    'nonce'      => wp_create_nonce( 'guesty_nonce' ),
                    'stripe_key'     => $this->stripe_key,
                    'booking_url'          => get_option( 'guesty_booking_page_url', site_url( '/booking/' ) ),
                    'contact_phone'        => get_option( 'guesty_contact_phone', '' ),
                    'contact_email'        => get_option( 'guesty_contact_email', get_option( 'admin_email', '' ) ),
                    'cancellation_policy'  => $cancellation_policy,
                    'house_rules'          => $house_rules,
                    'terms_url'            => get_option( 'guesty_terms_url', get_privacy_policy_url() ),
                    'default_currency'     => get_option( 'guesty_default_currency', 'GBP' ),
                    'placeholder_image'    => GUESTY_PROPERTY_SYNC_PLUGIN_URL . 'assets/images/upsell-placeholder.jpg',
                    'copy_icon'            => GUESTY_PROPERTY_SYNC_PLUGIN_URL . 'assets/images/copy-icon.svg',
                )
            );
        }

        if ( $is_property ) {
            wp_enqueue_style(
                'guesty-single-style',
                plugin_dir_url( __FILE__ ) . 'css/guesty-single.css',
                array(),
                GUESTY_PROPERTY_SYNC_VERSION
            );
        }

        if ( $is_property || $has_search ) {
            wp_enqueue_script(
                'guesty-slick-script',
                plugin_dir_url( __FILE__ ) . 'library/slick/slick.min.js',
                array( 'jquery' ),
                '1.8.1',
                true
            );
            wp_enqueue_style(
                'guesty-slick-style',
                plugin_dir_url( __FILE__ ) . 'library/slick/slick.css',
                array(),
                '1.8.1'
            );
            wp_enqueue_style(
                'guesty-slick-theme',
                plugin_dir_url( __FILE__ ) . 'library/slick/slick-theme.css',
                array( 'guesty-slick-style' ),
                '1.8.1'
            );
        }

        if ( $has_search ) {
            wp_enqueue_script( 'recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );
        }
    }
    

    private function should_enqueue_frontend_assets() {
        $relevant = is_singular( 'property' )
            || $this->is_booking_page()
            || is_page( array( 'search-results', 'favorites' ) )
            || isset( $_GET['listing_id'] )
            || $this->page_has_any_shortcode(
                array(
                    'property_search_filter',
                    'property_search_results',
                    'property_calendar',
                    'property_gallery',
                    'property_amenities',
                    'favorites_list',
                    'favorites_single',
                )
            );

        return (bool) apply_filters( 'guesty_should_enqueue_assets', $relevant );
    }

    private function is_booking_page() {
        if ( is_page( array( 'booking', 'checkout' ) ) ) {
            return true;
        }

        $booking_url = (string) get_option( 'guesty_booking_page_url', site_url( '/booking/' ) );
        $booking_path = untrailingslashit( (string) wp_parse_url( $booking_url, PHP_URL_PATH ) );
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $request_path = untrailingslashit( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) );

        return $booking_path && $request_path === $booking_path;
    }

    private function find_property_id_by_guesty_id( $listing_id ) {
        $posts = get_posts(
            array(
                'post_type'      => 'property',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => 'guesty_id',
                'meta_value'     => sanitize_text_field( $listing_id ),
                'no_found_rows'  => true,
            )
        );
        return ! empty( $posts ) ? (int) $posts[0] : 0;
    }

    private function page_has_any_shortcode( array $shortcodes ) {
        global $post;
        if ( ! $post instanceof WP_Post || empty( $post->post_content ) ) {
            return false;
        }
        foreach ( $shortcodes as $shortcode ) {
            if ( has_shortcode( $post->post_content, $shortcode ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add Search Filter Forms short code
     */
    function property_search_form($atts) {
        $results_page = isset($atts['results_page']) ? esc_url($atts['results_page']) : site_url('/search-results');
        
        // Basic Filter
        $title      = isset($_GET['title']) ? sanitize_text_field($_GET['title']) : '';
        $arrival    = isset($_GET['arrival']) ? sanitize_text_field($_GET['arrival']) : '';
        $departure  = isset($_GET['departure']) ? sanitize_text_field($_GET['departure']) : '';
        $guests     = isset($_GET['guests']) ? sanitize_text_field($_GET['guests']) : '';

        // Advance Filter
        $bedrooms   = isset($_GET['bedrooms']) ? sanitize_text_field($_GET['bedrooms']) : '';
        $bathrooms  = isset($_GET['bathrooms']) ? sanitize_text_field($_GET['bathrooms']) : '';
        $highlights = isset($_GET['highlights']) ? array_map('sanitize_text_field', (array) $_GET['highlights']) : [];
        $price_min  = isset($_GET['price_min']) ? intval($_GET['price_min']) : '';
        $price_max  = isset($_GET['price_max']) ? intval($_GET['price_max']) : '';
        $destination = isset($_GET['destination']) ? sanitize_text_field($_GET['destination']) : '';
        $property_type_filter = isset($_GET['property_type']) ? sanitize_text_field($_GET['property_type']) : '';
        $sort = isset($_GET['sort']) ? sanitize_key($_GET['sort']) : '';
        $property_types = $this->get_synced_property_types();

        ob_start(); ?>
        <form action="<?php echo esc_url( $results_page ); ?>" method="get" id="property-search-form">

            <div class="basic-fields">
                <div>
                    <input type="text" name="destination" id="destination" value="<?php echo esc_attr( $destination ); ?>" placeholder="Destination / City" autocomplete="off">
                </div>
                <div>
                    <input type="text" name="title" id="title" value="<?php echo esc_attr( $title ); ?>" placeholder="Property Name" autocomplete="off">
                </div>
                <div>
                    <input type="text" name="arrival" id="arrival" value="<?php echo esc_attr( $arrival ); ?>" placeholder="Arrival Date" autocomplete="off" inputmode="none">
                </div>
                <div>
                    <input type="text" name="departure" id="departure" value="<?php echo esc_attr( $departure ); ?>" placeholder="Departure Date" autocomplete="off" inputmode="none">
                </div>
                <div>
                    <input type="number" name="guests" id="guests" min="1" value="<?php echo esc_attr( $guests ); ?>" placeholder="Number of Guests" autocomplete="off">
                </div>
                <div>
                    <button type="submit">Search</button>
                </div>
                <div class="advance-filter">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 14C11.3118 14 12.4269 14.842 12.8345 16.015L12.8834 16.0067L13 16H19C19.5523 16 20 16.4477 20 17C20 17.5128 19.614 17.9355 19.1166 17.9933L19 18H13C12.9434 18 12.888 17.9953 12.834 17.9863C12.4269 19.158 11.3118 20 10 20C8.6882 20 7.57305 19.158 7.16547 17.985L7.11664 17.9933L7 18H1C0.447715 18 0 17.5523 0 17C0 16.4872 0.386023 16.0645 0.883362 16.0067L1 16H7C7.05655 16 7.11202 16.0047 7.16602 16.0137C7.57306 14.842 8.6882 14 10 14ZM10 16C9.44771 16 9 16.4477 9 17C9 17.5523 9.44771 18 10 18C10.5523 18 11 17.5523 11 17C11 16.4477 10.5523 16 10 16ZM3 7C4.3118 7 5.42695 7.84196 5.83453 9.01495L5.88336 9.00673L6 9H19C19.5523 9 20 9.44771 20 10C20 10.5128 19.614 10.9355 19.1166 10.9933L19 11H6C5.94345 11 5.88798 10.9953 5.83398 10.9863C5.42694 12.158 4.3118 13 3 13C1.34315 13 0 11.6569 0 10C0 8.34315 1.34315 7 3 7ZM3 9C2.44771 9 2 9.44771 2 10C2 10.5523 2.44771 11 3 11C3.55229 11 4 10.5523 4 10C4 9.44771 3.55229 9 3 9ZM14 0C15.3118 0 16.4269 0.841957 16.8345 2.01495L16.8834 2.00673L17 2H19C19.5523 2 20 2.44771 20 3C20 3.51284 19.614 3.93551 19.1166 3.99327L19 4H17C16.9434 4 16.888 3.9953 16.834 3.98628C16.4269 5.15804 15.3118 6 14 6C12.6882 6 11.5731 5.15804 11.1655 3.98505L11.1166 3.99327L11 4H1C0.447715 4 0 3.55229 0 3C0 2.48716 0.386023 2.06449 0.883362 2.00673L1 2H11C11.0566 2 11.112 2.0047 11.166 2.01372C11.5731 0.841958 12.6882 0 14 0ZM14 2C13.4477 2 13 2.44771 13 3C13 3.55229 13.4477 4 14 4C14.5523 4 15 3.55229 15 3C15 2.44771 14.5523 2 14 2Z"></path></svg>
                    <span class="advance-filter-showmore">More</span>
                </div>
            </div>
        

            <div class="advance-fields" style="display:none;">   

                <div class="advance-fields-top">
                    <div class="advance-fields-row space-between">
                        <h2 class="advance-fields-header">Filters</h2>
                        <span class="advance-fields-close"></span>
                    </div>

                    <hr class="advance-fields-hr">
                </div>

                <div class="advance-fields-body">
                    <div class="advance-fields-row guesty-filter-selects">
                        <div class="advance-fields-column">
                            <label class="advance-fields-column-header" for="property_type">Property Type</label>
                            <select name="property_type" id="property_type">
                                <option value="">Any property type</option>
                                <?php foreach ( $property_types as $type ) : ?>
                                    <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $property_type_filter, $type ); ?>><?php echo esc_html( $type ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="advance-fields-column">
                            <label class="advance-fields-column-header" for="sort">Sort By</label>
                            <select name="sort" id="sort">
                                <option value="" <?php selected( $sort, '' ); ?>>Recommended</option>
                                <option value="price_asc" <?php selected( $sort, 'price_asc' ); ?>>Price: Low to High</option>
                                <option value="price_desc" <?php selected( $sort, 'price_desc' ); ?>>Price: High to Low</option>
                                <option value="newest" <?php selected( $sort, 'newest' ); ?>>Newest</option>
                                <option value="featured" <?php selected( $sort, 'featured' ); ?>>Featured</option>
                            </select>
                        </div>
                    </div>

                    <div class="advance-fields-row"> 
                        <h3 class="advance-fields-header">Rooms</h3>
                    </div>

                    <div class="advance-fields-row">
                        <div class="advance-fields-column">
                            <p class="advance-fields-column-header">Bedrooms</p>
                            <div class="filter-group">
                                <div class="stepper" data-name="bedrooms" data-max="4">
                                    <button type="button" class="minus">−</button>

                                    <input type="text" class="display" value="<?php echo esc_attr($bedrooms ?? ''); ?>" placeholder="Any" disabled>
                                    

                                    <button type="button" class="plus">+</button>

                                    <!-- hidden value that submits -->
                                    <input type="hidden" name="bedrooms" class="stepper-value" value="<?php echo esc_attr($bedrooms ?? ''); ?>">   
                                </div>
                            </div>
                        </div>

                        <div class="advance-fields-column">
                            <p class="advance-fields-column-header">Bathrooms</p>
                            <div class="filter-group">
                                <div class="stepper" data-name="bathrooms" data-max="3">
                                    <button type="button" class="minus">−</button>

                                    <input type="text" class="display" value="<?php echo esc_attr($bathrooms ?? ''); ?>" placeholder="Any" disabled>

                                    <button type="button" class="plus">+</button>

                                    <input type="hidden" name="bathrooms" class="stepper-value" value="<?php echo esc_attr($bathrooms ?: ''); ?>">
                                </div>
                                <!-- <input type="radio" id="bath-any" name="bathrooms" value="any" <?php checked($bathrooms, 'any'); ?>>
                                <label for="bath-any">Any</label>

                                <input type="radio" id="bath-1" name="bathrooms" value="1" <?php checked($bathrooms, '1'); ?>>
                                <label for="bath-1">1</label>

                                <input type="radio" id="bath-2" name="bathrooms" value="2" <?php checked($bathrooms, '2'); ?>>
                                <label for="bath-2">2</label>

                                <input type="radio" id="bath-3" name="bathrooms" value="3" <?php checked($bathrooms, '3'); ?>>
                                <label for="bath-3">3</label>

                                <input type="radio" id="bath-4" name="bathrooms" value="4" <?php checked($bathrooms, '4'); ?>>
                                <label for="bath-4">4</label>

                                <input type="radio" id="bath-5" name="bathrooms" value="5+" <?php checked($bathrooms, '5+'); ?>>
                                <label for="bath-5">5+</label> -->
                            </div>
                        </div>
                    </div>

                    <div class="advance-fields-row"> 
                        <h3 class="advance-fields-header">Highlights</h3>
                    </div>

                    <div class="advance-fields-column">
                        <div class="filter-group">

                            <?php
                            $highlight_icons = array(
                                'water-views'    => '<svg xmlns="http://www.w3.org/2000/svg" width="93" height="93" viewBox="0 0 93 93" fill="none"><path d="M18.0621 73.501C18.6477 73.5529 19.2407 73.59 19.8411 73.59C24.7257 73.59 29.2027 71.8625 32.7013 68.9931C36.185 71.8699 40.662 73.59 45.5466 73.59C50.4313 73.59 54.9008 71.8699 58.3994 68.9931C61.898 71.8699 66.3675 73.59 71.2522 73.59C71.8526 73.59 72.4455 73.553 73.0311 73.5085L79.7911 54.5424C80.5842 52.3255 79.4575 49.8862 77.2561 49.041L73.587 47.6396V27.4651C73.587 25.0776 71.6524 23.1351 69.2583 23.1351H53.4703V21.1406C53.4703 19.7318 52.3288 18.59 50.9205 18.59H40.1654C38.757 18.59 37.6156 19.7318 37.6156 21.1406V23.1351H21.8275C19.4408 23.1351 17.4988 25.0702 17.4988 27.4651V47.6396L13.8298 49.041C11.6357 49.8788 10.5091 52.3255 11.2948 54.5424L18.0621 73.501ZM23.1543 28.4808H67.9241V45.4746L45.5392 36.9184L23.1543 45.4672V28.4808Z"></path></svg>',
                                'pet-friendly'  => '<svg xmlns="http://www.w3.org/2000/svg" width="93" height="93" viewBox="0 0 93 93" fill="none"><path d="M61.9899 61.5704C58.7112 60.3917 56.4089 57.5735 56.4161 54.2397L56.417 54.0734C56.4297 48.4224 51.8594 43.4658 45.9206 43.3166C39.8286 43.1641 34.8397 47.8155 34.8397 53.5753L34.8411 54.241C34.8487 57.5744 32.547 60.3922 29.2691 61.5704C26.5491 62.5481 24.7466 64.3856 24.6578 67.701C24.5308 72.4459 27.6369 76.6057 32.4555 77.8375C35.088 78.5101 37.6223 78.2597 39.7836 77.3528C43.4895 75.7969 47.7677 75.796 51.4737 77.3519C53.6358 78.2602 56.1719 78.5105 58.8054 77.8367C63.6239 76.6035 66.7291 72.4433 66.6012 67.6989C66.5111 64.3848 64.709 62.5477 61.9899 61.5704Z"></path><path d="M36.0095 42.3419C41.5716 41.2644 44.7241 34.0765 43.051 26.2876C41.3778 18.4987 35.5129 13.0586 29.9513 14.1361C24.3892 15.214 21.2366 22.4014 22.9098 30.1903C24.5826 37.9792 30.4474 43.4194 36.0095 42.3419Z"></path><path d="M55.2486 42.3419C60.8107 43.4194 66.6756 37.9792 68.3483 30.1903C70.0215 22.4014 66.8689 15.214 61.3068 14.1361C55.7452 13.0586 49.8803 18.4987 48.2071 26.2876C46.5344 34.0765 49.687 41.2644 55.2486 42.3419Z"></path><path d="M26.8937 48.0537C25.1944 42.4092 20.393 38.6513 16.1693 39.661C11.9451 40.6707 9.89884 46.0654 11.5977 51.7104C13.2965 57.3553 18.0984 61.1132 22.3221 60.103C26.5463 59.0934 28.5926 53.6986 26.8937 48.0537Z"></path><path d="M75.0889 39.661C70.8652 38.6513 66.0637 42.4092 64.3645 48.0537C62.6656 53.6986 64.7119 59.0934 68.936 60.103C73.1602 61.1132 77.9616 57.3553 79.6609 51.7104C81.3598 46.0654 79.3131 40.6707 75.0889 39.661Z"></path></svg>',
                                'outside-space' => '<svg xmlns="http://www.w3.org/2000/svg" width="91" height="66" viewBox="0 0 91 66" fill="none"><path d="M70.4711 5.92037L71.2683 2.89889C71.592 1.67293 70.8798 0.409876 69.6779 0.0796627C68.476 -0.25055 67.2377 0.475917 66.914 1.70188L66.1168 4.72336C51.7472 1.82572 37.5267 9.03673 33.0918 21.8617C32.8491 22.5634 33.0999 23.3393 33.6989 23.7604C34.2978 24.1815 35.099 24.1402 35.6534 23.6572C37.9802 21.643 41.1204 20.9164 44.0583 21.7255C47.3766 22.6377 49.9138 25.3373 50.6706 28.7674C50.9336 29.9561 52.6089 30.4143 53.4222 29.5227C55.7693 26.9471 59.29 25.9151 62.6082 26.8273C65.9264 27.7396 68.4637 30.4391 69.2204 33.8692C69.354 34.4636 69.7951 34.9341 70.3738 35.0909C70.9524 35.2478 71.5675 35.0703 71.9722 34.6245C74.3193 32.0488 77.8399 31.0127 81.1582 31.925C84.096 32.734 86.4553 34.9671 87.4751 37.9018C87.7179 38.6035 88.3896 39.0492 89.118 38.9957C89.8423 38.9379 90.4412 38.393 90.5789 37.6624C93.0635 24.3049 84.3226 10.779 70.4711 5.92037Z"></path><path d="M57.7704 51.7912L62.8463 32.8425C63.169 31.6446 62.4549 30.4019 61.2687 30.077C60.0784 29.7562 58.8437 30.4668 58.525 31.6688L53.3282 51.0718C49.4103 50.552 45.2786 50.2677 41.0013 50.2677C23.4458 50.2677 8.22978 54.9702 0.789646 61.8457C-0.81221 63.3239 0.180387 66 2.35514 66H79.6466C81.8214 66 82.81 63.3239 81.2121 61.8457C76.2856 57.2934 67.937 53.7076 57.7741 51.7908L57.7704 51.7912Z"></path></svg>',
                                'parking'       => '<svg xmlns="http://www.w3.org/2000/svg" width="93" height="93" viewBox="0 0 93 93" fill="none"><path d="M49.7564 26.75H31.9457V65.25H39.8037V50.8466H49.7568C56.4116 50.8466 61.8056 45.4531 61.8056 38.7979C61.8056 32.1427 56.412 26.7491 49.7568 26.7491L49.7564 26.75ZM39.8032 42.9891V34.6083H49.7564C52.0669 34.6083 53.9468 36.4882 53.9468 38.7988C53.9468 41.1094 52.067 42.9892 49.7564 42.9892L39.8032 42.9891Z"></path><path d="M68.75 18C71.645 18 74 20.355 74 23.25V68.75C74 71.645 71.645 74 68.75 74H23.25C20.355 74 18 71.645 18 68.75V23.25C18 20.355 20.355 18 23.25 18H68.75ZM68.75 11H23.25C16.4858 11 11 16.4858 11 23.25V68.75C11 75.5142 16.4858 81 23.25 81H68.75C75.5142 81 81 75.5142 81 68.75V23.25C81 16.4858 75.5142 11 68.75 11Z"></path></svg>',
                                'lift-or-level-access'  => '<svg xmlns="http://www.w3.org/2000/svg" width="93" height="93" viewBox="0 0 93 93" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M8.4692 25.7151C9.17623 24.8674 10.438 24.7581 11.2856 25.469L16.9223 30.1956C18.4106 31.4417 20.5903 31.4417 22.0785 30.1956L23.8519 28.7073C26.8285 26.2112 31.1722 26.2112 34.1489 28.7073L35.9223 30.1956C37.4106 31.4417 39.5903 31.4417 41.0785 30.1956L42.8519 28.7073C45.8285 26.2112 50.1722 26.2112 53.1489 28.7073L54.9223 30.1956C56.4106 31.4417 58.5903 31.4417 60.0785 30.1956L61.8519 28.7073C64.8285 26.2112 69.1722 26.2112 72.1489 28.7073L73.9223 30.1956C75.4106 31.4417 77.5903 31.4417 79.0785 30.1956L84.7152 25.469C85.5629 24.7581 86.8246 24.8675 87.5316 25.7151C88.2425 26.5628 88.1332 27.8245 87.2855 28.5315L81.6488 33.2581C78.6722 35.7542 74.3285 35.7542 71.3518 33.2581L69.5784 31.7737C68.0901 30.5237 65.9104 30.5237 64.4222 31.7737L62.6488 33.2581C59.6722 35.7542 55.3285 35.7542 52.3518 33.2581L50.5784 31.7737C49.0901 30.5237 46.9104 30.5237 45.4222 31.7737L43.6488 33.2581C40.6722 35.7542 36.3285 35.7542 33.3518 33.2581L31.5784 31.7737C30.0901 30.5237 27.9104 30.5237 26.4222 31.7737L24.6488 33.2581C21.6722 35.7542 17.3285 35.7542 14.3518 33.2581L8.7151 28.5315C7.86744 27.8245 7.75826 26.5627 8.4692 25.7151ZM8.4692 41.6491C9.17623 40.8054 10.438 40.6921 11.2856 41.403L16.9223 46.1296C18.4106 47.3796 20.5903 47.3796 22.0785 46.1296L23.8519 44.6452C26.8285 42.1491 31.1722 42.1491 34.1489 44.6452L35.9223 46.1296C37.4106 47.3796 39.5903 47.3796 41.0785 46.1296L42.8519 44.6452C45.8285 42.1491 50.1722 42.1491 53.1489 44.6452L54.9223 46.1296C56.4106 47.3796 58.5903 47.3796 60.0785 46.1296L61.8519 44.6452C64.8285 42.1491 69.1722 42.1491 72.1489 44.6452L73.9223 46.1296C75.4106 47.3796 77.5903 47.3796 79.0785 46.1296L84.7152 41.403C85.5629 40.6921 86.8246 40.8054 87.5316 41.6491C88.2425 42.4968 88.1332 43.7585 87.2855 44.4694L81.6488 49.1921C78.6722 51.6921 74.3285 51.6921 71.3518 49.1921L69.5784 47.7077C68.0901 46.4577 65.9104 46.4577 64.4222 47.7077L62.6488 49.1921C59.6722 51.6921 55.3285 51.6921 52.3518 49.1921L50.5784 47.7077C49.0901 46.4577 46.9104 46.4577 45.4222 47.7077L43.6488 49.1921C40.6722 51.6921 36.3285 51.6921 33.3518 49.1921L31.5784 47.7077C30.0901 46.4577 27.9104 46.4577 26.4222 47.7077L24.6488 49.1921C21.6722 51.6921 17.3285 51.6921 14.3518 49.1921L8.7151 44.4694C7.86744 43.7585 7.75826 42.4967 8.4692 41.6491ZM8.4692 57.5871C9.17623 56.7394 10.438 56.6301 11.2856 57.3371L16.9223 62.0637C18.4106 63.3137 20.5903 63.3137 22.0785 62.0637L23.8519 60.5793C26.8285 58.0832 31.1722 58.0832 34.1489 60.5793L35.9223 62.0637C37.4106 63.3137 39.5903 63.3137 41.0785 62.0637L42.8519 60.5793C45.8285 58.0832 50.1722 58.0832 53.1489 60.5793L54.9223 62.0637C56.4106 63.3137 58.5903 63.3137 60.0785 62.0637L61.8519 60.5793C64.8285 58.0832 69.1722 58.0832 72.1489 60.5793L73.9223 62.0637C75.4106 63.3137 77.5903 63.3137 79.0785 62.0637L84.7152 57.3371C85.5629 56.6301 86.8246 56.7394 87.5316 57.5871C88.2425 58.4309 88.1332 59.6926 87.2855 60.4035L81.6488 65.1301C78.6722 67.6262 74.3285 67.6262 71.3518 65.1301L69.5784 63.6457C68.0901 62.3957 65.9104 62.3957 64.4222 63.6457L62.6488 65.1301C59.6722 67.6262 55.3285 67.6262 52.3518 65.1301L50.5784 63.6457C49.0901 62.3957 46.9104 62.3957 45.4222 63.6457L43.6488 65.1301C40.6722 67.6262 36.3285 67.6262 33.3518 65.1301L31.5784 63.6457C30.0901 62.3957 27.9104 62.3957 26.4222 63.6457L24.6488 65.1301C21.6722 67.6262 17.3285 67.6262 14.3518 65.1301L8.7151 60.4035C7.86744 59.6926 7.75826 58.4308 8.4692 57.5871Z" stroke="black" stroke-width="3"></path></svg>',
                            );

                            $allowed_tags = array( 'water-views', 'pet-friendly', 'outside-space', 'parking', 'lift-or-level-access' );

                            $available_tags = get_terms( array(
                                'taxonomy'   => 'property_tag',
                                'hide_empty' => true,
                                'slug'       => $allowed_tags,
                            ) );

                            if ( !empty($available_tags) && !is_wp_error($available_tags) ) {
                                foreach ( $available_tags as $tag ) {
                                    $slug = $tag->slug;
                                    $checked = in_array( $slug, $highlights ) ? 'checked' : '';

                                    ?>
                                    <input type="checkbox" 
                                        id="highlight-<?php echo esc_attr($slug); ?>" 
                                        name="highlights[]" 
                                        value="<?php echo esc_attr($slug); ?>" 
                                        <?php echo $checked; ?>>

                                    <label class="highlights-checkbox" for="highlight-<?php echo esc_attr($slug); ?>">
                                        <?php 
                                        // SVG if available
                                        if ( isset( $highlight_icons[$slug] ) ) {
                                            echo $highlight_icons[$slug];
                                        }
                                        ?>
                                        <span class="text"><?php echo esc_html($tag->name); ?></span>
                                    </label>
                                    <?php
                                }
                            }
                            ?>
                        </div>
                    </div>

                    <div class="advance-fields-column"> 
                        <h3 class="advance-fields-header">Price Range</h3>
                        <p class="advance-fields-column-header">The average nightly price is £180</p>
                    </div>

                    <div class="advance-fields-row"> 
                        <div class="price-range-field">
                            <div id="price_range_slider"></div>
                            <div class="range-values">

                                <input type="hidden" name="price_min" id="price_min" value="<?php echo esc_attr($price_min); ?>">
                                <input type="hidden" name="price_max" id="price_max" value="<?php echo esc_attr($price_max); ?>">

                                <div class="range-values-row">
                                    <div class="range-values-col">
                                        <p class="range-values-label">Min price</p>
                                        <p class="range-values-price" id="price_range_display_min">£60</p> 
                                    </div>
                                    <div class="range-values-col">
                                        <p class="range-values-label">Max price</p>
                                        <p class="range-values-price" id="price_range_display_max">£360</p> 
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="advance-fields-bottom">
                    <hr class="advance-fields-hr">

                    <div class="advance-fields-row space-between footer-cta"> 
                        <a href="#" id="clear-all-filters" class="advance-fields-secondary">Clear all</a>
                        <button type="submit" class="advance-fields-primary">Show properties</button>
                    </div>
                </div>
            </div>

        </form> 

        <?php
        return ob_get_clean();
    }

    /**
     * Return distinct property types already stored during Guesty sync.
     * This is a local database lookup and never calls the Guesty API.
     */
    private function get_synced_property_types() {
        $cached = wp_cache_get( 'guesty_synced_property_types', 'guesty' );
        if ( false !== $cached ) {
            return is_array( $cached ) ? $cached : array();
        }

        global $wpdb;
        $types = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND pm.meta_value <> ''
                   AND p.post_type = %s
                   AND p.post_status = %s
                 ORDER BY pm.meta_value ASC",
                'property_type',
                'property',
                'publish'
            )
        );
        $types = array_values( array_filter( array_map( 'sanitize_text_field', (array) $types ) ) );
        wp_cache_set( 'guesty_synced_property_types', $types, 'guesty', HOUR_IN_SECONDS );
        return $types;
    }

    private function is_day_bookable($day) {

        $status = $day['status'] ?? 'unavailable';
        $date   = $day['date'];
        $blocks = $day['blocks'] ?? [];

        // 🔴 HARD BLOCK: owner blocks (NEW - highest priority)
        if (!empty($blocks['o']) || !empty($blocks['m'])) {
            return false;
        }

        // 🔴 Fallback: check blockRefs (extra safety)
        foreach ($day['blockRefs'] ?? [] as $block) {
            if (in_array($block['type'], ['o', 'm'])) {
                return false;
            }
        }

        // Already available → OK (unchanged behavior)
        if ($status === 'available') {
            return true;
        }

        // Only override for TODAY (unchanged)
        $today = wp_date('Y-m-d');
        if ($date !== $today) {
            return false;
        }

        // Look for advance notice block (unchanged logic)
        $advance_notice_hours = null;

        foreach ($day['blockRefs'] ?? [] as $block) {
            if ($block['type'] === 'an') {
                $advance_notice_hours = $block['hours'] ?? null;
                break;
            }
        }

        if ($advance_notice_hours === null) {
            return false;
        }

        // Convert to 24-hour cutoff (unchanged logic)
        $cutoff_hour_24 = (int) $advance_notice_hours + 12;
        $cutoff_time = sprintf('%02d:00', $cutoff_hour_24);

        $current_time = current_time('H:i');

        return $current_time < $cutoff_time;
    }

    public function get_guest_days($startDate, $endDate) {
        $guests = isset($_REQUEST['guests']) ? max(1, intval($_REQUEST['guests'])) : 1;
        return $this->search_service->search(
            sanitize_text_field($startDate),
            sanitize_text_field($endDate),
            $guests
        );
    }

    /**
     * Add Search Results short code
     * 
     * ** Sample usage :
     * 
     * [property_search_results orderby=rand]
     * [property_search_results order=DESC orderby=title]
     * [property_search_results display=special-offer]
     * [property_search_results]
     */
    public function property_search_results($atts = []) {
        $atts = shortcode_atts([
            'order'   => 'ASC',
            'orderby' => '',
            'display' => '',
        ], $atts, 'property_search_results');

        $orderby        = strtolower($atts['orderby']);
        $order          = strtoupper($atts['order']);
        $display        = strtolower($atts['display']);
        $random_seed    = isset($_GET['seed']) ? intval($_GET['seed']) : rand(1000, 999999);

        $ids            = isset($_GET['ids']) ? explode(',', $_GET['ids']) : []; // used to share IDS from  favorites
        $ids            = array_map('intval', $ids);
        $ids            = array_filter($ids); // remove 0/null

        $args = [
            'post_type'      => 'property',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'paged'          => 1,
            's'              => sanitize_text_field($_GET['title'] ?? ''),
            'meta_query'     => [],
            'tax_query'      => [],
        ];

        // IDs
        if (!empty($_GET['ids'])) {
            $args['post__in'] = $ids;
        }

        // Bedrooms
        if (!empty($_GET['bedrooms']) && $_GET['bedrooms'] !== 'any') {
            $args['meta_query'][] = [
                'key'     => 'property_bedrooms',
                'value'   => intval($_GET['bedrooms']),
                'compare' => (false !== strpos((string) $_GET['bedrooms'], '+')) ? '>=' : '=',
                'type'    => 'NUMERIC'
            ];
        }

        // Bathrooms
        if (!empty($_GET['bathrooms']) && $_GET['bathrooms'] !== 'any') {
            $args['meta_query'][] = [
                'key'     => 'property_bathrooms',
                'value'   => intval($_GET['bathrooms']),
                'compare' => (false !== strpos((string) $_GET['bathrooms'], '+')) ? '>=' : '=',
                'type'    => 'NUMERIC'
            ];
        }

        // Highlights
        if (!empty($_GET['highlights']) && is_array($_GET['highlights'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'property_tag',
                'field'    => 'slug',
                'terms'    => array_map('sanitize_text_field', $_GET['highlights']),
                'operator' => 'IN',
            ];
        }

        // Guests filter
        if (!empty($_GET['guests'])) {
            $args['meta_query'][] = [
                'key'     => 'property_accommodates',
                'value'   => intval($_GET['guests']),
                'compare' => '>=',
                'type'    => 'NUMERIC'
            ];
            $args['meta_key'] = 'property_accommodates';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'ASC';
        } else {
            if ($orderby === 'rand') {
                $args['orderby'] = 'rand';
                add_filter('posts_orderby', function ($orderby_sql, $wp_query) use ($random_seed) {
                    return "RAND(" . esc_sql($random_seed) . ")";
                }, 10, 2);
            } elseif (in_array($orderby, ['title', 'date', 'meta_value_num'], true)) {
                $args['orderby'] = $orderby;
                $args['order'] = in_array($order, ['ASC','DESC']) ? $order : 'ASC';
            } else {
                $args['meta_key'] = 'property_accommodates';
                $args['orderby'] = 'meta_value_num';
                $args['order'] = 'ASC';
            }
        }

        $price_min = isset($_GET['price_min']) ? floatval($_GET['price_min']) : 0;
        $price_max = isset($_GET['price_max']) ? floatval($_GET['price_max']) : 0;

        // Local-only advanced filters. These use post_meta populated by
        // the sync process and never call Guesty during result rendering.
        $destination = sanitize_text_field( $_GET['destination'] ?? '' );
        if ( '' !== $destination ) {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array( 'key' => 'property_city', 'value' => $destination, 'compare' => 'LIKE' ),
                array( 'key' => 'property_neighborhood', 'value' => $destination, 'compare' => 'LIKE' ),
                array( 'key' => 'property_building_name', 'value' => $destination, 'compare' => 'LIKE' ),
                array( 'key' => 'property_full_address', 'value' => $destination, 'compare' => 'LIKE' ),
            );
        }

        $property_type_filter = sanitize_text_field( $_GET['property_type'] ?? '' );
        if ( '' !== $property_type_filter && 'any' !== $property_type_filter ) {
            $args['meta_query'][] = array(
                'key'     => 'property_type',
                'value'   => $property_type_filter,
                'compare' => '=',
            );
        }

        if ( $price_min > 0 || $price_max > 0 ) {
            $price_clause = array( 'key' => 'property_base_price', 'type' => 'NUMERIC' );
            if ( $price_min > 0 && $price_max > 0 ) {
                $price_clause['value'] = array( $price_min, $price_max );
                $price_clause['compare'] = 'BETWEEN';
            } elseif ( $price_min > 0 ) {
                $price_clause['value'] = $price_min;
                $price_clause['compare'] = '>=';
            } else {
                $price_clause['value'] = $price_max;
                $price_clause['compare'] = '<=';
            }
            $args['meta_query'][] = $price_clause;
        }

        if ( 'special-offer' === $display ) {
            $args['meta_query'][] = array(
                'key'     => 'property_has_promotion',
                'value'   => 1,
                'compare' => '=',
                'type'    => 'NUMERIC',
            );
        }

        $sort = sanitize_key( $_GET['sort'] ?? '' );
        if ( 'price_asc' === $sort || 'price_desc' === $sort ) {
            $args['meta_key'] = 'property_base_price';
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'price_desc' === $sort ? 'DESC' : 'ASC';
        } elseif ( 'newest' === $sort ) {
            $args['orderby'] = 'date';
            $args['order']   = 'DESC';
        } elseif ( 'featured' === $sort ) {
            $args['meta_key'] = 'property_featured';
            $args['orderby']  = array( 'meta_value_num' => 'DESC', 'date' => 'DESC' );
        }

        // Availability + Price Range
        $arrival   = !empty($_GET['arrival']) ? sanitize_text_field($_GET['arrival']) : '';
        $departure = !empty($_GET['departure']) ? sanitize_text_field($_GET['departure']) : '';

        // Guesty availability is requested only when the visitor explicitly
        // submits both dates. Price and promotion filters remain local-only.

        if (!empty($arrival) && !empty($departure)) {
            $available = $this->get_guest_days($arrival, $departure);

            $available_ids = wp_list_pluck($available, 'post_id');
            $args['post__in'] = !empty($available_ids) ? $available_ids : [0];

            $this->available_pricing_data = [];
            foreach ($available as $item) {
                $this->available_pricing_data[$item['post_id']] = [
                    'total_price'       => $item['total_price'],
                    'total_adjusted'    => $item['total_adjusted'],
                    'is_discounted'     => $item['is_discounted'],
                    'promo_name'        => $item['promo_name'],
                    'startDate'         => $item['startDate'],
                    'endDate'           => $item['endDate'],
                    'price_is_estimate'  => !empty($item['price_is_estimate']),
                    'currency'           => $item['currency'] ?? get_option( 'guesty_default_currency', 'GBP' ),
                ];
            }

            if (empty($available_ids)) {
                ob_start(); ?>
                <ul id="property-search" style="grid-template-columns: repeat(1, 1fr) !important;">
                    <p style="text-align: center;">No properties available for the selected filters.</p>
                </ul>
                <?php
                return ob_get_clean();
            }
        }

        $results = $this->render_property_list($args);

        ob_start(); ?>

        <div class="search-result-with-map">
            <div class="search-result-with-map-container">
                <ul id="property-search">
                    <?= $results['html']; ?>
                </ul>

                <?php if ($results['max_num_pages'] > 1): ?>
                    <button id="load-more-results"
                            data-page="1"
                            data-orderby="<?= esc_attr($orderby); ?>"
                            data-order="<?= esc_attr($order); ?>"
                            data-seed="<?= esc_attr($random_seed); ?>"
                            data-display="<?= esc_attr($display); ?>"
                            data-arrival="<?= esc_attr($arrival); ?>"
                            data-departure="<?= esc_attr($departure); ?>">
                        Load More
                    </button>
                <?php endif; ?>
            </div> 
        
            <div class="search-result-with-map-container gmaps-wrapper">  
                <div class="gmaps-sticky"> 
                    <?php if (!empty($results['locations'])): ?>
                        <div id="property-map"  ></div>
                        <script>
                            var mapLocations = <?php echo json_encode($results['locations']); ?>;
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function guesty_load_more() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }

        $paged          = max(1, intval($_POST['paged'] ?? 1));
        $orderby        = strtolower(sanitize_text_field($_POST['orderby'] ?? ''));
        $order          = strtoupper(sanitize_text_field($_POST['order'] ?? 'ASC'));
        $seed           = sanitize_text_field($_POST['seed'] ?? '');
        $shown_ids      = isset($_POST['shown_ids']) ? array_map('intval', $_POST['shown_ids']) : [];
        $search_title   = trim(sanitize_text_field($_POST['title'] ?? ''));
        $display        = strtolower(sanitize_text_field($_POST['display'] ?? ''));

        $shared_ids     = isset($_POST['shared_ids']) ? array_map('intval', $_POST['shared_ids']) : [];

        if (!empty($search_title)) {
            add_filter('posts_search', function ($search, $query) use ($search_title) {
                global $wpdb;
                if ($query->get('post_type') === 'property') {
                    $like = '%' . $wpdb->esc_like($search_title) . '%';
                    $search = $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s)", $like);
                }
                return $search;
            }, 10, 2);
        }

        $args = [
            'post_type'      => 'property',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'paged'          => $paged,
            's'              => $search_title,
            'meta_query'     => [],
            'tax_query'      => [],
            // 'post__not_in'   => $shown_ids,
        ];

        $price_min = isset($_POST['price_min']) ? floatval($_POST['price_min']) : 0;
        $price_max = isset($_POST['price_max']) ? floatval($_POST['price_max']) : 0;

        $destination = sanitize_text_field( $_POST['destination'] ?? '' );
        if ( '' !== $destination ) {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array( 'key' => 'property_city', 'value' => $destination, 'compare' => 'LIKE' ),
                array( 'key' => 'property_neighborhood', 'value' => $destination, 'compare' => 'LIKE' ),
                array( 'key' => 'property_building_name', 'value' => $destination, 'compare' => 'LIKE' ),
                array( 'key' => 'property_full_address', 'value' => $destination, 'compare' => 'LIKE' ),
            );
        }

        $property_type_filter = sanitize_text_field( $_POST['property_type'] ?? '' );
        if ( '' !== $property_type_filter && 'any' !== $property_type_filter ) {
            $args['meta_query'][] = array( 'key' => 'property_type', 'value' => $property_type_filter, 'compare' => '=' );
        }

        if ( $price_min > 0 || $price_max > 0 ) {
            $price_clause = array( 'key' => 'property_base_price', 'type' => 'NUMERIC' );
            if ( $price_min > 0 && $price_max > 0 ) {
                $price_clause['value'] = array( $price_min, $price_max );
                $price_clause['compare'] = 'BETWEEN';
            } elseif ( $price_min > 0 ) {
                $price_clause['value'] = $price_min;
                $price_clause['compare'] = '>=';
            } else {
                $price_clause['value'] = $price_max;
                $price_clause['compare'] = '<=';
            }
            $args['meta_query'][] = $price_clause;
        }

        if ( 'special-offer' === $display ) {
            $args['meta_query'][] = array( 'key' => 'property_has_promotion', 'value' => 1, 'compare' => '=', 'type' => 'NUMERIC' );
        }

        $sort = sanitize_key( $_POST['sort'] ?? '' );

         // Availability + Price Range
        $arrival   = !empty($_POST['arrival']) ? sanitize_text_field($_POST['arrival']) : '';
        $departure = !empty($_POST['departure']) ? sanitize_text_field($_POST['departure']) : '';

        // Guesty availability is requested only when the visitor explicitly
        // submits both dates. Price and promotion filters remain local-only.

        if (!empty($arrival) && !empty($departure)) {
            $available = $this->get_guest_days($arrival, $departure);

            $available_ids = wp_list_pluck($available, 'post_id');
            $args['post__in'] = !empty($available_ids) ? $available_ids : [0];

            $this->available_pricing_data = [];
            foreach ($available as $item) {
                $this->available_pricing_data[$item['post_id']] = [
                    'total_price' => $item['total_price'],
                    'total_adjusted' => $item['total_adjusted'],
                    'is_discounted'     => $item['is_discounted'],
                    'promo_name'        => $item['promo_name'],
                    'startDate'   => $item['startDate'],
                    'endDate'     => $item['endDate'],
                    'price_is_estimate' => !empty($item['price_is_estimate']),
                    'currency'          => $item['currency'] ?? get_option( 'guesty_default_currency', 'GBP' ),
                ];
            }
        }

        // IDs from Share favorites
        if (!empty($shared_ids)) {
            $args['post__in'] = $shared_ids;
            $args['orderby'] = 'post__in';
        }

        // Bedrooms
        if (!empty($_POST['bedrooms']) && $_POST['bedrooms'] !== 'any') {
            $args['meta_query'][] = [
                'key'     => 'property_bedrooms',
                'value'   => intval($_POST['bedrooms']),
                'compare' => (false !== strpos((string) $_POST['bedrooms'], '+')) ? '>=' : '=',
                'type'    => 'NUMERIC'
            ];
        }

        // Bathrooms
        if (!empty($_POST['bathrooms']) && $_POST['bathrooms'] !== 'any') {
            $args['meta_query'][] = [
                'key'     => 'property_bathrooms',
                'value'   => intval($_POST['bathrooms']),
                'compare' => (false !== strpos((string) $_POST['bathrooms'], '+')) ? '>=' : '=',
                'type'    => 'NUMERIC'
            ];
        }

        // Highlights
        if (!empty($_POST['highlights']) && is_array($_POST['highlights'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'property_tag',
                'field'    => 'slug',
                'terms'    => array_map('sanitize_text_field', $_POST['highlights']),
                'operator' => 'IN',
            ];
        }

        // Guests
        if (!empty($_POST['guests'])) {
            $args['meta_query'][] = [
                'key'     => 'property_accommodates',
                'value'   => intval($_POST['guests']),
                'compare' => '>=',
                'type'    => 'NUMERIC'
            ];
            $args['meta_key'] = 'property_accommodates';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'ASC';
        } else {
            if ($orderby === 'rand') {
                $args['orderby'] = 'rand';
                if ($seed) {
                    add_filter('posts_orderby', function ($orderby_sql, $wp_query) use ($seed) {
                        return "RAND(" . esc_sql($seed) . ")";
                    }, 10, 2);
                }
            } elseif (in_array($orderby, ['title', 'date', 'meta_value_num'], true)) {
                $args['orderby'] = $orderby;
                $args['order'] = in_array($order, ['ASC','DESC']) ? $order : 'ASC';
            } else {
                $args['meta_key'] = 'property_accommodates';
                $args['orderby'] = 'meta_value_num';
                $args['order'] = 'ASC';
            }
        }

        // Explicit user sort takes precedence over the default guest-capacity order.
        if ( 'price_asc' === $sort || 'price_desc' === $sort ) {
            $args['meta_key'] = 'property_base_price';
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'price_desc' === $sort ? 'DESC' : 'ASC';
        } elseif ( 'newest' === $sort ) {
            $args['orderby'] = 'date';
            $args['order']   = 'DESC';
        } elseif ( 'featured' === $sort ) {
            $args['meta_key'] = 'property_featured';
            $args['orderby']  = array( 'meta_value_num' => 'DESC', 'date' => 'DESC' );
        }

        $results = $this->render_property_list($args);

        remove_all_filters('posts_orderby');
        remove_all_filters('posts_search');

        wp_send_json([
            'html'     => $results['html'],
            'has_more' => $paged < $results['max_num_pages'],
            'locations' => $results['locations']
        ]);
    }

    /**
     * Shortcode: Display favorites with Load More
     */
    function property_favorites() {
        ob_start(); ?>

        <!-- Share Button -->
        <div class="favorites-share" style="display: none;">
            <button id="share-favorites-link" class="btn-share">
                Share my favourites
            </button>
        </div>

        <!-- Favorites List -->
        <div id="favorites-list" class="favorites-container">
            <p class="favorites-container_loader">
                Loading your favourites... <span></span>
            </p>
        </div>

        <!-- Load More -->
        <button id="load-more-favorites" data-page="1" style="display:none;">
            Load More
        </button>

        <!-- Share Modal -->
        <div id="favorites-modal" class="favorites-modal" style="display:none;">
            
            <div class="favorites-modal-overlay"></div>

            <div class="favorites-modal-content">
                
                <button type="button" class="favorites-modal-close">&times;</button>

                <h3>Share your favourites</h3>

                <form id="favorites-share-form">

                    <!-- Message -->
                    <p class="form-message" style="display:none;"></p>

                    <!-- Sender Name -->
                    <div class="form-group">
                        <label for="sender_name">Sender Name *</label>
                        <input type="text" id="sender_name" name="sender_name" required>
                    </div>

                    <!-- Sender Email -->
                    <div class="form-group">
                        <label for="sender_email">Sender Email *</label>
                        <input type="email" id="sender_email" name="sender_email" required>
                    </div>

                    <!-- Recipients -->
                    <div class="form-group">
                        <label for="recipient_emails">Recipient email(s) *</label>
                        <input type="text" id="recipient_emails" name="recipient_emails" required>
                        <small>Enter one or more email addresses, separated by commas.</small>
                    </div>

                    <!-- Newsletter -->
                    <div class="form-group checkbox">
                        <label>
                            <input type="checkbox" name="newsletter" value="1">
                            Sign me up for exclusive offers and updates
                        </label>
                    </div>

                    <!-- reCAPTCHA -->
                    <!-- <div class="form-group">
                        <div class="g-recaptcha" data-sitekey="YOUR_SITE_KEY"></div>
                    </div> -->

                    <!-- Submit -->
                    <div class="form-group">
                        <button type="submit" class="btn-submit">Send</button>
                    </div>

                </form>
            </div>
        </div>

        <?php
        return ob_get_clean();
    }

    function send_favorites_email() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'guesty_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
        }

        $sender_name  = sanitize_text_field( wp_unslash( $_POST['sender_name'] ?? '' ) );
        $sender_email = sanitize_email( wp_unslash( $_POST['sender_email'] ?? '' ) );
        $recipients   = sanitize_text_field( wp_unslash( $_POST['recipient_emails'] ?? '' ) );
        $newsletter   = isset( $_POST['newsletter'] ) ? intval( $_POST['newsletter'] ) : 0;
        $favorites    = isset( $_POST['favorites'] ) && is_array( $_POST['favorites'] )
            ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['favorites'] ) ) ) )
            : array();

        if ( ! $sender_name || ! is_email( $sender_email ) || ! $recipients || empty( $favorites ) ) {
            wp_send_json_error( array( 'message' => 'Please provide a valid sender, recipient, and at least one favourite.' ), 400 );
        }

        $brand_name    = sanitize_text_field( get_option( 'guesty_brand_name', get_bloginfo( 'name' ) ) );
        $contact_email = sanitize_email( get_option( 'guesty_contact_email', get_option( 'admin_email', '' ) ) );
        $contact_phone = sanitize_text_field( get_option( 'guesty_contact_phone', '' ) );
        $website_url   = home_url( '/' );
        $link          = add_query_arg( 'ids', implode( ',', $favorites ), site_url( '/properties/' ) );
        $emails        = array_unique( array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $recipients ) ) ), 'is_email' ) );

        if ( empty( $emails ) ) {
            wp_send_json_error( array( 'message' => 'No valid recipient email address was supplied.' ), 400 );
        }

        $subject = sprintf( '%s shared property favourites with you', $sender_name );
        $support_lines = '';
        if ( $contact_phone ) {
            $support_lines .= '<strong>Phone:</strong> ' . esc_html( $contact_phone ) . '<br>';
        }
        if ( $contact_email ) {
            $support_lines .= '<strong>Email:</strong> ' . esc_html( $contact_email );
        }

        $message = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#333">'
            . '<p>Hi,</p>'
            . '<p><strong>' . esc_html( $sender_name ) . '</strong> shared a selection of favourite properties with you from <strong>' . esc_html( $brand_name ) . '</strong>.</p>'
            . '<p><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:12px 20px;background:#1f5d78;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold">View shared favourites</a></p>'
            . '<p>Open the collection to review photos, availability, pricing, and property details.</p>'
            . ( $support_lines ? '<p>' . $support_lines . '</p>' : '' )
            . '<p>Kind regards,<br><strong>' . esc_html( $brand_name ) . '</strong><br><a href="' . esc_url( $website_url ) . '">' . esc_html( wp_parse_url( $website_url, PHP_URL_HOST ) ) . '</a></p>'
            . '</div>';

        $admin_subject = 'New favourites share submission';
        $admin_message = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#333">'
            . '<p><strong>Sender:</strong> ' . esc_html( $sender_name ) . ' (' . esc_html( $sender_email ) . ')</p>'
            . '<p><strong>Recipients:</strong> ' . esc_html( implode( ', ', $emails ) ) . '</p>'
            . '<p><strong>Newsletter signup:</strong> ' . ( $newsletter ? 'Yes' : 'No' ) . '</p>'
            . '<p><strong>Shared link:</strong> <a href="' . esc_url( $link ) . '">View favourites</a></p>'
            . '</div>';

        $from_email = $contact_email && is_email( $contact_email ) ? $contact_email : get_option( 'admin_email' );
        $headers = array(
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $brand_name . ' <' . $from_email . '>',
            'Reply-To: ' . $sender_name . ' <' . $sender_email . '>',
        );

        $sent = true;
        foreach ( $emails as $email ) {
            $sent = wp_mail( $email, $subject, $message, $headers ) && $sent;
        }
        if ( $contact_email && is_email( $contact_email ) ) {
            wp_mail( $contact_email, $admin_subject, $admin_message, $headers );
        }

        if ( ! $sent ) {
            wp_send_json_error( array( 'message' => 'The message could not be sent. Check the WordPress mail configuration.' ), 500 );
        }
        wp_send_json_success( array( 'message' => 'Email sent successfully.' ) );
    }

    function get_favorite_posts() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }

        $ids   = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
        $per_page = 6; // Adjust per page limit

        if (empty($ids)) {
            wp_send_json_success([
                'html' => '<p>No favourites selected.</p>',
                'has_more' => false,
            ]);
        }

        // Slice IDs for pagination
        // $offset = ($paged - 1) * $per_page;
        // $paged_ids = array_slice($ids, $offset, $per_page);

        $args = [
            'post_type'      => 'property',
            'post_status'    => 'publish',
            'orderby'        => 'post__in',
            'post__in'       => $ids,
        ];

        $results = $this->render_property_list($args);

        ob_start(); ?>
        <?= $results['html']; ?>
        <?php
        $html = ob_get_clean();

        $has_more = !empty($ids) && count($ids) >= $per_page;

        wp_send_json_success([
            'html'     => $html,
            'has_more' => $has_more,
        ]);
    }

    public function property_search_suggest($arrival, $departure) {

        $args = [
            'post_type'      => 'property',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'paged'          => 1,
            's'              => '',
            'orderby'        => 'rand',
            'meta_query'     => [],
        ];

        // Arrival/Departure filter
        if (!empty($arrival) && !empty($departure)) {
            $available = $this->get_guest_days($arrival, $departure);
            $available_ids = wp_list_pluck($available, 'post_id');
            $args['post__in'] = $available_ids;

            $this->available_pricing_data = [];
            foreach ($available as $item) {
                $this->available_pricing_data[$item['post_id']] = [
                    'total_price'      => $item['total_price'],
                    'total_adjusted'   => $item['total_adjusted'] ?? $item['total_price'],
                    'startDate'        => $item['startDate'] ?? $arrival,
                    'endDate'          => $item['endDate'] ?? $departure,
                    'price_is_estimate'=> !empty($item['price_is_estimate']),
                    'currency'         => $item['currency'] ?? get_option( 'guesty_default_currency', 'GBP' ),
                ];
            }

            // Early exit if no available properties
            if (empty($available)) {
                ob_start(); ?>
                <ul id="property-search" style="grid-template-columns: repeat(1, 1fr) !important;">
                    <p style="text-align: center;">No properties available for the selected dates.</p>
                </ul>
                <?php
                return ob_get_clean();
            }
        }

        $results = $this->render_property_list($args);

        ob_start(); ?>
        <ul id="property-search-suggest">
            <?= $results['html']; ?>
        </ul> 
        <?php  

        return ob_get_clean();

    }

    private function enrich_results_with_exact_quotes(array $available, $arrival, $departure, $guests = 1) {
        if (empty($available)) {
            return $available;
        }

        $candidate_ids = array();
        foreach (array_slice($available, 0, 12) as $item) {
            if (!empty($item['guesty_id'])) {
                $candidate_ids[] = $item['guesty_id'];
            }
        }
        if (empty($candidate_ids)) {
            return $available;
        }

        $quotes = $this->quote_service->get_visible_quotes($candidate_ids, $arrival, $departure, max(1, (int)$guests));
        foreach ($available as &$item) {
            $id = $item['guesty_id'] ?? '';
            if (!$id || empty($quotes[$id])) {
                continue;
            }
            $quote = $quotes[$id];
            $item['total_price'] = $quote['total_price'];
            $item['total_adjusted'] = $quote['total_adjusted'];
            $item['is_discounted'] = $quote['is_discounted'];
            $item['currency'] = $quote['currency'];
            $item['price_is_estimate'] = false;
        }
        unset($item);
        return $available;
    }

    private function render_property_list($args = []) {
        $query = new WP_Query($args);
        $locations = [];

        // Grab query params we want to preserve
        $title      = sanitize_text_field($_REQUEST['title'] ?? '');
        $arrival    = sanitize_text_field($_REQUEST['arrival'] ?? '');
        $departure  = sanitize_text_field($_REQUEST['departure'] ?? '');
        $guests     = sanitize_text_field($_REQUEST['guests'] ?? '');

        $query_args = array();
        if ($arrival)   $query_args['arrival'] = $arrival;
        if ($departure) $query_args['departure'] = $departure;
        if ($guests)    $query_args['guests'] = $guests;

        ob_start();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $id = get_the_ID();
                $price_info = $this->available_pricing_data[$id] ?? null;

                // Grab lat/lng
                $lat = get_post_meta($id, 'property_latitude', true);
                $lng = get_post_meta($id, 'property_longitude', true);
                if ($lat && $lng) {
                    $locations[] = [
                        'title' => html_entity_decode( wp_strip_all_tags( get_the_title() ), ENT_QUOTES, 'UTF-8' ),
                        'lat'   => (float) $lat,
                        'lng'   => (float) $lng,
                        'link'  => get_permalink(),
                        'cardID'    => get_the_ID(),
                    ];
                }

                $pictures = maybe_unserialize(get_post_meta($id, 'property_pictures', true));
                $bedrooms = get_post_meta($id, 'property_bedrooms', true);
                $bathrooms = get_post_meta($id, 'property_bathrooms', true);
                $accommodates = get_post_meta($id, 'property_accommodates', true);
                $property_type = get_post_meta($id, 'property_type', true);
                $base_price = get_post_meta($id, 'property_base_price', true);
                $currency = get_post_meta($id, 'property_currency', true);
                $property_title = get_post_meta($id, 'property_title', true);
                $display_title = !empty($property_title) ? $property_title : get_the_title();
                $building_name = get_post_meta($id, 'property_building_name', true);
                $city = get_post_meta($id, 'property_city', true);

                // Create location display logic
                $location_display = '';
                if (!empty($building_name) && !empty($city)) {
                    $location_display = $building_name . ', ' . $city;
                } elseif (!empty($city)) {
                    $location_display = $city;
                } elseif (!empty($building_name)) {
                    $location_display = $building_name;
                }

                $is_discounted = (bool) get_post_meta($id, 'property_has_promotion', true);
                $promo_name    = (string) get_post_meta($id, 'property_promotion_name', true);

                // Generate permalink with only selected query params
                $permalink = get_permalink();
                if (!empty($query_args)) {
                    $permalink = add_query_arg($query_args, $permalink);
                }

                ?>
                <?php $guesty_id = get_post_meta($id, 'guesty_id', true); ?>
                <li data-post-id="<?php the_ID(); ?>" data-guesty-id="<?php echo esc_attr($guesty_id); ?>" data-price-estimate="0" id="<?php the_ID(); ?>">
                    <div class="property-search-wrapper">
                        <p class="property-type"><?php echo esc_html($property_type); ?></p>
                        <div class="property-favorite" data-post-id="<?php the_ID(); ?>">
                            <?php
                            $svg_path = plugin_dir_path(__FILE__) . '../assets/images/heart.svg';
                            if (file_exists($svg_path)) {
                                echo file_get_contents($svg_path);
                            }
                            ?>
                        </div>
                        <div class="property-search-image">
                            <?php 
                            if (!empty($pictures) && is_array($pictures)) {
                                $count = 0;
                                foreach ($pictures as $pic) {
                                    if (!empty($pic['original'])) {
                                        echo '<a href="' . esc_url($permalink) . '" class="property-search-img_box"><img data-lazy="' . esc_url($pic['original']) . '" class="property-search-img" alt=""></a>';
                                        $count++;
                                        if ($count >= 5) break;
                                    }
                                }
                            }
                            ?>
                        </div>
                        <a href="<?php echo esc_url($permalink); ?>" class="property-search-wrapper-row icons-wrapper">
                            <?php if(!empty($bedrooms)) : ?><div class="property-icon"><span class="property-icon-bedrooms"></span> <?php echo esc_html($bedrooms); ?></div><?php endif; ?>
                            <?php if(!empty($bathrooms)) : ?><div class="property-icon"><span class="property-icon-bathrooms"></span> <?php echo esc_html($bathrooms); ?></div><?php endif; ?>
                            <?php if(!empty($accommodates)) : ?><div class="property-icon"><span class="property-icon-accommodates"></span> <?php echo esc_html($accommodates); ?></div><?php endif; ?>
                        </a>
                    </div>
                    <a href="<?php echo esc_url($permalink); ?>" class="property-search-wrapper">
                        <div class="property-search-wrapper-row title-price-wrapper">
                            <div class="property-search-wrapper-col">
                                
                                <div class="property-location-wrapper" <?php echo $is_discounted && !empty($promo_name) ? 'style="margin-bottom:10px;"' : '' ; ?>> 
                                    <?php if (!empty($location_display)): ?>
                                        <p class="property-location"><?php echo esc_html($location_display); ?></p>
                                    <?php endif; ?>
                                    <?php if ($is_discounted && !empty($promo_name)): ?>
                                        <p class="property-promo-name"><?php echo esc_html( $promo_name ); ?></p>
                                    <?php endif; ?>
                                </div>
                                <h2 class="property-title"><?php echo esc_html($display_title); ?></h2>
                                <?php
                                $currency_code = strtoupper( (string) ( $currency ?: get_option( 'guesty_default_currency', 'GBP' ) ) );
                                $currency_symbol = $this->get_currency_symbol( $currency_code );
                                $currency_prefix = in_array( $currency_code, array( 'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'NZD' ), true )
                                    ? $currency_symbol
                                    : $currency_symbol . ' ';
                                $base_price_value = is_numeric( $base_price ) ? (float) $base_price : 0;
                                ?>
                                <?php if ( $base_price_value > 0 ) : ?>
                                    <div class="property-priceDates guesty-stored-price">
                                        <span class="property-devider"></span>
                                        <p class="property-pricingPerNights"><strong>From <?php echo esc_html( $currency_prefix . number_format_i18n( $base_price_value, 2 ) ); ?></strong> per night</p>
                                    </div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </a>
                </li>
                <?php
            }
        } else {

            if(!empty($title) && !empty($arrival) && !empty($departure)) {
                echo '<p style="text-align: center;">The chosen date range is not available for "<strong>'.$title.'</strong>". <br> You can check below some available Properties with the chosen dates.</p>';
                echo '<style> #property-search { grid-template-columns: repeat(1, 1fr) !important; } </style>';

                echo $this->property_search_suggest($arrival, $departure);
            } else {
                echo '<p style="text-align: center;">No properties found matching your criteria.</p>';
                echo '<style> #property-search { grid-template-columns: repeat(1, 1fr) !important; } </style>';
            }
        }

        wp_reset_postdata();

        return [
            'html' => ob_get_clean(),
            'max_num_pages' => $query->max_num_pages,
            'locations'   => $locations,
        ];
    }

    /**
     * Add Favourite short code
     */
    function guesty_single_favorite_shortcode($atts) {
        $atts = shortcode_atts([
            'post_id' => get_the_ID(), // fallback to current post
        ], $atts);

        $post_id = intval($atts['post_id']);

        ob_start();
        ?>
        <li style="list-style:none;">
        <div id="property-search" class="property-single-favorite">
            <div class="property-favorite" data-post-id="<?php echo esc_attr($post_id); ?>">
                <?php
                $svg_path = plugin_dir_path(__FILE__) . '../assets/images/heart.svg';
                if (file_exists($svg_path)) {
                    echo file_get_contents($svg_path);
                }
                ?>
                <!-- Hidden title for JS --> 
                <h2 style="display:none;" class="property-title"><?php echo esc_html(get_the_title($post_id)); ?></h2>
                </div>
            </div>
        </li>
        <?php
        return ob_get_clean();
    }


    /**
     * Add Amenities short code
     */
    function property_featured_amenities($atts = []) {

        $atts = shortcode_atts([
            'epc' => false,
        ], $atts, 'property_featured_amenities');

        $show_epc = filter_var($atts['epc'], FILTER_VALIDATE_BOOLEAN);

        ob_start();

        if (!is_singular()) {
            return '';
        }

        $post_id = get_the_ID();

        $amenities  = get_post_meta($post_id, 'property_amenities', true);
        $epc_rating = get_post_meta($post_id, 'property_epc_ratings', true);

        // Normalize amenities
        if (!empty($amenities)) {
            $amenities = maybe_unserialize($amenities);
        } else {
            $amenities = [];
        }

        if (!is_array($amenities) || empty($amenities)) {
            return '';
        }

        // Normalize EPC
        $epc_rating = !empty($epc_rating) ? strtoupper(trim($epc_rating)) : '';

        $groups = $this->get_amenity_grouped_priority();

        echo '<div class="featured-amenities">';

        foreach ($groups as $group => $group_items) {

            $matched = array_intersect($amenities, $group_items);

            if (!empty($matched)) {

                echo '<div class="amenity-group">';
                echo '<h5>' . esc_html($group) . '</h5>';
                echo '<ul>';

                foreach ($matched as $amenity) {
                    echo '<li>' . esc_html($amenity) . '</li>';
                }

                echo '</ul>';
                echo '</div>';
            }
        }

        // ✅ EPC as its own LAST group
        if ($show_epc && !empty($epc_rating)) {

            echo '<div class="amenity-group epc-group">';
            echo '<h5>' . esc_html('EPC - ' . $epc_rating) . '</h5>';
            echo '</div>';
        }

        echo '</div>';

        return ob_get_clean();
    }

    private function get_amenity_grouped_priority() {

        return [

            'Popular Amenities' => [
                // 'Air conditioning',
                'Bed linens',
                // 'Cable TV',
                'Dryer',
                'Elevator',
                'Essentials',
                // 'Hair dryer',
                // 'Hangers',
                // 'Heating',
                // 'Indoor fireplace',
                // 'Internet',
                'Iron',
                // 'Kitchen',
                'Patio or balcony',
                // 'TV',
                'Washer',
                'Wireless Internet'
            ],

            'Accessibility' => [
                // 'Accessible-height bed ',
                // 'Accessible-height toilet ',
                // 'Disabled parking spot',
                // 'Grab-rails for shower and toilet',
                // 'Grab-rails in toilet',
                // 'Path to entrance lit at night',
                // 'Roll-in shower with shower bench or chair',
                // 'Shower bench',
                // 'Shower chair',
                'Single level home',
                // 'Step-free access',
                // 'Tub with shower bench',
                // 'Wheelchair accessible',
                // 'Wide clearance to bed',
                // 'Wide clearance to shower and toilet',
                // 'Wide doorway',
                // 'Wide hallway clearance',
            ],

            'Bathroom' => [
                // 'Body soap',
                // 'Cleaning products',
                // 'Conditioner',
                // 'Hot water',
                // 'Shampoo',
                // 'Shower gel',
                'Towels provided'
            ],

            // 'Bedroom & Laundry' => [
            //     'Clothing storage',
            //     'Dryer in common space',
            //     'Washer in common space'
            // ],

            'Entertainment' => [
                // 'Dvd player',
                // 'Foosball table',
                // 'Game room',
                // 'Piano',
                // 'Ping pong table',
                // 'Pool table',
                'Sound system'
            ],

            'Family' => [
                // 'Baby bath',
                // 'Baby monitor',
                // 'Babysitter recommendations',
                // 'Bathtub',
                // 'Board games',
                // 'Changing table',
                // 'Children’s books and toys',
                // 'Children’s dinnerware',
                // 'Crib',
                'Family/kid friendly',
                // 'Fireplace guards',
                // 'Game console',
                // 'High chair',
                // 'Outlet covers',
                // 'Pack `n play/travel crib',
                // 'Room-darkening shades',
                // 'Stair gates',
                // 'Table corner guards',
                // 'Window guards'
            ],

            // 'Heating & cooling' => [
            //     'Portable fans'
            // ],

            'Home Safety' => [
                'Carbon monoxide detector',
                'Emergency exit',
                'Fire extinguisher',
                // 'First aid kit',
                'Smoke detector'
            ],

            'Kitchen & Dining' => [
                // 'Baking sheet',
                // 'Barbeque utensils',
                // 'Blender',
                // 'Breakfast',
                // 'Coffee',
                // 'Coffee maker',
                // 'Cookware',
                // 'Dining table',
                // 'Dishes and silverware',
                'Dishwasher',
                'Freezer',
                // 'Ice maker',
                // 'Kettle',
                // 'Microwave',
                // 'Mini fridge',
                // 'Oven',
                // 'Refrigerator',
                // 'Rice maker',
                'Stove',
                // 'Toaster',
                // 'Trash compactor',
                // 'Wine glasses',
            ],

            'Location' => [
                // 'Beach',
                'Beach Front',
                'Beach View',
                // 'Beach access',
                // 'City View',
                // 'Desert View',
                // 'Downtown',
                // 'Garden View',
                // 'Golf course front',
                // 'Golf view',
                // 'Lake',
                // 'Lake Front',
                // 'Lake access',
                // 'Laundromat nearby',
                // 'Mountain',
                // 'Mountain view',
                'Near Ocean',
                // 'Ocean Front',
                // 'Resort',
                // 'Resort access',
                // 'Rural',
                'Sea view',
                // 'Ski In',
                // 'Ski Out',
                // 'Ski In/Ski Out',
                // 'Town',
                // 'Village',
                // 'Water View',
                // 'Waterfront',            
            ],

            // 'Logistics' => [
            //     'Cleaning Disinfection',
            //     'Cleaning before checkout',
            //     'Desk',
            //     'Enhanced cleaning practices',
            //     'High touch surfaces disinfected',
            //     'Laptop friendly workspace',
            //     'Long term stays allowed',
            //     'Luggage dropoff allowed',
            // ],

            // 'Nearby Activities' => [
            //     'Cycling',
            //     'Fishing',
            //     'Golf - Optional',
            //     'Horseback Riding',
            //     'Museums',
            //     'Shopping',
            //     'Theme Parks',
            //     'Water Parks',
            //     'Water Sports',
            //     'Zoo'
            // ],

            'Outdoor' => [
                // 'BBQ grill',
                // 'Beach essentials',
                // 'Bicycles available',
                // 'Bikes',
                // 'Boat slip',
                // 'Doorman',
                // 'Fire Pit',
                // 'Garden or backyard',
                // 'Hammock',
                // 'Kayak',
                // 'Outdoor kitchen',
                'Outdoor seating (furniture)',
                // 'River',
            ],

            'Parking' => [
                'Free parking on premises',
                // 'Free parking on street',
                // 'Garage',
                // 'Paid parking',
                // 'Paid parking off premises',
            ],

            // 'Pool' => [
            //     'Communal pool',
            //     'Indoor pool',
            //     'Outdoor pool',
            //     'Private pool',
            //     'Rooftop pool',
            //     'Swimming pool',
            // ],

            // 'Services & Extras' => [
            //     'Ceiling fan',
            //     'EV charger',
            //     'Extra pillows and blankets',
            //     'Pocket Wifi',
            //     'Private entrance',
            //     'Safe',
            //     'Stereo system',
            // ],

            // 'Wellness' => [
            //     'Gym',
            //     'Hot tub',
            //     'Sauna',
            //     'Spa',
            // ],
            
        ];
    }

    function property_amenities() {
        ob_start(); 
        // Get amenities
        $amenities = get_post_meta( get_the_ID(), 'property_amenities', true );
        if (!empty($amenities)) {
            $amenities = maybe_unserialize($amenities);
        } else {
            $amenities = array();
        }

        if ( is_singular() && !empty($amenities) ) {

            // Filter out location attractions
            $location_keywords = $this->get_location_attraction_keywords();
            $amenities = array_filter($amenities, function($amenity) use ($location_keywords) {
                if (!is_string($amenity)) {
                    return true;
                }
                foreach ($location_keywords as $keyword) {
                    if (stripos($amenity, $keyword) !== false) {
                        return false;
                    }
                }
                return true;
            });
            
            $priority_amenities = $this->get_amenity_priority();
            usort($amenities, function($a, $b) use ($priority_amenities) {
                // Ensure we are comparing strings
                if (!is_string($a)) return 1;
                if (!is_string($b)) return -1;

                $a_pos = array_search($a, $priority_amenities);
                $b_pos = array_search($b, $priority_amenities);

                if ($a_pos !== false && $b_pos !== false) {
                    return $a_pos <=> $b_pos; // Both in priority list, sort by position
                } elseif ($a_pos !== false) {
                    return -1; // a is in priority list, b is not
                } elseif ($b_pos !== false) {
                    return 1; // b is in priority list, a is not
                } else {
                    return strcasecmp($a, $b); // Neither is in priority list, sort alphabetically
                }
            });
            
            ?>
            <ul class="single-post-amenities">
                <?php foreach ($amenities as $amenity): ?>
                    <?php if (is_string($amenity)): ?>
                        <li><?php echo esc_html($amenity); ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * Defines the priority order for amenities.
     *
     * @return array The list of prioritized amenities.
     */
    private function get_amenity_priority() {
        return [
            // Connectivity & Climate
            'Wireless Internet',
            'Internet',
            'Heating',

            // Kitchen & Dining
            'Kitchen',
            'Refrigerator',
            'Freezer',
            'Microwave',
            'Oven',
            'Stove',
            'Dishwasher',
            'Dining table',
            'Cookware',
            'Dishes and silverware',
            'Toaster',
            'Kettle',
            'Wine glasses',

            // Bedroom & Bathroom
            'Essentials',
            'Bed linens',
            'Towels provided',
            'Hot water',
            'Bathtub',
            'Hair dryer',
            'Iron',
            'Hangers',
            'Clothing storage',

            // Laundry
            'Washer',
            'Dryer',

            // Family & Accessibility
            'Pets allowed',
            'Suitable for children (2-12 years)',
            'Suitable for infants (under 2 years)',
            'Elevator',
            'Single level home',
            'Path to entrance lit at night',
            'Luggage dropoff allowed',

            // Entertainment & Outdoor
            'TV',
            'Patio or balcony',

            // Safety & Cleaning
            'Smoke detector',
            'Carbon monoxide detector',
            'Fire extinguisher',
            'Emergency exit',
            'Enhanced cleaning practices',
            'Cleaning Disinfection',
            'High touch surfaces disinfected',

            // Lower Priority Consumables
            'Baking sheet',
            'Shampoo',
            'Body soap',
            'Shower gel',
            'Conditioner',
            'Cleaning products',

            // Activities
            'Cycling',
            'Fishing',
            'Golf - Optional',
            'Horseback Riding',
            'Water Sports',
        ];
    }

    /**
     * Defines keywords to identify and filter out location-based amenities.
     *
     * @return array The list of keywords for location attractions.
     */
    private function get_location_attraction_keywords() {
        return [
            'beach', 'park', 'museum', 'restaurant', 'airport', 'station',
            'downtown', 'lake', 'river', 'mountain', 'view', 'near',
            'access', 'attraction', 'centre', 'center', 'theatre', 'theater',
            'zoo', 'ocean', 'cinema', 'gallery', 'harbor', 'harbour', 'shop', 'market',
            'town',
        ];
    }

    function threesixty() {
        global $post;
        $threesixty = get_post_meta(get_the_ID(), 'property_360_video_link', true);

        if(empty($threesixty)) { ?>
             <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const wrapper = document.getElementById('threeSixty');
                    if (wrapper) wrapper.style.display = 'none';
                });
            </script>
        <?php
        }
    }

    function floorplan() {
        global $post;
        $floorplan = get_post_meta(get_the_ID(), 'property_floorPlan_img', true);

        if(!empty($floorplan)) {
            ?>
            <div id="floorPlan_modal" class="floorPlan" onclick="closeFloorPlan(event)">
                <span class="floorPlan_close" onclick="closeFloorPlan(event)"></span>
                <img src="<?php echo $floorplan; ?>" alt="" srcset="">
            </div>

            <style>
                .floorPlan {
                    position: fixed;
                    top: 0;
                    left: 0;
                    z-index: 99;
                    width: 100%;
                    height: 100vh;
                    background: #000000c2;
                    display: none;
                    justify-content: center;
                    align-items: center;
                }

                .floorPlan img {
                    width: 50%;
                    object-fit: contain;
                    height: 85vh;
                }

                span.floorPlan_close::after,
                span.floorPlan_close::before {
                    content: '';
                    position: absolute;
                    top: 50%;
                    width: 100%;
                    height: 2px;
                    background: white;
                }

                span.floorPlan_close::after {
                    transform: rotate(45deg);
                }

                span.floorPlan_close::before {
                    transform: rotate(135deg);
                }

                span.floorPlan_close {
                    position: absolute;
                    width: 20px;
                    height: 20px;
                    display: block;
                    top: 2%;
                    right: 2%;
                    cursor: pointer;
                }

                @media (max-width: 768px) {
                    .floorPlan img {
                        width: 90%;
                        object-fit: contain;
                        height: 85vh;
                    }
                }
            </style>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const wrapper = document.getElementById('openFloorPlan');
                    
                    if (wrapper) {
                        const link = wrapper.querySelector('a');

                        if (link) {
                            link.addEventListener('click', function (e) {
                                e.preventDefault(); 
                                openFloorPlan();
                            });
                        }
                    }
                });

                function openFloorPlan() {
                    const modal = document.getElementById('floorPlan_modal');
                    
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }

                function closeFloorPlan(event) {
                    if (!event || event.target.id === 'floorPlan_modal' || event.target.className === 'floorPlan_close') {
                        document.getElementById('floorPlan_modal').style.display = 'none';
                        document.body.style.overflow = 'auto'; // Restore scrolling
                    }
                }
            </script>
            <?php
        } else { ?>
             <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const wrapper = document.getElementById('openFloorPlan');
                    if (wrapper) wrapper.style.display = 'none';
                });
            </script>
            <?php
        }
    }

    /**
     * Add Gallery short code
     */
    function property_gallery($atts) {
        $show = isset($atts['show']) ? intval($atts['show']) : 6;

        ob_start();  
        // Get images
        $pictures = get_post_meta(get_the_ID(), 'property_pictures', true);
        if (!empty($pictures)) {
            $pictures = maybe_unserialize($pictures);
        } else {
            $pictures = array();
        }

        if (is_singular() && !empty($pictures)) {  
            ?>
            <div class="single-post-pictures">
                <?php 
                foreach ($pictures as $index => $picture): 
                    if (!empty($picture['original'])):
                        $hidden_class = $index >= $show ? 'hidden-image' : '';
                        ?>
                        <img src="<?php echo esc_url($picture['original']); ?>" alt="" class="gallery-image <?php echo esc_attr($hidden_class); ?>" data-lightbox-index="<?php echo $index; ?>" onclick="openLightbox(<?php echo $index; ?>)">
                        <?php 
                    endif;
                endforeach; 
                ?>
            </div>
            <?php if (count($pictures) > $show): ?>
                <button class="toggle-gallery-button" onclick="toggleGallery(this)">See More</button>
            <?php endif; ?>

            <!-- Lightbox Modal -->
            <div id="lightbox-modal" class="lightbox-modal" onclick="closeLightbox(event)">
                <div class="lightbox-content">
                    <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
                    <img id="lightbox-image" src="" alt="">
                    <div class="lightbox-nav">
                        <button class="lightbox-prev" onclick="changeLightboxImage(-1)">&#10094;</button>
                        <button class="lightbox-next" onclick="changeLightboxImage(1)">&#10095;</button>
                    </div>
                    <div class="lightbox-counter">
                        <span id="lightbox-current">1</span> / <span id="lightbox-total"><?php echo count($pictures); ?></span>
                    </div>
                </div>
            </div>

            <style>
                .gallery-image {
                    cursor: pointer;
                    transition: transform 0.2s ease;
                }
                
                .gallery-image:hover {
                    transform: scale(1.05);
                }
                
                .hidden-image {
                    display: none;
                }
                
                .lightbox-modal {
                    display: none;
                    position: fixed;
                    z-index: 9999;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    background-color: rgba(0, 0, 0, 0.9);
                    animation: fadeIn 0.3s ease;
                }
                
                .lightbox-content {
                    position: relative;
                    margin: auto;
                    padding: 20px;
                    width: 90%;
                    max-width: 1200px;
                    height: 100%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-direction: column;
                }
                
                #lightbox-image {
                    max-width: 100%;
                    max-height: 80vh;
                    object-fit: contain;
                    border-radius: 8px;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
                }
                
                .lightbox-close {
                    position: absolute;
                    top: 20px;
                    right: 35px;
                    color: #fff;
                    font-size: 40px;
                    font-weight: bold;
                    cursor: pointer;
                    z-index: 10000;
                    transition: color 0.3s ease;
                }
                
                .lightbox-close:hover {
                    color: #ccc;
                }
                
                .lightbox-nav {
                    position: absolute;
                    top: 50%;
                    transform: translateY(-50%);
                    width: 100%;
                    display: flex;
                    justify-content: space-between;
                    padding: 0 20px;
                    pointer-events: none;
                }
                
                .lightbox-prev,
                .lightbox-next {
                    background: rgba(255, 255, 255, 0.2);
                    border: none;
                    color: black;
                    font-size: 24px;
                    padding: 15px 20px;
                    cursor: pointer;
                    border-radius: 50%;
                    transition: background 0.3s ease;
                    pointer-events: auto;
                }
                
                .lightbox-prev:hover,
                .lightbox-next:hover {
                    background: rgba(255, 255, 255, 0.4);
                }
                
                .lightbox-counter {
                    position: absolute;
                    bottom: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    color: white;
                    font-size: 16px;
                    background: rgba(0, 0, 0, 0.5);
                    padding: 8px 16px;
                    border-radius: 20px;
                }
                
                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                
                @media (max-width: 768px) {
                    .lightbox-close {
                        font-size: 30px;
                        top: 10px;
                        right: 20px;
                    }
                    
                    .lightbox-prev,
                    .lightbox-next {
                        font-size: 18px;
                        padding: 10px 15px;
                    }
                    
                    .lightbox-counter {
                        font-size: 14px;
                        bottom: 10px;
                    }
                }
            </style>

            <script>
                let currentLightboxIndex = 0;
                const lightboxImages = <?php echo json_encode(array_values(array_filter($pictures, function($pic) { return !empty($pic['original']); }))); ?>;
                
                function openLightbox(index) {
                    currentLightboxIndex = index;
                    const modal = document.getElementById('lightbox-modal');
                    const image = document.getElementById('lightbox-image');
                    const current = document.getElementById('lightbox-current');
                    
                    if (lightboxImages[index] && lightboxImages[index].original) {
                        image.src = lightboxImages[index].original;
                        current.textContent = index + 1;
                        modal.style.display = 'block';
                        document.body.style.overflow = 'hidden'; // Prevent background scrolling
                    }
                }
                
                function closeLightbox(event) {
                    if (!event || event.target.id === 'lightbox-modal' || event.target.className === 'lightbox-close') {
                        document.getElementById('lightbox-modal').style.display = 'none';
                        document.body.style.overflow = 'auto'; // Restore scrolling
                    }
                }
                
                function changeLightboxImage(direction) {
                    currentLightboxIndex += direction;
                    
                    if (currentLightboxIndex >= lightboxImages.length) {
                        currentLightboxIndex = 0;
                    } else if (currentLightboxIndex < 0) {
                        currentLightboxIndex = lightboxImages.length - 1;
                    }
                    
                    openLightbox(currentLightboxIndex);
                }
                
                function toggleGallery(button) {
                    const container = button.previousElementSibling;
                    const hiddenImages = container.querySelectorAll('.gallery-image');
                    let isExpanded = button.classList.toggle('expanded');

                    hiddenImages.forEach((img, index) => {
                        if (index >= <?php echo $show; ?>) {
                            img.style.display = isExpanded ? 'inline-block' : 'none';
                        }
                    });

                    button.textContent = isExpanded ? 'See Less' : 'See More';
                }
                
                // Close lightbox with ESC key
                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape') {
                        closeLightbox();
                        closeFloorPlan();
                    } else if (event.key === 'ArrowLeft') {
                        changeLightboxImage(-1);
                    } else if (event.key === 'ArrowRight') {
                        changeLightboxImage(1);
                    }
                });
            </script>
            <?php
        }

        return ob_get_clean();
    }


    /**
     * Add Calendar short code
     */
    function property_calendar() {  
        // $listing_id = '680f8c69a543ec00100b204b'; // Fallback Guesty ID sample only
        $listing_id = '';
        if ( is_singular('property') ) {
            $listing_id = (get_post_meta( get_the_ID(), 'guesty_id', true )) ? get_post_meta( get_the_ID(), 'guesty_id', true ) : ''; // Fallback Guesty ID sample only
        } 
        
        if( isset($_GET['listing_id']) ) {
            $listing_id = sanitize_text_field($_GET['listing_id']);
        }

        if ( empty($listing_id) ) {
            return '<div class="calendar-error">Missing listing ID</div>';
        }

        $arrival_date = null;
        $departure_date = null;

        if ( isset($_GET['departure']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['departure']) ) {
            $departure_date = sanitize_text_field($_GET['departure']);
        }

        if ( isset($_GET['arrival']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['arrival']) ) {
            $arrival_date = sanitize_text_field($_GET['arrival']);
            $timestamp = strtotime($arrival_date);
            $month = wp_date('m', $timestamp);
            $year = wp_date('Y', $timestamp);
        } else {
            $month = wp_date('m');
            $year = wp_date('Y');
        }
        
        // Get the first day of the month
        $firstOfMonth = strtotime("{$year}-{$month}-01");

        // Move $startDate to the Sunday before (or same day if already Sunday)
        $startDate = wp_date('Y-m-d', strtotime('last sunday', $firstOfMonth));
        if (wp_date('w', $firstOfMonth) == 0) {
            // If the 1st is Sunday, "last sunday" gives previous week, so correct that
            $startDate = wp_date('Y-m-d', $firstOfMonth);
        }

        // Get the first day of next month
        $firstOfNextMonth = strtotime("+1 month", $firstOfMonth);

        // Move $endDate to the Saturday after end of next month
        $endOfCalendar = strtotime("+1 month", $firstOfNextMonth); // End of next month
        $endDate = wp_date('Y-m-d', strtotime('next saturday', $endOfCalendar));

        // Critical optimisation: do not call Guesty or render a live calendar
        // during the initial page response. The calendar is requested only after
        // the visitor opens the date picker/availability widget.
        $html  = '<div class="calendar-wrapper guesty-calendar-lazy"';
        $html .= ' data-listing-id="' . esc_attr( $listing_id ) . '"';
        $html .= ' data-month="' . esc_attr( $month ) . '"';
        $html .= ' data-year="' . esc_attr( $year ) . '"';
        $html .= ' data-arrival="' . esc_attr( (string) $arrival_date ) . '"';
        $html .= ' data-departure="' . esc_attr( (string) $departure_date ) . '">';
        $html .= '<button type="button" class="guesty-calendar-open" aria-expanded="false">' . esc_html__( 'Check availability', 'guesty-properties-sync' ) . '</button>';
        $html .= '<div id="calendar-loader" class="guesty-calendar-skeleton" style="display:none;" aria-hidden="true">';
        $html .= '<span></span><span></span><span></span><span></span>';
        $html .= '</div>';
        $html .= '<div id="calendar-content" aria-live="polite"></div>';
        $html .= '</div>';

        return $html;
    }

    public function guesty_load_calendar() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error('Invalid nonce', 403);
        }
        if ( ! $this->allow_public_request( 'calendar', 30, MINUTE_IN_SECONDS ) ) {
            wp_send_json_error( array( 'message' => 'Too many calendar requests. Please retry shortly.' ), 429 );
        }

        if (!isset($_POST['month'], $_POST['year'], $_POST['listing_id'])) {
            wp_send_json_error('Missing parameters');
        }

        $month = intval($_POST['month']);
        $year = intval($_POST['year']);
        $listing_id = sanitize_text_field($_POST['listing_id']);
        if ( $month < 1 || $month > 12 || $year < (int) wp_date( 'Y' ) - 1 || $year > (int) wp_date( 'Y' ) + 5 ) {
            wp_send_json_error( array( 'message' => 'Invalid calendar month.' ), 400 );
        }
        if ( ! $this->is_local_listing_id( $listing_id ) ) {
            wp_send_json_error( array( 'message' => 'Unknown property.' ), 404 );
        }

        $arrival_date = isset($_POST['arrival_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['arrival_date'])
            ? sanitize_text_field($_POST['arrival_date'])
            : null;

        $departure_date = isset($_POST['departure_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['departure_date'])
            ? sanitize_text_field($_POST['departure_date'])
            : null;

        // Calculate start and end date with padding
        $firstOfMonth = strtotime("{$year}-{$month}-01");

        // Move start date to the Sunday before (or same day if already Sunday)
        $start_date = wp_date('Y-m-d', strtotime('last sunday', $firstOfMonth));
        if (wp_date('w', $firstOfMonth) == 0) {
            $start_date = wp_date('Y-m-d', $firstOfMonth); // Already Sunday
        }

        // Get first day of next month
        $firstOfNextMonth = strtotime("+1 month", $firstOfMonth);

        // Move end date to the Saturday after the next month
        $endOfCalendar = strtotime("+1 month", $firstOfNextMonth); // End of next month
        $end_date = wp_date('Y-m-d', strtotime('next saturday', $endOfCalendar));

        // Fetch calendar data
        $calendar_data = $this->calendar_service->get_calendar($listing_id, $start_date, $end_date, true);

        // Render calendar HTML
        $html = $this->render_static_calendar($calendar_data, $month, $year, $listing_id, $arrival_date, $departure_date);

        echo $html;
        wp_die();
    }


    private function render_static_calendar($calendar_data, $month, $year, $listing_id, $arrival_date = null, $departure_date = null) {
        $calendar = array_fill(0, 6, array_fill(0, 7, null));

        $first_day_of_month = strtotime("{$year}-{$month}-01");
        $days_in_month = wp_date('t', $first_day_of_month);
        $start_day = wp_date('w', $first_day_of_month); // 0 (Sun) - 6 (Sat)

        // Previous and next month calculations
        $prev_month = $month - 1;
        $prev_year = $year;
        if ($prev_month < 1) {
            $prev_month = 12;
            $prev_year--;
        }
        $next_month = $month + 1;
        $next_year = $year;
        if ($next_month > 12) {
            $next_month = 1;
            $next_year++;
        }

        $days_in_prev_month = wp_date('t', strtotime("{$prev_year}-{$prev_month}-01"));

        // Fill the calendar grid
        $day = 1;
        for ($week = 0; $week < 6; $week++) {
            for ($day_of_week = 0; $day_of_week < 7; $day_of_week++) {
                $cell_index = $week * 7 + $day_of_week;
                $calendar_date = null;

                if ($cell_index < $start_day) {
                    // Pad with previous month
                    $prev_day = $days_in_prev_month - $start_day + $cell_index + 1;
                    $calendar_date = sprintf('%04d-%02d-%02d', $prev_year, $prev_month, $prev_day);
                } elseif ($day <= $days_in_month) {
                    $calendar_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $day++;
                } else {
                    // Pad with next month
                    $next_day = $cell_index - $start_day - $days_in_month + 1;
                    $calendar_date = sprintf('%04d-%02d-%02d', $next_year, $next_month, $next_day);
                }
 
                $calendar[$week][$day_of_week] = $calendar_date;
            }
        }

        // Handle error
        if (is_wp_error($calendar_data)) {
            return '<div class="guesty-calendar-error">Error loading calendar: ' . esc_html($calendar_data->get_error_message()) . '</div>';
        }

        // Process availability and reservation ranges
        $available_days = [];
        $minNights = [];
        $reservation_ranges = [];
        $block_ranges = [];
        $ownerReservation = false;

        foreach ($calendar_data['data']['days'] ?? [] as $day_data) {

            $date = $day_data['date'];
            $available_days[$date] = $day_data['status'] ?? 'unavailable';
            $minNights[$date] = $day_data['minNights'];

            $blocks = $day_data['blocks'] ?? [];

            // 🔴 Detect owner reservation / blocks
            $has_owner_block = false;

            // Reservations (existing logic)
            foreach (['reservation', 'ownersReservation'] as $type) {
                if (!empty($day_data[$type]['_id'])) {
                    $res_id = $day_data[$type]['_id'];
                    $has_owner_block = true;

                    if (!isset($reservation_ranges[$res_id])) {
                        $reservation_ranges[$res_id] = [
                            'check_in'  => $day_data[$type]['checkInDateLocalized'],
                            'check_out' => $day_data[$type]['checkOutDateLocalized'],
                        ];
                    }
                }
            }

            // 🔴 FAST check via blocks (NEW)
            if (!empty($blocks['o']) || !empty($blocks['m'])) {
                $has_owner_block = true;
            }

            // 🔴 Fallback via blockRefs (existing + improved)
            foreach ($day_data['blockRefs'] ?? [] as $block) {
                if (in_array($block['type'], ['m', 'o'])) {
                    $has_owner_block = true;

                    $block_id = $block['_id'] ?? md5($block['startDate'] . $block['endDate']);

                    if (!isset($block_ranges[$block_id])) {
                        $block_ranges[$block_id] = [
                            'start' => substr($block['startDate'], 0, 10),
                            'end'   => date('Y-m-d', strtotime($block['endDate'] . ' +1 day')),
                        ];
                    }
                }
            }

            // 🟡 Advance notice detection
            $has_advance_notice_block = false;
            $advance_notice_hours = null;

            foreach ($day_data['blockRefs'] ?? [] as $block) {
                if ($block['type'] === 'an') {
                    $has_advance_notice_block = true;
                    $advance_notice_hours = $block['hours'] ?? null;
                    break;
                }
            }

            $tz = wp_timezone();

            $current_time = new DateTime('now', $tz);
            $cutoff_time = new DateTime('today 18:00', $tz);

            $current_date_ams = $current_time->format('Y-m-d');
            $is_today = ($date === $current_date_ams);
            $is_before_cutoff = $current_time < $cutoff_time;

            
            // 🎯 PRIORITY LOGIC (FIXED)
            if ($has_owner_block) {
                // 🔴 ALWAYS wins
                $available_days[$date] = 'unavailable';

            } elseif ($is_today && $has_advance_notice_block) {

                // Optional: dynamic cutoff using hours
                // if ($advance_notice_hours !== null) {
                //     $cutoff_hour_24 = (int) $advance_notice_hours + 12;
                //     $cutoff_time = new DateTime("today {$cutoff_hour_24}:00", $tz);
                //     $is_before_cutoff = $current_time < $cutoff_time;
                // }

                if ($is_before_cutoff) {
                    $available_days[$date] = 'available';
                } else {
                    $available_days[$date] = 'unavailable';
                }
            }

            // echo "<pre>";
            // echo "DATE: $date\n";
            // echo "AMS TODAY: $current_date_ams\n";
            // echo "NOW: " . $current_time->format('H:i') . "\n";
            // echo "CUTOFF: " . $cutoff_time->format('H:i') . "\n";
            // echo "BEFORE CUTOFF: " . ($is_before_cutoff ? 'YES' : 'NO') . "\n";
            // echo "STATUS: " . ($available_days[$date] ?? 'NULL') . "\n";
            // echo "</pre>";
        }

        // Determine if previous button should be disabled
        $current_month = (int) wp_date('n');
        $current_year = (int) wp_date('Y');
        $is_current_month = ((int) $month === $current_month && (int) $year === $current_year);
        $prev_disabled_attr = $is_current_month ? 'disabled aria-disabled="true" tabindex="-1"' : '';

        // Build calendar table HTML
        $html = '<table class="calendar" data-listing-id="' . esc_attr($listing_id) . '">';
        $html .= '<thead><tr>';
        $html .= '<th><button class="nav-btn" data-month="' . $prev_month . '" data-year="' . $prev_year . '" data-listing-id="' . esc_attr($listing_id) . '" ' . $prev_disabled_attr . '><span class="nav-btn_prev"></span></button></th>';
        
        $html .= '<th colspan="5">';
        $html .= '<select class="calendar-month-selector" data-listing-id="' . esc_attr($listing_id) . '">';

        $now = new DateTime(); 
        $start_month = (int) $now->format('n');
        $start_year = (int) $now->format('Y');

        // Build month selector based on the current viewed month, not today's date
        $start = DateTime::createFromFormat('Y-n-j', "{$year}-{$month}-1")->setTime(0, 0);

        for ($i = 0; $i < 12; $i++) {
            $option_month = (int) $start->format('n');
            $option_year = (int) $start->format('Y');
            $selected = ($option_month === (int) $month && $option_year === (int) $year) ? 'selected' : '';

            $html .= '<option value="' . esc_attr($option_month) . '-' . esc_attr($option_year) . '" ' . $selected . '>';
            $html .= esc_html($start->format('F Y'));
            $html .= '</option>';

            // Safe increment to next month
            $start->modify('first day of next month');
        }

        $html .= '</select>';
        $html .= '</th>';

        $html .= '<th><button class="nav-btn" data-month="' . $next_month . '" data-year="' . $next_year . '" data-listing-id="' . esc_attr($listing_id) . '"><span class="nav-btn_next"></span></button></th>';
        $html .= '</tr><tr>';
        $html .= '<th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>';
        $html .= '</tr></thead><tbody>';

        // Render calendar days
        foreach ($calendar as $week) {
            $html .= '<tr>';
            foreach ($week as $date) {
                $classes = [];

                $timestamp = strtotime($date);
                $day_num = (int) wp_date('j', $timestamp);
                $cell_month = (int) wp_date('n', $timestamp);
                $cell_year = (int) wp_date('Y', $timestamp);

                $today = wp_date('Y-m-d');
                $tomorrow = wp_date('Y-m-d', strtotime('+1 day'));
                $is_future_or_today = $date >= $today;

                $status = $available_days[$date] ?? 'unavailable';
                $minNight = $minNights[$date];

                if ($cell_month !== (int) $month) {
                    $classes[] = 'pad-day';
                }

                if ($is_future_or_today) {
                    $classes[] = $status === 'available' ? 'available' : 'unavailable';
                } else {
                    $classes[] = $status === 'available' ? 'past' : 'unavailable';
                }

                foreach ($reservation_ranges as $range) {
                    if ($date === $range['check_in']) {
                        $classes[] = 'check-in';
                    } elseif ($date === $range['check_out']) {
                        $classes[] = 'check-out';
                    } elseif ($date > $range['check_in'] && $date < $range['check_out']) {
                        $classes[] = 'in-reservation-range';
                    }
                }

                foreach ($block_ranges as $range) {
                    if ($date === $range['start']) {
                        $classes[] = 'block-start';
                    } elseif ($date === $range['end']) {
                        $classes[] = 'block-end';
                    } elseif ($date > $range['start'] && $date <= $range['end']) {
                        $classes[] = 'in-block-range';
                    }
                }

                if ($arrival_date && $departure_date) {
                    if ($date === $arrival_date) {
                        $classes[] = 'selected-check-in';
                    } elseif ($date === $departure_date) {
                        $classes[] = 'selected-check-out';
                    } elseif ($date > $arrival_date && $date < $departure_date) {
                        $classes[] = 'selected-range';
                    }
                }

                if ($date === $current_date_ams && $is_before_cutoff) {
                    $classes[] = 'same-day-allowed';
                } else {
                    if ($date === $today) {
                        $classes[] = 'today';
                    }
                    if ($date === $tomorrow && $current_time >= $cutoff_time) {
                        $classes[] = 'tomorrow';
                    }
                }
                

                $html .= "<td class='" . esc_attr(implode(' ', $classes)) . "' data-date='{$date}' data-minnight='{$minNight}'>{$day_num}</td>";
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function is_local_listing_id( $listing_id ) {
        $listing_id = trim( (string) $listing_id );
        if ( '' === $listing_id ) {
            return false;
        }
        $map = $this->search_service->get_listing_map();
        return ! empty( $map['post_map'][ $listing_id ] );
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

    public function get_currency_symbol($code) {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'AUD' => 'A$',
            'CAD' => 'C$',
            'NZD' => 'NZ$',
            'CHF' => 'CHF',
        ];
    
        $code = strtoupper( sanitize_text_field( (string) $code ) );
        return isset($symbols[$code]) ? $symbols[$code] : $code;
    }
    public function guesty_check_availability() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }
        if ( ! $this->allow_public_request( 'availability', 20, MINUTE_IN_SECONDS ) ) {
            wp_send_json_error( array( 'message' => 'Too many availability requests. Please retry shortly.' ), 429 );
        }

        $startDate = sanitize_text_field($_POST['startDate'] ?? '');
        $endDate   = sanitize_text_field($_POST['endDate'] ?? '');
        $listingId = sanitize_text_field($_POST['listingId'] ?? '');
        $start = DateTimeImmutable::createFromFormat( '!Y-m-d', $startDate );
        $end = DateTimeImmutable::createFromFormat( '!Y-m-d', $endDate );
        if ( ! $start || ! $end || $start >= $end ) {
            wp_send_json_error( array( 'message' => 'Invalid stay dates.' ), 400 );
        }
        if ( ! $this->is_local_listing_id( $listingId ) ) {
            wp_send_json_error( array( 'message' => 'Unknown property.' ), 404 );
        }

        $response = $this->calendar_service->get_calendar($listingId, $startDate, $endDate, true);
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()), 503);
        }

        wp_send_json_success($response);
    }
    public function guesty_booking_data() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }
        if ( ! $this->allow_public_request( 'booking_quote', 15, MINUTE_IN_SECONDS ) ) {
            wp_send_json_error( array( 'message' => 'Too many pricing requests. Please retry shortly.' ), 429 );
        }

        $body = array(
            'checkInDateLocalized'  => sanitize_text_field($_POST['checkInDateLocalized'] ?? ''),
            'checkOutDateLocalized' => sanitize_text_field($_POST['checkOutDateLocalized'] ?? ''),
            'guestsCount'           => max(1, intval($_POST['guestsCount'] ?? 1)),
            'listingId'             => sanitize_text_field($_POST['listingId'] ?? ''),
            'source'                => sanitize_text_field($_POST['source'] ?? 'website'),
            'couponCode'            => sanitize_text_field($_POST['couponCode'] ?? ''),
            'numberOfGuests'        => array(
                'numberOfChildren' => max(0, intval($_POST['numberOfGuests']['numberOfChildren'] ?? 0)),
                'numberOfInfants'  => max(0, intval($_POST['numberOfGuests']['numberOfInfants'] ?? 0)),
                'numberOfAdults'   => max(1, intval($_POST['numberOfGuests']['numberOfAdults'] ?? 1)),
                'numberOfPets'     => max(0, intval($_POST['numberOfGuests']['numberOfPets'] ?? 0)),
            ),
        );

        if ( ! $this->is_local_listing_id( $body['listingId'] ) ) {
            wp_send_json_error( array( 'message' => 'Unknown property.' ), 404 );
        }

        $response = $this->quote_service->get_quote($body);
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()), 503);
        }

        wp_send_json_success($response);
    }

    // public function guesty_booking_reservation() {
    //     // Verify nonce
    //     if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
    //         wp_send_json_error(['message' => 'Invalid nonce']);
    //     }

    //     $quoteId = sanitize_text_field($_POST['quoteId']);
    //     $ratePlanId = sanitize_text_field($_POST['ratePlanId']);
    //     $paymentMethodId = sanitize_text_field($_POST['stripe_payment_method_id'] ?? '');
    //     $stripeCustomerId = sanitize_text_field($_POST['stripe_customer_id'] ?? '');
    //     $guestData = json_decode(stripslashes($_POST['guest']), true);

    //     if (!$paymentMethodId || !$stripeCustomerId) {
    //         wp_send_json_error(['message' => 'Missing Stripe payment details.']);
    //     }

    //     $guest = [
    //         'firstName' => sanitize_text_field($guestData['firstName']),
    //         'lastName'  => sanitize_text_field($guestData['lastName']),
    //         'email'     => sanitize_email($guestData['email']),
    //         'phones'    => [sanitize_text_field($guestData['phone'])],
    //         'address'   => [
    //             'street'  => sanitize_text_field($guestData['address']['street']),
    //             'zipCode' => sanitize_text_field($guestData['address']['zipCode']),
    //             'city'    => sanitize_text_field($guestData['address']['city']),
    //             'state'   => sanitize_text_field($guestData['address']['state']),
    //             'country' => sanitize_text_field($guestData['address']['country']),
    //         ]
    //     ];

    //     $body = [
    //         'status'                    => 'confirmed',
    //         'reservedUntil'             => -1,
    //         'guest'                     => $guest,
    //         'ratePlanId'                => $ratePlanId,
    //         'quoteId'                   => $quoteId,
    //         'stripe_payment_method_id'  => $paymentMethodId,
    //         'stripe_customer_id'        => $stripeCustomerId,
    //     ];

    //     $response = $this->api->request('reservations-v3/quote', 'POST', $body);

    //     if (is_wp_error($response)) {
    //         wp_send_json_error(['message' => $response->get_error_message()]);
    //     } else {
    //         $reservationId  = $response['reservationId'];
    //         $updateBody     = ['plannedDeparture' => '12:00'];
    //         $updateResponse = $this->api->request("reservations-v3/{$reservationId}/dates", 'PUT', $updateBody);
    //     }

    //     wp_send_json_success($response);
    // }

    public function guesty_booking_reservation() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        if ( ! $this->allow_public_request( 'reservation', 6, MINUTE_IN_SECONDS ) ) {
            wp_send_json_error( array( 'message' => 'Too many booking attempts. Please retry shortly.' ), 429 );
        }

        $listing_id         = sanitize_text_field($_POST['listingId'] ?? '');
        if ( ! $this->is_local_listing_id( $listing_id ) ) {
            wp_send_json_error( array( 'message' => 'Unknown property.' ), 404 );
        }

        $quoteId            = sanitize_text_field($_POST['quoteId'] ?? '');
        $ratePlanId         = sanitize_text_field($_POST['ratePlanId'] ?? '');
        $guestyPaymentToken = sanitize_text_field($_POST['guesty_payment_token'] ?? '');
        $paymentProviderId  = sanitize_text_field($_POST['payment_provider_id'] ?? '');
        $guestData          = json_decode(stripslashes($_POST['guest'] ?? ''), true);

        if (empty($guestyPaymentToken)) {
            wp_send_json_error(['message' => 'Missing GuestyPay payment details.']);
        }

        if (empty($guestData)) {
            wp_send_json_error(['message' => 'Missing guest information.']);
        }

        // ✅ Build Guest
        $guest = [
            'firstName' => sanitize_text_field($guestData['firstName'] ?? ''),
            'lastName'  => sanitize_text_field($guestData['lastName'] ?? ''),
            'email'     => sanitize_email($guestData['email'] ?? ''),
            'phones'    => [sanitize_text_field($guestData['phone'] ?? '')],
            'address'   => [
                'street'  => sanitize_text_field($guestData['address']['street'] ?? ''),
                'zipCode' => sanitize_text_field($guestData['address']['zipCode'] ?? ''),
                'city'    => sanitize_text_field($guestData['address']['city'] ?? ''),
                'state'   => sanitize_text_field($guestData['address']['state'] ?? ''),
                'country' => sanitize_text_field($guestData['address']['country'] ?? ''),
            ]
        ];

        // ✅ Guesty payload
        $body = [
            'status'             => 'confirmed',
            'reservedUntil'      => -1,
            'guest'              => $guest,
            'ratePlanId'         => $ratePlanId,
            'quoteId'            => $quoteId,
            'paymentProviderId'  => $paymentProviderId,
            'paymentMethodToken' => $guestyPaymentToken,
        ];

        $response = $this->api->request('reservations-v3/quote', 'POST', $body);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $check_in   = sanitize_text_field($_POST['checkIn'] ?? ($_POST['checkInDateLocalized'] ?? ''));
        $check_out  = sanitize_text_field($_POST['checkOut'] ?? ($_POST['checkOutDateLocalized'] ?? ''));
        if (!empty($GLOBALS['guesty_optimization']) && $GLOBALS['guesty_optimization'] instanceof Guesty_Optimization) {
            $GLOBALS['guesty_optimization']->invalidate_booking($listing_id, $check_in, $check_out);
        }

        wp_send_json_success($response);
    }



    public function guesty_create_guest() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        if ( ! $this->allow_public_request( 'guest_creation', 6, MINUTE_IN_SECONDS ) ) {
            wp_send_json_error( array( 'message' => 'Too many guest requests. Please retry shortly.' ), 429 );
        }

        if (empty($_POST['guest'])) {
            wp_send_json_error(['message' => 'Missing guest data']);
        }

        // Decode the JSON string sent from JS
        $guestData = json_decode(stripslashes($_POST['guest']), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'Invalid guest JSON format']);
        }

        // Build Guesty API payload
        $body = [
            'firstName' => sanitize_text_field($guestData['firstName']),
            'lastName'  => sanitize_text_field($guestData['lastName']),
            'email'     => sanitize_email($guestData['email']),
            'phones'    => [sanitize_text_field($guestData['phone'])],
            'address'   => [
                'street'  => sanitize_text_field($guestData['address']['street']),
                'zipCode' => sanitize_text_field($guestData['address']['zipCode']),
                'city'    => sanitize_text_field($guestData['address']['city']),
                'state'   => sanitize_text_field($guestData['address']['state']),
                'country' => sanitize_text_field($guestData['address']['country']),
            ]
        ];

        $response = $this->api->request('guests-crud', 'POST', $body);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        wp_send_json_success($response);

    }

    public function guesty_additional_fees_get() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }

        $listing_id = sanitize_text_field($_POST['listingId'] ?? '');
        if ( ! $this->is_local_listing_id( $listing_id ) ) {
            wp_send_json_error( array( 'message' => 'Unknown property.' ), 404 );
        }

        $response = $this->api->request("additional-fees/listing/{$listing_id}", 'GET');

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $post = get_posts([
            'post_type'      => 'property',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_query'     => [
                [
                    'key'   => 'guesty_id',
                    'value' => $listing_id,
                ],
            ],
        ]);

        $post_id = !empty($post) ? $post[0]->ID : 0;

        $dog_limit = $post_id
            ? get_post_meta($post_id, 'property_dog_permitted', true)
            : 0;
                
        // ✅ Attach it to response
        wp_send_json_success([
            'fees'       => $response,
            'dog_limit'  => (int) $dog_limit,
        ]);
    }

    public function guesty_additional_fees_post() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        $inquiryId = sanitize_text_field($_POST['inquiryId'] ?? '');
        $ratePlanIds = json_decode(stripslashes($_POST['ratePlanIds'] ?? '[]'), true);
        $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);

        if (empty($inquiryId) || empty($ratePlanIds) || !is_array($items)) {
            wp_send_json_error(['message' => 'Missing inquiry ID or rate plans or bad items format']);
        }

        // Sanitize rate plan IDs
        $ratePlanIds = array_map('sanitize_text_field', (array)$ratePlanIds);

        // Build additionalFeeIds array (can be empty to clear)
        $additionalFeeIds = [];
        foreach ($items as $item) {
            $feeId = sanitize_text_field($item['feeId'] ?? '');
            $quantity = intval($item['quantity'] ?? 0);
            if ($feeId && $quantity > 0) {
                for ($i = 0; $i < $quantity; $i++) {
                    $additionalFeeIds[] = $feeId;
                }
            }
        }

        // ✅ Allow empty additionalFeeIds to remove fees
        $body = [
            'ratePlanIds' => $ratePlanIds,
            'additionalFeeIds' => $additionalFeeIds,
        ];

        $response = $this->api->request("additional-fees/inquiries/{$inquiryId}/upsells", 'POST', $body);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        if (isset($response['statusCode']) && $response['statusCode'] >= 400) {
            wp_send_json_error(['message' => $response['message'] ?? 'Failed to apply additional fees']);
        }

        wp_send_json_success($response);
    }

    function guesty_create_payment_intent() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }

        if (!isset($_POST['amount'])) {
            wp_send_json_error('Amount is required.');
        }

        require_once __DIR__ . '/../vendor/autoload.php'; // Adjust path as needed
        \Stripe\Stripe::setApiKey($this->stripe_secret); // your secret key

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => intval($_POST['amount']), // amount in cents
                'currency' => strtolower( get_option( 'guesty_default_currency', 'GBP' ) ),
                'metadata' => [
                    'Quote ID' => sanitize_text_field($_POST['quote_id']),
                ],
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);
            wp_send_json_success([
                'clientID'      => $intent->id,
                'clientSecret'  => $intent->client_secret
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    function guesty_create_setup_intent() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }

        if (empty($_POST['listingId'])) {
            wp_send_json_error(['message' => 'listingId is required.']);
        }

        $listingId = sanitize_text_field($_POST['listingId']);
        if ( ! $this->is_local_listing_id( $listingId ) ) {
            wp_send_json_error( array( 'message' => 'Unknown property.' ), 404 );
        }
        $quoteId   = isset($_POST['quoteId']) ? sanitize_text_field($_POST['quoteId']) : '';

        $email     = sanitize_email($_POST['email'] ?? '');
        $firstName = sanitize_text_field($_POST['firstName'] ?? '');
        $lastName  = sanitize_text_field($_POST['lastName'] ?? '');
        $phone     = sanitize_text_field($_POST['phone'] ?? '');

        // 1. Get Guesty's connected Stripe account ID for the listing
        $response = $this->api->request("payment-providers/provider-by-listing?listingId={$listingId}", 'GET');

        if (is_wp_error($response) || empty($response['providerAccountId'])) {
            wp_send_json_error(['message' => 'Could not fetch Guesty provider account.']);
        }

        $providerAccountId = $response['providerAccountId'];
        $paymentProviderId = $response['paymentProviderId'];

        require_once __DIR__ . '/../vendor/autoload.php';
        \Stripe\Stripe::setApiKey($this->stripe_secret);

        try {
            $customerId = null;
            if ($email) {
                $customer = \Stripe\Customer::create([
                    'email' => $email,
                    'name'  => trim($firstName . ' ' . $lastName),
                    'phone' => $phone
                ], [
                    'stripe_account' => $providerAccountId,
                ]);

                $customerId = $customer->id;
            }

            // 2. Create SetupIntent on Guesty's connected account
            $intent = \Stripe\SetupIntent::create([
                'customer' => $customerId,
                'payment_method_types' => ['card'],
                'metadata' => [
                    'listing_id' => $listingId,
                    'quote_id'   => $quoteId
                ],
            ], [
                'stripe_account' => $providerAccountId,
            ]);

            wp_send_json_success([
                'customerId'        => $customerId ?? null,
                'setupIntentId'     => $intent->id,
                'clientSecret'      => $intent->client_secret,
                'stripeAccount'     => $providerAccountId,
                'paymentProviderId' => $paymentProviderId,
            ]);

        } catch (Exception $e) {
            error_log('Stripe SetupIntent Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Stripe error: ' . $e->getMessage()]);
        }
    }

    function guesty_create_guesty_payment() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }

        if (empty($_POST['listingId'])) {
            wp_send_json_error(['message' => 'listingId is required.']);
        }

        $listingId = sanitize_text_field($_POST['listingId']);
        if ( ! $this->is_local_listing_id( $listingId ) ) {
            wp_send_json_error( array( 'message' => 'Unknown property.' ), 404 );
        }
        $quoteId   = isset($_POST['quoteId']) ? sanitize_text_field($_POST['quoteId']) : '';

        $email     = sanitize_email($_POST['email'] ?? '');
        $firstName = sanitize_text_field($_POST['firstName'] ?? '');
        $lastName  = sanitize_text_field($_POST['lastName'] ?? '');
        $phone     = sanitize_text_field($_POST['phone'] ?? '');

        // 1. Get Guesty's connected Stripe account ID for the listing
        $response = $this->api->request("payment-providers/provider-by-listing?listingId={$listingId}", 'GET');

        if (is_wp_error($response) || empty($response['providerAccountId'])) {
            wp_send_json_error(['message' => 'Could not fetch Guesty provider account.']);
        }

        $providerAccountId = $response['providerAccountId'];
        $paymentProviderId = $response['paymentProviderId'];

        require_once __DIR__ . '/../vendor/autoload.php';
        \Stripe\Stripe::setApiKey($this->stripe_secret);

        try {
            $customerId = null;
            if ($email) {
                $customer = \Stripe\Customer::create([
                    'email' => $email,
                    'name'  => trim($firstName . ' ' . $lastName),
                    'phone' => $phone
                ], [
                    'stripe_account' => $providerAccountId,
                ]);

                $customerId = $customer->id;
            }

            // 2. Create SetupIntent on Guesty's connected account
            $intent = \Stripe\SetupIntent::create([
                'customer' => $customerId,
                'payment_method_types' => ['card'],
                'metadata' => [
                    'listing_id' => $listingId,
                    'quote_id'   => $quoteId
                ],
            ], [
                'stripe_account' => $providerAccountId,
            ]);

            wp_send_json_success([
                'customerId'        => $customerId ?? null,
                'setupIntentId'     => $intent->id,
                'clientSecret'      => $intent->client_secret,
                'stripeAccount'     => $providerAccountId,
                'paymentProviderId' => $paymentProviderId,
            ]);

        } catch (Exception $e) {
            error_log('Stripe SetupIntent Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Stripe error: ' . $e->getMessage()]);
        }
    }


    function payment_provider() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }

        $listingId = sanitize_text_field($_POST['listingId'] ?? '');
        if ( ! $this->is_local_listing_id( $listingId ) ) {
            wp_send_json_error( array( 'message' => 'Unknown property.' ), 404 );
        }

        $response = $this->api->request("payment-providers/provider-by-listing?listingId={$listingId}", 'GET');

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        wp_send_json_success($response);
    }

    function guesty_payment_method() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'guesty_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        $guestID            = sanitize_text_field($_POST['guestID']);
        $tokenId            = sanitize_text_field($_POST['guestyCardToken']); // rename for clarity
        $paymentProviderId  = sanitize_text_field($_POST['paymentProviderId']);
        $reservationId      = sanitize_text_field($_POST['reservationId']);

        $body = [
            '_id'               => $tokenId, 
            'paymentProviderId' => $paymentProviderId,
            'reservationId'     => $reservationId,
            'reuse'             => false
        ];

        $response = $this->api->request("guests/{$guestID}/payment-methods", 'POST', $body);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        
        $updateBody     = ['plannedDeparture' => '12:00'];
        $updateResponse = $this->api->request("reservations-v3/{$reservationId}/dates", 'PUT', $updateBody);
        
        wp_send_json_success($response);
    }
}