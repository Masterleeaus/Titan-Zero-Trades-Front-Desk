<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * AI provider choices for the Settings > AI tab.
 *
 * Three groups:
 *  - Options: an account with a provider (or a server you run yourself).
 *    Each one is an OpenAI-compatible address with a suggested model.
 *  - Your own key: OpenAI, Anthropic, or any other OpenAI-compatible server.
 *
 * Every choice is stored in the same options the AI add-on already reads:
 * tzfd_ai_provider, tzfd_ai_api_base, tzfd_ai_model and tzfd_ai_api_key.
 */
class TZFD_AI_Options {

    /**
     * Every choice the AI tab offers, in display order.
     *
     * Keys:
     *  id           stable identifier, stored in the form
     *  label        name shown to the admin
     *  group        free (shown as "Options") or own (shown as "Your own key")
     *  provider     value stored in tzfd_ai_provider (openai, anthropic or custom)
     *  base         OpenAI-compatible address, '' for the provider's own default
     *  model        suggested model, used when the model box is left blank
     *  key_optional true when the server needs no key (a local server)
     *  key_url      page where an API key is created, '' when none is needed
     *  note         one line shown under the choice
     *
     * @return array
     */
    public static function presets() {
        $presets = array(
            array(
                'id' => 'groq', 'label' => 'Groq', 'group' => 'free', 'provider' => 'custom',
                'base' => 'https://api.groq.com/openai/v1', 'model' => 'openai/gpt-oss-120b', 'key_optional' => false,
                'key_url' => 'https://console.groq.com/keys',
                'note' => __( 'Get a key from Groq.', 'titan-zero-trades-front-desk' ),
            ),
            array(
                'id' => 'openrouter', 'label' => 'OpenRouter', 'group' => 'free', 'provider' => 'custom',
                'base' => 'https://openrouter.ai/api/v1', 'model' => 'meta-llama/llama-3.3-70b-instruct:free', 'key_optional' => false,
                'key_url' => 'https://openrouter.ai/keys',
                'note' => __( 'Get a key from OpenRouter.', 'titan-zero-trades-front-desk' ),
            ),
            array(
                'id' => 'cerebras', 'label' => 'Cerebras', 'group' => 'free', 'provider' => 'custom',
                'base' => 'https://api.cerebras.ai/v1', 'model' => 'llama-3.3-70b', 'key_optional' => false,
                'key_url' => 'https://cloud.cerebras.ai/',
                'note' => __( 'Get a key from Cerebras.', 'titan-zero-trades-front-desk' ),
            ),
            array(
                'id' => 'google', 'label' => 'Google AI Studio', 'group' => 'free', 'provider' => 'custom',
                'base' => 'https://generativelanguage.googleapis.com/v1beta/openai/', 'model' => 'gemini-2.5-flash', 'key_optional' => false,
                'key_url' => 'https://aistudio.google.com/app/apikey',
                'note' => __( 'Get a key from Google AI Studio.', 'titan-zero-trades-front-desk' ),
            ),
            array(
                'id' => 'ollama', 'label' => 'Ollama (your own server)', 'group' => 'free', 'provider' => 'custom',
                'base' => 'http://localhost:11434/v1', 'model' => 'llama3.2', 'key_optional' => true,
                'key_url' => '',
                'note' => __( 'Runs on a server you control. No key needed.', 'titan-zero-trades-front-desk' ),
            ),
            array(
                'id' => 'openai', 'label' => 'OpenAI', 'group' => 'own', 'provider' => 'openai',
                'base' => '', 'model' => '', 'key_optional' => false, 'key_url' => '',
                'note' => __( 'Your own OpenAI key.', 'titan-zero-trades-front-desk' ),
            ),
            array(
                'id' => 'anthropic', 'label' => 'Anthropic (Claude)', 'group' => 'own', 'provider' => 'anthropic',
                'base' => '', 'model' => '', 'key_optional' => false, 'key_url' => '',
                'note' => __( 'Your own Anthropic key.', 'titan-zero-trades-front-desk' ),
            ),
            array(
                'id' => 'custom', 'label' => __( 'Other (OpenAI-compatible server)', 'titan-zero-trades-front-desk' ), 'group' => 'own', 'provider' => 'custom',
                'base' => '', 'model' => '', 'key_optional' => false, 'key_url' => '',
                'note' => __( 'Any server that speaks the OpenAI format. Enter its address.', 'titan-zero-trades-front-desk' ),
            ),
        );

        return apply_filters( 'titan_zero_front_desk_ai_presets', $presets );
    }

    /** One choice by id, or null. */
    public static function preset( $id ) {
        foreach ( self::presets() as $p ) {
            if ( $p['id'] === $id ) return $p;
        }
        return null;
    }

    /**
     * Which choice matches what is stored now. Works from the saved provider and address, so
     * sites saved before this tab existed (or by the setup wizard) show the right choice.
     */
    public static function current_choice() {
        $provider = (string) get_option( 'tzfd_ai_provider', 'openai' );
        $base     = rtrim( trim( (string) get_option( 'tzfd_ai_api_base', '' ) ), '/' );
        if ( $provider === 'anthropic' ) return 'anthropic';
        if ( $base !== '' ) {
            foreach ( self::presets() as $p ) {
                if ( $p['base'] !== '' && rtrim( $p['base'], '/' ) === $base ) return $p['id'];
            }
            return 'custom';
        }
        return $provider === 'custom' ? 'custom' : 'openai';
    }

    /**
     * Turns a form choice into the values to store. Pure: reads no options, so it can be tested.
     *
     * @param string $choice  preset id from the form
     * @param array  $posted  keys: model, base (typed by the admin; used by Other only)
     * @return array          keys: provider, base, model, key_optional
     */
    public static function resolve( $choice, $posted = array() ) {
        $p = self::preset( $choice );
        if ( null === $p ) $p = self::preset( 'openai' );

        $model = sanitize_text_field( trim( (string) ( $posted['model'] ?? '' ) ) );
        if ( $model === '' ) $model = $p['model'];

        $base = $p['base'];
        if ( $p['id'] === 'custom' ) $base = esc_url_raw( trim( (string) ( $posted['base'] ?? '' ) ), array( 'http', 'https' ) );

        return array(
            'id'           => $p['id'],
            'provider'     => $p['provider'],
            'base'         => $base,
            'model'        => $model,
            'key_optional' => ! empty( $p['key_optional'] ),
        );
    }

    /** No upgrade link: the plugin shows no upgrade card or upgrade prompt. Always ''. */
    public static function upgrade_url() {
        return '';
    }

    /** No price line: the plugin shows no upgrade card or upgrade prompt. Always ''. */
    public static function price() {
        return '';
    }

    /** True when the stored key is the placeholder used by servers that need no key. */
    public static function key_is_placeholder() {
        return TZFD_Addon_Support::secret_decrypt( get_option( 'tzfd_ai_api_key', '' ) ) === 'local';
    }
}
