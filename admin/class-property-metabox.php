<?php
/**
 * Property Metabox Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/admin
 */

class Property_Metabox {

    /**
     * Initialize the class
     */
    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_property_meta_boxes' ) );
    }

    /**
     * Register the property metaboxes
     */
    public function add_property_meta_boxes() {
        add_meta_box(
            'property_details_metabox',
            __( 'Property Details', 'guesty-properties-sync' ),
            array( $this, 'render_property_details_metabox' ),
            'property',
            'normal',
            'high'
        );
        
        add_meta_box(
            'property_location_metabox',
            __( 'Property Location', 'guesty-properties-sync' ),
            array( $this, 'render_property_location_metabox' ),
            'property',
            'normal',
            'default'
        );
        
        add_meta_box(
            'property_pricing_metabox',
            __( 'Property Pricing', 'guesty-properties-sync' ),
            array( $this, 'render_property_pricing_metabox' ),
            'property',
            'side',
            'default'
        );
        
        add_meta_box(
            'property_identifiers_metabox',
            __( 'Property Identifiers', 'guesty-properties-sync' ),
            array( $this, 'render_property_identifiers_metabox' ),
            'property',
            'side',
            'default'
        );
    }

    /**
     * Render the property details metabox
     *
     * @param WP_Post $post Current post object
     */
    public function render_property_details_metabox( $post ) {
        $bedrooms = get_post_meta( $post->ID, 'property_bedrooms', true );
        $bathrooms = get_post_meta( $post->ID, 'property_bathrooms', true );
        $accommodates = get_post_meta( $post->ID, 'property_accommodates', true );
        $property_type = get_post_meta( $post->ID, 'property_type', true );
        $property_status = get_post_meta( $post->ID, 'property_status', true );
        $nickname = get_post_meta( $post->ID, 'property_nickname', true );
        $title = get_post_meta( $post->ID, 'property_title', true );
        $threesixtyVideoLink = get_post_meta( $post->ID, 'property_360_video_link', true );
        $epc = get_post_meta( $post->ID, 'property_epc_ratings', true );
        $dogs = get_post_meta( $post->ID, 'property_dog_permitted', true );
        $property_floorPlan_img = get_post_meta( $post->ID, 'property_floorPlan_img', true );
        // Get amenities
        $amenities = get_post_meta( $post->ID, 'property_amenities', true );
        if (!empty($amenities)) {
            $amenities = maybe_unserialize($amenities);
        } else {
            $amenities = array();
        }
        
        // Get images
        $pictures = get_post_meta( $post->ID, 'property_pictures', true );
        if (!empty($pictures)) {
            $pictures = maybe_unserialize($pictures);
        } else {
            $pictures = array();
        }
        
        $main_image = get_post_meta( $post->ID, 'property_main_image', true );
        
        // Display form
        wp_nonce_field( 'property_details_metabox', 'property_details_nonce' );
        ?>
        <div class="property-details-container">
            <div class="property-basic-details">
                <h3><?php _e( 'Basic Information', 'guesty-properties-sync' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><label for="property_nickname"><?php _e( 'Nickname', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="text" id="property_nickname" name="property_nickname" value="<?php echo esc_attr( $nickname ); ?>" class="regular-text" readonly>
                            <p class="description"><?php _e( 'Property nickname from Guesty', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_title"><?php _e( 'Title', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="text" id="property_title" name="property_title" value="<?php echo esc_attr( $title ); ?>" class="regular-text" readonly>
                            <p class="description"><?php _e( 'Property title from Guesty', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_type"><?php _e( 'Property Type', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="text" id="property_type" name="property_type" value="<?php echo esc_attr( $property_type ); ?>" class="regular-text" readonly>
                            <p class="description"><?php _e( 'Type of property', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_status"><?php _e( 'Status', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="text" id="property_status" name="property_status" value="<?php echo esc_attr( $property_status ); ?>" class="regular-text" readonly>
                            <p class="description"><?php _e( 'Property status', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_bedrooms"><?php _e( 'Bedrooms', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="number" id="property_bedrooms" name="property_bedrooms" value="<?php echo esc_attr( $bedrooms ); ?>" class="small-text" readonly>
                            <p class="description"><?php _e( 'Number of bedrooms', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_bathrooms"><?php _e( 'Bathrooms', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="text" id="property_bathrooms" name="property_bathrooms" value="<?php echo esc_attr( $bathrooms ); ?>" class="small-text" readonly>
                            <p class="description"><?php _e( 'Number of bathrooms', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_accommodates"><?php _e( 'Accommodates', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="number" id="property_accommodates" name="property_accommodates" value="<?php echo esc_attr( $accommodates ); ?>" class="small-text" readonly>
                            <p class="description"><?php _e( 'Maximum number of guests', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_360_video_link"><?php _e( '360 video link', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="text" id="property_360_video_link" name="property_360_video_link" value="<?php echo esc_attr( $threesixtyVideoLink ); ?>" class="regular-text" readonly>
                            <p class="description"><?php _e( 'Custom Field > 360 video link', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_epc_ratings"><?php _e( 'EPC Ratings', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="text" id="property_epc_ratings" name="property_epc_ratings" value="<?php echo esc_attr( $epc ); ?>" class="regular-text" readonly>
                            <p class="description"><?php _e( 'Custom Field > EPC Ratings', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_dog_permitted"><?php _e( 'Dog no. permitted', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="number" id="property_dog_permitted" name="property_dog_permitted" value="<?php echo esc_attr( $dogs ); ?>" class="small-text" readonly>
                            <p class="description"><?php _e( 'Custom Field > Dog no. permitted', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="property_floorPlan_img"><?php _e( 'Floor Plan', 'guesty-properties-sync' ); ?></label></th>
                        <td>
                            <input type="text" id="property_floorPlan_img" name="property_floorPlan_img" value="<?php echo esc_attr( $property_floorPlan_img ); ?>" class="regular-text" readonly>
                            <p class="description"><?php _e( 'Floor Plan', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php if (!empty($amenities)): ?>
            <div class="property-amenities">
                <h3><?php _e( 'Amenities', 'guesty-properties-sync' ); ?></h3>
                <ul class="property-amenities-list">
                    <?php foreach ($amenities as $amenity): ?>
                        <?php if (is_string($amenity)): ?>
                            <li><?php echo esc_html($amenity); ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($pictures)): ?>
            <div class="property-images">
                <h3><?php _e( 'Property Images', 'guesty-properties-sync' ); ?></h3>
                <div class="property-images-gallery">
                    <?php 
                    $image_count = 0;
                    foreach ($pictures as $picture): 
                        if (!empty($picture['thumbnail'])):
                            $image_count++;
                            if ($image_count > 5) break; // Show max 5 images
                    ?>
                        <div class="property-image">
                            <img src="<?php echo esc_url($picture['thumbnail']); ?>" alt="" width="150">
                        </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                    <?php if (count($pictures) > 5): ?>
                        <p><?php printf(__('and %d more images', 'guesty-properties-sync'), count($pictures) - 5); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <style>
            .property-images-gallery {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .property-image img {
                border: 1px solid #ddd;
                padding: 3px;
            }
            .property-amenities-list {
                column-count: 2;
                column-gap: 20px;
            }
        </style>
        <?php
    }

    /**
     * Render the property location metabox
     *
     * @param WP_Post $post Current post object
     */
    public function render_property_location_metabox( $post ) {

        $full_address  = get_post_meta( $post->ID, 'property_full_address', true );
        $street        = get_post_meta( $post->ID, 'property_street', true );
        $building_name = get_post_meta( $post->ID, 'property_building_name', true );

        $apt           = get_post_meta( $post->ID, 'property_apt', true );
        $apartment     = get_post_meta( $post->ID, 'property_apartment', true );
        $unit          = get_post_meta( $post->ID, 'property_unit', true );
        $floor         = get_post_meta( $post->ID, 'property_floor', true );

        $city          = get_post_meta( $post->ID, 'property_city', true );
        $state         = get_post_meta( $post->ID, 'property_state', true );
        $county        = get_post_meta( $post->ID, 'property_county', true );
        $neighborhood  = get_post_meta( $post->ID, 'property_neighborhood', true );
        $country       = get_post_meta( $post->ID, 'property_country', true );
        $zipcode       = get_post_meta( $post->ID, 'property_zipcode', true );

        $latitude      = get_post_meta( $post->ID, 'property_latitude', true );
        $longitude     = get_post_meta( $post->ID, 'property_longitude', true );
        $latlang       = get_post_meta( $post->ID, 'property_latlang', true );

        wp_nonce_field( 'property_location_metabox', 'property_location_nonce' );
        ?>

        <div class="property-location-container">
            <table class="form-table">

                <tr>
                    <th><label><?php _e( 'Full Address', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $full_address ); ?>" class="large-text" readonly>
                        <p class="description"><?php _e( 'Complete formatted address of the property.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'Street', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $street ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e( 'Street name and number.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'Building Name', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $building_name ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e( 'Name of the building or complex.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'Unit', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $unit ); ?>" class="small-text" readonly>
                        <p class="description"><?php _e( 'Unit number within the building.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'Floor', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $floor ); ?>" class="small-text" readonly>
                        <p class="description"><?php _e( 'Floor level of the unit.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'Apartment / Apt', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $apt ?: $apartment ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e( 'Apartment or suite identifier.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'City', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $city ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e( 'City where the property is located.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'State / Region', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $state ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e( 'State or region of the property.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'County', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $county ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e( 'County or administrative division.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'Neighborhood', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $neighborhood ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e( 'Neighborhood or district.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'Country', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $country ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e( 'Country where the property is located.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'Zipcode', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $zipcode ); ?>" class="regular-text" readonly>
                        <p class="description"><?php _e( 'Postal or ZIP code.', 'guesty-properties-sync' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label><?php _e( 'Coordinates', 'guesty-properties-sync' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $latitude ); ?>" class="" readonly>
                        <input type="text" value="<?php echo esc_attr( $longitude ); ?>" class="" readonly>

                        <p class="description">
                            <?php _e( 'Latitude and longitude coordinates of the property.', 'guesty-properties-sync' ); ?>

                            <?php if ( ! empty( $latitude ) && ! empty( $longitude ) ): ?>
                                (
                                <a href="https://www.google.com/maps?q=<?php echo esc_attr($latitude); ?>,<?php echo esc_attr($longitude); ?>" target="_blank">
                                    <?php _e('View on Google Maps', 'guesty-properties-sync'); ?>
                                </a>
                                )
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>

            </table>

        </div>

        <?php
    }

    /**
     * Render the property pricing metabox
     *
     * @param WP_Post $post Current post object
     */
    public function render_property_pricing_metabox( $post ) {
        $base_price = get_post_meta( $post->ID, 'property_base_price', true );
        $currency = get_post_meta( $post->ID, 'property_currency', true );
        
        wp_nonce_field( 'property_pricing_metabox', 'property_pricing_nonce' );
        ?>
        <p>
            <label for="property_base_price"><strong><?php _e( 'Base Price', 'guesty-properties-sync' ); ?>:</strong></label>
            <input type="text" id="property_base_price" name="property_base_price" value="<?php echo esc_attr( $base_price ); ?>" class="medium-text" readonly>
            <?php echo esc_html($currency); ?>
        </p>
        <p class="description"><?php _e( 'Base price per night', 'guesty-properties-sync' ); ?></p>
        <?php
    }

    /**
     * Render the property identifiers metabox
     *
     * @param WP_Post $post Current post object
     */
    public function render_property_identifiers_metabox( $post ) {
        $guesty_id = get_post_meta( $post->ID, 'guesty_id', true );
        $listing_id = get_post_meta( $post->ID, 'property_listing_id', true );
        $account_id = get_post_meta( $post->ID, 'property_account_id', true );
        $last_updated = get_post_meta( $post->ID, 'guesty_last_updated', true );
        
        wp_nonce_field( 'property_identifiers_metabox', 'property_identifiers_nonce' );
        ?>
        <p>
            <label for="guesty_id"><strong><?php _e( 'Guesty ID', 'guesty-properties-sync' ); ?>:</strong></label><br>
            <code><?php echo esc_html( $guesty_id ); ?></code>
        </p>
        
        <?php if (!empty($listing_id)): ?>
        <p>
            <label for="property_listing_id"><strong><?php _e( 'Listing ID', 'guesty-properties-sync' ); ?>:</strong></label><br>
            <code><?php echo esc_html( $listing_id ); ?></code>
        </p>
        <?php endif; ?>
        
        <?php if (!empty($account_id)): ?>
        <p>
            <label for="property_account_id"><strong><?php _e( 'Account ID', 'guesty-properties-sync' ); ?>:</strong></label><br>
            <code><?php echo esc_html( $account_id ); ?></code>
        </p>
        <?php endif; ?>
        
        <?php if (!empty($last_updated)): ?>
        <p>
            <label><strong><?php _e( 'Last Updated', 'guesty-properties-sync' ); ?>:</strong></label><br>
            <span><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_updated ) ) ); ?></span>
        </p>
        <?php endif; ?>
        <?php
    }
}

// Initialize class
new Property_Metabox(); 