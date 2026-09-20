<?php
/**
 * The word lists the code guards run on. They are per language on purpose:
 * an Italian verb list catches nothing on an English site.
 */
final class Fili_Lang {

	/** @var array<string,array<string,array<string,bool>>> */
	private static array $cache = array();

	/** @return array<string,bool> a set, for O(1) lookups */
	public static function set( string $list ): array {
		$lang = fili_settings()['language'];
		if ( ! isset( self::$cache[ $lang ] ) ) {
			$file = FILI_DIR . 'lang/' . preg_replace( '/[^a-z]/', '', $lang ) . '.php';
			$raw  = file_exists( $file ) ? require $file : require FILI_DIR . 'lang/it.php';
			foreach ( $raw as $k => $words ) {
				self::$cache[ $lang ][ $k ] = array_fill_keys( preg_split( '/\s+/', trim( $words ) ), true );
			}
		}
		return self::$cache[ $lang ][ $list ] ?? array();
	}
}
