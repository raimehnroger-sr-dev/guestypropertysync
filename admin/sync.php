<?php
/**
 * Sync Properties admin page
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Include the property sync manager
require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/class-guesty-property-sync-manager.php';

// Check if sync was triggered
$sync_triggered = false;
$sync_results = array();

if ( isset( $_POST['guesty_sync_submit'] ) && check_admin_referer( 'guesty_sync_nonce' ) ) {
    $sync_triggered = true;
    
    // Get sync limit from form or use default
    $limit = isset( $_POST['guesty_sync_limit'] ) ? intval( $_POST['guesty_sync_limit'] ) : 10;
    
    // Perform the actual API sync using our sync manager
    $sync_manager = new Guesty_Property_Sync_Manager();
    $sync_results = $sync_manager->sync_properties( $limit );

    // Log the results
    if ( isset( $sync_results['success'] ) && $sync_results['success'] ) {
        error_log( 'Guesty manual-sync completed: ' . $sync_results['message'] );
    } else {
        error_log( 'Guesty manual-sync failed: ' . ( isset( $sync_results['message'] ) ? $sync_results['message'] : 'Unknown error' ) );
    }
}

// Get API credentials to check if they're set
$api_key = get_option( 'guesty_api_key', '' );
$api_secret = get_option( 'guesty_api_secret', '' );
$api_configured = ( ! empty( $api_key ) && ! empty( $api_secret ) );
?>

<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    
    <?php if ( $sync_triggered && isset( $sync_results['success'] ) && $sync_results['success'] ) : ?>
        <div class="notice notice-success">
            <p><?php echo esc_html( $sync_results['message'] ); ?></p>
            <?php if ( isset( $sync_results['properties_added'] ) || isset( $sync_results['properties_updated'] ) ) : ?>
                <ul>
                    <?php if ( isset( $sync_results['properties_added'] ) ) : ?>
                        <li><?php printf( __( 'Properties added: %d', 'guesty-properties-sync' ), $sync_results['properties_added'] ); ?></li>
                    <?php endif; ?>
                    
                    <?php if ( isset( $sync_results['properties_updated'] ) ) : ?>
                        <li><?php printf( __( 'Properties updated: %d', 'guesty-properties-sync' ), $sync_results['properties_updated'] ); ?></li>
                    <?php endif; ?>
                    
                    <?php if ( isset( $sync_results['properties_failed'] ) && $sync_results['properties_failed'] > 0 ) : ?>
                        <li><?php printf( __( 'Properties failed: %d', 'guesty-properties-sync' ), $sync_results['properties_failed'] ); ?></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php elseif ( $sync_triggered ) : ?>
        <div class="notice notice-error">
            <p><?php _e( 'An error occurred during sync.', 'guesty-properties-sync' ); ?></p>
            <?php if ( isset( $sync_results['message'] ) ) : ?>
                <p><?php echo esc_html( $sync_results['message'] ); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <h2><?php _e( 'Sync Properties from Guesty', 'guesty-properties-sync' ); ?></h2>
        
        <?php if ( ! $api_configured ) : ?>
            <div class="notice notice-warning">
                <p><?php _e( 'API credentials not configured. Please configure them in the Settings tab before syncing.', 'guesty-properties-sync' ); ?></p>
            </div>
        <?php else : ?>
            <p><?php _e( 'Click the button below to manually sync properties from Guesty. This may take a few minutes depending on the number of properties.', 'guesty-properties-sync' ); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'guesty_sync_nonce' ); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="guesty_sync_limit"><?php _e( 'Number of Properties', 'guesty-properties-sync' ); ?></label>
                        </th>
                        <td>
                            <input type="number" id="guesty_sync_limit" name="guesty_sync_limit" value="10" min="1" max="150" class="small-text" />
                            <p class="description"><?php _e( 'Limit the number of properties to sync (max: 100)', 'guesty-properties-sync' ); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="guesty_sync_submit" class="button-primary" value="<?php _e( 'Sync Properties Now', 'guesty-properties-sync' ); ?>" />
                </p>
            </form>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h2><?php _e( 'Sync History', 'guesty-properties-sync' ); ?></h2>
        <p><?php _e( 'Last sync: ', 'guesty-properties-sync' ); ?> 
        <?php 
        $last_sync = get_option( 'guesty_last_sync', false );
        if ( $last_sync ) {
            echo date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync );
        } else {
            _e( 'Never', 'guesty-properties-sync' );
        }
        ?>
        </p>
    </div>
    
    <?php if ( $api_configured ) : ?>
    <div class="card">
        <h2><?php _e( 'Property List', 'guesty-properties-sync' ); ?></h2>
        <?php
        // Query for existing property posts
        $args = array(
            'post_type'      => 'property',
            'posts_per_page' => 10,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );
        
        $properties_query = new WP_Query( $args );
        
        if ( $properties_query->have_posts() ) :
        ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e( 'Property', 'guesty-properties-sync' ); ?></th>
                        <th><?php _e( 'Guesty ID', 'guesty-properties-sync' ); ?></th>
                        <th><?php _e( 'Last Updated', 'guesty-properties-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ( $properties_query->have_posts() ) : $properties_query->the_post(); ?>
                        <tr>
                            <td>
                                <strong><a href="<?php echo get_edit_post_link(); ?>"><?php the_title(); ?></a></strong>
                            </td>
                            <td><?php echo esc_html( get_post_meta( get_the_ID(), 'guesty_id', true ) ); ?></td>
                            <td><?php echo esc_html( get_post_meta( get_the_ID(), 'guesty_last_updated', true ) ); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            
            <p><a href="<?php echo admin_url( 'edit.php?post_type=property' ); ?>" class="button"><?php _e( 'View All Properties', 'guesty-properties-sync' ); ?></a></p>
        <?php
        else :
            _e( 'No properties have been synced yet.', 'guesty-properties-sync' );
        endif;
        
        wp_reset_postdata();
        ?>
    </div>
    <?php endif; ?>
</div> 