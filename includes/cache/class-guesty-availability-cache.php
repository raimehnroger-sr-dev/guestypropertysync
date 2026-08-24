<?php
/**
 * Persistent Guesty calendar cache.
 *
 * Stores one row per listing/date so frontend searches and calendars do not need
 * to call Guesty on every page request.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Guesty_Optimized_Availability_Cache {

    const TABLE_SUFFIX = 'guesty_optimized_calendar';
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
            guesty_id varchar(80) NOT NULL,
            calendar_date date NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'unavailable',
            min_nights smallint(5) unsigned NOT NULL DEFAULT 1,
            price decimal(12,2) DEFAULT NULL,
            currency varchar(12) DEFAULT NULL,
            allotment int(11) DEFAULT NULL,
            day_json longtext DEFAULT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY guesty_date (guesty_id, calendar_date),
            KEY calendar_date (calendar_date),
            KEY status (status),
            KEY last_synced (last_synced)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'guesty_optimized_calendar_db_version', self::DB_VERSION, false );
    }

    public static function maybe_upgrade() {
        if ( get_option( 'guesty_optimized_calendar_db_version' ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    public function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * Save a Guesty calendar response.
     *
     * @param string $guesty_id Guesty listing ID.
     * @param array  $calendar  Guesty response.
     * @return int Number of saved days.
     */
    public function save_calendar( $guesty_id, $calendar ) {
        global $wpdb;

        if ( empty( $guesty_id ) || is_wp_error( $calendar ) || ! is_array( $calendar ) ) {
            return 0;
        }

        if ( ! $this->table_exists() ) {
            self::create_table();
        }

        $days = $this->extract_days( $calendar );
        if ( empty( $days ) ) {
            return 0;
        }

        $table = self::table_name();
        $now   = current_time( 'mysql', true );
        $saved = 0;

        foreach ( array_chunk( $days, 75 ) as $chunk ) {
            $placeholders = array();
            $values       = array();

            foreach ( $chunk as $day ) {
                if ( empty( $day['date'] ) || ! $this->valid_date( $day['date'] ) ) {
                    continue;
                }

                $allotment = isset( $day['allotment'] ) && is_numeric( $day['allotment'] )
                    ? (int) $day['allotment']
                    : null;

                $status = isset( $day['status'] ) ? sanitize_key( $day['status'] ) : 'unavailable';
                if ( null !== $allotment ) {
                    $status = $allotment > 0 ? 'available' : 'unavailable';
                }

                $price = null;
                if ( isset( $day['price'] ) && is_numeric( $day['price'] ) ) {
                    $price = (float) $day['price'];
                } elseif ( isset( $day['rate'] ) && is_numeric( $day['rate'] ) ) {
                    $price = (float) $day['rate'];
                }

                $currency = '';
                if ( ! empty( $day['currency'] ) ) {
                    $currency = sanitize_text_field( $day['currency'] );
                } elseif ( ! empty( $calendar['currency'] ) ) {
                    $currency = sanitize_text_field( $calendar['currency'] );
                } elseif ( ! empty( $calendar['data']['currency'] ) ) {
                    $currency = sanitize_text_field( $calendar['data']['currency'] );
                }

                $placeholders[] = '(%s,%s,%s,%d,NULLIF(%f,0),%s,NULLIF(%d,-1),%s,%s)';
                $values[]       = sanitize_text_field( $guesty_id );
                $values[]       = sanitize_text_field( $day['date'] );
                $values[]       = $status;
                $values[]       = isset( $day['minNights'] ) ? max( 1, (int) $day['minNights'] ) : 1;
                $values[]       = null === $price ? 0 : $price;
                $values[]       = $currency;
                $values[]       = null === $allotment ? -1 : $allotment;
                $values[]       = wp_json_encode( $day );
                $values[]       = $now;
            }

            if ( empty( $placeholders ) ) {
                continue;
            }

            $sql = "INSERT INTO {$table}
                (guesty_id, calendar_date, status, min_nights, price, currency, allotment, day_json, last_synced)
                VALUES " . implode( ',', $placeholders ) . "
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    min_nights = VALUES(min_nights),
                    price = NULLIF(VALUES(price), 0),
                    currency = VALUES(currency),
                    allotment = NULLIF(VALUES(allotment), -1),
                    day_json = VALUES(day_json),
                    last_synced = VALUES(last_synced)";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
            if ( false !== $result ) {
                $saved += count( $placeholders );
            }
        }

        return $saved;
    }

    /**
     * Return a Guesty-compatible calendar response from local storage.
     */
    public function get_calendar( $guesty_id, $start_date, $end_date, $max_age_hours = 0 ) {
        global $wpdb;

        if ( ! $this->table_exists() || ! $this->valid_date( $start_date ) || ! $this->valid_date( $end_date ) ) {
            return null;
        }

        $table  = self::table_name();
        $params = array( $guesty_id, $start_date, $end_date );
        $age_sql = '';

        if ( $max_age_hours > 0 ) {
            $cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( (int) $max_age_hours * HOUR_IN_SECONDS ) );
            $age_sql  = ' AND last_synced >= %s';
            $params[] = $cutoff;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT calendar_date, status, min_nights, price, currency, allotment, day_json, last_synced
                FROM {$table}
                WHERE guesty_id = %s
                  AND calendar_date >= %s
                  AND calendar_date <= %s
                  {$age_sql}
                ORDER BY calendar_date ASC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        if ( empty( $rows ) ) {
            return null;
        }

        $days = array();
        foreach ( $rows as $row ) {
            $day = json_decode( (string) $row['day_json'], true );
            if ( ! is_array( $day ) ) {
                $day = array();
            }

            $day['date']      = $row['calendar_date'];
            $day['status']    = $row['status'];
            $day['minNights'] = (int) $row['min_nights'];

            if ( null !== $row['price'] ) {
                $day['price'] = (float) $row['price'];
            }
            if ( ! empty( $row['currency'] ) ) {
                $day['currency'] = $row['currency'];
            }
            if ( null !== $row['allotment'] ) {
                $day['allotment'] = (int) $row['allotment'];
            }

            $days[] = $day;
        }

        return array(
            'data' => array(
                'days'       => $days,
                'from_cache' => true,
                'synced_at'  => $rows[0]['last_synced'],
            ),
        );
    }

    /**
     * Get coverage, availability, and estimated pricing for a group of listings.
     */
    public function get_range_summary( array $guesty_ids, $check_in, $check_out, $max_age_hours = 6 ) {
        global $wpdb;

        $summary = array(
            'available' => array(),
            'covered'   => array(),
            'stale'     => array(),
            'missing'   => array_values( array_unique( array_filter( $guesty_ids ) ) ),
            'prices'    => array(),
        );

        if ( empty( $summary['missing'] ) || ! $this->table_exists() ) {
            return $summary;
        }

        try {
            $start = new DateTimeImmutable( $check_in );
            $end   = new DateTimeImmutable( $check_out );
        } catch ( Exception $e ) {
            return $summary;
        }

        $nights = (int) $start->diff( $end )->days;
        if ( $nights < 1 ) {
            return $summary;
        }

        $table        = self::table_name();
        $ids          = array_values( array_unique( array_map( 'sanitize_text_field', $summary['missing'] ) ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );

        // Checkout day is not an occupied night, so use calendar_date < check_out.
        $sql = "SELECT guesty_id,
                       COUNT(*) AS day_count,
                       SUM(
                           CASE
                               WHEN allotment IS NOT NULL THEN CASE WHEN allotment > 0 THEN 1 ELSE 0 END
                               WHEN status = 'available' THEN 1
                               ELSE 0
                           END
                       ) AS available_count,
                       MAX(CASE WHEN calendar_date = %s THEN min_nights ELSE 1 END) AS required_min_nights,
                       MIN(last_synced) AS oldest_sync,
                       SUM(COALESCE(price,0)) AS estimated_total,
                       MAX(currency) AS currency
                FROM {$table}
                WHERE guesty_id IN ({$placeholders})
                  AND calendar_date >= %s
                  AND calendar_date < %s
                GROUP BY guesty_id";
        $params = array_merge( array( $check_in ), $ids, array( $check_in, $check_out ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        $cutoff_ts = time() - ( max( 1, (int) $max_age_hours ) * HOUR_IN_SECONDS );
        $seen      = array();

        foreach ( $rows as $row ) {
            $id   = $row['guesty_id'];
            $seen[] = $id;

            if ( (int) $row['day_count'] < $nights ) {
                continue;
            }

            $summary['covered'][] = $id;
            $is_fresh = ! empty( $row['oldest_sync'] ) && strtotime( $row['oldest_sync'] . ' UTC' ) >= $cutoff_ts;
            if ( ! $is_fresh ) {
                $summary['stale'][] = $id;
            }

            $required_min_nights = max( 1, (int) ( $row['required_min_nights'] ?? 1 ) );
            if ( (int) $row['available_count'] >= $nights && $nights >= $required_min_nights ) {
                $summary['available'][] = $id;
            }

            $summary['prices'][ $id ] = array(
                'total'    => (float) $row['estimated_total'],
                'currency' => $row['currency'] ?: '',
            );
        }

        $summary['missing'] = array_values( array_diff( $ids, $seen ) );
        return $summary;
    }

    public function invalidate_range( $guesty_id, $start_date, $end_date ) {
        global $wpdb;
        if ( ! $this->table_exists() ) {
            return 0;
        }

        $table = self::table_name();
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE guesty_id = %s AND calendar_date >= %s AND calendar_date <= %s",
                $guesty_id,
                $start_date,
                $end_date
            )
        );
    }

    public function delete_property( $guesty_id ) {
        global $wpdb;
        if ( ! $this->table_exists() ) {
            return 0;
        }
        return (int) $wpdb->delete( self::table_name(), array( 'guesty_id' => $guesty_id ), array( '%s' ) );
    }

    public function cleanup( $days_to_keep = 14 ) {
        global $wpdb;
        if ( ! $this->table_exists() ) {
            return 0;
        }

        $before = gmdate( 'Y-m-d', time() - ( max( 1, (int) $days_to_keep ) * DAY_IN_SECONDS ) );
        return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE calendar_date < %s', $before ) );
    }

    public function clear() {
        global $wpdb;
        if ( ! $this->table_exists() ) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
    }

    public function stats() {
        global $wpdb;

        $stats = array(
            'rows'       => 0,
            'properties' => 0,
            'oldest'     => null,
            'newest'     => null,
        );

        if ( ! $this->table_exists() ) {
            return $stats;
        }

        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $stats['rows']       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $stats['properties'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT guesty_id) FROM {$table}" );
        $stats['oldest']     = $wpdb->get_var( "SELECT MIN(last_synced) FROM {$table}" );
        $stats['newest']     = $wpdb->get_var( "SELECT MAX(last_synced) FROM {$table}" );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return $stats;
    }

    private function extract_days( array $calendar ) {
        if ( ! empty( $calendar['data']['days'] ) && is_array( $calendar['data']['days'] ) ) {
            return $calendar['data']['days'];
        }
        if ( ! empty( $calendar['days'] ) && is_array( $calendar['days'] ) ) {
            return $calendar['days'];
        }
        if ( isset( $calendar['data'] ) && is_array( $calendar['data'] ) && isset( $calendar['data'][0]['date'] ) ) {
            return $calendar['data'];
        }
        return array();
    }

    private function valid_date( $date ) {
        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date );
        return $parsed && $parsed->format( 'Y-m-d' ) === $date;
    }
}
