<?php
/**
 * Persistent cache for exact Guesty quote responses.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Guesty_Optimized_Pricing_Cache {

    const TABLE_SUFFIX = 'guesty_optimized_quotes';
    const DB_VERSION   = '2.0.0';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function create_table() {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cache_key char(40) NOT NULL,
            guesty_id varchar(80) NOT NULL,
            check_in date NOT NULL,
            check_out date NOT NULL,
            adults smallint unsigned NOT NULL DEFAULT 1,
            children smallint unsigned NOT NULL DEFAULT 0,
            infants smallint unsigned NOT NULL DEFAULT 0,
            pets smallint unsigned NOT NULL DEFAULT 0,
            quote_json longtext NOT NULL,
            total_price decimal(12,2) DEFAULT NULL,
            adjusted_price decimal(12,2) DEFAULT NULL,
            currency varchar(12) DEFAULT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cache_key (cache_key),
            KEY guesty_id (guesty_id),
            KEY expires_at (expires_at),
            KEY stay_dates (check_in, check_out)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'guesty_optimized_quotes_db_version', self::DB_VERSION, false );
    }

    public static function maybe_upgrade() {
        if ( get_option( 'guesty_optimized_quotes_db_version' ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    public function key( $guesty_id, $check_in, $check_out, $adults, $children, $infants, $pets ) {
        return sha1( implode( '|', array(
            $guesty_id,
            $check_in,
            $check_out,
            (int) $adults,
            (int) $children,
            (int) $infants,
            (int) $pets,
        ) ) );
    }

    public function get( $guesty_id, $check_in, $check_out, $adults = 1, $children = 0, $infants = 0, $pets = 0, $allow_stale = false ) {
        global $wpdb;

        $table = self::table_name();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }

        $key = $this->key( $guesty_id, $check_in, $check_out, $adults, $children, $infants, $pets );
        $sql = "SELECT * FROM {$table} WHERE cache_key = %s";
        if ( ! $allow_stale ) {
            $sql .= ' AND expires_at > %s';
            $row = $wpdb->get_row( $wpdb->prepare( $sql, $key, current_time( 'mysql', true ) ), ARRAY_A );
        } else {
            $row = $wpdb->get_row( $wpdb->prepare( $sql, $key ), ARRAY_A );
        }

        if ( empty( $row ) ) {
            return null;
        }

        $quote = json_decode( $row['quote_json'], true );
        if ( ! is_array( $quote ) ) {
            return null;
        }

        return array(
            'quote_data'     => $quote,
            'total_price'    => null === $row['total_price'] ? null : (float) $row['total_price'],
            'adjusted_price' => null === $row['adjusted_price'] ? null : (float) $row['adjusted_price'],
            'currency'       => $row['currency'],
            'expires_at'     => $row['expires_at'],
            'is_stale'       => strtotime( $row['expires_at'] . ' UTC' ) <= time(),
        );
    }

    public function set( $guesty_id, $check_in, $check_out, $adults, $children, $infants, $pets, array $quote, $ttl_minutes = 15 ) {
        global $wpdb;

        $table = self::table_name();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            self::create_table();
        }

        $prices = $this->extract_prices( $quote );
        $now    = current_time( 'mysql', true );
        $expiry = gmdate( 'Y-m-d H:i:s', time() + ( max( 1, (int) $ttl_minutes ) * MINUTE_IN_SECONDS ) );

        $data = array(
            'cache_key'      => $this->key( $guesty_id, $check_in, $check_out, $adults, $children, $infants, $pets ),
            'guesty_id'      => sanitize_text_field( $guesty_id ),
            'check_in'       => sanitize_text_field( $check_in ),
            'check_out'      => sanitize_text_field( $check_out ),
            'adults'         => (int) $adults,
            'children'       => (int) $children,
            'infants'        => (int) $infants,
            'pets'           => (int) $pets,
            'quote_json'     => wp_json_encode( $quote ),
            'total_price'    => $prices['total'],
            'adjusted_price' => $prices['adjusted'],
            'currency'       => $prices['currency'],
            'created_at'     => $now,
            'expires_at'     => $expiry,
        );

        return false !== $wpdb->replace(
            $table,
            $data,
            array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%f', '%f', '%s', '%s', '%s' )
        );
    }

    public function invalidate_overlapping( $guesty_id, $start_date, $end_date ) {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE guesty_id = %s AND NOT (check_out <= %s OR check_in >= %s)",
                $guesty_id,
                $start_date,
                $end_date
            )
        );
    }

    public function cleanup() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
    }

    public function clear() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
    }

    public function stats() {
        global $wpdb;
        $table = self::table_name();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $exists ) {
            return array( 'rows' => 0, 'valid' => 0, 'properties' => 0 );
        }

        $now = current_time( 'mysql', true );
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        return array(
            'rows'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'valid'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE expires_at > %s", $now ) ),
            'properties' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT guesty_id) FROM {$table}" ),
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    public function extract_prices( array $quote ) {
        $plans = array();

        if ( ! empty( $quote['rates']['ratePlans'] ) && is_array( $quote['rates']['ratePlans'] ) ) {
            $plans = $quote['rates']['ratePlans'];
        } elseif ( ! empty( $quote['ratePlans'] ) && is_array( $quote['ratePlans'] ) ) {
            $plans = $quote['ratePlans'];
        }

        $total    = null;
        $adjusted = null;
        $currency = '';

        if ( ! empty( $plans ) ) {
            $plan  = reset( $plans );
            $money = isset( $plan['money']['money'] ) ? $plan['money']['money'] : ( isset( $plan['money'] ) ? $plan['money'] : array() );

            foreach ( array( 'fareAccommodation', 'totalPrice', 'total' ) as $key ) {
                if ( isset( $money[ $key ] ) && is_numeric( $money[ $key ] ) ) {
                    $total = (float) $money[ $key ];
                    break;
                }
            }
            foreach ( array( 'fareAccommodationAdjusted', 'totalPriceAdjusted', 'total' ) as $key ) {
                if ( isset( $money[ $key ] ) && is_numeric( $money[ $key ] ) ) {
                    $adjusted = (float) $money[ $key ];
                    break;
                }
            }
            $currency = $money['currency'] ?? ( $plan['currency'] ?? '' );
        }

        if ( null === $adjusted ) {
            $adjusted = $total;
        }

        return array(
            'total'    => $total,
            'adjusted' => $adjusted,
            'currency' => sanitize_text_field( $currency ),
        );
    }
}
