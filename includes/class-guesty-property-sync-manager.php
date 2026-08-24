<?php
/**
 * Property Sync Manager Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes
 */

class Guesty_Property_Sync_Manager {
    
    /**
     * Guesty API instance
     *
     * @var Guesty_API
     */
    private $api;
    
    /**
     * Initialize the class and set its properties.
     */
    public function __construct() {
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/api/class-guesty-api.php';
        $this->api = new Guesty_API();
    }
    
    /**
     * Sync properties from Guesty in batches using do-while
     *
     * @param int $batch_size Number of properties to sync per batch
     * @return array Sync results
     */
    public function sync_properties( $batch_size = 50 ) {
        $results = array(
            'success' => false,
            'message' => '',
            'properties_added' => 0,
            'properties_updated' => 0,
            'properties_failed' => 0,
            'total_processed' => 0,
        );

        $offset = 0;
        $total_properties = null;

        if ( class_exists( 'Guesty_Activity_Log' ) ) {
            Guesty_Activity_Log::add( 'sync', '', 'Property sync started.', 'success' );
        }

        do {
            // Fetch batch
            $properties = $this->api->get_properties( $batch_size, $offset );

            if ( is_wp_error( $properties ) ) {
                $results['message'] = $properties->get_error_message();
                if ( class_exists( 'Guesty_Activity_Log' ) ) {
                    Guesty_Activity_Log::add( 'sync', '', 'Property sync failed: ' . $results['message'], 'error' );
                }
                return $results;
            }

            if ( empty( $properties ) || ! isset( $properties['results'] ) || count( $properties['results'] ) === 0 ) {
                break; // no more properties
            }

            $batch_count = count( $properties['results'] );
            if ( isset( $properties['count'] ) && is_numeric( $properties['count'] ) ) {
                $total_properties = max( 0, (int) $properties['count'] );
            }

            // Process each property
            foreach ( $properties['results'] as $property ) {
                $result = $this->process_property( $property );

                if ( $result === 'added' ) {
                    $results['properties_added']++;
                } elseif ( $result === 'updated' ) {
                    $results['properties_updated']++;
                } else {
                    $results['properties_failed']++;
                }

                $results['total_processed']++;
            }

            // Move to the next batch. When Guesty omits the aggregate count,
            // a short batch is the reliable end-of-pagination signal.
            $offset += $batch_count;
            $has_more = null !== $total_properties
                ? $offset < $total_properties
                : $batch_count >= $batch_size;

        } while ( $has_more );

        // Update last sync time
        update_option( 'guesty_last_sync', time() );

        $results['success'] = true;
        $results['message'] = sprintf(
            __( 'Processed %d properties in total. Added: %d, Updated: %d, Failed: %d', 'guesty-properties-sync' ),
            $results['total_processed'],
            $results['properties_added'],
            $results['properties_updated'],
            $results['properties_failed']
        );

        wp_cache_delete( 'guesty_synced_property_types', 'guesty' );
        if ( class_exists( 'Guesty_Activity_Log' ) ) {
            Guesty_Activity_Log::add(
                'sync',
                '',
                $results['message'],
                $results['properties_failed'] > 0 ? 'warning' : 'success'
            );
        }

        return $results;
    }
    
