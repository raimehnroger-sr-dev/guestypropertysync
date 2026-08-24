<?php
/**
 * Lightweight activity log for sync, webhook, and API events.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Guesty_Activity_Log {

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'guesty_activity_log';
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            event_type varchar(40) NOT NULL,
            listing_id varchar(100) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'success',
            message text NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY event_type (event_type),
            KEY listing_id (listing_id),
            KEY status (status)
        ) {$charset};";
        dbDelta( $sql );
        update_option( 'guesty_activity_log_db_version', '1.0', false );
    }

    public static function maybe_upgrade() {
        if ( '1.0' !== get_option( 'guesty_activity_log_db_version', '' ) ) {
            self::create_table();
        }
    }

    public static function add( $event_type, $listing_id, $message, $status = 'success' ) {
        global $wpdb;
        self::maybe_upgrade();

        $allowed_statuses = array( 'success', 'warning', 'error' );
        $status = in_array( $status, $allowed_statuses, true ) ? $status : 'success';

        $wpdb->insert(
            self::table_name(),
            array(
                'created_at' => current_time( 'mysql', true ),
                'event_type' => sanitize_key( $event_type ),
                'listing_id' => sanitize_text_field( (string) $listing_id ),
                'status'     => $status,
                'message'    => sanitize_textarea_field( (string) $message ),
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public static function recent( $limit = 100 ) {
        global $wpdb;
        self::maybe_upgrade();
        $limit = max( 1, min( 500, (int) $limit ) );
        return $wpdb->get_results( "SELECT * FROM " . self::table_name() . " ORDER BY id DESC LIMIT {$limit}", ARRAY_A );
    }

    public static function clear() {
        global $wpdb;
        self::maybe_upgrade();
        $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
    }

    public static function cleanup() {
        global $wpdb;
        self::maybe_upgrade();
        $days = max( 1, min( 365, (int) get_option( 'guesty_activity_log_retention_days', 30 ) ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s', $cutoff ) );
    }
}
