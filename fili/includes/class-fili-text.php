<?php
/**
 * Everything Fili knows about text, and nothing about WordPress or the network.
 *
 * The central promise is here: an anchor is a run of words that already exists in the
 * post, outside every region a link must never enter. Offsets are byte offsets into the
 * raw post_content, so what is judged is exactly what would be edited.
 */
final class Fili_Text {

	/** Regions a link must never enter. */
	private const PROTECTED_RE = '~<a\b[^>]*>.*?</a>|<(h[1-6])\b[^>]*>.*?</\1>|<blockquote\b[^>]*>.*?</blockquote>|<(pre|code|script|style)\b[^>]*>.*?</\2>|<figcaption\b[^>]*>.*?</figcaption>|<!--.*?-->|\[[^\]\n]{1,120}\]|<[^>]+>~is';

	private const MIN_WORDS = 2;
	private const MAX_WORDS = 6;

	/** Same byte length as the input, every protected byte turned into a space. */
	public static function mask( string $html ): string {
		if ( ! preg_match_all( self::PROTECTED_RE, $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		foreach ( $m[0] as [ $found, $at ] ) {
			$len  = strlen( $found );
			$html = substr_replace( $html, str_repeat( ' ', $len ), $at, $len );
		}
		return $html;
	}

	/** @return string[] lowercase content words */
	public static function words( string $text ): array {
		$stop = Fili_Lang::set( 'stop' );
		preg_match_all( '~[\p{L}\p{N}][\p{L}\p{N}\-]{2,}~u', mb_strtolower( $text ), $m );
		return array_values( array_filter( $m[0], static fn( $w ) => ! isset( $stop[ $w ] ) ) );
	}

	/** @return string[] sentences of the masked text */
	public static function sentences( string $masked ): array {
		return preg_split( '~[.!?;:\n]+~u', $masked, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	}

	public static function sentence_hash( string $sentence ): string {
		return substr( md5( mb_strtolower( preg_replace( '~\s+~u', ' ', trim( $sentence ) ) ) ), 0, 16 );
	}

	/**
	 * Phrases of the source that could carry a link to a target, best first.
	 *
	 * @param string              $masked    masked source content
	 * @param string[]            $key_words content words of the target title
	 * @param array<string,float> $idf       idf of those words
	 * @param array<string,bool>  $furniture hashes of sentences that are site boilerplate
	 * @return string[]
	 */
	public static function anchors( string $masked, array $key_words, array $idf, array $furniture, int $limit = 6 ): array {
		$keys  = array_fill_keys( $key_words, true );
		$edge  = Fili_Lang::set( 'edge' );
		$stop  = Fili_Lang::set( 'stop' );
		$found = array();

		foreach ( self::sentences( $masked ) as $sentence ) {
			if ( isset( $furniture[ self::sentence_hash( $sentence ) ] ) ) {
				continue;
			}
			if ( ! preg_match_all( '~[\p{L}\p{N}][\p{L}\p{N}\'’\-]*~u', $sentence, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$tok = $m[0];
			$n   = count( $tok );
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $len = self::MIN_WORDS; $len <= self::MAX_WORDS && $i + $len <= $n; $len++ ) {
					$first = $tok[ $i ];
					$last  = $tok[ $i + $len - 1 ];
					$text  = substr( $sentence, $first[1], $last[1] + strlen( $last[0] ) - $first[1] );
					// words joined by exactly one space: anything else crosses a tag, a comma
					// or a masked region, and would not be a clean run of visible words
					if ( strlen( $text ) < 6 || strlen( $text ) > 80 || preg_match( '~[^\p{L}\p{N}\'’\- ]|  ~u', $text ) ) {
						continue;
					}
					// a phrase that opens or closes on a function word ('questo stile', 'sia l'ultima')
					// is a slice of a sentence, not a name
					$f = mb_strtolower( $first[0] );
					$l = mb_strtolower( $last[0] );
					if ( isset( $edge[ $f ] ) || isset( $edge[ $l ] ) || isset( $stop[ $f ] ) || isset( $stop[ $l ] ) ) {
						continue;
					}
					$common = array();
					for ( $k = $i; $k < $i + $len; $k++ ) {
						$w = mb_strtolower( $tok[ $k ][0] );
						if ( isset( $keys[ $w ] ) ) {
							$common[ $w ] = true;
						}
					}
					if ( ! $common ) {
						continue;
					}
					$score = 0.0;
					foreach ( array_keys( $common ) as $w ) {
						$score += $idf[ $w ] ?? 0.0;
					}
					$score *= 1 + 0.15 * count( $common );
					if ( $score > ( $found[ $text ] ?? 0 ) ) {
						$found[ $text ] = $score;
					}
				}
			}
		}

		arsort( $found );
		$kept = array();
		foreach ( array_keys( $found ) as $text ) {
			if ( self::malformed( $text ) ) {
				continue;
			}
			foreach ( $kept as $k ) {
				if ( str_contains( $k, $text ) ) {
					continue 2;
				}
			}
			$kept[] = $text;
			if ( count( $kept ) >= $limit ) {
				break;
			}
		}
		return $kept;
	}

	/**
	 * The guards a machine checks better than a model. Returns the reason, or null when fine.
	 * An anchor names a thing: it does not open like a question, carries no finite verb,
	 * and is not a clause that starts on a participle followed by an article.
	 */
	public static function malformed( string $anchor ): ?string {
		$parts = preg_split( '~\s+~u', trim( $anchor ) );
		$bits  = array();
		foreach ( $parts as $w ) {
			$w = mb_strtolower( self::utrim( $w, '.,:;!?"«»' ) );
			if ( "e'" === $w ) {
				return 'verb';
			}
			$bits[] = self::utrim( $w, "'’" );
			if ( preg_match( "~['’]~u", $w ) ) {
				foreach ( preg_split( "~['’]~u", $w, -1, PREG_SPLIT_NO_EMPTY ) as $piece ) {
					$bits[] = $piece;
				}
			}
		}
		$first = mb_strtolower( self::utrim( $parts[0], "'’" ) );
		$last  = mb_strtolower( self::utrim( end( $parts ), "'’" ) );
		if ( isset( Fili_Lang::set( 'interrogative' )[ $first ] ) ) {
			return 'question';
		}
		$edge = Fili_Lang::set( 'edge' );
		if ( isset( $edge[ $first ] ) || isset( $edge[ $last ] ) ) {
			return 'fragment';
		}
		$verbs = Fili_Lang::set( 'verb' );
		foreach ( $bits as $b ) {
			if ( isset( $verbs[ $b ] ) ) {
				return 'verb';
			}
		}
		if ( count( $parts ) > 1 ) {
			$second = mb_strtolower( self::utrim( $parts[1], "'’" ) );
			foreach ( array_keys( Fili_Lang::set( 'participle' ) ) as $suffix ) {
				if ( '' !== $suffix && str_ends_with( $first, $suffix ) && isset( Fili_Lang::set( 'article' )[ $second ] ) ) {
					return 'clause';
				}
			}
		}
		return null;
	}

	/** trim() for a list that holds multibyte characters. */
	private static function utrim( string $w, string $chars ): string {
		$c = preg_quote( $chars, '~' );
		return (string) preg_replace( "~^[$c]+|[$c]+$~u", '', $w );
	}

	/** The sentence around an anchor, for the review page. Plain text. */
	public static function context( string $content, string $anchor ): string {
		$plain = trim( preg_replace( '~\s+~u', ' ', wp_strip_all_tags( $content ) ) );
		$at    = mb_strpos( $plain, $anchor );
		if ( false === $at ) {
			return '';
		}
		$from = max( 0, $at - 130 );
		$to   = min( mb_strlen( $plain ), $at + mb_strlen( $anchor ) + 130 );
		return ( $from ? '…' : '' ) . mb_substr( $plain, $from, $to - $from ) . ( $to < mb_strlen( $plain ) ? '…' : '' );
	}

	/** Byte offset of the first occurrence of the anchor in linkable text, or null. */
	public static function locate( string $content, string $anchor ): ?int {
		$at = strpos( self::mask( $content ), $anchor );
		// the mask keeps byte length, so the offset is valid in the raw content; the check
		// below makes that an asserted fact instead of an assumption
		return ( false !== $at && substr( $content, $at, strlen( $anchor ) ) === $anchor ) ? $at : null;
	}

	/** Title words in common over title words in total: near 1 means the same piece twice. */
	public static function title_overlap( string $a, string $b ): float {
		$x = array_unique( array_filter( self::words( $a ), static fn( $w ) => mb_strlen( $w ) > 3 ) );
		$y = array_unique( array_filter( self::words( $b ), static fn( $w ) => mb_strlen( $w ) > 3 ) );
		$u = count( array_unique( array_merge( $x, $y ) ) );
		return $u ? count( array_intersect( $x, $y ) ) / $u : 0.0;
	}
}
