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
    const MAX_CONTENT_CHARS  = 2000;  // owner decision #330: longest text value sent to the hosted service
    const MIN_SECRET       = 32;     // characters
    const TIMEOUT          = 8;      // seconds
    const EVENT_OPTION     = 'tzfd_ai_events';  // redacted event log, newest last
    const EVENT_LIMIT      = 50;     // events kept
    const AUDIT_OPTION     = 'tzfd_ai_connector_audit';  // connect, consent and disconnect trail, newest last
    const AUDIT_LIMIT      = 100;    // audit entries kept
    const RETENTION_DEFAULT = 30;    // days an event is kept
    const RETENTION_MAX    = 365;    // days

    // Entitlement names reported by the hosted service. Informational only: they never gate a feature.
    const TIER_FREE     = 'free_ai';
    const TIER_ASSIST   = 'assistant';
    const TIER_AGENT    = 'agent_team';

    /**
     * Feature registry. A feature needs every listed scope granted by the site administrator and,
     * when budget is non-empty, budget left this month. No paid tier gates a feature.
     * Scopes are granted only through grant_consent(), never by model output.
     */
    public static function features() {
        return array(
            'faq.answer'        => array( 'scopes' => array( 'faq.read' ), 'budget' => 'model_fallback' ),
            'faq.embed_query'   => array( 'scopes' => array( 'faq.read' ), 'budget' => 'embedding' ),
            'faq.suggest_batch' => array( 'scopes' => array( 'faq.read' ), 'budget' => 'faq_batch' ),
            'insight.monthly'   => array( 'scopes' => array( 'reports.read' ), 'budget' => 'insight' ),
            'assistant.turn'    => array( 'scopes' => array( 'faq.read', 'customer.read' ), 'budget' => '' ),
            'agent.run'         => array( 'scopes' => array( 'booking.read', 'booking.write', 'customer.read' ), 'budget' => '' ),
        );
    }

    /**
     * Named monthly budget sets per site (issue #331). 'contract' is live by default (the standing contract
     * figures). 'pilot' holds the provisional #284 pilot figures and is selected only through the
     * tzfd_ai_connector_budget_profile filter. The pilot faq_batch (weekly) is approximated as 4 per month.
     * Per-visitor fallback turn cap (#353, 200 per visitor) is enforced in TZFD_AI_Answer_Box. The 3 missed-call reply turns from #284 have no counter in code yet.
     */
    public static function budget_profiles() {
        return array(
            'contract' => array( 'model_fallback' => 200, 'embedding' => 800, 'faq_batch' => 16, 'insight' => 4 ),
            'pilot'    => array( 'model_fallback' => 50, 'embedding' => 200, 'faq_batch' => 4, 'insight' => 1 ),
        );
    }

    /** Standing monthly budgets per site. The live set is 'contract' unless the profile filter selects another. */
    public static function budget_limits() {
        $profiles = self::budget_profiles();
        $profile  = 'contract';
        if ( function_exists( 'apply_filters' ) ) {
            $picked = apply_filters( 'tzfd_ai_connector_budget_profile', $profile );
            if ( is_string( $picked ) && isset( $profiles[ $picked ] ) ) { $profile = $picked; }
        }
        $limits = $profiles[ $profile ];
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
     * Sends one typed request and records a redacted event (feature name, status, latency only;
     * never the payload, the response body or the secret). Always returns a structured array:
     * ok, status, use_local, contract, data (and missing_scopes where relevant).
     */
    public static function request( $feature, $payload = array() ) {
        $started = microtime( true );
        $result  = self::dispatch( $feature, $payload );
        $known   = is_string( $feature ) && isset( self::features()[ $feature ] ) ? $feature : 'unknown';
        self::log_event( $known, $result['status'], (int) round( ( microtime( true ) - $started ) * 1000 ) );
        return $result;
    }

    private static function dispatch( $feature, $payload ) {
        $def = is_string( $feature ) && isset( self::features()[ $feature ] ) ? self::features()[ $feature ] : null;
        if ( null === $def ) { return self::result( 'feature_not_allowed' ); }
        if ( ! is_array( $payload ) ) { return self::result( 'invalid_request' ); }
        if ( ! self::text_within_limit( $payload ) ) { return self::result( 'invalid_request' ); }

        $cfg = self::config();
        if ( ! $cfg['enabled'] ) { return self::result( 'ai_disabled' ); }
        if ( ! $cfg['consent'] ) { return self::result( 'consent_required' ); }
        $missing = array_values( array_diff( $def['scopes'], $cfg['scopes'] ) );
        if ( array() !== $missing ) {
            return self::result( 'scope_not_granted', array(), array( 'missing_scopes' => $missing ) );
        }
        $secret = self::secret();
        if ( ! self::configured( $cfg, $secret ) ) { return self::result( 'not_configured' ); }
        // Issue #376: the site must hold a valid pairing to a Central company before any hosted call.
        // Fails closed when the pairing class is missing, expired, mismatched or unpaired. No budget is charged.
        if ( ! class_exists( 'TZFD_Central_Tenant' ) ) { return self::result( 'not_configured' ); }
        $pair = TZFD_Central_Tenant::resolve( $cfg['site_id'], TZFD_Central_Tenant::OPERATION_HOSTED );
        if ( empty( $pair['ok'] ) ) { return self::result( 'not_configured' ); }

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
        try {
            $raw = self::transport( $request );
        } catch ( Throwable $e ) {
            // A failing transport must never break the local booking flow: treat it as an outage.
            $raw = array( 'error' => 'offline' );
        }
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
            'contract'        => self::CONTRACT,
            'enabled'         => $cfg['enabled'],
            'connected'       => self::configured( $cfg, self::secret() ) && $cfg['consent'],
            'consent'         => $cfg['consent'],
            'consent_version' => self::CONSENT_VERSION,
            'disclosure'      => self::disclosure(),
            'scopes'          => $cfg['scopes'],
            'entitlements'    => $cfg['entitlements'],
            'endpoint_set'    => '' !== $cfg['endpoint'],
            'secret_set'      => $has,
            'site_id'         => $cfg['site_id'],
            'retention_days'  => self::retention_days(),
            'event_count'     => count( self::events() ),
        );
    }

    /** Redacted event log, oldest first. Each entry holds only at, feature, status and latency_ms. */
    public static function events() {
        $events = get_option( self::EVENT_OPTION, array() );
        return is_array( $events ) ? array_values( $events ) : array();
    }

    /** Deletes the whole event log. Usage counters and budgets are not touched. */
    public static function clear_events() {
        delete_option( self::EVENT_OPTION );
        return true;
    }

    /** Days an event is kept (1..RETENTION_MAX). */
    public static function retention_days() {
        $opt  = self::raw_option();
        $days = isset( $opt['retention_days'] ) ? (int) $opt['retention_days'] : self::RETENTION_DEFAULT;
        return max( 1, min( self::RETENTION_MAX, $days ) );
    }

    /** Sets event retention. Only an integer from 1 to RETENTION_MAX is accepted. */
    public static function set_retention_days( $days ) {
        if ( ! is_int( $days ) || $days < 1 || $days > self::RETENTION_MAX ) { return false; }
        $opt = self::raw_option();
        $opt['retention_days'] = $days;
        update_option( self::OPTION, $opt, false );
        return true;
    }

    /** Usage counters and budget use for one month (YYYY-MM, default current). */
    public static function usage( $period = '' ) {
        $period = '' === $period ? gmdate( 'Y-m' ) : (string) $period;
        return self::read_usage( $period );
    }

    /**
     * What the administrator is told before consenting: provider, data classes (scopes), hosted quota and
     * the consent version. Retention is null until the owner decides it, so no retention promise is made here.
     */
    public static function disclosure() {
        $scopes = array();
        foreach ( self::features() as $def ) { $scopes = array_merge( $scopes, $def['scopes'] ); }
        return array(
            'contract'         => self::CONTRACT,
            'consent_version'  => self::CONSENT_VERSION,
            'provider'         => 'Titan hosted AI service (endpoint set by the site administrator)',
            'data_classes'     => array_values( array_unique( $scopes ) ),
            'quota'            => self::budget_limits(),
            'retention'        => null,
            'owner_decisions'  => array( 'retention', 'consent_wording', 'provider_legal_name' ),
        );
    }

    /**
     * Metered cost for one month. $unit_costs is the owner's price per 1,000 tokens:
     * array( 'input' => n, 'output' => n, 'embedding' => n ). Without a complete price table no money figure
     * is produced, so no cost is invented. Cache hit ratio = hosted cache hits / successful calls.
     */
    public static function cost_report( array $unit_costs = array(), $period = '' ) {
        $usage  = self::usage( $period );
        $totals = array( 'calls' => 0, 'ok' => 0, 'fallback_local' => 0, 'cache_hits' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'embedding_tokens' => 0, 'latency_ms' => 0 );
        foreach ( $usage['features'] as $f ) {
            foreach ( array_keys( $totals ) as $key ) {
                $totals[ $key ] += isset( $f[ $key ] ) ? max( 0, (int) $f[ $key ] ) : 0;
            }
        }
        $keys  = array( 'input', 'output', 'embedding' );
        $known = true;
        foreach ( $keys as $key ) {
            if ( ! isset( $unit_costs[ $key ] ) || ! is_numeric( $unit_costs[ $key ] ) || (float) $unit_costs[ $key ] < 0 ) { $known = false; }
        }
        $cost = null;
        if ( $known ) {
            $cost = round(
                ( $totals['tokens_in'] * (float) $unit_costs['input'] + $totals['tokens_out'] * (float) $unit_costs['output'] + $totals['embedding_tokens'] * (float) $unit_costs['embedding'] ) / 1000,
                6
            );
        }
        return array(
            'period'          => $usage['period'],
            'totals'          => $totals,
            'cache_hit_ratio' => $totals['ok'] > 0 ? round( $totals['cache_hits'] / $totals['ok'], 4 ) : 0.0,
            'cost_known'      => $known,
            'cost'            => $cost,
        );
    }

    /**
     * Upper bound on metered units across $tenants sites at the pilot budget ceilings. Counts only: money
     * needs the owner's unit prices (see cost_report) and a per-unit cost is not assumed here.
     */
    public static function tenant_ceiling( $tenants ) {
        $tenants = max( 0, (int) $tenants );
        $units   = array();
        $total   = 0;
        foreach ( self::budget_limits() as $key => $limit ) {
            $units[ $key ] = $tenants * (int) $limit;
            $total        += $units[ $key ];
        }
        return array( 'tenants' => $tenants, 'max_units_by_budget' => $units, 'max_units_total' => $total );
    }

    /**
     * Setup notice shown before the administrator keeps AI on: what leaves the site, the confirmation the
     * administrator gives when saving consent, and where the terms are.
     * Consent wording and the 30-day retention default were approved by the owner on issue #348 (decision
     * recorded 2026-11-23). wording_status records that approval. The owner confirmed on 2026-11-23 that the
     * terms were legal-reviewed; terms_status records that approval (not draft).
     * Filters: tzfd_ai_setup_notice( $text, $disclosure ), tzfd_ai_consent_confirmation( $text, $disclosure )
     * and tzfd_ai_terms_url( $url ).
     */
    public static function setup_notice() {
        $d       = self::disclosure();
        $text    = 'Local AI features run on this site and send nothing away. Hosted AI runs only after you connect an endpoint and agree below: '
            . 'then the text visitors type and the fields each AI feature needs (' . implode( ', ', $d['data_classes'] ) . ') are sent to '
            . $d['provider'] . '. Booking, calendar, customer and reminder features work without AI. '
            . 'You can withdraw consent or turn AI off at any time, and withdrawing stops hosted requests straight away.';
        $confirm = 'I agree that the data listed above may be sent to the hosted AI endpoint I have connected, and I understand I can withdraw this at any time.';
        // Terms page approved by the owner on 2026-11-23 after legal review (issue #348). Not a draft.
        $url     = 'https://titanzero.io/scheduling-enquiries-terms/';
        if ( function_exists( 'apply_filters' ) ) {
            $text    = apply_filters( 'tzfd_ai_setup_notice', $text, $d );
            $confirm = apply_filters( 'tzfd_ai_consent_confirmation', $confirm, $d );
            $url     = apply_filters( 'tzfd_ai_terms_url', $url );
        }
        return array(
            'text'           => (string) $text,
            'confirmation'   => (string) $confirm,
            'terms_url'      => (string) $url,
            'owner_review'   => false,
            'wording_status' => 'owner_approved_issue_348',
            'terms_status'   => 'owner_approved_issue_348',
        );
    }

    /** Turns AI on or off for the free plugin. AI is on until an administrator turns it off. */
    public static function set_enabled( $on ) {
        $opt = self::raw_option();
        $opt['enabled'] = (bool) $on;
        update_option( self::OPTION, $opt, false );
        return true;
    }

    /**
     * Pairs the site with the hosted service. Endpoint must be https. Returns false on invalid input.
     */
    public static function configure( $endpoint, $site_id, $secret ) {
        if ( ! is_string( $endpoint ) || ! is_string( $site_id ) || ! is_string( $secret ) ) { return false; }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure-PHP suite runs without WordPress; the scheme/host check below is the same either way.
        $parts = parse_url( $endpoint );
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
        self::audit( 'connect' );
        return true;
    }

    /** Explicit opt-in for the listed scopes. Unknown scopes are dropped. */
    public static function grant_consent( array $scopes ) {
        $opt = self::raw_option();
        $opt['consent']         = true;
        $opt['consent_version'] = self::CONSENT_VERSION;
        $opt['consent_at']      = time();
        $opt['scopes']      = self::clean_scopes( $scopes );
        update_option( self::OPTION, $opt, false );
        self::audit( 'consent_granted', array( 'scopes' => $opt['scopes'] ) );
        return true;
    }

    /** Immediate revocation. Requests stop at once, before any transport call. */
    public static function revoke_consent() {
        $opt = self::raw_option();
        $opt['consent'] = false;
        $opt['scopes']  = array();
        update_option( self::OPTION, $opt, false );
        self::audit( 'consent_revoked' );
        return true;
    }

    /** Stores the tiers the hosted service reports for this site. Unknown tiers are dropped. */
    public static function set_entitlements( array $tiers ) {
        $opt = self::raw_option();
        $opt['entitlements'] = self::clean_tiers( $tiers );
        update_option( self::OPTION, $opt, false );
        return true;
    }

    /**
     * Removes the secret, endpoint, consent and entitlements, and the Central company pairing (#452), so a
     * later pairing can choose a different company. Usage history is kept.
     */
    public static function disconnect() {
        delete_option( self::SECRET_OPTION );
        update_option( self::OPTION, array( 'consent' => false, 'scopes' => array(), 'endpoint' => '', 'site_id' => '', 'entitlements' => array() ), false );
        if ( class_exists( 'TZFD_Central_Tenant' ) ) { TZFD_Central_Tenant::disconnect(); }
        self::audit( 'disconnect' );
        return true;
    }

    /**
     * Connect, consent and disconnect trail, oldest first. Each entry holds only at, action and, for consent
     * changes, the scopes granted. The endpoint, site ID and secret are never recorded.
     *
     * @return array<int, array{at:int, action:string, scopes?:string[]}>
     */
    public static function audit_log() {
        $rows = get_option( self::AUDIT_OPTION, array() );
        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
    }

    // ---- internals -------------------------------------------------------

    /** True when every string in the payload, at any depth, holds at most MAX_CONTENT_CHARS characters. */
    private static function text_within_limit( $value ) {
        if ( is_string( $value ) ) { return mb_strlen( $value, 'UTF-8' ) <= self::MAX_CONTENT_CHARS; }
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) { if ( ! self::text_within_limit( $item ) ) { return false; } }
        }
        return true;
    }

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
            'enabled'      => ! isset( $opt['enabled'] ) || false !== $opt['enabled'],
            // Consent given under an older disclosure version does not count; the admin must grant again.
            'consent'      => isset( $opt['consent'] ) && true === $opt['consent']
                && isset( $opt['consent_version'] ) && self::CONSENT_VERSION === $opt['consent_version'],
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
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure-PHP suite runs without WordPress; the scheme/host check below is the same either way.
        $parts = parse_url( $cfg['endpoint'] );
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

    /**
     * Appends one redacted event and prunes entries older than the retention window. Best effort:
     * skipped when the lock is busy, so it never delays or fails a request.
     */
    private static function audit( $action, array $detail = array() ) {
        $entry = array( 'at' => time(), 'action' => (string) $action );
        if ( isset( $detail['scopes'] ) && is_array( $detail['scopes'] ) ) {
            $entry['scopes'] = self::clean_scopes( $detail['scopes'] );
        }
        $rows   = self::audit_log();
        $rows[] = $entry;
        update_option( self::AUDIT_OPTION, array_slice( $rows, -self::AUDIT_LIMIT ), false );
    }

    private static function log_event( $feature, $status, $latency ) {
        if ( ! self::lock() ) { return; }
        $now    = time();
        $cutoff = $now - self::retention_days() * 86400;
        $kept   = array();
        foreach ( self::events() as $event ) {
            if ( is_array( $event ) && isset( $event['at'] ) && (int) $event['at'] >= $cutoff ) { $kept[] = $event; }
        }
        $kept[] = array(
            'at'         => $now,
            'feature'    => (string) $feature,
            'status'     => (string) $status,
            'latency_ms' => max( 0, (int) $latency ),
        );
        update_option( self::EVENT_OPTION, array_slice( $kept, -self::EVENT_LIMIT ), false );
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
