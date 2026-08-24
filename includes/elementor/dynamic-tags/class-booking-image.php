<?php
/**
 * Guesty Elementor Dynamic Tags
 *
 * @package Guesty_Property_Sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Booking_Image extends \Elementor\Core\DynamicTags\Data_Tag {

    public function get_name() {
        return 'guesty-booking-image';
    }

    public function get_title() {
        return __( 'Booking Main Image', 'guesty-properties-sync' );
    }

    public function get_group() {
        return [ 'guesty-booking' ];
    }

    public function get_categories() {
        return [ \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY ];
    }

    /**
     * Register controls
     */
    protected function register_controls() {        
        $this->add_control(
            'fallback',
            [
                'label' => __( 'Fallback Image', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::MEDIA,
            ]
        );
    }

    public function get_value( array $options = [] ) {
        $listing_id = $_GET['listing_id'] ?? '';
        if ( ! $listing_id ) {
            return $this->get_fallback_image();
        }

        // Transient cache
        $cache_key = 'guesty_listing_' . md5( $listing_id );
        $data = get_transient( $cache_key );

        if ( ! $data ) {
            if ( ! isset( $this->api ) ) {
                require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/api/class-guesty-api.php';
                $this->api = new Guesty_API();
            }

            $data = $this->api->request( "listings/{$listing_id}", 'GET' );

            if ( is_wp_error( $data ) ) {
                // Optional: log error for debugging
                error_log( 'Guesty API Error: ' . $data->get_error_message() );
                return $this->get_fallback_image();
            }

            if ( is_array( $data ) && empty( $data['error'] ) ) {
                set_transient( $cache_key, $data, HOUR_IN_SECONDS );
            } else {
                return $this->get_fallback_image();
            }
        } 

        // Get the first image original URL
        if ( is_array( $data ) && ! empty( $data['pictures'] ) && is_array( $data['pictures'] ) ) {
            $first_image = $data['pictures'][0]['original'] ?? '';
            if ( $first_image ) {
                return [
                    'url' => esc_url( $first_image ),
                    'id'  => 0, // Elementor expects this format
                ];
            } else {
                return $this->get_fallback_image();
            }
        }

        return false;
    }

    /**
     * Get fallback image
     *
     * @return array
     */
    private function get_fallback_image() {
        $fallback = $this->get_settings( 'fallback' );
        
        if ( ! empty( $fallback['id'] ) ) {
            return [
                'id' => $fallback['id'],
                'url' => $fallback['url'],
            ];
        }
        
        return [
            'id' => 0,
            'url' => GUESTY_PROPERTY_SYNC_PLUGIN_URL . 'assets/images/property-placeholder.jpg',
        ];
    }
}