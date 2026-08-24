<?php
/**
 * Property Link Tag Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes/elementor/dynamic-tags
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Property_Link extends \Elementor\Core\DynamicTags\Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name() {
        return 'guesty-property-link';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title() {
        return __( 'Property Link', 'guesty-properties-sync' );
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
            'field',
            [
                'label' => __( 'Field', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    // Basic information
                    'property_360_video_link' => __( '360 video link', 'guesty-properties-sync' ), 
                    'property_description_neighborhood' => __( 'Location link', 'guesty-properties-sync' ), 
                ],
                'default' => 'property_title',
            ]
        );
        
        $this->add_control(
            'fallback_link',
            [
                'label' => __( 'Fallback', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => '#',
            ]
        );
    }

    /**
     * Render tag output
     */
    public function render() {
        // Get selected field
        $field = $this->get_settings( 'field' );
        $fallback = $this->get_settings( 'fallback_link' );
        
        if ( empty( $field ) ) {
            return;
        }
        
        // Get current post
        $post_id = get_the_ID();
        
        // Check if we're on a property post type
        if ( get_post_type( $post_id ) !== 'property' ) {
            echo esc_html( $fallback );
            return;
        }
        if($field == 'property_360_video_link') {
            $value = get_post_meta( $post_id, $field, true );
        } else if($field == 'property_description_neighborhood'){
            $value = get_post_meta( $post_id, $field, true );

            // Special-case rewrite
            $check = strtolower( trim( $value ) );
            if ( $check === 'poole town' ) {
                $value = 'poole-old-town';
            }

            // URL-friendly slug
            $slug = sanitize_title( $value );

            // Get all posts in "locations" category
            $location_posts = get_posts( array(
                'post_type'      => 'post',
                'category_name'  => 'locations', // category slug
                'posts_per_page' => -1,
                'fields'         => 'ids', // faster
            ) );

            $allowed_slugs = array();
            foreach ( $location_posts as $loc_post_id ) {
                $allowed_slugs[] = get_post_field( 'post_name', $loc_post_id );
            }

            // Compare & set value
            if ( in_array( $slug, $allowed_slugs, true ) ) {
                $value = '/' . $slug;
            } else {
                $value = '/locations';
            }
        } 
        
        // Check if field has a value
        if ( empty( $value ) ) {
            echo esc_html( $fallback );
            return;
        }
        
        // Output the field value
        echo esc_html( $value );
    }
} 