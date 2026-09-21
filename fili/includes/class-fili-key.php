<?php
/**
 * Where the API key lives, and how it moves between the two places.
 *
 * Writing to wp-config.php is the one genuinely dangerous thing Fili does: get it wrong
 * and the site is gone. So the write is narrow (one line, found by a precise pattern),
 * it is checked for syntax before it lands, and the previous content is held in memory
 * and put back the moment anything fails.
 *
 * No backup file is written. A copy of wp-config.php left in the site folder is
 * downloadable by anyone who guesses the name, credentials and all.
 */
final class Fili_Key {

	private const MARK = 'Fili API key';
	// The whole block, comment and define together, plus the blank line that follows it:
	// without that last bit every add-and-remove would leave an empty line behind and the
	// file would slowly drift away from what it was.
	private const RE = "~^[ \\t]*/\\*[^\\n]*" . self::MARK . "[^\\n]*\\*/[ \\t]*\\R+[ \\t]*define\\(\\s*['\"]FILI_API_KEY['\"]\\s*,.*?\\);[ \\t]*\\R*|^[ \\t]*define\\(\\s*['\"]FILI_API_KEY['\"]\\s*,.*?\\);[ \\t]*\\R*~m";

	public static function in_config(): bool {
		return defined( 'FILI_API_KEY' ) && FILI_API_KEY;
	}

	public static function config_path(): string {
		// the standard layout, plus the one where wp-config sits one level up
		foreach ( array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' ) as $p ) {
			if ( file_exists( $p ) ) {
				return $p;
			}
		}
		return '';
	}

	public static function config_writable(): bool {
		$p = self::config_path();
		return '' !== $p && is_writable( $p );
	}

	/** The line to paste by hand, for whoever prefers to do it themselves. */
	public static function line( string $key ): string {
		return "/* " . self::MARK . " */\ndefine( 'FILI_API_KEY', '" . addcslashes( $key, "\\'" ) . "' );";
	}

	/**
	 * Put a key into wp-config.php, replacing the one that is there.
	 * An empty key removes the line, so the panel takes over again.
	 *
	 * @return true|WP_Error
	 */
	public static function write_config( string $key ) {
		$path = self::config_path();
		if ( '' === $path ) {
			return new WP_Error( 'fili_cfg_missing', __( 'Non trovo wp-config.php.', 'fili' ) );
		}
		if ( ! is_writable( $path ) ) {
			return new WP_Error( 'fili_cfg_ro', __( 'wp-config.php non è scrivibile: il tuo hosting lo protegge. Copia la riga a mano.', 'fili' ) );
		}
		$prima = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $prima || '' === $prima ) {
			return new WP_Error( 'fili_cfg_read', __( 'Non riesco a leggere wp-config.php.', 'fili' ) );
		}

		$dopo = (string) preg_replace( self::RE, '', $prima ); // via the old line, whatever it looked like
		if ( '' !== $key ) {
			$blocco = self::line( $key ) . "\n\n";
			// it has to sit before WordPress boots, or the constant arrives too late
			$pos = false;
			foreach ( array( "/* That's all", '/* That’s all', 'wp-settings.php' ) as $ago ) {
				$pos = strpos( $dopo, $ago );
				if ( false !== $pos ) {
					break;
				}
			}
			if ( false === $pos ) {
				return new WP_Error( 'fili_cfg_spot', __( 'Non trovo il punto giusto in wp-config.php: copia la riga a mano.', 'fili' ) );
			}
			$dopo = substr_replace( $dopo, $blocco, $pos, 0 );
		}

		$errore = self::check( $dopo, $key );
		if ( $errore ) {
			return new WP_Error( 'fili_cfg_bad', $errore );
		}

		// hold the old content in memory: if the write half-succeeds, put it straight back
		if ( false === file_put_contents( $path, $dopo, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $path, $prima, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'fili_cfg_write', __( 'La scrittura non è riuscita: wp-config.php è rimasto com\'era.', 'fili' ) );
		}
		$riletto = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $riletto !== $dopo ) {
			file_put_contents( $path, $prima, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'fili_cfg_verify', __( 'Il file riletto non corrisponde: ho rimesso wp-config.php com\'era.', 'fili' ) );
		}
		return true;
	}

	/**
	 * Is this still valid PHP, and does it still say what it must?
	 * token_get_all with TOKEN_PARSE throws on a syntax error: a real check, in process.
	 *
	 * @return string empty when fine, the reason otherwise
	 */
	private static function check( string $code, string $key ): string {
		if ( ! str_contains( $code, '<?php' ) || ! str_contains( $code, 'DB_NAME' ) ) {
			return __( 'Il file risultante non sembra più wp-config.php: non ho scritto niente.', 'fili' );
		}
		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			return __( 'Il file risultante non sarebbe PHP valido: non ho scritto niente.', 'fili' );
		}
		if ( '' !== $key && ! str_contains( $code, "'FILI_API_KEY'" ) ) {
			return __( 'La riga della chiave non è finita nel file: non ho scritto niente.', 'fili' );
		}
		return '';
	}
}
