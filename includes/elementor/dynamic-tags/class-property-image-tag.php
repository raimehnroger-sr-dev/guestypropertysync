<?php
/**
 * Property Image Dynamic Tag Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes/elementor/dynamic-tags
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Property_Image_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name() {
        return 'guesty-property-image';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title() {
        return __( 'Property Image', 'guesty-properties-sync' );
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
        return [ \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY ];
    }

    /**
     * Register controls
     */
    protected function register_controls() {
        $this->add_control(
            'image_type',
            [
                'label' => __( 'Image Type', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'main_image' => __( 'Main Image', 'guesty-properties-sync' ),
                    'specific_index' => __( 'Specific Image (by index)', 'guesty-properties-sync' ),
                ],
                'default' => 'main_image',
            ]
        );
        
        $this->add_control(
            'image_index',
            [
                'label' => __( 'Image Index (0-based)', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'min' => 0,
                'default' => 0,
                'condition' => [
                    'image_type' => 'specific_index',
                ],
            ]
        );
        
        $this->add_control(
            'fallback',
            [
                'label' => __( 'Fallback Image', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::MEDIA,
            ]
        );
    }

    /**
     * Get value
     *
     * @param array $options
     * @return array|false
     */
    public function get_value( array $options = [] ) {
        // Get current post
        $post_id = get_the_ID();
        
        // Check if we're on a property post type
        if ( get_post_type( $post_id ) !== 'property' ) {
            return $this->get_fallback_image();
        }
        
        $image_type = $this->get_settings( 'image_type' );
        $image_url = '';
        
        if ( $image_type === 'main_image' ) {
            // Get main image URL
            $image_url = get_post_meta( $post_id, 'property_main_image', true );
        } else if ( $image_type === 'specific_index' ) {
            // Get specific image by index
            $index = intval( $this->get_settings( 'image_index' ) );
            
            // Get all pictures
            $pictures = get_post_meta( $post_id, 'property_pictures', true );
            
            if ( ! empty( $pictures ) ) {
                $pictures = maybe_unserialize( $pictures );
                
                if ( is_array( $pictures ) && isset( $pictures[ $index ] ) && ! empty( $pictures[ $index ]['thumbnail'] ) ) {
                    $image_url = $pictures[ $index ]['thumbnail'];
                }
            }
        }
        
        // If no image, use fallback
        if ( empty( $image_url ) ) {
            return $this->get_fallback_image();
        }
        
        return [
            'id' => 0,
            'url' => $image_url,
        ];
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