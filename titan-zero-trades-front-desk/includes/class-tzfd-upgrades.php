<?php
/**
 * Optional links to the paid hosted AI Assistant and AI Agent services.
 *
 * Front Desk works fully without them. There is no separate Pro package, and
 * the plugins list shows no upgrade link.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Upgrades {
	/** Product page for the paid hosted AI Assistant subscription. */
	const AI_URL = 'https://titanzero.io/frontdeskai';

	/** Product page for the paid hosted AI Agent subscription. */
	const AGENT_URL = 'https://titanzero.io/frontdeskagent';

	/** Return a safe HTTPS product URL, or an empty string when none is set. */
	public static function url( $product ) {
		$map = array(
			'ai'    => self::AI_URL,
			'agent' => self::AGENT_URL,
		);
		$url = isset( $map[ $product ] ) ? $map[ $product ] : '';
		$url = (string) apply_filters( 'tzfd_upgrade_url', $url, $product );
		return 0 === strpos( $url, 'https://' ) ? esc_url_raw( $url ) : '';
	}

	/** Cards for every product that has a URL. */
	public static function cards() {
		$cards = array();
		$ai    = self::url( 'ai' );
		$agent = self::url( 'agent' );
		if ( '' !== $ai ) {
			$cards[] = array(
				'name'   => __( 'AI Assistant', 'titan-zero-trades-front-desk' ),
				'text'   => __( 'A paid, hosted AI service that runs outside WordPress and needs a subscription. Read its data handling and terms before you connect it.', 'titan-zero-trades-front-desk' ),
				'button' => __( 'Explore AI Assistant', 'titan-zero-trades-front-desk' ),
				'url'    => $ai,
			);
		}
		if ( '' !== $agent ) {
			$cards[] = array(
				'name'   => __( 'AI Agent', 'titan-zero-trades-front-desk' ),
				'text'   => __( 'A paid, hosted agent service that runs outside WordPress and needs a subscription. Read its data handling and terms before you connect it.', 'titan-zero-trades-front-desk' ),
				'button' => __( 'Explore AI Agent', 'titan-zero-trades-front-desk' ),
				'url'    => $agent,
			);
		}
		return $cards;
	}

	/** Print the upgrade cards. Prints nothing when no product URL is set. */
	public static function render_cards() {
		$cards = self::cards();
		if ( empty( $cards ) ) { return; }
		echo '<h2>' . esc_html__( 'Upgrades', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p class="tzfd-set-intro">' . esc_html__( 'Optional. Front Desk works fully without them.', 'titan-zero-trades-front-desk' ) . '</p>';
		echo '<div class="tzfd-upgrade-cards">';
		foreach ( $cards as $card ) {
			echo '<div class="tzfd-upgrade-card"><h3>' . esc_html( $card['name'] ) . '</h3>';
			echo '<p>' . esc_html( $card['text'] ) . '</p>';
			echo '<p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $card['url'] ) . '">' . esc_html( $card['button'] ) . '</a></p></div>';
		}
		echo '</div>';
	}
}
