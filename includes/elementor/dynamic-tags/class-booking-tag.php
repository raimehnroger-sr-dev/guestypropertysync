<?php
/**
 * Guesty Elementor Dynamic Tags
 *
 * @package Guesty_Property_Sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Booking_Tag extends \Elementor\Core\DynamicTags\Tag {

    protected $api;

    public function on_register() {
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/api/class-guesty-api.php';
        $this->api = new Guesty_API();
    }

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name() {
        return 'guesty-booking-field';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title() {
        return __( 'Booking Field', 'guesty-properties-sync' );
    }

    /**
     * Get tag groups
     *
     * @return array
     */
    public function get_group() {
        return [ 'guesty-booking' ];
    }

    /**
     * Get tag categories
     *
     * @return array
     */
    public function get_categories() {
        return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
    }

    /**
     * Register controls
     */
    protected function register_controls() {
        $this->add_control(
            'guesty_field',
            [
                'label' => __( 'Field', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    // Basic information
                    // 'guesty_id' => __( 'Guesty ID', 'guesty-properties-sync' ),
                    'title' => __( 'Title', 'guesty-properties-sync' ),
                    'nickname' => __( 'Nickname', 'guesty-properties-sync' ),
                    'type' => __( 'Property Type', 'guesty-properties-sync' ),
                    // 'status' => __( 'Status', 'guesty-properties-sync' ),
                    'bedrooms' => __( 'Bedrooms', 'guesty-properties-sync' ),
                    'bathrooms' => __( 'Bathrooms', 'guesty-properties-sync' ),
                    'accommodates' => __( 'Accommodates', 'guesty-properties-sync' ),
                    
                    // Address
                    'full' => __( 'Full Address', 'guesty-properties-sync' ),
                    'street' => __( 'Street', 'guesty-properties-sync' ),
                    'city' => __( 'City', 'guesty-properties-sync' ),
                    'state' => __( 'State/Region', 'guesty-properties-sync' ),
                    'country' => __( 'Country', 'guesty-properties-sync' ),
                    'zipcode' => __( 'Zipcode', 'guesty-properties-sync' ),
                    
                    // Pricing
                    'basePrice' => __( 'Base Price', 'guesty-properties-sync' ),
                    'currency' => __( 'Currency', 'guesty-properties-sync' ),

                    'tags' => __( 'Tags', 'guesty-properties-sync' ),
                    'minNights' => __( 'Min Nights', 'guesty-properties-sync' ),
                    'maxNights' => __( 'Max Nights', 'guesty-properties-sync' ),
                    
                    // Identifiers
                    '_id' => __( 'Listing ID', 'guesty-properties-sync' ),
                ],
                'default' => 'Booking Field',
            ]
        );
        
        $this->add_control(
            'guesty_before',
            [
                'label' => __( 'Before', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::TEXT,
            ]
        );
        
        $this->add_control(
            'guesty_after',
            [
                'label' => __( 'After', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::TEXT,
            ]
        );
        
        $this->add_control(
            'guesty_fallback',
            [
                'label' => __( 'Fallback', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => '',
            ]
        );
    }

    /**
     * Render tag output
     */
    public function render() {
        $field    = $this->get_settings( 'guesty_field' );
        $before   = $this->get_settings( 'guesty_before' );
        $after    = $this->get_settings( 'guesty_after' );
        $fallback = $this->get_settings( 'guesty_fallback' );

        if ( empty( $field ) ) {
            return;
        }

        $listing_id = $_GET['listing_id'] ?? '';
        if ( ! $listing_id ) {
            echo esc_html( $fallback );
            return;
        }

        $cache_key = 'guesty_listing_' . md5( $listing_id );
        $data = get_transient( $cache_key );

        if ( ! $data ) {
            if ( ! isset( $this->api ) ) {
                require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/api/class-guesty-api.php';
                $this->api = new Guesty_API();
            }

            $data = $this->api->request( "listings/{$listing_id}", 'GET' );

            if ( is_wp_error( $data ) ) {
                error_log( 'Guesty API Error: ' . $data->get_error_message() );
                echo esc_html( $fallback );
                return;
            }

            if ( is_array( $data ) && empty( $data['error'] ) ) {
                set_transient( $cache_key, $data, HOUR_IN_SECONDS );
            } else {
                echo esc_html( $fallback );
                return;
            }
        }


        if (
            in_array( $field, [ 'full', 'street', 'city', 'state', 'country', 'zipcode' ] ) &&
            isset( $data['address'] ) &&
            is_array( $data['address'] ) &&
            isset( $data['address'][ $field ] )
        ) {
            $value = $data['address'][ $field ];
        } elseif (
            in_array( $field, [ 'minNights', 'maxNights' ] ) &&
            isset( $data['terms'] ) &&
            is_array( $data['terms'] ) &&
            isset( $data['terms'][ $field ] )
        ) {
            $value = $data['terms'][ $field ];
        } elseif ( isset( $data[ $field ] ) && $data[ $field ] !== '' ) {
            $value = $data[ $field ];
        } else {
            $value = $fallback;
        }

        if ( is_array( $value ) ) {
            // Convert array to string, join by comma (or other logic)
            $value = implode( ', ', $value );
        }

        echo esc_html( $before . $value . $after );
    }
} 