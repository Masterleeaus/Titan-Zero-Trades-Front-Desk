<?php
/**
 * Contract for the one optional hosted call the Free FAQ search may make: a query embedding (#21).
 *
 * The connector from the AI-connector lane (TZFD_AI_Connector) is not on main yet, so this file defines the
 * interface, a stub that is always unavailable, and a filter. The connector registers itself with
 * add_filter( 'tzfd_embedding_provider', ... ) and returns an object implementing this interface.
 * Until that happens the Free path works without it, and every answer it gives still comes from approved text.
 *
 * Implementations must never throw. A null return means "proxy unavailable or refused", which the search treats
 * as a normal, expected outcome.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface TZFD_Embedding_Provider {
    /**
     * @param string $text Already redacted text. Never a raw customer record.
     * @return float[]|null Embedding vector, or null when unavailable.
     */
    public function embed( $text );
}

/** Default provider: always unavailable. Keeps the Free path fully local. */
final class TZFD_Embedding_Provider_Stub implements TZFD_Embedding_Provider {
    public function embed( $text ) {
        return null;
    }
}

final class TZFD_Embedding_Provider_Resolver {

    /** Returns the registered provider, or the stub. Anything that is not a provider is ignored. */
    public static function resolve() {
        $provider = function_exists( 'apply_filters' ) ? apply_filters( 'tzfd_embedding_provider', null ) : null;
        return $provider instanceof TZFD_Embedding_Provider ? $provider : new TZFD_Embedding_Provider_Stub();
    }
}
