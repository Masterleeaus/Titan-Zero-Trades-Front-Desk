<?php
/**
 * In-plugin view of the hosted AI service level (Free / Assistant / Agent Team).
 *
 * This class holds pure decision logic only: it makes no network calls, stores nothing and
 * never gates local features. The verified, signed entitlement payload is produced by the
 * connector contract (issue #16, #4, #43). This file only consumes an already verified payload.
 *
 * Invariant: local booking, customer, calendar, rules, FAQ and reporting code is never switched
 * off by this class. See docs/AI-SERVICES-ENTITLEMENT-AND-DOWNGRADE.md.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_AI_Services {
    const SCHEMA_VERSION   = 1;
    const LEVEL_FREE       = 'free_ai';
    const LEVEL_ASSISTANT  = 'assistant';
    const LEVEL_AGENT_TEAM = 'agent_team';

    /** Option holding the last verified entitlement payload received through the connector. */
    const OPTION = 'tzfd_ai_services_entitlement';

    const STATE_ACTIVE        = 'active';
    const STATE_GRACE         = 'grace';
    const STATE_PAUSED_AGENTS = 'paused_agents';

    /** Entitlement identifiers this build understands. Any other identifier is ignored. */
    const KNOWN_ENTITLEMENTS = array( self::LEVEL_ASSISTANT, self::LEVEL_AGENT_TEAM );

    /** Remote services that a level may request. Used by remote_service_allowed(). */
    const SERVICE_BASIC_AI     = 'basic_ai';
    const SERVICE_ASSISTANT    = 'assistant';
    const SERVICE_AGENT_TEAM   = 'agent_team';

    /** Local data and features that must survive every downgrade. Stated as data for tests and docs. */
    const PRESERVED_LOCAL = array(
        'bookings', 'customers', 'services', 'staff', 'resources', 'calendars', 'reminders',
        'faq_knowledge', 'local_rules', 'local_reports', 'privacy_exports', 'local_audit_log',
    );

    /** Cached descriptors, loaded once from data/ai-services/capabilities-v1.json. */
    private static $descriptors = null;

    /**
     * Capability descriptors (levels, what each includes, remote services, disclosure text).
     * Prices are intentionally null: they come from the verified Titan account, never from this file.
     *
     * @return array<string,mixed>
     */
    public static function descriptors() {
        if ( null !== self::$descriptors ) {
            return self::$descriptors;
        }
        $path = dirname( __DIR__ ) . '/data/ai-services/capabilities-v1.json';
        $raw  = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = false !== $raw ? json_decode( $raw, true ) : null;
        if ( ! is_array( $data ) || (int) ( $data['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION || empty( $data['levels'] ) ) {
            $data = array( 'schema_version' => self::SCHEMA_VERSION, 'levels' => array() );
        }
        self::$descriptors = $data;
        return self::$descriptors;
    }

    /**
     * Decide the effective level for this site from a verified, cached entitlement payload.
     *
     * @param mixed    $payload          Decoded entitlement payload, or null when none is cached.
     * @param int|null $site_company_id  Company this site belongs to, or null to skip the company check.
     * @param int|null $now              Unix time, or null for the current time.
     * @return array{level:string,reason:string,assistant:bool,agent_team:bool,local_features:string}
     */
    public static function resolve( $payload, $site_company_id = null, $now = null ) {
        $now = null === $now ? time() : (int) $now;

        if ( ! is_array( $payload ) ) {
            return self::result( self::LEVEL_FREE, 'no_entitlement' );
        }
        if ( (int) ( $payload['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION ) {
            return self::result( self::LEVEL_FREE, 'unsupported_schema' );
        }
        $company = (int) ( $payload['company_id'] ?? 0 );
        if ( $company <= 0 ) {
            return self::result( self::LEVEL_FREE, 'invalid_company' );
        }
        if ( null !== $site_company_id && (int) $site_company_id !== $company ) {
            return self::result( self::LEVEL_FREE, 'company_mismatch' );
        }
        $expires = (int) ( $payload['expires_at'] ?? 0 );
        if ( $expires <= 0 || $now >= $expires ) {
            return self::result( self::LEVEL_FREE, 'cache_expired' );
        }

        $state = (string) ( $payload['state'] ?? '' );
        $known = array_values( array_intersect( self::KNOWN_ENTITLEMENTS, (array) ( $payload['entitlements'] ?? array() ) ) );
        if ( array() === $known ) {
            return self::result( self::LEVEL_FREE, 'no_known_entitlement' );
        }

        $has_agent     = in_array( self::LEVEL_AGENT_TEAM, $known, true );
        $has_assistant = in_array( self::LEVEL_ASSISTANT, $known, true );

        if ( self::STATE_PAUSED_AGENTS === $state ) {
            // Agent Team is paused by the owner or the operator. Assistant remains when entitled.
            return $has_assistant
                ? self::result( self::LEVEL_ASSISTANT, 'paused_agents' )
                : self::result( self::LEVEL_FREE, 'paused_agents' );
        }
        if ( ! in_array( $state, array( self::STATE_ACTIVE, self::STATE_GRACE ), true ) ) {
            // Revoked, expired, cancelled, over budget, failed payment or unknown states drop to Free.
            return self::result( self::LEVEL_FREE, '' !== $state ? $state : 'unknown_state' );
        }

        $reason = self::STATE_GRACE === $state ? 'grace' : 'active';
        if ( $has_agent ) {
            return self::result( self::LEVEL_AGENT_TEAM, $reason ); // Agent Team includes the Assistant experience.
        }
        return self::result( self::LEVEL_ASSISTANT, $reason );
    }

    /**
     * Local features are never gated by hosted level. Always true, for every level and state.
     *
     * @param array $resolved Output of resolve().
     */
    public static function local_features_enabled( $resolved ) {
        unset( $resolved );
        return true;
    }

    /**
     * Whether a remote service may be requested. No paid level gates a service: every known service is allowed,
     * and each hosted request still needs its own consent, scopes and budget in TZFD_AI_Connector.
     *
     * @param string $service  One of the SERVICE_* constants.
     * @param array  $resolved Output of resolve() (unused; kept for the call signature).
     */
    public static function remote_service_allowed( $service, $resolved ) {
        unset( $resolved );
        return in_array( (string) $service, array( self::SERVICE_BASIC_AI, self::SERVICE_ASSISTANT, self::SERVICE_AGENT_TEAM ), true );
    }

    /**
     * What a downgrade does, as data. Remote work stops, and local data and features are kept.
     *
     * @param string $from Current level.
     * @param string $to   Target level.
     * @return array{preserved:string[],stopped_remote:string[],disabled_local:string[]}
     */
    public static function downgrade_actions( $from, $to ) {
        $from_rank = self::rank( $from );
        $to_rank   = self::rank( $to );
        $stopped   = array();
        if ( $to_rank < self::rank( self::LEVEL_AGENT_TEAM ) && $from_rank >= self::rank( self::LEVEL_AGENT_TEAM ) ) {
            $stopped[] = 'agent_schedules_and_queued_agent_tasks';
        }
        if ( $to_rank < self::rank( self::LEVEL_ASSISTANT ) && $from_rank >= self::rank( self::LEVEL_ASSISTANT ) ) {
            $stopped[] = 'assistant_conversations_and_drafts_requests';
        }
        return array(
            'preserved'      => self::PRESERVED_LOCAL,
            'stopped_remote' => $stopped,
            'disabled_local' => array(), // Always empty: downgrades never switch local features off.
        );
    }

    /** @return int */
    private static function rank( $level ) {
        switch ( (string) $level ) {
            case self::LEVEL_AGENT_TEAM:
                return 2;
            case self::LEVEL_ASSISTANT:
                return 1;
        }
        return 0;
    }

    /** @return array{level:string,reason:string,assistant:bool,agent_team:bool,local_features:string} */
    private static function result( $level, $reason ) {
        return array(
            'level'          => $level,
            'reason'         => (string) $reason,
            'assistant'      => self::rank( $level ) >= self::rank( self::LEVEL_ASSISTANT ),
            'agent_team'     => self::rank( $level ) >= self::rank( self::LEVEL_AGENT_TEAM ),
            'local_features' => 'enabled',
        );
    }
}
