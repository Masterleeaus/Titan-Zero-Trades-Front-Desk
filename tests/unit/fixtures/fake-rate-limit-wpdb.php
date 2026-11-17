<?php
/**
 * In-memory stand-in for the wp_options statements that TZFD_Rate_Limit issues. Not shipped in the plugin.
 *
 * It models the four statement shapes in class-tzfd-rate-limit.php: INSERT IGNORE (unique option_name),
 * the conditional counting UPDATE, the expired-window UPDATE, and the DELETE and LIKE purge. Each branch
 * applies the same WHERE condition the SQL states, so a test that passes here shows the PHP decision logic
 * is right. It does not prove MySQL's concurrency behaviour; that needs a live database.
 */
final class TZ_Fake_Rate_Limit_Wpdb {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	/** @var array<string,string> option_name => option_value ("<window_end>:<count>") */
	public $rows    = array();

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $sql, ...$args ) {
		return array( 'sql' => (string) $sql, 'args' => $args );
	}

	private static function head( $value ) {
		return (int) explode( ':', (string) $value )[0];
	}

	private static function count_of( $value ) {
		$parts = explode( ':', (string) $value );
		return (int) end( $parts );
	}

	public function query( $p ) {
		$sql = $p['sql'];
		$a   = $p['args'];

		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			if ( isset( $this->rows[ $a[0] ] ) ) {
				return 0;
			}
			$this->rows[ $a[0] ] = $a[1];
			return 1;
		}

		if ( 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'CONCAT(' ) ) {
			// Count one hit: row in window (window_end > now) and below the limit.
			$name = $a[0];
			$now  = (int) $a[1];
			$max  = (int) $a[2];
			if ( ! isset( $this->rows[ $name ] ) ) { return 0; }
			$value = $this->rows[ $name ];
			if ( self::head( $value ) <= $now || self::count_of( $value ) >= $max ) { return 0; }
			$this->rows[ $name ] = self::head( $value ) . ':' . ( self::count_of( $value ) + 1 );
			return 1;
		}

		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			// Expired-window reset: only a row whose window_end is at or before now is replaced.
			$new  = (string) $a[0];
			$name = $a[1];
			$now  = (int) $a[2];
			if ( ! isset( $this->rows[ $name ] ) || self::head( $this->rows[ $name ] ) > $now ) { return 0; }
			$this->rows[ $name ] = $new;
			return 1;
		}

		if ( 0 === strpos( $sql, 'DELETE' ) && false !== strpos( $sql, 'LIKE' ) ) {
			$prefix = rtrim( str_replace( '\\', '', $a[0] ), '%' );
			$now    = (int) $a[1];
			$n      = 0;
			foreach ( $this->rows as $name => $value ) {
				if ( 0 === strpos( $name, $prefix ) && self::head( $value ) <= $now ) {
					unset( $this->rows[ $name ] );
					$n++;
				}
			}
			return $n;
		}

		if ( 0 === strpos( $sql, 'DELETE' ) ) {
			$name = $a[0];
			if ( ! isset( $this->rows[ $name ] ) ) { return 0; }
			unset( $this->rows[ $name ] );
			return 1;
		}

		return false;
	}

	public function get_var( $p ) {
		$name = $p['args'][0];
		return isset( $this->rows[ $name ] ) ? $name : null;
	}
}
