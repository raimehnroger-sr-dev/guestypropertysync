<?php
/**
 * Property Map Dynamic Tag Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes/elementor/dynamic-tags
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Property_Map_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name() {
        return 'guesty-property-map';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title() {
        return __( 'Property Map', 'guesty-properties-sync' );
    }

    /**
     * Get tag groups
     *
     * @return array
     */
    public function get_group() {
        return [ 'guesty-properties' ];
    }

    /**
     * Get tag categories
     *
     * @return array
     */
    public function get_categories() {
        return [ \Elementor\Modules\DynamicTags\Module::URL_CATEGORY ];
    }

    /**
     * Register controls
     */
    protected function register_controls() {
        $this->add_control(
            'map_type',
            [
                'label' => __( 'Map Type', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'google' => __( 'Google Maps', 'guesty-properties-sync' ),
                    'openstreetmap' => __( 'OpenStreetMap', 'guesty-properties-sync' ),
                ],
                'default' => 'google',
            ]
        );
        
        $this->add_control(
            'zoom_level',
            [
                'label' => __( 'Zoom Level', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'zoom' ],
                'range' => [
                    'zoom' => [
                        'min' => 1,
                        'max' => 20,
                        'step' => 1,
                    ],
                ],
                'default' => [
                    'unit' => 'zoom',
                    'size' => 14,
                ],
            ]
        );
        
        $this->add_control(
            'map_mode',
            [
                'label' => __( 'Map Mode', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'place' => __( 'Place', 'guesty-properties-sync' ),
                    'directions' => __( 'Directions', 'guesty-properties-sync' ),
                    'view' => __( 'View', 'guesty-properties-sync' ),
                ],
                'default' => 'place',
                'condition' => [
                    'map_type' => 'google',
                ],
            ]
        );
        
        $this->add_control(
            'fallback_url',
            [
                'label' => __( 'Fallback URL', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::URL,
                'default' => [
                    'url' => 'https://maps.google.com',
                ],
            ]
        );
    }

    /**
     * Get value
     *
     * @param array $options
     * @return string
     */
    public function get_value( array $options = [] ) {
        // Get current post
        $post_id = get_the_ID();
        
        // Check if we're on a property post type
        if ( get_post_type( $post_id ) !== 'property' ) {
            return $this->get_settings( 'fallback_url' )['url'];
        }
        
        // Get property coordinates
        $latitude = get_post_meta( $post_id, 'property_latitude', true );
        $longitude = get_post_meta( $post_id, 'property_longitude', true );
        
        // If coordinates are empty, use address or fallback
        if ( empty( $latitude ) || empty( $longitude ) ) {
            $full_address = get_post_meta( $post_id, 'property_full_address', true );
            
            if ( empty( $full_address ) ) {
                return $this->get_settings( 'fallback_url' )['url'];
            }
            
            // Use address for Google Maps
            if ( $this->get_settings( 'map_type' ) === 'google' ) {
                return 'https://www.google.com/maps/search/?api=1&query=' . urlencode( $full_address );
            } else {
                return 'https://www.openstreetmap.org/search?query=' . urlencode( $full_address );
            }
        }
        
        // Get map settings
        $map_type = $this->get_settings( 'map_type' );
        $zoom_level = $this->get_settings( 'zoom_level' )['size'];
        $map_mode = $this->get_settings( 'map_mode' );
        
        // Build the map URL
        $url = '';
        
        if ( $map_type === 'google' ) {
            if ( $map_mode === 'directions' ) {
                $url = 'https://www.google.com/maps/dir/?api=1&destination=' . $latitude . ',' . $longitude;
            } elseif ( $map_mode === 'view' ) {
                $url = 'https://www.google.com/maps/@' . $latitude . ',' . $longitude . ',' . $zoom_level . 'z';
            } else { // place mode
                $url = 'https://www.google.com/maps/search/?api=1&query=' . $latitude . ',' . $longitude;
            }
        } else { // OpenStreetMap
            $url = 'https://www.openstreetmap.org/?mlat=' . $latitude . '&mlon=' . $longitude . '&zoom=' . $zoom_level;
        }
        
        return $url;
    }
} 