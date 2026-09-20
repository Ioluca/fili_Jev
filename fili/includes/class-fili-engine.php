<?php
/**
 * The run, cut into slices that fit a shared host: 128 MB and a short request.
 * Every call to step() does a few seconds of work and saves where it stopped, so the
 * run survives a closed tab, a timeout, or a host that kills long requests.
 *
 * Phases: index -> finalize -> propose -> pairs_find -> pairs_judge -> done
 */
final class Fili_Engine {

	private const TIME_BUDGET   = 15;   // seconds of work per step
	private const BATCH_INDEX   = 25;
	private const MAX_TERMS     = 300;  // per post: bounds the index on very long posts
	private const CANDIDATES    = 24;
	private const TARGETS       = 8;
	private const KEEP_FROM     = 0.20; // stored; the review threshold is applied on display
	private const PAIR_DAYS     = 45;
	private const PAIR_MIN_SIM  = 0.16;
	private const MIN_POSTS     = 10;

	/** @return array<string,mixed> */
	public static function state(): array {
		$s = get_option( 'fili_state', array() );
		return is_array( $s ) && isset( $s['phase'] ) ? $s : array( 'phase' => 'idle' );
	}

	/** @param array<string,mixed> $s */
	private static function save( array $s ): array {
		update_option( 'fili_state', $s, false );
		return $s;
	}

	public static function start(): array {
		Fili_DB::reset_index();
		return self::save( array(
			'phase'     => 'index',
			'cursor'    => 0,
			'total'     => self::count_posts(),
			'indexed'   => 0,
			'judged'    => 0,
			'decisions' => 0,
			'pairs'     => 0,
			'started'   => time(),
			'error'     => '',
		) );
	}

	public static function stop(): array {
		$s          = self::state();
		$s['phase'] = 'idle';
		return self::save( $s );
	}

	private static function types_sql(): string {
		$types = array_map( 'sanitize_key', (array) fili_settings()['post_types'] );
		return "'" . implode( "','", $types ?: array( 'post' ) ) . "'";
	}

