<?php
/**
 * Property Address Dynamic Tag Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes/elementor/dynamic-tags
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Property_Address_Tag extends \Elementor\Core\DynamicTags\Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name() {
        return 'guesty-property-address';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title() {
        return __( 'Property Address', 'guesty-properties-sync' );
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
            'format',
            [
                'label' => __( 'Format', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'full' => __( 'Full Address', 'guesty-properties-sync' ),
                    'city_state' => __( 'City, State', 'guesty-properties-sync' ),
                    'city_state_country' => __( 'City, State, Country', 'guesty-properties-sync' ),
                    'city_country' => __( 'City, Country', 'guesty-properties-sync' ),
                    'street_city_state' => __( 'Street, City, State', 'guesty-properties-sync' ),
                    'street_city_state_zipcode' => __( 'Street, City, State Zipcode', 'guesty-properties-sync' ),
                ],
                'default' => 'full',
            ]
        );
        
        $this->add_control(
            'html_tag',
            [
                'label' => __( 'HTML Tag', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'h1' => 'H1',
                    'h2' => 'H2',
                    'h3' => 'H3',
                    'h4' => 'H4',
                    'h5' => 'H5',
                    'h6' => 'H6',
                    'p' => 'p',
                    'div' => 'div',
                    'span' => 'span',
                    '' => __( 'None', 'guesty-properties-sync' ),
                ],
                'default' => '',
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
        // Get current post
        $post_id = get_the_ID();
        
        // Check if we're on a property post type
        if ( get_post_type( $post_id ) !== 'property' ) {
            echo esc_html( $this->get_settings( 'fallback' ) );
            return;
        }
        
        $format = $this->get_settings( 'format' );
        $html_tag = $this->get_settings( 'html_tag' );
        $fallback = $this->get_settings( 'fallback' );
        
        // Get address components
        $full_address = get_post_meta( $post_id, 'property_full_address', true );
        $street = get_post_meta( $post_id, 'property_street', true );
        $city = get_post_meta( $post_id, 'property_city', true );
        $state = get_post_meta( $post_id, 'property_state', true );
        $country = get_post_meta( $post_id, 'property_country', true );
        $zipcode = get_post_meta( $post_id, 'property_zipcode', true );
        
        // Format address based on selected format
        $address = '';
        
        switch ( $format ) {
            case 'full':
                $address = $full_address;
                break;
                
            case 'city_state':
                if ( !empty( $city ) && !empty( $state ) ) {
                    $address = $city . ', ' . $state;
                }
                break;
                
            case 'city_state_country':
                if ( !empty( $city ) && !empty( $state ) && !empty( $country ) ) {
                    $address = $city . ', ' . $state . ', ' . $country;
                } elseif ( !empty( $city ) && !empty( $country ) ) {
                    $address = $city . ', ' . $country;
                }
                break;
                
            case 'city_country':
                if ( !empty( $city ) && !empty( $country ) ) {
                    $address = $city . ', ' . $country;
                }
                break;
                
            case 'street_city_state':
                if ( !empty( $street ) && !empty( $city ) && !empty( $state ) ) {
                    $address = $street . ', ' . $city . ', ' . $state;
                }
                break;
                
            case 'street_city_state_zipcode':
                if ( !empty( $street ) && !empty( $city ) && !empty( $state ) ) {
                    $address = $street . ', ' . $city . ', ' . $state;
                    
                    if ( !empty( $zipcode ) ) {
                        $address .= ' ' . $zipcode;
                    }
                }
                break;
                
            default:
                $address = $full_address;
                break;
        }
        
        // If address is empty, use fallback
        if ( empty( $address ) ) {
            echo esc_html( $fallback );
            return;
        }
        
        // Wrap address in HTML tag if specified
        if ( ! empty( $html_tag ) ) {
            echo '<' . esc_html( $html_tag ) . '>' . esc_html( $address ) . '</' . esc_html( $html_tag ) . '>';
        } else {
            echo esc_html( $address );
        }
    }
} 