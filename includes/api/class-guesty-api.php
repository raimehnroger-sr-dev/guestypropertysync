<?php
/**
 * Optimized Guesty Open API client.
 *
 * Features:
 * - Reuses OAuth tokens until shortly before expiration.
 * - Atomic authentication lock to prevent token stampedes.
 * - Retry-After handling for HTTP 429 responses.
 * - A short circuit breaker to protect the frontend during rate limiting.
 * - Optional GET response caching and stale fallback.
 * - Captures Guesty rate-limit response headers for diagnostics.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Guesty_API {

    // const TOKEN_ENDPOINT = 'https://open-api.guesty.com/oauth2/token';
    // const API_BASE_URL   = 'https://open-api.guesty.com/v1';

    const TOKEN_OPTION          = 'guesty_optimized_api_token';
    const TOKEN_TRANSIENT       = 'guesty_optimized_api_token';
    const TOKEN_LOCK_OPTION     = 'guesty_optimized_auth_lock';
    const TOKEN_HISTORY_OPTION  = 'guesty_optimized_token_history';
    const TOKEN_HEALTH_OPTION   = 'guesty_token_health';
    const TOKEN_REFRESH_BUFFER  = 300; // Five minutes before the hard expiry.
    const TOKEN_GENERATION_CAP  = 4;   // Plugin-side safety cap; Guesty's account cap is 5/24h.

    private $client_id;
    private $client_secret;
    private $credential_hash = '';
    private $access_token = '';
    private $token_issued_at = 0;
    private $token_expires = 0; // Hard expiry returned by Guesty.

    public function __construct() {
        $this->client_id       = trim( (string) get_option( 'guesty_api_key', '' ) );
        $this->client_secret   = trim( (string) get_option( 'guesty_api_secret', '' ) );
        $this->credential_hash = hash( 'sha256', $this->client_id . "\0" . $this->client_secret );
        $this->load_token();
    }

    /**
     * Load the shared token vault. The database option is authoritative so a
     * transient/cache flush cannot accidentally consume another Guesty token.
     */
    private function load_token() {
        $token = get_option( self::TOKEN_OPTION, array() );
        if ( ! is_array( $token ) || empty( $token['access_token'] ) ) {
            $token = get_transient( self::TOKEN_TRANSIENT );
        }

        if ( ! is_array( $token ) || empty( $token['access_token'] ) ) {
            return;
        }

        // Tokens created by v4.2.0 did not have a credential fingerprint. Keep
        // them usable so upgrading does not unnecessarily mint a new token.
        if ( ! empty( $token['credential_hash'] ) && ! hash_equals( (string) $token['credential_hash'], $this->credential_hash ) ) {
            $this->forget_token_only();
            return;
        }

        $expires = isset( $token['expires_at'] ) ? (int) $token['expires_at'] : (int) ( $token['expires'] ?? 0 );
        if ( empty( $expires ) ) {
            return;
        }

        $this->access_token    = (string) $token['access_token'];
        $this->token_issued_at = (int) ( $token['issued_at'] ?? get_option( 'guesty_last_auth_time', 0 ) );
        $this->token_expires   = $expires;

        if ( $this->token_expires > time() && false === get_transient( self::TOKEN_TRANSIENT ) ) {
            set_transient( self::TOKEN_TRANSIENT, $token, max( 30, $this->token_expires - time() ) );
        }
    }

    private function save_token( $access_token, $expires_in, $response = null ) {
        $now        = time();
        $expires_in = max( 120, (int) $expires_in );
        $expires    = $now + $expires_in;
        $data = array(
            'access_token'    => (string) $access_token,
            'issued_at'       => $now,
            'expires_at'      => $expires,
            'credential_hash' => $this->credential_hash,
        );

        $this->access_token    = (string) $access_token;
        $this->token_issued_at = $now;
        $this->token_expires   = $expires;

        // Persist independently from WordPress transients. This survives cache
        // purges and avoids token churn when Redis/Memcached is flushed.
        update_option( self::TOKEN_OPTION, $data, false );
        set_transient( self::TOKEN_TRANSIENT, $data, max( 30, $expires - $now ) );
        update_option( 'guesty_last_auth_time', $now, false );
        $this->record_token_generation( $now, $response );
    }

    /** Remove only the cached token; keep quota history/diagnostics intact. */
    private function forget_token_only() {
        $this->access_token    = '';
        $this->token_issued_at = 0;
        $this->token_expires   = 0;
        delete_transient( self::TOKEN_TRANSIENT );
        delete_option( self::TOKEN_OPTION );
    }

    private function token_is_usable( $minimum_seconds = 30 ) {
        return $this->access_token && $this->token_expires > ( time() + max( 0, (int) $minimum_seconds ) );
    }

    public function get_access_token() {
        // Guesty recommends refreshing shortly before expiry. Until that window,
        // every API request reuses the exact same shared access token.
        if ( $this->token_is_usable( self::TOKEN_REFRESH_BUFFER ) ) {
            return $this->access_token;
        }

        // In the final five minutes, try to rotate once. If Guesty's token endpoint
        // is temporarily unavailable or the safety guard is active, keep using the
        // still-valid token rather than failing a frontend request.
        if ( $this->token_is_usable( 30 ) ) {
            $old_token = $this->access_token;
            $authenticated = $this->authenticate( 'near_expiry' );
            return true === $authenticated && $this->access_token ? $this->access_token : $old_token;
        }

        $authenticated = $this->authenticate( 'expired_or_missing' );
        return true === $authenticated ? $this->access_token : false;
    }

    /**
     * Generate an OAuth token only when required. Guesty permits five token
     * generations per client ID per rolling 24 hours, so the plugin reserves
     * one slot and refuses to mint more than four itself.
     */
    public function authenticate( $reason = 'expired_or_missing', $invalidated_token = '' ) {
        if ( empty( $this->client_id ) || empty( $this->client_secret ) ) {
            return new WP_Error( 'guesty_credentials_missing', 'Guesty API credentials are not configured.' );
        }

        // A new Guesty_API instance may have created a token moments ago.
        $this->load_token();
        $required_lifetime = 'near_expiry' === $reason ? self::TOKEN_REFRESH_BUFFER : 30;
        if ( $this->token_is_usable( $required_lifetime ) ) {
            if ( 'confirmed_invalid_token' !== $reason || ( $invalidated_token && ! hash_equals( (string) $invalidated_token, (string) $this->access_token ) ) ) {
                return true;
            }
        }

        $quota = $this->token_generation_quota();
        if ( $quota['used'] >= self::TOKEN_GENERATION_CAP ) {
            $minutes = max( 1, (int) ceil( $quota['reset_in'] / 60 ) );
            $message = sprintf(
                'Guesty token safety limit reached (%d token generations recorded by this plugin in 24 hours). Existing cached data will continue to be used. Retry in approximately %d minutes.',
                $quota['used'],
                $minutes
            );
            $this->activity( $message, 'warning' );
            return new WP_Error( 'guesty_token_generation_guard', $message, array( 'retry_after' => $quota['reset_in'] ) );
        }

        // Prevent rapid regeneration when a cache/database layer is unstable.
        // A confirmed invalid_token response is the only emergency exception.
        if ( 'confirmed_invalid_token' !== $reason && ! empty( $quota['last'] ) && ( time() - $quota['last'] ) < 600 ) {
            return new WP_Error(
                'guesty_token_cooldown',
                'A Guesty access token was generated less than 10 minutes ago. Token regeneration is temporarily blocked to protect the daily quota.'
            );
        }

        $lock_acquired = add_option( self::TOKEN_LOCK_OPTION, time(), '', false );
        if ( ! $lock_acquired ) {
            $lock_time = (int) get_option( self::TOKEN_LOCK_OPTION, 0 );
            if ( $lock_time && ( time() - $lock_time ) > 30 ) {
                delete_option( self::TOKEN_LOCK_OPTION );
                $lock_acquired = add_option( self::TOKEN_LOCK_OPTION, time(), '', false );
            }
        }

        if ( ! $lock_acquired ) {
            // Another PHP request is minting the shared token. Wait briefly and
            // read the database vault instead of issuing a second OAuth request.
            for ( $i = 0; $i < 8; $i++ ) {
                usleep( 150000 );
                $this->load_token();
                if ( $this->token_is_usable( 15 ) ) {
                    return true;
                }
            }
            return new WP_Error( 'guesty_auth_locked', 'Guesty authentication is already in progress.' );
        }

        try {
            // Re-check after obtaining the lock: another request may have stored
            // a token between the first check and lock acquisition.
            $this->load_token();
            $required_lifetime = 'near_expiry' === $reason ? self::TOKEN_REFRESH_BUFFER : 30;
            if ( $this->token_is_usable( $required_lifetime ) ) {
                if ( 'confirmed_invalid_token' !== $reason || ( $invalidated_token && ! hash_equals( (string) $invalidated_token, (string) $this->access_token ) ) ) {
                    return true;
                }
            }

            $response = wp_remote_post(
                self::TOKEN_ENDPOINT,
                array(
                    'headers' => array(
                        'Accept'       => 'application/json',
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ),
                    'body' => array(
                        'grant_type'    => 'client_credentials',
                        'scope'         => 'open-api',
                        'client_id'     => $this->client_id,
                        'client_secret' => $this->client_secret,
                    ),
                    'timeout' => 15,
                )
            );

            if ( is_wp_error( $response ) ) {
                $message = 'OAuth request failed: ' . $response->get_error_message();
                $this->log( $message );
                $this->activity( $message, 'error' );
                return $response;
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $body   = json_decode( wp_remote_retrieve_body( $response ), true );
            $this->record_token_endpoint_health( $response, $status );

            if ( 429 === $status ) {
                $retry_after = $this->token_retry_after_seconds( $response );
                set_transient( 'guesty_optimized_circuit', 1, min( 300, $retry_after ) );
                $this->activity( sprintf( 'Guesty OAuth token generation rate limited; retry after %d seconds.', $retry_after ), 'warning' );
                return new WP_Error( 'guesty_token_rate_limited', 'Guesty OAuth token generation is rate limited.', array( 'retry_after' => $retry_after ) );
            }

            if ( $status < 200 || $status >= 300 || empty( $body['access_token'] ) ) {
                $message = $this->error_message( $body, 'Guesty authentication failed.' );
                $this->activity( sprintf( 'Guesty authentication failed (%d): %s', $status, $message ), 'error' );
                return new WP_Error( 'guesty_auth_failed', $message, array( 'status' => $status ) );
            }

            $this->save_token( $body['access_token'], $body['expires_in'] ?? 86400, $response );
            return true;
        } finally {
            delete_option( self::TOKEN_LOCK_OPTION );
        }
    }

    /**
     * Detect an actual OAuth/JWT failure. Do not treat every 401 as an expired
     * token: Guesty booking endpoints may use 401 for availability/terms checks.
     */
    private function response_confirms_invalid_token( $response, $body ) {
        $www_auth = strtolower( (string) wp_remote_retrieve_header( $response, 'www-authenticate' ) );
        if ( false !== strpos( $www_auth, 'invalid_token' ) || false !== strpos( $www_auth, 'token expired' ) ) {
            return true;
        }

        $parts = array();
        if ( is_array( $body ) ) {
            foreach ( array( 'error', 'error_description', 'message', 'detail' ) as $key ) {
                if ( isset( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
                    $parts[] = $body[ $key ];
                }
            }
            if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
                $parts[] = $body['error']['message'];
            }
        }
        $message = strtolower( implode( ' ', $parts ) );
        foreach ( array( 'invalid_token', 'invalid token', 'token has expired', 'token expired', 'expired token', 'jwt expired', 'access token expired' ) as $needle ) {
            if ( false !== strpos( $message, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    /** Record successful generations in a rolling 24-hour local safety ledger. */
    private function record_token_generation( $timestamp, $response = null ) {
        $history   = get_option( self::TOKEN_HISTORY_OPTION, array() );
        $history   = is_array( $history ) ? array_map( 'intval', $history ) : array();
        $threshold = $timestamp - DAY_IN_SECONDS;
        $history   = array_values( array_filter( $history, function( $ts ) use ( $threshold ) { return $ts > $threshold; } ) );
        $history[] = (int) $timestamp;
        update_option( self::TOKEN_HISTORY_OPTION, $history, false );

        if ( $response ) {
            $this->record_token_endpoint_health( $response, 200 );
        }
    }

    private function token_generation_quota() {
        $now       = time();
        $threshold = $now - DAY_IN_SECONDS;
        $history   = get_option( self::TOKEN_HISTORY_OPTION, array() );
        $history   = is_array( $history ) ? array_map( 'intval', $history ) : array();
        $history   = array_values( array_filter( $history, function( $ts ) use ( $threshold ) { return $ts > $threshold; } ) );
        update_option( self::TOKEN_HISTORY_OPTION, $history, false );
        $first = empty( $history ) ? 0 : min( $history );
        return array(
            'used'     => count( $history ),
            'last'     => empty( $history ) ? 0 : max( $history ),
            'reset_in' => $first ? max( 0, DAY_IN_SECONDS - ( $now - $first ) ) : 0,
        );
    }

    private function record_token_endpoint_health( $response, $status ) {
        $health = get_option( self::TOKEN_HEALTH_OPTION, array() );
        $health = is_array( $health ) ? $health : array();
        $health['last_token_response_at'] = current_time( 'mysql', true );
        $health['last_token_status']      = (int) $status;

        $map = array(
            'ratelimit-limit'          => 'limit_day',
            'ratelimit-remaining'      => 'remaining_day',
            'ratelimit-reset'          => 'reset_seconds',
            'x-ratelimit-limit-day'    => 'limit_day',
            'x-ratelimit-remaining-day'=> 'remaining_day',
        );
        foreach ( $map as $header => $key ) {
            $value = wp_remote_retrieve_header( $response, $header );
            if ( '' !== (string) $value && is_numeric( $value ) ) {
                $health[ $key ] = (int) $value;
            }
        }
        update_option( self::TOKEN_HEALTH_OPTION, $health, false );
    }

    private function token_retry_after_seconds( $response ) {
        foreach ( array( 'retry-after', 'ratelimit-reset' ) as $header_name ) {
            $header = wp_remote_retrieve_header( $response, $header_name );
            if ( is_numeric( $header ) ) {
                return max( 1, min( DAY_IN_SECONDS, (int) $header ) );
            }
        }
        return 300;
    }

    public function get_token_diagnostics() {
        // Ensure the object reflects the latest shared database state.
        $this->load_token();
        $quota  = $this->token_generation_quota();
        $health = get_option( self::TOKEN_HEALTH_OPTION, array() );
        $health = is_array( $health ) ? $health : array();
        return array(
            'has_token'          => (bool) $this->access_token,
            'usable'             => $this->token_is_usable( 30 ),
            'issued_at'          => $this->token_issued_at,
            'expires_at'         => $this->token_expires,
            'expires_in'         => $this->token_expires ? max( 0, $this->token_expires - time() ) : 0,
            'plugin_used_24h'    => $quota['used'],
            'plugin_guard_cap'   => self::TOKEN_GENERATION_CAP,
            'plugin_reset_in'    => $quota['reset_in'],
            'guesty_limit_day'   => $health['limit_day'] ?? null,
            'guesty_remaining'   => $health['remaining_day'] ?? null,
            'guesty_reset'       => $health['reset_seconds'] ?? null,
            'last_token_status'  => $health['last_token_status'] ?? null,
        );
    }

    /**
     * Make a Guesty API request.
     *
     * Existing three-argument calls remain compatible. The fourth argument is
     * optional and supports cache_ttl, stale_ttl, timeout, retries, and bypass_circuit.
     */
    public function request( $endpoint, $method = 'GET', $data = array(), $options = array() ) {
        $method = strtoupper( $method );
        $options = wp_parse_args(
            $options,
            array(
                'cache_ttl'       => 0,
                'stale_ttl'       => 0,
                'timeout'         => wp_doing_cron() ? 30 : 15,
                'retries'         => wp_doing_cron() ? 2 : 1,
                'bypass_circuit'  => false,
            )
        );

        $url = self::API_BASE_URL . '/' . ltrim( $endpoint, '/' );
        if ( 'GET' === $method && ! empty( $data ) ) {
            $url = add_query_arg( $data, $url );
        }

        $cache_key = 'guesty_api_' . md5( $method . '|' . $url );
        $stale_key = $cache_key . '_stale';

        if ( 'GET' === $method && (int) $options['cache_ttl'] > 0 ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        if ( ! $options['bypass_circuit'] && get_transient( 'guesty_optimized_circuit' ) ) {
            $stale = get_transient( $stale_key );
            if ( false !== $stale ) {
                return $stale;
            }
            return new WP_Error( 'guesty_circuit_open', 'Guesty is temporarily rate limited. Cached data is being used where available.' );
        }

        $token = $this->get_access_token();
        if ( ! $token ) {
            $stale = get_transient( $stale_key );
            return false !== $stale ? $stale : new WP_Error( 'guesty_auth_failed', 'Unable to authenticate with Guesty.' );
        }

        $attempt = 0;
        $max_attempts = max( 1, (int) $options['retries'] + 1 );
        $did_refresh_token = false;

        while ( $attempt < $max_attempts ) {
            $attempt++;

            $args = array(
                'method'  => $method,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'User-Agent'    => 'WordPress/Guesty-Property-Sync-Optimized-4.2.1',
                ),
                'timeout' => max( 5, (int) $options['timeout'] ),
            );

            if ( 'GET' !== $method && ! empty( $data ) ) {
                $args['body'] = wp_json_encode( $data );
            }

            $started  = microtime( true );
            $response = wp_remote_request( $url, $args );
            $elapsed  = round( ( microtime( true ) - $started ) * 1000 );

            if ( is_wp_error( $response ) ) {
                $this->record_health( 0, $elapsed, array(), $response->get_error_message(), $url );
                if ( $attempt < $max_attempts ) {
                    usleep( $this->backoff_microseconds( $attempt ) );
                    continue;
                }

                $stale = get_transient( $stale_key );
                if ( false === $stale ) {
                    $this->activity( sprintf( 'Guesty request failed for %s: %s', $endpoint, $response->get_error_message() ), 'error' );
                }
                return false !== $stale ? $stale : $response;
            }

            $status  = (int) wp_remote_retrieve_response_code( $response );
            $headers = wp_remote_retrieve_headers( $response );
            $raw     = wp_remote_retrieve_body( $response );
            $body    = '' === $raw ? array() : json_decode( $raw, true );

            if ( null === $body && '' !== $raw ) {
                $body = array( 'raw_response' => substr( $raw, 0, 1000 ) );
            }

            $this->record_health( $status, $elapsed, $headers, '', $url );

            if ( in_array( $status, array( 401, 403 ), true ) && ! $did_refresh_token ) {
                // Only mint another OAuth token if the current token is actually
                // expired or Guesty explicitly identifies it as invalid. A generic
                // 401 can be a reservation/calendar validation response.
                $hard_expired = $this->token_expires && $this->token_expires <= ( time() + 30 );
                $invalid_token = $this->response_confirms_invalid_token( $response, $body );

                if ( $hard_expired || $invalid_token ) {
                    $did_refresh_token = true;
                    $invalidated_value = $token;
                    if ( $hard_expired ) {
                        $this->forget_token_only();
                    }
                    $authenticated = $this->authenticate( $invalid_token ? 'confirmed_invalid_token' : 'expired_or_missing', $invalidated_value );
                    if ( true === $authenticated && $this->access_token ) {
                        $token = $this->access_token;
                        $attempt--;
                        continue;
                    }
                }
            }

            if ( 429 === $status ) {
                $retry_after = $this->retry_after_seconds( $response );
                set_transient( 'guesty_optimized_circuit', 1, $retry_after );

                if ( $attempt < $max_attempts && $retry_after <= 3 ) {
                    sleep( $retry_after );
                    continue;
                }

                $stale = get_transient( $stale_key );
                if ( false === $stale ) {
                    $this->activity( sprintf( 'Guesty rate limit reached for %s; retry after %d seconds.', $endpoint, $retry_after ), 'warning' );
                }
                return false !== $stale
                    ? $stale
                    : new WP_Error( 'guesty_rate_limited', 'Guesty rate limit reached. Please retry shortly.', array( 'retry_after' => $retry_after ) );
            }

            if ( $status >= 500 && $attempt < $max_attempts ) {
                usleep( $this->backoff_microseconds( $attempt ) );
                continue;
            }

            if ( $status < 200 || $status >= 300 ) {
                $stale = get_transient( $stale_key );
                if ( false !== $stale && 'GET' === $method ) {
                    return $stale;
                }

                $message = $this->error_message( $body, 'Guesty API error (' . $status . ').' );
                $this->activity( sprintf( 'Guesty API error for %s (%d): %s', $endpoint, $status, $message ), 'error' );
                return new WP_Error(
                    'guesty_api_error',
                    $message,
                    array( 'status' => $status, 'response' => $body )
                );
            }

            if ( 'GET' === $method && (int) $options['cache_ttl'] > 0 ) {
                set_transient( $cache_key, $body, (int) $options['cache_ttl'] );
                $stale_ttl = max( (int) $options['stale_ttl'], (int) $options['cache_ttl'] );
                if ( $stale_ttl > 0 ) {
                    set_transient( $stale_key, $body, $stale_ttl );
                }
            }

            return $body;
        }

        $this->activity( sprintf( 'Guesty request failed after retries for %s.', $endpoint ), 'error' );
        return new WP_Error( 'guesty_request_failed', 'Guesty request failed after retries.' );
    }

    public function get_properties( $limit = 10, $offset = 0, $filter = array() ) {
        $params = array(
            'limit' => max( 1, min( 100, (int) $limit ) ),
            'skip'  => max( 0, (int) $offset ),
        );
        if ( ! empty( $filter ) ) {
            $params['filter'] = is_string( $filter ) ? $filter : wp_json_encode( $filter );
        }

        return $this->request( 'listings', 'GET', $params, array( 'cache_ttl' => 5 * MINUTE_IN_SECONDS, 'stale_ttl' => HOUR_IN_SECONDS ) );
    }

    public function get_available_listings( $check_in, $check_out, $min_occupancy = 1, $limit = 100, $skip = 0 ) {
        $params = array(
            'available' => wp_json_encode(
                array(
                    'checkIn'     => $check_in,
                    'checkOut'    => $check_out,
                    'minOccupancy'=> max( 1, (int) $min_occupancy ),
                )
            ),
            'limit'  => max( 1, min( 100, (int) $limit ) ),
            'skip'   => max( 0, (int) $skip ),
            'fields' => '_id title nickname accommodates prices price',
            'active' => 'true',
            'listed' => 'true',
            'sort'   => '_id',
        );

        return $this->request(
            'listings',
            'GET',
            $params,
            array(
                'cache_ttl' => 5 * MINUTE_IN_SECONDS,
                'stale_ttl' => 30 * MINUTE_IN_SECONDS,
                'timeout'   => 15,
                'retries'   => 1,
            )
        );
    }

    /**
     * Full optimized calendar response retained for the production calendar renderer.
     */
    public function get_calendar( $listing_id, $start_date, $end_date ) {
        $cache_key = Guesty_Transient_Cache::calendar_key( $listing_id, $start_date, $end_date, 'full' );
        $cached    = Guesty_Transient_Cache::get( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $endpoint = 'availability-pricing/api/calendar/listings/minified/' . rawurlencode( $listing_id );
        $response = $this->request(
            $endpoint,
            'GET',
            array(
                'startDate'        => $start_date,
                'endDate'          => $end_date,
                'view'             => 'full',
                'includeAllotment' => 'true',
            ),
            array( 'timeout' => 20, 'retries' => 1 )
        );

        if ( ! is_wp_error( $response ) ) {
            $minutes = max( 5, min( 1440, (int) get_option( 'guesty_calendar_cache_minutes', 60 ) ) );
            Guesty_Transient_Cache::set( 'calendar', $listing_id, $cache_key, $response, $minutes * MINUTE_IN_SECONDS );
        }

        return $response;
    }

    public function get_calendar_compact( $listing_id, $start_date, $end_date ) {
        $cache_key = Guesty_Transient_Cache::calendar_key( $listing_id, $start_date, $end_date, 'compact' );
        $cached    = Guesty_Transient_Cache::get( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $endpoint = 'availability-pricing/api/calendar/listings/minified/' . rawurlencode( $listing_id );
        $response = $this->request(
            $endpoint,
            'GET',
            array(
                'startDate'        => $start_date,
                'endDate'          => $end_date,
                'view'             => 'compact',
                'includeAllotment' => 'true',
            ),
            array( 'timeout' => 20, 'retries' => 2 )
        );

        if ( ! is_wp_error( $response ) ) {
            $minutes = max( 5, min( 1440, (int) get_option( 'guesty_calendar_cache_minutes', 60 ) ) );
            Guesty_Transient_Cache::set( 'calendar', $listing_id, $cache_key, $response, $minutes * MINUTE_IN_SECONDS );
        }

        return $response;
    }

    /**
     * Retrieve reservations for dashboard reporting. The response is cached by
     * request parameters so the admin dashboard never polls Guesty on each view.
     */
    public function get_reservations( array $filters = array(), $limit = 100, $skip = 0, $sort = '-checkInDateLocalized', $force = false ) {
        $params = array(
            'limit'  => max( 1, min( 100, (int) $limit ) ),
            'skip'   => max( 0, (int) $skip ),
            'sort'   => sanitize_text_field( $sort ),
            'fields' => '_id confirmationCode status checkInDateLocalized checkOutDateLocalized listing guest money totalPrice totalPaid currency source createdAt',
        );
        if ( ! empty( $filters ) ) {
            $params['filters'] = $filters;
        }

        return $this->request(
            'reservations',
            'GET',
            $params,
            array(
                'cache_ttl' => $force ? 0 : 4 * HOUR_IN_SECONDS,
                'stale_ttl' => $force ? 0 : DAY_IN_SECONDS,
                'timeout'   => 20,
                'retries'   => 1,
                'bypass_circuit' => (bool) $force,
            )
        );
    }

    public function create_webhook() {
        $webhook_url = rest_url( 'guesty/v1/webhook' );

        return $this->request(
            'webhooks',
            'POST',
            array(
                'url'    => $webhook_url,
                'events' => array(
                    'listing.new',
                    'listing.updated',
                    'listing.removed',
                    'listing.calendar.updated',
                    'calendar.updated.v2',
                    'reservation.new',
                    'reservation.updated',
                ),
            )
        );
    }

    public function get_custom_fields() {
        return $this->request( 'properties-api/custom-fields', 'GET', array(), array( 'cache_ttl' => HOUR_IN_SECONDS, 'stale_ttl' => DAY_IN_SECONDS ) );
    }

    private function retry_after_seconds( $response ) {
        $header = wp_remote_retrieve_header( $response, 'retry-after' );
        if ( is_numeric( $header ) ) {
            return max( 1, min( 120, (int) $header ) );
        }
        return 5;
    }

    private function backoff_microseconds( $attempt ) {
        $base = min( 2000000, 200000 * ( 2 ** max( 0, $attempt - 1 ) ) );
        return $base + wp_rand( 0, 150000 );
    }

    private function error_message( $body, $fallback ) {
        if ( is_array( $body ) ) {
            foreach ( array( 'message', 'detail', 'error_description' ) as $key ) {
                if ( ! empty( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
                    return sanitize_text_field( $body[ $key ] );
                }
            }
            if ( ! empty( $body['error']['message'] ) ) {
                return sanitize_text_field( $body['error']['message'] );
            }
            if ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
                return sanitize_text_field( $body['error'] );
            }
        }
        return $fallback;
    }

    private function record_health( $status, $elapsed_ms, $headers, $error, $url ) {
        $health = array(
            'last_request_at' => current_time( 'mysql', true ),
            'status'          => (int) $status,
            'elapsed_ms'      => (int) $elapsed_ms,
            'error'           => sanitize_text_field( $error ),
            'endpoint'        => preg_replace( '#^' . preg_quote( self::API_BASE_URL, '#' ) . '/#', '', strtok( $url, '?' ) ),
        );

        foreach ( array( 'second', 'minute', 'hour' ) as $interval ) {
            $limit = $this->header_value( $headers, 'x-ratelimit-limit-' . $interval );
            $remaining = $this->header_value( $headers, 'x-ratelimit-remaining-' . $interval );
            if ( null !== $limit ) {
                $health[ 'limit_' . $interval ] = (int) $limit;
            }
            if ( null !== $remaining ) {
                $health[ 'remaining_' . $interval ] = (int) $remaining;
            }
        }

        update_option( 'guesty_api_health', $health, false );
    }

    private function header_value( $headers, $name ) {
        if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
            $value = $headers->offsetGet( $name );
            return null === $value ? null : $value;
        }
        if ( is_array( $headers ) ) {
            foreach ( $headers as $key => $value ) {
                if ( strtolower( (string) $key ) === strtolower( $name ) ) {
                    return is_array( $value ) ? reset( $value ) : $value;
                }
            }
        }
        return null;
    }

    private function activity( $message, $status = 'error' ) {
        if ( class_exists( 'Guesty_Activity_Log' ) ) {
            Guesty_Activity_Log::add( 'api', '', sanitize_text_field( $message ), $status );
        }
    }

    private function log( $message ) {
        if ( get_option( 'guesty_debug_logging', '0' ) === '1' && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Guesty Optimized] ' . $message );
        }
    }
}