	private static function count_posts(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN (" . self::types_sql() . ')' ); // phpcs:ignore
	}

	/** One slice of work. @return array<string,mixed> the state after it */
	public static function step(): array {
		$s = self::state();
		if ( in_array( $s['phase'], array( 'idle', 'done' ), true ) ) {
			return $s;
		}
		if ( ( $s['total'] ?? 0 ) < self::MIN_POSTS ) {
			$s['phase'] = 'idle';
			$s['error'] = __( 'Servono almeno 10 articoli pubblicati per trovare dei legami.', 'fili' );
			return self::save( $s );
		}
		// a run reads hundreds of posts: without this the object cache keeps every one of
		// them and a 128 MB host runs out of memory half way
		wp_suspend_cache_addition( true );
		$deadline = microtime( true ) + self::TIME_BUDGET;
		switch ( $s['phase'] ) {
			case 'index':
				$s = self::step_index( $s );
				break;
			case 'finalize':
				$s = self::step_finalize( $s );
				break;
			case 'propose':
				$s = self::step_propose( $s, $deadline );
				break;
			case 'pairs_find':
				$s = self::step_pairs_find( $s, $deadline );
				break;
			case 'pairs_judge':
				$s = self::step_pairs_judge( $s, $deadline );
				break;
		}
		return self::save( $s );
	}

	/* ---------------------------------------------------------------- index */

	private static function step_index( array $s ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN (" . self::types_sql() . ') AND ID > %d ORDER BY ID LIMIT %d', // phpcs:ignore
			$s['cursor'], self::BATCH_INDEX
		) );
		if ( ! $ids ) {
			$s['phase'] = 'finalize';
			return $s;
		}
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post ) {
				continue;
			}
			$masked = Fili_Text::mask( $post->post_content );
			$counts = array_count_values( Fili_Text::words( $post->post_title . ' ' . $masked ) );
			arsort( $counts );
			$rows = array();
			foreach ( array_slice( $counts, 0, self::MAX_TERMS, true ) as $term => $c ) {
				$term = (string) $term;
				if ( strlen( $term ) <= 48 ) {
					$rows[] = $wpdb->prepare( '(%s,%d,%f)', $term, $id, 1 + log( $c ) );
				}
			}
			if ( $rows ) {
				$wpdb->query( 'INSERT IGNORE INTO ' . Fili_DB::t( 'terms' ) . ' (term,post_id,w) VALUES ' . implode( ',', $rows ) ); // phpcs:ignore
			}
			$hashes = array();
			foreach ( Fili_Text::sentences( $masked ) as $sentence ) {
				if ( str_word_count( $sentence ) >= 4 || mb_strlen( trim( $sentence ) ) > 30 ) {
					$hashes[ Fili_Text::sentence_hash( $sentence ) ] = true;
				}
			}
			if ( $hashes ) {
				$vals = implode( ',', array_map( static fn( $h ) => "('" . esc_sql( $h ) . "',1)", array_keys( $hashes ) ) );
				$wpdb->query( 'INSERT INTO ' . Fili_DB::t( 'sentences' ) . " (h,n) VALUES $vals ON DUPLICATE KEY UPDATE n=n+1" ); // phpcs:ignore
			}
			$wpdb->replace( Fili_DB::t( 'docs' ), array( 'post_id' => $id, 'content_hash' => md5( $post->post_content ) ) );
			$s['cursor'] = (int) $id;
			$s['indexed']++;
			unset( $post, $masked, $counts, $rows, $hashes );
		}
		return $s;
	}

	private static function step_finalize( array $s ): array {
		global $wpdb;
		$n = max( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Fili_DB::t( 'docs' ) ) ); // phpcs:ignore
		$wpdb->query( 'TRUNCATE TABLE ' . Fili_DB::t( 'df' ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . Fili_DB::t( 'df' ) . ' (term,df,idf) SELECT term, COUNT(*), GREATEST(0, LN(%d/(1+COUNT(*)))) FROM ' . Fili_DB::t( 'terms' ) . ' GROUP BY term', // phpcs:ignore
			$n
		) );
		$wpdb->query(
			'UPDATE ' . Fili_DB::t( 'docs' ) . ' d JOIN (SELECT t.post_id, SQRT(SUM(POW(t.w*f.idf,2))) n FROM ' . Fili_DB::t( 'terms' ) . ' t JOIN ' . Fili_DB::t( 'df' ) . ' f ON f.term=t.term GROUP BY t.post_id) x ON x.post_id=d.post_id SET d.norm=x.n' // phpcs:ignore
		);
		// sentences that only one post has tell nothing about boilerplate: drop them
		$wpdb->query( 'DELETE FROM ' . Fili_DB::t( 'sentences' ) . ' WHERE n < 5' ); // phpcs:ignore
		$s['phase']  = 'propose';
		$s['cursor'] = 0;
		$s['docs']   = $n;
		return $s;
	}

	/* -------------------------------------------------------------- propose */

	/** @return array<int,array{post_id:int,sim:float}> */
	private static function similar( int $post_id, int $limit, string $extra_join = '', string $having = '' ): array {
		global $wpdb;
		$docs = Fili_DB::t( 'docs' );
		$norm = (float) $wpdb->get_var( $wpdb->prepare( "SELECT norm FROM $docs WHERE post_id=%d", $post_id ) ); // phpcs:ignore
		if ( $norm <= 0 ) {
			return array();
		}
		$n   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $docs" ); // phpcs:ignore
		$sql = 'SELECT t2.post_id, SUM(t1.w*f.idf*t2.w*f.idf)/(%f*d2.norm) sim FROM ' . Fili_DB::t( 'terms' ) . ' t1'
			. ' JOIN ' . Fili_DB::t( 'df' ) . ' f ON f.term=t1.term AND f.idf>0 AND f.df<=%d'
			. ' JOIN ' . Fili_DB::t( 'terms' ) . ' t2 ON t2.term=t1.term AND t2.post_id<>t1.post_id'
			. " JOIN $docs d2 ON d2.post_id=t2.post_id AND d2.norm>0 $extra_join"
			. " WHERE t1.post_id=%d GROUP BY t2.post_id $having ORDER BY sim DESC LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $norm, max( 5, (int) ( $n * 0.3 ) ), $post_id, $limit ), ARRAY_A ) ?: array(); // phpcs:ignore
	}

	/** @return array<string,bool> */
	private static function furniture(): array {
		global $wpdb;
		$n    = max( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Fili_DB::t( 'docs' ) ) ); // phpcs:ignore
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT h FROM ' . Fili_DB::t( 'sentences' ) . ' WHERE n > %d', max( 10, (int) ( $n * 0.12 ) ) ) ); // phpcs:ignore
		return array_fill_keys( $rows, true );
	}

	private static function step_propose( array $s, float $deadline ): array {
		global $wpdb;
		$rubric    = require FILI_DIR . 'includes/rubric.php';
		$themes    = trim( (string) fili_settings()['themes'] );
		$anchor_q  = str_replace( '{themes}', $themes ? ': on this site those are ' . $themes : '', $rubric['anchor'] );
		$furniture = self::furniture();

		while ( microtime( true ) < $deadline ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM ' . Fili_DB::t( 'docs' ) . ' WHERE proposed=0 AND post_id>%d ORDER BY post_id LIMIT 1', $s['cursor'] ) ); // phpcs:ignore
			if ( ! $id ) {
				$s['phase']  = 'pairs_find';
				$s['cursor'] = 0;
				return $s;
			}
			$s['cursor'] = $id;
			$post        = get_post( $id );
			$masked      = $post ? Fili_Text::mask( $post->post_content ) : '';
			$targets     = array();

			foreach ( self::similar( $id, self::CANDIDATES ) as $row ) {
				$t = get_post( (int) $row['post_id'] );
				if ( ! $t || Fili_Text::title_overlap( $post->post_title, $t->post_title ) >= 0.8 ) {
					continue; // the same piece twice is for the duplicates report, never a link
				}
				if ( str_contains( $post->post_content, '/' . $t->post_name . '/' ) || str_contains( $post->post_content, '?p=' . $t->ID ) ) {
					continue; // already linked
				}
				$keys = array_unique( Fili_Text::words( $t->post_title ) );
				if ( ! $keys ) {
					continue;
				}
				$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
				$idf  = $wpdb->get_results( $wpdb->prepare( 'SELECT term, idf FROM ' . Fili_DB::t( 'df' ) . " WHERE term IN ($in)", $keys ), OBJECT_K ); // phpcs:ignore
				$idf  = array_map( static fn( $r ) => (float) $r->idf, $idf );
				$anch = Fili_Text::anchors( $masked, $keys, $idf, $furniture );
				if ( $anch ) {
					$targets[] = array( 'post' => $t, 'anchors' => $anch );
				}
				if ( count( $targets ) >= self::TARGETS ) {
					break;
				}
			}

			if ( $targets ) {
				$questions = array();
				$state     = array(
					'source_title' => $post->post_title,
					'source_text'  => mb_substr( $post->post_content, 0, 6000 ),
					'targets'      => array(),
				);
				foreach ( $targets as $i => $t ) {
					$state['targets'][] = array( 'n' => $i, 'title' => $t['post']->post_title );
					$criteria           = array();
					foreach ( $t['anchors'] as $j => $a ) {
						$criteria[ "a$j" ] = $a;
					}
					$criteria['none']                            = 'No candidate phrase names the specific subject of this target.';
					$questions[ sprintf( 'link_%02d', $i ) ]   = array( 'type' => 'noul', 'instructions' => str_replace( '{n}', (string) $i, $rubric['link'] ) );
					$questions[ sprintf( 'anchor_%02d', $i ) ] = array( 'type' => 'choice', 'instructions' => str_replace( '{n}', (string) $i, $anchor_q ), 'criteria' => $criteria );
				}
				$answers = Fili_Jev::ask( $state, $questions );
				if ( is_wp_error( $answers ) ) {
					$s['phase'] = 'idle';
					$s['error'] = $answers->get_error_message();
					return $s;
				}
				$s['decisions'] += count( $questions );
				foreach ( $targets as $i => $t ) {
					$score  = (float) ( $answers[ sprintf( 'link_%02d', $i ) ]['noul'] ?? 0 );
					$choice = (string) ( $answers[ sprintf( 'anchor_%02d', $i ) ]['choice'] ?? 'none' );
					if ( $score < self::KEEP_FROM || ! preg_match( '/^a(\d+)$/', $choice, $m ) || ! isset( $t['anchors'][ (int) $m[1] ] ) ) {
						continue;
					}
					$anchor = $t['anchors'][ (int) $m[1] ];
					if ( null === Fili_Text::locate( $post->post_content, $anchor ) ) {
						continue;
					}
					$wpdb->query( $wpdb->prepare(
						'INSERT IGNORE INTO ' . Fili_DB::t( 'proposals' ) . ' (source_id,target_id,anchor,context,score,confidence,status,created) VALUES (%d,%d,%s,%s,%f,%f,%s,%s)', // phpcs:ignore
						$id, $t['post']->ID, $anchor, Fili_Text::context( $post->post_content, $anchor ), $score,
						(float) ( $answers[ sprintf( 'anchor_%02d', $i ) ]['confidence'] ?? 0 ), 'proposed', current_time( 'mysql' )
					) );
				}
			}
			$wpdb->update( Fili_DB::t( 'docs' ), array( 'proposed' => 1 ), array( 'post_id' => $id ) );
			$s['judged']++;
			unset( $post, $masked, $targets );
		}
		return $s;
	}

	/* ---------------------------------------------------------------- pairs */

	private static function step_pairs_find( array $s, float $deadline ): array {
		global $wpdb;
		while ( microtime( true ) < $deadline ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM ' . Fili_DB::t( 'docs' ) . ' WHERE paired=0 AND post_id>%d ORDER BY post_id LIMIT 1', $s['cursor'] ) ); // phpcs:ignore
			if ( ! $id ) {
				$s['phase']  = 'pairs_judge';
				$s['cursor'] = 0;
				return $s;
			}
			$s['cursor'] = $id;
			$date        = get_post_field( 'post_date', $id );
			$join        = $wpdb->prepare( "JOIN {$wpdb->posts} p2 ON p2.ID=t2.post_id AND p2.post_date > %s AND p2.post_date <= DATE_ADD(%s, INTERVAL %d DAY)", $date, $date, self::PAIR_DAYS ); // phpcs:ignore
			foreach ( self::similar( $id, 12, $join, $wpdb->prepare( 'HAVING sim >= %f', self::PAIR_MIN_SIM ) ) as $row ) {
				$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . Fili_DB::t( 'pairs' ) . ' (a_id,b_id,similarity) VALUES (%d,%d,%f)', $id, $row['post_id'], $row['sim'] ) ); // phpcs:ignore
				$s['pairs']++;
			}
			$wpdb->update( Fili_DB::t( 'docs' ), array( 'paired' => 1 ), array( 'post_id' => $id ) );
		}
		return $s;
	}

	private static function step_pairs_judge( array $s, float $deadline ): array {
		global $wpdb;
		$rubric    = require FILI_DIR . 'includes/rubric.php';
		$questions = array();
		foreach ( $rubric['pairs'] as $k => $text ) {
			$questions[ $k ] = array( 'type' => 'noul', 'instructions' => $text );
		}
		while ( microtime( true ) < $deadline ) {
			$pair = $wpdb->get_row( 'SELECT a_id,b_id FROM ' . Fili_DB::t( 'pairs' ) . ' WHERE same_news IS NULL LIMIT 1', ARRAY_A ); // phpcs:ignore
			if ( ! $pair ) {
				$s['phase']    = 'done';
				$s['finished'] = time();
				return $s;
			}
			$a = get_post( (int) $pair['a_id'] );
			$b = get_post( (int) $pair['b_id'] );
			$v = array( 'same_news' => 0, 'same_content' => 0, 'later_event' => 0 );
			if ( $a && $b ) {
				$text    = static fn( $p ) => mb_substr( trim( preg_replace( '~\s+~u', ' ', Fili_Text::mask( $p->post_content ) ) ), 0, 3500 );
				$answers = Fili_Jev::ask( array(
					'first'  => array( 'title' => $a->post_title, 'date' => substr( $a->post_date, 0, 10 ), 'text' => $text( $a ) ),
					'second' => array( 'title' => $b->post_title, 'date' => substr( $b->post_date, 0, 10 ), 'text' => $text( $b ) ),
				), $questions );
				if ( is_wp_error( $answers ) ) {
					$s['phase'] = 'idle';
					$s['error'] = $answers->get_error_message();
					return $s;
				}
				foreach ( $v as $k => $_ ) {
					$v[ $k ] = (float) ( $answers[ $k ]['noul'] ?? 0 );
				}
				$s['decisions'] += 3;
			}
			$wpdb->update( Fili_DB::t( 'pairs' ), $v, array( 'a_id' => $pair['a_id'], 'b_id' => $pair['b_id'] ) );
		}
		return $s;
	}

	/**
	 * Groups of posts that tell the same news again. A legitimate follow-up
	 * (something that happened later) is kept out.
	 *
	 * @return array<int,int[]> groups of post ids, biggest first
	 */
	public static function duplicate_groups(): array {
		global $wpdb;
		$rows   = $wpdb->get_results( 'SELECT a_id,b_id FROM ' . Fili_DB::t( 'pairs' ) . ' WHERE same_news >= 0.85 AND later_event < 0.5', ARRAY_A ); // phpcs:ignore
		$parent = array();
		$find   = static function ( $x ) use ( &$parent, &$find ) {
			$parent[ $x ] ??= $x;
			return $parent[ $x ] === $x ? $x : ( $parent[ $x ] = $find( $parent[ $x ] ) );
		};
		foreach ( $rows as $r ) {
			$parent[ $find( (int) $r['a_id'] ) ] = $find( (int) $r['b_id'] );
		}
		$groups = array();
		foreach ( array_keys( $parent ) as $id ) {
			$groups[ $find( $id ) ][] = $id;
		}
		usort( $groups, static fn( $x, $y ) => count( $y ) <=> count( $x ) );
		return $groups;
	}
}