    /**
     * Process a single property
     *
     * @param array $property Property data from Guesty API
     * @return string Status: 'added', 'updated', or 'failed'
     */
    public function process_property( $property ) {
        if ( ! is_array( $property ) || empty( $property['_id'] ) ) {
            if ( class_exists( 'Guesty_Activity_Log' ) ) {
                Guesty_Activity_Log::add( 'sync', '', 'Skipped property payload without a Guesty listing ID.', 'error' );
            }
            return 'failed';
        }

        // Check if property exists by Guesty ID.
        $existing_property = $this->get_property_by_guesty_id( $property['_id'] );
        $is_active = ! empty( $property['active'] );
        $is_listed = ! array_key_exists( 'isListed', $property ) || ! empty( $property['isListed'] );
        $post_status = ( $is_active && $is_listed ) ? 'publish' : 'draft';

        // Generate slug (you can customize this)
        $slug = sanitize_title( $this->get_property_title( $property ) );
        
        $property_data = array(
            'post_title'   => $this->get_property_title( $property ),
            'post_content' => $this->get_property_content( $property ),
            'post_status'  => $post_status,
            'post_type'    => 'property',
            'post_name'    => $slug,
            'meta_input'   => $this->get_property_meta( $property ),
        );
        
        if ( $existing_property ) {
            // Update existing property
            $property_data['ID'] = $existing_property->ID;
            $post_id = wp_update_post( $property_data );
            $status = 'updated';
        } else {
            // Create new property
            $post_id = wp_insert_post( $property_data );
            $status = 'added';
        }
        
        if ( is_wp_error( $post_id ) ) {
            error_log( 'Failed to process property: ' . $post_id->get_error_message() );
            if ( class_exists( 'Guesty_Activity_Log' ) ) {
                Guesty_Activity_Log::add( 'sync', (string) ( $property['_id'] ?? '' ), 'Property sync failed: ' . $post_id->get_error_message(), 'error' );
            }
            return 'failed';
        }

        if ( !empty( $property['tags'] ) && is_array( $property['tags'] ) ) {
            $tags = array_map( 'sanitize_text_field', $property['tags'] );
            wp_set_object_terms( $post_id, $tags, 'property_tag', false );
        }
        
        return $status;
    }

    /**
     * Delete a property by Guesty listing ID
     *
     * Finds a WordPress property post linked to the given Guesty listing ID
     * and permanently deletes it from the system.
     *
     * @param string $listing_id Guesty listing ID
     * @return array {
     *     Array containing operation result.
     *
     *     @type bool   $success Whether the deletion was successful.
     *     @type string  $message Optional status message.
     *     @type int     $post_id Optional deleted post ID.
     * }
     */
    public function delete_property( $listing_id ) {

        if ( empty( $listing_id ) ) {
            error_log('Delete failed: Missing listing ID');
            return [
                'success' => false,
                'message' => 'Missing listing ID'
            ];
        }

        // Find property by Guesty ID
        $existing_property = $this->get_property_by_guesty_id( $listing_id );

        if ( !$existing_property ) {
            error_log("Delete skipped: Property not found for Guesty ID {$listing_id}");
            return [
                'success' => true,
                'message' => 'Property not found (already deleted)'
            ];
        }

        $post_id = $existing_property->ID;

        // Permanently delete (use false if you prefer trash instead)
        $deleted = wp_delete_post( $post_id, false );

        if ( !$deleted ) {
            error_log("Delete failed: Could not delete post ID {$post_id}");
            return [
                'success' => false,
                'message' => 'Failed to delete property'
            ];
        }

        error_log("Property deleted: Guesty ID {$listing_id} | Post ID {$post_id}");

        return [
            'success' => true,
            'post_id' => $post_id
        ];
    }
    
    /**
     * Get a property post by Guesty ID
     *
     * @param string $guesty_id Guesty property ID
     * @return WP_Post|false Post object or false if not found
     */
    private function get_property_by_guesty_id( $guesty_id ) {
        $args = array(
            'post_type'      => 'property',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'meta_query'     => array(
                array(
                    'key'   => 'guesty_id',
                    'value' => $guesty_id,
                ),
            ),
        );
        
        $query = new WP_Query( $args );
        
        if ( $query->have_posts() ) {
            return $query->posts[0];
        }
        
        return false;
    }
    
    /**
     * Get property title from Guesty data
     *
     * @param array $property Property data from Guesty API
     * @return string Property title
     */
    private function get_property_title( $property ) {
        // Use title or nickname as the property title
        if ( !empty( $property['title'] ) ) {
            return sanitize_text_field( $property['title'] );
        } elseif ( !empty( $property['nickname'] ) ) {
            return sanitize_text_field( $property['nickname'] );
        } else {
            return __( 'Unnamed Property', 'guesty-properties-sync' );
        }
    }
    
    /**
     * Get property content from Guesty data
     *
     * @param array $property Property data from Guesty API
     * @return string Property content
     */
    private function get_property_content( $property ) {
        $content = '';
        
        // Use description as content if available
        if ( !empty( $property['publicDescription']['space'] ) ) {
            $content = wp_kses_post( $property['publicDescription']['space']);
        }
        
        return $content;
    }
    
