<?php
/**
 * Property Gallery Dynamic Tag Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes/elementor/dynamic-tags
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Property_Gallery_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name() {
        return 'guesty-property-gallery';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title() {
        return __( 'Property Gallery', 'guesty-properties-sync' );
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
        return [ \Elementor\Modules\DynamicTags\Module::GALLERY_CATEGORY ];
    }

    /**
     * Register controls
     */
    protected function register_controls() {
        $this->add_control(
            'limit',
            [
                'label' => __( 'Limit', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'min' => 1,
                'default' => 10,
                'description' => __( 'Limit the number of images in the gallery', 'guesty-properties-sync' ),
            ]
        );
    }

    /**
     * Get value
     *
     * @param array $options
     * @return array
     */
    public function get_value( array $options = [] ) {
        $gallery = [];
        
        // Get current post
        $post_id = get_the_ID();
        
        // Check if we're on a property post type
        if ( get_post_type( $post_id ) !== 'property' ) {
            return $gallery;
        }
        
        // Get pictures
        $pictures = get_post_meta( $post_id, 'property_pictures', true );
        
        if ( empty( $pictures ) ) {
            return $gallery;
        }
        
        $pictures = maybe_unserialize( $pictures );
        
        if ( ! is_array( $pictures ) ) {
            return $gallery;
        }
        
        // Get limit
        $limit = intval( $this->get_settings( 'limit' ) );
        $count = 0;
        
        foreach ( $pictures as $picture ) {
            if ( empty( $picture['thumbnail'] ) ) {
                continue;
            }
            
            $gallery[] = [
                'id' => 0,
                'url' => $picture['thumbnail'],
            ];
            
            $count++;
            
            if ( $limit > 0 && $count >= $limit ) {
                break;
            }
        }
        
        return $gallery;
    }
} 