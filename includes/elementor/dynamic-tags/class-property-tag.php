<?php
/**
 * Property Dynamic Tag Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes/elementor/dynamic-tags
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Property_Tag extends \Elementor\Core\DynamicTags\Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name() {
        return 'guesty-property-field';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title() {
        return __( 'Property Field', 'guesty-properties-sync' );
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
        return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
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
                    'guesty_id' => __( 'Guesty ID', 'guesty-properties-sync' ),
                    'property_nickname' => __( 'Nickname', 'guesty-properties-sync' ),
                    'property_title' => __( 'Title', 'guesty-properties-sync' ),
                    'property_type' => __( 'Property Type', 'guesty-properties-sync' ),
                    'property_status' => __( 'Status', 'guesty-properties-sync' ),
                    'property_bedrooms' => __( 'Bedrooms', 'guesty-properties-sync' ),
                    'property_bathrooms' => __( 'Bathrooms', 'guesty-properties-sync' ),
                    'property_accommodates' => __( 'Accommodates', 'guesty-properties-sync' ),
                    'property_description_space' => __( 'Description (Space)', 'guesty-properties-sync' ),
                    'property_epc_ratings' => __( 'EPC Ratings', 'guesty-properties-sync' ),
                    
                    // Address
                    'property_full_address' => __( 'Full Address', 'guesty-properties-sync' ),
                    'property_street' => __( 'Street', 'guesty-properties-sync' ),
                    'property_city' => __( 'City', 'guesty-properties-sync' ),
                    'property_state' => __( 'State/Region', 'guesty-properties-sync' ),
                    'property_country' => __( 'Country', 'guesty-properties-sync' ),
                    'property_zipcode' => __( 'Zipcode', 'guesty-properties-sync' ),
                    
                    // Pricing
                    'property_base_price' => __( 'Base Price', 'guesty-properties-sync' ),
                    'property_currency' => __( 'Currency', 'guesty-properties-sync' ),
                    
                    // Identifiers
                    'property_listing_id' => __( 'Listing ID', 'guesty-properties-sync' ),
                    'property_account_id' => __( 'Account ID', 'guesty-properties-sync' ),
                ],
                'default' => 'property_title',
            ]
        );
        
        $this->add_control(
            'before',
            [
                'label' => __( 'Before', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::TEXT,
            ]
        );
        
        $this->add_control(
            'after',
            [
                'label' => __( 'After', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::TEXT,
            ]
        );
        
        $this->add_control(
            'fallback',
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
        // Get selected field
        $field = $this->get_settings( 'field' );
        $before = $this->get_settings( 'before' );
        $after = $this->get_settings( 'after' );
        $fallback = $this->get_settings( 'fallback' );
        
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
        
        // Get field value
        $value = get_post_meta( $post_id, $field, true );
        
        // Check if field has a value
        if ( empty( $value ) && $value !== '0' ) {
            echo esc_html( $fallback );
            return;
        }
        
        // Format special fields
        if ( $field === 'property_bedrooms' || $field === 'property_accommodates' ) {
            $value = intval( $value );
        } else if ( $field === 'property_bathrooms' ) {
            $value = floatval( $value );
        } else if ( $field === 'property_base_price' ) {
            $currency = get_post_meta( $post_id, 'property_currency', true );
            $value = number_format( floatval( $value ), 2 );
            $after = ' ' . $currency . $after;
        }
        
        // Output the field value
        echo esc_html( $before ) . esc_html( $value ) . esc_html( $after );
    }
} 