    /**
     * Get property meta fields from Guesty data
     *
     * @param array $property Property data from Guesty API
     * @return array Property meta fields
     */
    private function get_property_meta( $property ) {
        $meta = array(
            'guesty_id'                 => $property['_id'],
            'guesty_last_updated'       => current_time( 'mysql' ),
            // Always write the optimisation-plan fields so removed/zero values
            // cannot leave stale searchable pricing or capacity metadata.
            'property_base_price'       => 0,
            'property_min_nights'       => 0,
            'property_type'             => '',
            'property_accommodates'     => 0,
            'property_bedrooms'         => 0,
            'property_bathrooms'        => 0,
            'property_currency'         => get_option( 'guesty_default_currency', 'GBP' ),
        );
        // echo '<pre>';
        // print_r($property);
        // echo '</pre>';
        
        // Add address information if available
        if ( !empty( $property['address'] ) ) {
            $meta['property_address'] = maybe_serialize( $property['address'] );
            
            if ( !empty( $property['address']['street'] ) ) {
                $meta['property_street'] = sanitize_text_field( $property['address']['street'] );
            }
            
            if ( !empty( $property['address']['city'] ) ) {
                $meta['property_city'] = sanitize_text_field( $property['address']['city'] );
            }
            
            if ( !empty( $property['address']['state'] ) ) {
                $meta['property_state'] = sanitize_text_field( $property['address']['state'] );
            }
            
            if ( !empty( $property['address']['zipcode'] ) ) {
                $meta['property_zipcode'] = sanitize_text_field( $property['address']['zipcode'] );
            }
            
            if ( !empty( $property['address']['country'] ) ) {
                $meta['property_country'] = sanitize_text_field( $property['address']['country'] );
            }
            
            if ( !empty( $property['address']['lat'] ) && !empty( $property['address']['lng'] ) ) {
                $meta['property_latitude'] = (float) $property['address']['lat'];
                $meta['property_longitude'] = (float) $property['address']['lng'];
            }
            
            if ( !empty( $property['address']['lat'] ) && !empty( $property['address']['lng'] ) ) {
                $meta['property_latlang'] = (float) $property['address']['lat'] . ', ' . (float) $property['address']['lng'];
            }
            
            if ( !empty( $property['address']['apt'] ) ) {
                $meta['property_apt'] = sanitize_text_field( $property['address']['apt'] );
            }

            if ( !empty( $property['address']['full'] ) ) {
                $meta['property_full_address'] = sanitize_text_field( $property['address']['full'] );
            }
            
            if ( !empty( $property['address']['apartment'] ) ) {
                $meta['property_apartment'] = sanitize_text_field( $property['address']['apartment'] );
            }
            
            if ( !empty( $property['address']['county'] ) ) {
                $meta['property_county'] = sanitize_text_field( $property['address']['county'] );
            }

            if ( !empty( $property['address']['floor'] ) ) {
                $meta['property_floor'] = sanitize_text_field( $property['address']['floor'] );
            }

            if ( !empty( $property['address']['unit'] ) ) {
                $meta['property_unit'] = sanitize_text_field( $property['address']['unit'] );
            }

            if ( !empty( $property['address']['neighborhood'] ) ) {
                $meta['property_neighborhood'] = sanitize_text_field( $property['address']['neighborhood'] );
            }
            
            if ( !empty( $property['address']['buildingName'] ) ) {
                $meta['property_building_name'] = sanitize_text_field( $property['address']['buildingName'] );
            }
        }
        
        // Add basic property details
        if ( !empty( $property['bedrooms'] ) ) {
            $meta['property_bedrooms'] = intval( $property['bedrooms'] );
        }
        
        if ( !empty( $property['bathrooms'] ) ) {
            $meta['property_bathrooms'] = floatval( $property['bathrooms'] );
        }
        
        if ( !empty( $property['accommodates'] ) ) {
            $meta['property_accommodates'] = intval( $property['accommodates'] );
        }
        
        // Add pricing information - handle both old and new API formats
        // First check the newer API format (prices object)
        if ( !empty( $property['prices'] ) ) {
            if ( !empty( $property['prices']['basePrice'] ) ) {
                $meta['property_base_price'] = floatval( $property['prices']['basePrice'] );
            }
            
            if ( !empty( $property['prices']['basePriceUSD'] ) ) {
                $meta['property_base_price_usd'] = floatval( $property['prices']['basePriceUSD'] );
            }
            
            if ( !empty( $property['prices']['currency'] ) ) {
                $meta['property_currency'] = sanitize_text_field( $property['prices']['currency'] );
            }
            
            if ( !empty( $property['prices']['cleaningFee'] ) ) {
                $meta['property_cleaning_fee'] = floatval( $property['prices']['cleaningFee'] );
            }
            
            if ( !empty( $property['prices']['securityDepositFee'] ) ) {
                $meta['property_security_deposit'] = floatval( $property['prices']['securityDepositFee'] );
            }
            
            // Add additional pricing fields
            if ( !empty( $property['prices']['weekendBasePrice'] ) ) {
                $meta['property_weekend_base_price'] = floatval( $property['prices']['weekendBasePrice'] );
            }
            
            if ( !empty( $property['prices']['guestsIncludedInRegularFee'] ) ) {
                $meta['property_guests_included'] = intval( $property['prices']['guestsIncludedInRegularFee'] );
            }
            
            if ( !empty( $property['prices']['extraPersonFee'] ) ) {
                $meta['property_extra_person_fee'] = floatval( $property['prices']['extraPersonFee'] );
            }
            
            if ( !empty( $property['prices']['monthlyPriceFactor'] ) ) {
                $meta['property_monthly_price_factor'] = floatval( $property['prices']['monthlyPriceFactor'] );
            }
            
            if ( !empty( $property['prices']['weeklyPriceFactor'] ) ) {
                $meta['property_weekly_price_factor'] = floatval( $property['prices']['weeklyPriceFactor'] );
            }
        } 
        // Then check the legacy pricing field for backward compatibility
        elseif ( !empty( $property['pricing'] ) ) {
            if ( !empty( $property['pricing']['basePrice'] ) ) {
                $meta['property_base_price'] = floatval( $property['pricing']['basePrice'] );
            }
            
            if ( !empty( $property['pricing']['currency'] ) ) {
                $meta['property_currency'] = sanitize_text_field( $property['pricing']['currency'] );
            }
            
            if ( !empty( $property['pricing']['cleaningFee'] ) ) {
                $meta['property_cleaning_fee'] = floatval( $property['pricing']['cleaningFee'] );
            }
            
            if ( !empty( $property['pricing']['securityDeposit'] ) ) {
                $meta['property_security_deposit'] = floatval( $property['pricing']['securityDeposit'] );
            }
        }
        // Finally, check for direct pricing fields (oldest API format)
        else {
            if ( !empty( $property['basePrice'] ) ) {
                $meta['property_base_price'] = floatval( $property['basePrice'] );
            }
            
            if ( !empty( $property['currency'] ) ) {
                $meta['property_currency'] = sanitize_text_field( $property['currency'] );
            }
        }
        
        // Add any property-level additional fees if available
        if ( !empty( $property['additionalFees'] ) && is_array( $property['additionalFees'] ) ) {
            $meta['property_additional_fees'] = maybe_serialize( $property['additionalFees'] );
            
            // Process each additional fee and add as individual meta entries
            foreach ( $property['additionalFees'] as $fee ) {
                if ( !empty( $fee['secondIdentifier'] ) && !empty( $fee['amount'] ) ) {
                    $meta['property_fee_' . sanitize_title( $fee['secondIdentifier'] )] = floatval( $fee['amount'] );
                    
                    // Also store the fee details
                    if ( !empty( $fee['title'] ) ) {
                        $meta['property_fee_' . sanitize_title( $fee['secondIdentifier'] ) . '_title'] = sanitize_text_field( $fee['title'] );
                    }
                    
                    if ( !empty( $fee['type'] ) ) {
                        $meta['property_fee_' . sanitize_title( $fee['secondIdentifier'] ) . '_type'] = sanitize_text_field( $fee['type'] );
                    }
                }
            }
        }
        
        if ( !empty( $property['nickname'] ) ) {
            $meta['property_nickname'] = sanitize_text_field( $property['nickname'] );
        }
        
        if ( !empty( $property['title'] ) ) {
            $meta['property_title'] = sanitize_text_field( $property['title'] );
        }
        
        // Add property amenities
        if ( !empty( $property['amenities'] ) && is_array( $property['amenities'] ) ) {
            $meta['property_amenities'] = maybe_serialize( $property['amenities'] );
            
            // Create individual meta entries for easier querying
            foreach ( $property['amenities'] as $amenity ) {
                if (is_string($amenity)) {
                    $meta['property_amenity_' . sanitize_title($amenity)] = 1;
                }
            }
        }
        
        // Add property images
        if ( !empty( $property['pictures'] ) && is_array( $property['pictures'] ) ) {
            
            // Remove pictures with caption 'FLOORPLAN'
            $filtered_pictures = array_filter( $property['pictures'], function( $picture ) {
                return !isset( $picture['caption'] ) || strtoupper( $picture['caption'] ) !== 'FLOORPLAN';
            });

            // Reindex the array to avoid non-sequential keys
            $filtered_pictures = array_values( $filtered_pictures );

            // Save filtered pictures
            if ( !empty( $filtered_pictures ) ) {
                $meta['property_pictures'] = maybe_serialize( $filtered_pictures );

                // Store the main image URL separately if available
                if ( !empty( $filtered_pictures[0]['original'] ) ) {
                    $meta['property_main_image'] = esc_url_raw( $filtered_pictures[0]['original'] );
                }
            }
        }

        // Floorplan image
        $floorplan_found = false;
        if ( ! empty( $property['pictures'] ) && is_array( $property['pictures'] ) ) {
            foreach ( array_reverse( $property['pictures'] ) as $picture ) {
                if ( ! empty( $picture['caption'] ) && strtoupper( $picture['caption'] ) === 'FLOORPLAN' ) {
                    $meta['property_floorPlan_img'] = esc_url_raw( $picture['original'] );
					$floorplan_found = true;
                    // error_log( 'FLOORPLAN image found: ' . $meta['property_floorPlan_img'] );
                    break; // Stop after the first match from the end
                }
            }
        }
		
		// Clear the field if no floorplan exists
		if ( ! $floorplan_found ) {
			$meta['property_floorPlan_img'] = ''; // or null
		}
        
        // Add property status
        if ( !empty( $property['status'] ) ) {
            $meta['property_status'] = sanitize_text_field( $property['status'] );
        }
        
        // Add property tags/labels
        if ( !empty( $property['tags'] ) && is_array( $property['tags'] ) ) {
            $meta['property_tags'] = maybe_serialize( $property['tags'] );
        }
        
        // Add property type
        if ( !empty( $property['propertyType'] ) ) {
            $meta['property_type'] = sanitize_text_field( $property['propertyType'] );
        }
        
        // Add unique identifiers
        if ( !empty( $property['listingId'] ) ) {
            $meta['property_listing_id'] = sanitize_text_field( $property['listingId'] );
        }
        
        if ( !empty( $property['accountId'] ) ) {
            $meta['property_account_id'] = sanitize_text_field( $property['accountId'] );
        }
        
        // Add terms information if available
        if ( !empty( $property['terms'] ) ) {
            if ( !empty( $property['terms']['minNights'] ) ) {
                $meta['property_min_nights'] = intval( $property['terms']['minNights'] );
            }
            
            if ( !empty( $property['terms']['maxNights'] ) ) {
                $meta['property_max_nights'] = intval( $property['terms']['maxNights'] );
            }
        }

        if ( !empty( $property['publicDescription']['space'] ) ) {
            $meta['property_description_space'] = sanitize_textarea_field( $property['publicDescription']['space'] );
        }

        if ( ! empty( $property['publicDescription']['neighborhood'] ) ) {
            $neighborhood = $property['publicDescription']['neighborhood'];

            // Split on the first hyphen
            $parts = explode('-', $neighborhood, 2);

            // Trim spaces and take the first part
            $meta['property_description_neighborhood'] = sanitize_text_field( trim($parts[0]) );
        }

        // Custom Fields

        $meta['property_360_video_link'] = '';
        $meta['property_epc_ratings'] = '';
        $meta['property_dog_permitted'] = '';

        if (!empty($property['customFields']) && is_array($property['customFields'])) {

            foreach ($property['customFields'] as $field) {

                if ( !empty($field['fieldId']) && $field['fieldId'] === '683fe7a8f53dec00118b7669' && !empty($field['value']) ) {
                    $meta['property_360_video_link'] = sanitize_textarea_field($field['value']);
                    
                }

                if ( !empty($field['fieldId']) && $field['fieldId'] === '69306427ce299a003a12ec57' && !empty($field['value']) ) {
                    $meta['property_epc_ratings'] = sanitize_textarea_field($field['value']);
                    
                }

                if ( !empty($field['fieldId']) && $field['fieldId'] === '69762184e24cd20014f80fc3' && !empty($field['value']) ) {
                    $meta['property_dog_permitted'] = sanitize_textarea_field($field['value']);
                    
                }
            }
        }
        
        // Stable optimisation-plan aliases used by search cards and filters.
        $meta['_guesty_base_price']   = isset( $meta['property_base_price'] ) ? (float) $meta['property_base_price'] : 0;
        $meta['_guesty_min_nights']   = isset( $meta['property_min_nights'] ) ? (int) $meta['property_min_nights'] : 0;
        $meta['_guesty_property_type'] = isset( $meta['property_type'] ) ? (string) $meta['property_type'] : '';
        $meta['_guesty_max_guests']   = isset( $meta['property_accommodates'] ) ? (int) $meta['property_accommodates'] : 0;
        $meta['_guesty_bedrooms']     = isset( $meta['property_bedrooms'] ) ? (int) $meta['property_bedrooms'] : 0;
        $meta['_guesty_bathrooms']    = isset( $meta['property_bathrooms'] ) ? (float) $meta['property_bathrooms'] : 0;

        // Checkout trust content is stored during listing sync so checkout can
        // render it without another Guesty request.
        $cancellation_policy = $this->first_property_value(
            $property,
            array( 'cancellationPolicy', 'cancellation_policy', 'terms.cancellationPolicy', 'terms.cancellation_policy' )
        );
        $house_rules = $this->first_property_value(
            $property,
            array( 'houseRules', 'house_rules', 'publicDescription.houseRules', 'publicDescription.house_rules' )
        );
        $meta['property_cancellation_policy'] = $this->normalise_text_value( $cancellation_policy );
        $meta['property_house_rules'] = $this->normalise_text_value( $house_rules );

        // Promotion and featured state are also local metadata. This prevents
        // a quote call being used merely to decide whether to show a badge.
        $promotions = $property['activePromotions'] ?? $property['promotions'] ?? array();
        $promotion_name = '';
        if ( is_array( $promotions ) ) {
            $promotion_rows = isset( $promotions[0] ) ? $promotions : array( $promotions );
            foreach ( $promotion_rows as $promotion ) {
                if ( ! is_array( $promotion ) ) {
                    continue;
                }
                if ( isset( $promotion['active'] ) && ! $promotion['active'] ) {
                    continue;
                }
                $promotion_name = sanitize_text_field( (string) ( $promotion['name'] ?? $promotion['title'] ?? $promotion['code'] ?? '' ) );
                if ( '' !== $promotion_name ) {
                    break;
                }
            }
        }
        $meta['property_has_promotion'] = '' !== $promotion_name ? 1 : 0;
        $meta['property_promotion_name'] = $promotion_name;

        $tags_for_featured = array_map( 'strtolower', array_map( 'strval', (array) ( $property['tags'] ?? array() ) ) );
        $meta['property_featured'] = ! empty( $property['isFeatured'] ) || in_array( 'featured', $tags_for_featured, true ) ? 1 : 0;

        // Store the complete property data for reference
        $meta['guesty_property_data'] = maybe_serialize( $property );
        
        return $meta;
    }

    /**
     * Return the first non-empty value found at one of the supplied dot paths.
     */
    private function first_property_value( array $property, array $paths ) {
        foreach ( $paths as $path ) {
            $value = $property;
            foreach ( explode( '.', $path ) as $segment ) {
                if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                    $value = null;
                    break;
                }
                $value = $value[ $segment ];
            }
            if ( null !== $value && '' !== $value && array() !== $value ) {
                return $value;
            }
        }
        return '';
    }

    /**
     * Convert Guesty text/array policy fields into safe readable post meta.
     */
    private function normalise_text_value( $value ) {
        if ( is_array( $value ) ) {
            $parts = array();
            array_walk_recursive( $value, function ( $item ) use ( &$parts ) {
                if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
                    $parts[] = trim( (string) $item );
                }
            } );
            $value = implode( "
", array_values( array_unique( $parts ) ) );
        }
        return sanitize_textarea_field( (string) $value );
    }
} 