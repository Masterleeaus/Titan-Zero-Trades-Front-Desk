<?php
/** Progressive admin wizards and optional WordPress AI Client suggestions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Smart_Wizards {
    public static function boot() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
        add_action( 'wp_ajax_tzfd_wizard_suggestions', array( __CLASS__, 'suggestions' ) );
    }
    public static function assets() {
        if ( ! is_admin() || ! current_user_can( 'tz_fd_access' ) ) { return; }
        // Only Front Desk routes: no global admin DOM modification.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route check to select assets; no state change.
        $page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( ! in_array( $page, array( 'tz_fd_calendar', 'tzfd-resources', 'tzfd-equipment', 'tzfd-locations', 'tz_fd_services', 'tz-customers' ), true ) ) { return; }
        $base = plugin_dir_url( dirname( __DIR__ ) . '/titan-zero-front-desk.php' );
        wp_enqueue_style( 'tzfd-smart-wizards', $base . 'css/tzfd-smart-wizards.css', array(), TZFD_VERSION );
        wp_enqueue_script( 'tzfd-smart-wizards', $base . 'js/tzfd-smart-wizards.js', array(), TZFD_VERSION, true );
        wp_add_inline_script( 'tzfd-smart-wizards', 'window.tzfdWizardConfig=' . wp_json_encode( array(
            'ajax' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'tzfd_wizard_suggestions' ),
            'aiAvailable' => function_exists( 'wp_ai_client_prompt' ) && ( ! function_exists( 'wp_supports_ai' ) || wp_supports_ai() ),
            'labels' => array(
                'previous' => __( 'Back', 'titan-zero-trades-front-desk' ),
                'next' => __( 'Continue', 'titan-zero-trades-front-desk' ),
                'review' => __( 'Review and save', 'titan-zero-trades-front-desk' ),
                'suggest' => __( 'Suggest details', 'titan-zero-trades-front-desk' ),
                'apply' => __( 'Apply selected suggestions', 'titan-zero-trades-front-desk' ),
                'notes' => __( 'Describe what you need. Suggestions only fill empty fields and are never saved without your confirmation.', 'titan-zero-trades-front-desk' ),
                'unavailable' => __( 'AI is optional. Configure a WordPress AI provider in Settings → Connectors to enable AI suggestions.', 'titan-zero-trades-front-desk' ),
            ),
        ) ) . ';', 'before' );
    }
    public static function suggestions() {
        // Capability and nonce are verified before any request field is read.
        if ( ! current_user_can( 'tz_fd_access' ) || ! check_ajax_referer( 'tzfd_wizard_suggestions', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Access denied or expired request.', 'titan-zero-trades-front-desk' ) ), 403 );
        }
        require_once __DIR__ . '/class-tzfd-admin-write-guard.php';
        $content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : null;
        if ( ! TZFD_Admin_Write_Guard::valid( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ), $_POST, $content_length, 20, 8192 ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid suggestion request.', 'titan-zero-trades-front-desk' ) ), 400 );
            return;
        }
        $type = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
        $allowed = array(
            'booking' => array( 'name', 'email', 'phone', 'notes' ),
            'resource' => array( 'name', 'role', 'kind', 'notes', 'capacity' ),
            'location' => array( 'name', 'address', 'phone', 'postcodes' ),
            'service' => array( 'name', 'description', 'duration', 'price' ),
            'customer' => array( 'name', 'email', 'phone', 'address', 'notes' ),
        );
        if ( ! isset( $allowed[ $type ] ) ) { wp_send_json_error( array( 'message' => __( 'Unsupported form.', 'titan-zero-trades-front-desk' ) ), 400 ); }
        $description = isset( $_POST['description'] ) && is_string( $_POST['description'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) ) : '';
        if ( '' === $description || strlen( $description ) > 1500 ) { wp_send_json_error( array( 'message' => __( 'Provide a description of 1–1500 characters.', 'titan-zero-trades-front-desk' ) ), 400 ); }
        if ( ! function_exists( 'wp_ai_client_prompt' ) || ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) ) {
            wp_send_json_error( array( 'message' => __( 'WordPress AI Client is unavailable. Local suggestions remain enabled.', 'titan-zero-trades-front-desk' ) ), 503 );
        }
        // Per-user rate limit protects paid provider credentials from rapid repeated calls.
        // Store only a counter: never persist the submitted description or provider response.
        $user_id = get_current_user_id();
        $limit_key = 'tzfd_ai_wizard_rate_' . $user_id;
        $requests = (int) get_transient( $limit_key );
        if ( $requests >= 10 ) {
            wp_send_json_error( array( 'message' => __( 'AI suggestion limit reached. Try again later or use local defaults.', 'titan-zero-trades-front-desk' ) ), 429 );
        }
        set_transient( $limit_key, $requests + 1, HOUR_IN_SECONDS );
        // No customer records, secrets or API keys are transmitted; only text explicitly submitted for this request.
        $prompt = 'Provide concise structured suggestions for a WordPress business ' . $type . ' form. Return ONLY a JSON object; no markdown or explanation. Allowed keys: ' . implode( ', ', $allowed[ $type ] ) . '. Use plain text strings only. Do not invent personal contact details, addresses, dates, prices or identity. Omit uncertain values. Description: ' . $description;
        // Same budget boundary as the booking assistant: reserve before the call, settle with real usage after it.
        if ( ! class_exists( 'TZFD_AI_Provider_Dispatch' ) ) {
            require_once dirname( __DIR__ ) . '/includes/class-tzfd-ai-provider-dispatch.php';
        }
        $company_id  = TZFD_Scoped_Store::company_id();
        $provider_id = 'wordpress-ai-client';
        $site_pays   = TZFD_AI_Provider_Dispatch::site_pays_provider( $provider_id );
        $gate        = $site_pays ? array( 'allowed' => true ) : TZFD_AI_Provider_Dispatch::reserve( $company_id, $provider_id, wp_generate_uuid4(), 2048 );
        if ( empty( $gate['allowed'] ) ) {
            wp_send_json_error( array( 'message' => __( 'AI suggestions are not authorised for this business yet. Local suggestions remain enabled.', 'titan-zero-trades-front-desk' ) ), 503 );
        }
        $usage_known = false;
        $prompt_used = 0;
        $completion_used = 0;
        $failed      = false;
        try {
            $result = wp_ai_client_prompt( $prompt )->using_temperature( 0.2 )->using_max_tokens( 800 )->generate_text_result();
            $failed = is_wp_error( $result ) || ! is_object( $result );
            $text   = $failed ? '' : $result->toText();
            $token_usage = ( ! $failed && method_exists( $result, 'getTokenUsage' ) ) ? $result->getTokenUsage() : null;
            if ( is_object( $token_usage ) && method_exists( $token_usage, 'getPromptTokens' ) && method_exists( $token_usage, 'getCompletionTokens' ) ) {
                $prompt_used     = (int) $token_usage->getPromptTokens();
                $completion_used = (int) $token_usage->getCompletionTokens();
                $usage_known     = $prompt_used >= 0 && $completion_used >= 0;
            }
        } catch ( Throwable $error ) {
            // Never leak provider internals or keys into admin-ajax responses.
            $failed = true;
            $text   = '';
        }
        // Unknown usage or a failed call must still be reported to the budget owner before anything is returned.
        $settlement = $site_pays ? array( 'settled' => true ) : ( $usage_known
            ? TZFD_AI_Provider_Dispatch::settle( $company_id, $provider_id, $gate['request_id'], $gate['reservation_id'], $prompt_used, $completion_used, $failed, $gate['reserved_tokens'] )
            : array( 'settled' => false ) );
        if ( empty( $settlement['settled'] ) ) {
            TZFD_AI_Provider_Dispatch::report_uncertain( $company_id, $provider_id, $gate['request_id'], $gate['reservation_id'], $failed ? 'provider_failure' : ( $usage_known ? 'settlement_unverified' : 'unknown_usage' ) );
        }
        if ( $failed || empty( $settlement['settled'] ) ) {
            wp_send_json_error( array( 'message' => __( 'AI suggestions are temporarily unavailable. Please check your WordPress AI connector configuration.', 'titan-zero-trades-front-desk' ) ), 502 );
        }
        if ( ! is_string( $text ) || strlen( $text ) > 12000 ) { wp_send_json_error( array( 'message' => __( 'Unexpected AI response.', 'titan-zero-trades-front-desk' ) ), 502 ); }
        $text = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $text ) ) );
        $object = json_decode( $text, true );
        if ( ! is_array( $object ) ) { wp_send_json_error( array( 'message' => __( 'AI returned invalid suggestion format.', 'titan-zero-trades-front-desk' ) ), 502 ); }
        wp_send_json_success( array( 'suggestions' => self::filter_suggestions( $type, $object, $description ) ) );
    }

    /**
     * Treat AI text as untrusted suggestions, never authoritative contact data or money.
     * Unverified personal details may be hallucinated even when the prompt prohibits it.
     */
    public static function filter_suggestions( $type, $object, $description ) {
        $keys = array(
            'booking' => array( 'name', 'email', 'phone', 'notes' ),
            'resource' => array( 'name', 'role', 'kind', 'notes', 'capacity' ),
            'location' => array( 'name', 'address', 'phone', 'postcodes' ),
            'service' => array( 'name', 'description', 'duration', 'price' ),
            'customer' => array( 'name', 'email', 'phone', 'address', 'notes' ),
        );
        if ( ! isset( $keys[ $type ] ) || ! is_array( $object ) || ! is_string( $description ) ) { return array(); }
        $out = array();
        foreach ( $keys[ $type ] as $key ) {
            if ( ! isset( $object[ $key ] ) || ! is_string( $object[ $key ] ) ) { continue; }
            $value = trim( sanitize_text_field( $object[ $key ] ) );
            if ( '' === $value || strlen( $value ) > 300 ) { continue; }
            // Require exact evidence supplied by the operator for personal contact details.
            if ( in_array( $key, array( 'email', 'phone', 'address' ), true ) && false === stripos( $description, $value ) ) { continue; }
            if ( 'email' === $key && ! is_email( $value ) ) { continue; }
            // Do not quote AI-invented prices: only suggest an amount the operator actually entered.
            if ( 'price' === $key && false === strpos( $description, $value ) ) { continue; }
            if ( 'capacity' === $key && ( ! ctype_digit( $value ) || (int) $value < 1 || (int) $value > 1000 ) ) { continue; }
            if ( 'duration' === $key && ( ! ctype_digit( $value ) || (int) $value < 1 || (int) $value > 1440 ) ) { continue; }
            if ( 'price' === $key && ( ! preg_match( '/^\d{1,8}(?:\.\d{1,2})?$/D', $value ) ) ) { continue; }
            $out[ $key ] = $value;
        }
        return $out;
    }
}
TZFD_Smart_Wizards::boot();
