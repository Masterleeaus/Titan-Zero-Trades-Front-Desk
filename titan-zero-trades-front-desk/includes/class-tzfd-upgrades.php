<?php
/**
 * Optional upgrade links for Front Desk Pro, AI Assistant and AI Agent.
 *
 * Front Desk works fully without them.
 * Pro is a separate download: this plugin never installs or loads it.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Upgrades {
	/** Sales or download page for the separately distributed Pro package. Empty until published. */
	const PRO_URL = 'https://titanzero.io/frontdeskpro';

	/** Product page for the paid hosted AI Assistant subscription. */
	const AI_URL = 'https://titanzero.io/frontdeskai';

	/** Product page for the paid hosted AI Agent subscription. */
	const AGENT_URL = 'https://titanzero.io/frontdeskagent';

	/** Return a safe HTTPS product URL, or an empty string when none is set. */
	public static function url( $product ) {
		$map = array(
			'pro'   => self::PRO_URL,
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
		$pro   = self::url( 'pro' );
		$ai    = self::url( 'ai' );
		$agent = self::url( 'agent' );
		if ( '' !== $pro ) {
			$cards[] = array(
				'name'   => __( 'Front Desk Pro', 'titan-zero-trades-front-desk' ),
				'text'   => __( 'Advanced booking operations, reporting and configuration. Pro is a separate download and is not installed by this plugin.', 'titan-zero-trades-front-desk' ),
				'button' => __( 'Explore Pro Features', 'titan-zero-trades-front-desk' ),
				'url'    => $pro,
			);
		}
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

	/** Plugins-list entry: one Upgrade link to the first product that has a URL (Pro, then AI Assistant, then AI Agent). */
	public static function plugin_row_link( $links ) {
		$url = '';
		foreach ( array( 'pro', 'ai', 'agent' ) as $product ) {
			$url = self::url( $product );
			if ( '' !== $url ) { break; }
		}
		if ( '' === $url ) { return $links; }
		$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Upgrade', 'titan-zero-trades-front-desk' ) . '</a>';
		return $links;
	}
}
