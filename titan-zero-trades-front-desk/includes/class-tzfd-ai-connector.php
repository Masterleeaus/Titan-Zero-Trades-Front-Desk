<?php
/**
 * Single optional AI service connector, contract tzfd-ai/1.
 *
 * One connector serves every hosted AI level of the one installable plugin:
 * free_ai (limited hosted basic AI), assistant and agent_team. Local booking never
 * calls this class; every failure path returns a structured result with use_local set,
 * so callers fall back to the deterministic local rules.
 *
 * Request (POST to the configured https endpoint, JSON body):
 *   X-TZFD-Contract:  tzfd-ai/1
 *   X-TZFD-Site:      <site id>
 *   X-TZFD-Timestamp: <Unix seconds>
 *   X-TZFD-Nonce:     <32 hex chars, fresh per request>
 *   X-TZFD-Signature: sha256=<HMAC-SHA256 of "<timestamp>.<nonce>.<body>" keyed with the secret>
 *
 * Response must echo the nonce and carry a signature over "<timestamp>.<nonce>.<body>"
 * with the same secret; the signed body must name this site. See docs/AI-SERVICE-CONNECTOR-CONTRACT.md.
 *
 * Transport seam: apply_filters( 'tzfd_ai_connector_transport', null, $request ) may return
 * array( 'status_code' => int, 'headers' => array, 'body' => string ) or array( 'error' => 'offline' ).
 * When no filter answers, the connector POSTs with wp_remote_post().
 *
 * @package TZFDFrontDesk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_AI_Connector {
    const CONTRACT         = 'tzfd-ai/1';
    const CONSENT_VERSION  = 'tzfd-ai-consent-1';
    const OPTION           = 'tzfd_ai_connector';
    const SECRET_OPTION    = 'tzfd_ai_connector_secret';
    const USAGE_PREFIX     = 'tzfd_ai_usage_';
    const LOCK_OPTION      = 'tzfd_ai_budget_lock';
    const LOCK_STALE       = 10;     // seconds before a crashed lock may be taken over
    const SIGNATURE_WINDOW = 300;    // seconds either side of the server clock
    const MAX_REQUEST_BYTES  = 16384;
    const MAX_RESPONSE_BYTES = 65536;
    const MIN_SECRET       = 32;     // characters
    const TIMEOUT          = 8;      // seconds

    const TIER_FREE     = 'free_ai';
    const TIER_ASSIST   = 'assistant';
    const TIER_AGENT    = 'agent_team';

    /**
     * Feature registry. A feature needs its tier entitled, every listed scope granted by the
     * site administrator, and (when budget is non-empty) budget left this month.
     * Scopes are granted only through grant_consent(), never by model output.
     */
    public static function features() {
        return array(
            'faq.answer'        => array( 'tier' => self::TIER_FREE,  'scopes' => array( 'faq.read' ), 'budget' => 'model_fallback' ),
            'faq.embed_query'   => array( 'tier' => self::TIER_FREE,  'scopes' => array( 'faq.read' ), 'budget' => 'embedding' ),
            'faq.suggest_batch' => array( 'tier' => self::TIER_FREE,  'scopes' => array( 'faq.read' ), 'budget' => 'faq_batch' ),
            'insight.monthly'   => array( 'tier' => self::TIER_FREE,  'scopes' => array( 'reports.read' ), 'budget' => 'insight' ),
            'assistant.turn'    => array( 'tier' => self::TIER_ASSIST, 'scopes' => array( 'faq.read', 'customer.read' ), 'budget' => '' ),
            'agent.run'         => array( 'tier' => self::TIER_AGENT, 'scopes' => array( 'booking.read', 'booking.write', 'customer.read' ), 'budget' => '' ),
        );
    }

    /** Pilot monthly budgets per site. Provisional; the hosted service stays authoritative. */
    public static function budget_limits() {
        $limits = array( 'model_fallback' => 50, 'embedding' => 200, 'faq_batch' => 4, 'insight' => 1 );
        if ( function_exists( 'apply_filters' ) ) {
            $limits = apply_filters( 'tzfd_ai_connector_budget_limits', $limits );
        }
        $clean = array();
        foreach ( is_array( $limits ) ? $limits : array() as $key => $value ) {
            $clean[ (string) $key ] = max( 0, (int) $value );
        }
        return $clean;
    }

    /**
     * Sends one typed request. Always returns a structured array:
     * ok, status, use_local, contract, data (and missing_scopes, tier where relevant).
     */
    public static function request( $feature, $payload = array() ) {
        $def = is_string( $feature ) && isset( self::features()[ $feature ] ) ? self::features()[ $feature ] : null;
        if ( null === $def ) { return self::result( 'feature_not_allowed' ); }
        if ( ! is_array( $payload ) ) { return self::result( 'invalid_request' ); }

        $cfg = self::config();
        if ( ! $cfg['consent'] ) { return self::result( 'consent_required' ); }
        if ( ! in_array( $def['tier'], $cfg['entitlements'], true ) ) {
            return self::result( 'tier_not_entitled', array(), array( 'tier' => $def['tier'] ) );
        }
        $missing = array_values( array_diff( $def['scopes'], $cfg['scopes'] ) );
        if ( array() !== $missing ) {
            return self::result( 'scope_not_granted', array(), array( 'missing_scopes' => $missing ) );
        }
        $secret = self::secret();
        if ( ! self::configured( $cfg, $secret ) ) { return self::result( 'not_configured' ); }

        $body = wp_json_encode( array(
            'contract' => self::CONTRACT,
            'site'     => $cfg['site_id'],
            'feature'  => $feature,
            'consent'  => array( 'version' => self::CONSENT_VERSION, 'scopes' => $cfg['scopes'] ),
            'payload'  => $payload,
        ) );
        if ( ! is_string( $body ) || strlen( $body ) > self::MAX_REQUEST_BYTES ) {
            return self::result( 'invalid_request' );
        }

        $budget = $def['budget'];
        if ( '' !== $budget ) {
            $reserved = self::reserve( $budget );
            if ( 'reserved' !== $reserved ) { return self::result( $reserved ); }
        }

        $nonce     = bin2hex( random_bytes( 16 ) );
        $timestamp = (string) time();
        $request   = array(
            'url'     => $cfg['endpoint'],
            'headers' => array(
                'Content-Type'    => 'application/json',
                'X-TZFD-Contract' => self::CONTRACT,
                'X-TZFD-Site'     => $cfg['site_id'],
                'X-TZFD-Timestamp' => $timestamp,
                'X-TZFD-Nonce'    => $nonce,
                'X-TZFD-Signature' => self::sign( $secret, $timestamp, $nonce, $body ),
            ),
            'body'    => $body,
            'timeout' => self::TIMEOUT,
        );

        $started = microtime( true );
        $raw     = self::transport( $request );
        $latency = (int) round( ( microtime( true ) - $started ) * 1000 );
        $outcome = self::interpret( $raw, $cfg, $secret, $nonce );

        // Nothing reached the model: give the reserved allowance back.
        if ( '' !== $budget && in_array( $outcome['status'], array( 'offline', 'invalid_response' ), true ) ) {
            self::release( $budget );
        }
        self::record( $feature, $outcome['status'], $outcome['usage'], $latency );

        return self::result( $outcome['status'], $outcome['data'] );
    }

    /** HMAC-SHA256 over "<timestamp>.<nonce>.<body>", formatted as sha256=<hex>. */
    public static function sign( $secret, $timestamp, $nonce, $body ) {
        return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, (string) $secret );
    }

    /** Connection and consent state for the admin screen. Never includes the secret. */
    public static function status() {
        $cfg = self::config();
        $has = '' !== self::secret();
        return array(
            'contract'     => self::CONTRACT,
            'connected'    => self::configured( $cfg, self::secret() ) && $cfg['consent'],
            'consent'      => $cfg['consent'],
            'scopes'       => $cfg['scopes'],
            'entitlements' => $cfg['entitlements'],
            'endpoint_set' => '' !== $cfg['endpoint'],
            'secret_set'   => $has,
            'site_id'      => $cfg['site_id'],
        );
    }

    /** Usage counters and budget use for one month (YYYY-MM, default current). */
    public static function usage( $period = '' ) {
        $period = '' === $period ? gmdate( 'Y-m' ) : (string) $period;
        return self::read_usage( $period );
    }

    /**
     * Pairs the site with the hosted service. Endpoint must be https. Returns false on invalid input.
     */
    public static function configure( $endpoint, $site_id, $secret ) {
        if ( ! is_string( $endpoint ) || ! is_string( $site_id ) || ! is_string( $secret ) ) { return false; }
        $parts = wp_parse_url( $endpoint );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
            return false;
        }
        if ( 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,64}$/D', $site_id ) ) { return false; }
        if ( strlen( $secret ) < self::MIN_SECRET ) { return false; }
        $opt = self::raw_option();
        $opt['endpoint'] = $endpoint;
        $opt['site_id']  = $site_id;
        update_option( self::OPTION, $opt, false );
        update_option( self::SECRET_OPTION, $secret, false );
        return true;
    }

    /** Explicit opt-in for the listed scopes. Unknown scopes are dropped. */
    public static function grant_consent( array $scopes ) {
        $opt = self::raw_option();
        $opt['consent']     = true;
        $opt['consent_at']  = time();
        $opt['scopes']      = self::clean_scopes( $scopes );
        update_option( self::OPTION, $opt, false );
        return true;
    }

    /** Immediate revocation. Requests stop at once, before any transport call. */
    public static function revoke_consent() {
        $opt = self::raw_option();
        $opt['consent'] = false;
        $opt['scopes']  = array();
        update_option( self::OPTION, $opt, false );
        return true;
    }

    /** Stores the tiers the hosted service reports for this site. Unknown tiers are dropped. */
    public static function set_entitlements( array $tiers ) {
        $opt = self::raw_option();
        $opt['entitlements'] = self::clean_tiers( $tiers );
        update_option( self::OPTION, $opt, false );
        return true;
    }

    /** Removes the secret, endpoint, consent and entitlements. Usage history is kept. */
    public static function disconnect() {
        delete_option( self::SECRET_OPTION );
        update_option( self::OPTION, array( 'consent' => false, 'scopes' => array(), 'endpoint' => '', 'site_id' => '', 'entitlements' => array() ), false );
        return true;
    }

    // ---- internals -------------------------------------------------------

    private static function result( $status, array $data = array(), array $extra = array() ) {
        return $extra + array(
            'ok'        => 'ok' === $status,
            'status'    => $status,
            'use_local' => 'ok' !== $status,
            'contract'  => self::CONTRACT,
            'data'      => $data,
        );
    }

    private static function feature_def( $feature ) {
        $all = self::features();
        return is_string( $feature ) && isset( $all[ $feature ] ) ? $all[ $feature ] : null;
    }

    private static function raw_option() {
        $opt = get_option( self::OPTION, array() );
        return is_array( $opt ) ? $opt : array();
    }

    private static function config() {
        $opt = self::raw_option();
        return array(
            'consent'      => isset( $opt['consent'] ) && true === $opt['consent'],
            'scopes'       => self::clean_scopes( isset( $opt['scopes'] ) && is_array( $opt['scopes'] ) ? $opt['scopes'] : array() ),
            'endpoint'     => isset( $opt['endpoint'] ) && is_string( $opt['endpoint'] ) ? $opt['endpoint'] : '',
            'site_id'      => isset( $opt['site_id'] ) && is_string( $opt['site_id'] ) ? $opt['site_id'] : '',
            'entitlements' => self::clean_tiers( isset( $opt['entitlements'] ) && is_array( $opt['entitlements'] ) ? $opt['entitlements'] : array() ),
        );
    }

    private static function secret() {
        $s = get_option( self::SECRET_OPTION, '' );
        return is_string( $s ) ? $s : '';
    }

    private static function configured( array $cfg, $secret ) {
        if ( '' === $cfg['endpoint'] || '' === $cfg['site_id'] ) { return false; }
        $parts = wp_parse_url( $cfg['endpoint'] );
        return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] )
            && 'https' === strtolower( $parts['scheme'] )
            && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,64}$/D', $cfg['site_id'] )
            && is_string( $secret ) && strlen( $secret ) >= self::MIN_SECRET;
    }

    private static function clean_scopes( array $scopes ) {
        $known = array();
        foreach ( self::features() as $def ) { $known = array_merge( $known, $def['scopes'] ); }
        return array_values( array_unique( array_intersect( $scopes, $known ) ) );
    }

    private static function clean_tiers( array $tiers ) {
        $known = array( self::TIER_FREE, self::TIER_ASSIST, self::TIER_AGENT );
        return array_values( array_unique( array_intersect( $tiers, $known ) ) );
    }

    private static function transport( array $request ) {
        $raw = function_exists( 'apply_filters' ) ? apply_filters( 'tzfd_ai_connector_transport', null, $request ) : null;
        if ( null === $raw ) {
            if ( ! function_exists( 'wp_remote_post' ) ) { return array( 'error' => 'offline' ); }
            $response = wp_remote_post( $request['url'], array(
                'timeout' => $request['timeout'],
                'headers' => $request['headers'],
                'body'    => $request['body'],
                'redirection' => 0,
            ) );
            if ( is_wp_error( $response ) ) { return array( 'error' => 'offline' ); }
            $h = wp_remote_retrieve_headers( $response );
            $headers = is_object( $h ) && method_exists( $h, 'getAll' ) ? $h->getAll() : ( is_array( $h ) ? $h : array() );
            return array(
                'status_code' => (int) wp_remote_retrieve_response_code( $response ),
                'headers'     => $headers,
                'body'        => (string) wp_remote_retrieve_body( $response ),
            );
        }
        return $raw;
    }

    /** Maps a transport answer to a status. Verifies signature, nonce, tenant and contract. */
    private static function interpret( $raw, array $cfg, $secret, $nonce ) {
        $none = array( 'data' => array(), 'usage' => array() );
        if ( ! is_array( $raw ) || isset( $raw['error'] ) ) { return array( 'status' => 'offline' ) + $none; }
        $code = isset( $raw['status_code'] ) ? (int) $raw['status_code'] : 0;
        if ( 200 !== $code ) { return array( 'status' => 'service_error' ) + $none; }

        $body = isset( $raw['body'] ) && is_string( $raw['body'] ) ? $raw['body'] : '';
        if ( '' === $body || strlen( $body ) > self::MAX_RESPONSE_BYTES ) { return array( 'status' => 'invalid_response' ) + $none; }

        $headers = array();
        foreach ( isset( $raw['headers'] ) && is_array( $raw['headers'] ) ? $raw['headers'] : array() as $name => $value ) {
            if ( is_scalar( $value ) ) { $headers[ strtolower( (string) $name ) ] = (string) $value; }
        }
        $ts  = isset( $headers['x-tzfd-timestamp'] ) ? $headers['x-tzfd-timestamp'] : '';
        $sig = isset( $headers['x-tzfd-signature'] ) ? $headers['x-tzfd-signature'] : '';
        $echo = isset( $headers['x-tzfd-nonce'] ) ? $headers['x-tzfd-nonce'] : '';
        if ( 1 !== preg_match( '/^[0-9]{1,11}$/D', $ts ) || abs( time() - (int) $ts ) > self::SIGNATURE_WINDOW ) {
            return array( 'status' => 'invalid_response' ) + $none;
        }
        if ( ! hash_equals( (string) $nonce, $echo ) ) { return array( 'status' => 'invalid_response' ) + $none; }
        if ( ! hash_equals( self::sign( $secret, $ts, $nonce, $body ), $sig ) ) { return array( 'status' => 'invalid_response' ) + $none; }

        $json = json_decode( $body, true );
        if ( ! is_array( $json ) || ! isset( $json['status'], $json['contract'], $json['site'] ) ) {
            return array( 'status' => 'invalid_response' ) + $none;
        }
        if ( ! is_string( $json['site'] ) || ! hash_equals( $cfg['site_id'], $json['site'] ) ) {
            return array( 'status' => 'invalid_response' ) + $none; // wrong tenant
        }
        $status = $json['status'];
        if ( 'contract_unsupported' === $status ) { return array( 'status' => 'contract_unsupported' ) + $none; }
        if ( self::CONTRACT !== $json['contract'] ) { return array( 'status' => 'invalid_response' ) + $none; }
        if ( 'ok' === $status ) {
            return array(
                'status' => 'ok',
                'data'   => isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array(),
                'usage'  => isset( $json['usage'] ) && is_array( $json['usage'] ) ? $json['usage'] : array(),
            );
        }
        if ( 'allowance_exhausted' === $status ) { return array( 'status' => 'allowance_exhausted' ) + $none; }
        if ( 'service_error' === $status ) { return array( 'status' => 'service_error' ) + $none; }
        return array( 'status' => 'invalid_response' ) + $none;
    }

    /** Atomic mutex on a unique option row. Returns false when another request holds it. */
    private static function lock() {
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) { return true; }
            $taken = (int) get_option( self::LOCK_OPTION, 0 );
            if ( $taken > 0 && time() - $taken <= self::LOCK_STALE ) { return false; }
            delete_option( self::LOCK_OPTION ); // stale lock from a crashed request
        }
        return false;
    }

    private static function unlock() {
        delete_option( self::LOCK_OPTION );
    }

    /** Reserves one unit of a monthly budget before any provider call. */
    private static function reserve( $key ) {
        if ( ! self::lock() ) { return 'busy'; }
        $period = gmdate( 'Y-m' );
        $usage  = self::read_usage( $period );
        $used   = isset( $usage['budget'][ $key ] ) ? (int) $usage['budget'][ $key ] : 0;
        $limits = self::budget_limits();
        $limit  = isset( $limits[ $key ] ) ? $limits[ $key ] : 0;
        if ( $used >= $limit ) {
            self::unlock();
            return 'allowance_exhausted';
        }
        $usage['budget'][ $key ] = $used + 1;
        self::write_usage( $period, $usage );
        self::unlock();
        return 'reserved';
    }

    private static function release( $key ) {
        if ( ! self::lock() ) { return; }
        $period = gmdate( 'Y-m' );
        $usage  = self::read_usage( $period );
        $used   = isset( $usage['budget'][ $key ] ) ? (int) $usage['budget'][ $key ] : 0;
        $usage['budget'][ $key ] = max( 0, $used - 1 );
        self::write_usage( $period, $usage );
        self::unlock();
    }

    /** Best-effort counters: never blocks a request when the lock is busy. */
    private static function record( $feature, $status, array $usage, $latency ) {
        if ( ! self::lock() ) { return; }
        $period = gmdate( 'Y-m' );
        $all    = self::read_usage( $period );
        $f      = isset( $all['features'][ $feature ] ) ? $all['features'][ $feature ] : array();
        $f      = $f + array( 'calls' => 0, 'ok' => 0, 'fallback_local' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'embedding_tokens' => 0, 'cache_hits' => 0, 'latency_ms' => 0 );
        $f['calls']++;
        if ( 'ok' === $status ) {
            $f['ok']++;
            $f['tokens_in']        += isset( $usage['tokens_in'] ) ? max( 0, (int) $usage['tokens_in'] ) : 0;
            $f['tokens_out']       += isset( $usage['tokens_out'] ) ? max( 0, (int) $usage['tokens_out'] ) : 0;
            $f['embedding_tokens'] += isset( $usage['embedding_tokens'] ) ? max( 0, (int) $usage['embedding_tokens'] ) : 0;
            $f['cache_hits']       += ! empty( $usage['cache_hit'] ) ? 1 : 0;
            $f['latency_ms']       += max( 0, (int) $latency );
        } else {
            $f['fallback_local']++;
        }
        $all['features'][ $feature ] = $f;
        self::write_usage( $period, $all );
        self::unlock();
    }

    private static function read_usage( $period ) {
        $u = get_option( self::USAGE_PREFIX . $period, array() );
        $u = is_array( $u ) ? $u : array();
        return array(
            'period'   => $period,
            'budget'   => isset( $u['budget'] ) && is_array( $u['budget'] ) ? $u['budget'] : array(),
            'features' => isset( $u['features'] ) && is_array( $u['features'] ) ? $u['features'] : array(),
        );
    }

    /** One small option per month; the month thirteen back is removed on each write. */
    private static function write_usage( $period, array $usage ) {
        update_option( self::USAGE_PREFIX . $period, $usage, false );
        $old = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . ' -13 months' ) );
        delete_option( self::USAGE_PREFIX . $old );
    }
}
