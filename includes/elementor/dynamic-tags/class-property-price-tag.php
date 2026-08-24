<?php
/**
 * Property Price Dynamic Tag Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes/elementor/dynamic-tags
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Property_Price_Tag extends \Elementor\Core\DynamicTags\Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name() {
        return 'guesty-property-price';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title() {
        return __( 'Property Price', 'guesty-properties-sync' );
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
            'price_format',
            [
                'label' => __( 'Price Format', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'price_only' => __( 'Price Only', 'guesty-properties-sync' ),
                    'price_currency' => __( 'Price with Currency', 'guesty-properties-sync' ),
                    'price_currency_per_night' => __( 'Price with Currency Per Night', 'guesty-properties-sync' ),
                ],
                'default' => 'price_currency',
            ]
        );
        
        $this->add_control(
            'decimal_points',
            [
                'label' => __( 'Decimal Points', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    '0' => '0',
                    '1' => '1',
                    '2' => '2',
                ],
                'default' => '2',
            ]
        );
        
        $this->add_control(
            'currency_position',
            [
                'label' => __( 'Currency Position', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'before' => __( 'Before Price', 'guesty-properties-sync' ),
                    'after' => __( 'After Price', 'guesty-properties-sync' ),
                ],
                'default' => 'before',
                'condition' => [
                    'price_format!' => 'price_only',
                ],
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
        
        // Get price and currency
        $price = get_post_meta( $post_id, 'property_base_price', true );
        $currency = get_post_meta( $post_id, 'property_currency', true );
        
        // If price is empty, use fallback
        if ( empty( $price ) && $price !== '0' ) {
            echo esc_html( $this->get_settings( 'fallback' ) );
            return;
        }
        
        // Format the price
        $decimal_points = intval( $this->get_settings( 'decimal_points' ) );
        $price_format = $this->get_settings( 'price_format' );
        $currency_position = $this->get_settings( 'currency_position' );
        $html_tag = $this->get_settings( 'html_tag' );
        
        // Format price based on decimal points
        $formatted_price = number_format( floatval( $price ), $decimal_points );
        
        // Build the final output
        $output = '';
        
        if ( $price_format === 'price_only' ) {
            $output = $formatted_price;
        } else {
            if ( $currency_position === 'before' ) {
                $output = $currency . ' ' . $formatted_price;
            } else {
                $output = $formatted_price . ' ' . $currency;
            }
            
            if ( $price_format === 'price_currency_per_night' ) {
                $output .= ' ' . __( 'per night', 'guesty-properties-sync' );
            }
        }
        
        // Wrap price in HTML tag if specified
        if ( ! empty( $html_tag ) ) {
            echo '<' . esc_html( $html_tag ) . '>' . esc_html( $output ) . '</' . esc_html( $html_tag ) . '>';
        } else {
            echo esc_html( $output );
        }
    }
} 