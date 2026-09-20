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
			'spend_at_start' => Fili_Jev::month_spend(),
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
		// One worker at a time. add_option() is atomic: it fails when the row exists, so an
		// admin tab and a wp-cli run can never advance the same cursor together. A lock older
		// than 90 seconds belongs to a request that died, and is taken over.
		$lock = (int) get_option( 'fili_lock', 0 );
		if ( $lock && time() - $lock < 90 ) {
			$s['busy'] = true;
			return $s;
		}
		delete_option( 'fili_lock' );
		if ( ! add_option( 'fili_lock', time(), '', 'no' ) ) {
			$s['busy'] = true;
			return $s;
		}
		try {
			return self::work( $s );
		} finally {
			delete_option( 'fili_lock' );
		}
	}

	/** @param array<string,mixed> $s */
	private static function work( array $s ): array {
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

	/**
	 * Everything local about one source: who it could link to and with which phrases.
	 * No network here, so it can be prepared for several sources before asking Jev once.
	 *
	 * @return array{post:WP_Post,targets:array,job:?array}|null
	 */
	private static function prepare_source( int $id, array $furniture, array $rubric, string $anchor_q ): ?array {
		global $wpdb;
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$masked  = Fili_Text::mask( $post->post_content );
		$targets = array();
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
		if ( ! $targets ) {
			return array( 'post' => $post, 'targets' => array(), 'job' => null );
		}
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
		return array( 'post' => $post, 'targets' => $targets, 'job' => array( 'state' => $state, 'questions' => $questions ) );
	}

	private static function store_proposals( WP_Post $post, array $targets, array $answers ): void {
		global $wpdb;
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
				$post->ID, $t['post']->ID, $anchor, Fili_Text::context( $post->post_content, $anchor ), $score,
				(float) ( $answers[ sprintf( 'anchor_%02d', $i ) ]['confidence'] ?? 0 ), 'proposed', current_time( 'mysql' )
			) );
		}
	}

	private static function step_propose( array $s, float $deadline ): array {
		global $wpdb;
		$rubric    = require FILI_DIR . 'includes/rubric.php';
		$themes    = trim( (string) fili_settings()['themes'] );
		$anchor_q  = str_replace( '{themes}', $themes ? ': on this site those are ' . $themes : '', $rubric['anchor'] );
		$furniture = self::furniture();
		$parallel  = max( 1, min( 8, (int) fili_settings()['parallel'] ) );

		while ( microtime( true ) < $deadline ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM ' . Fili_DB::t( 'docs' ) . ' WHERE proposed=0 ORDER BY post_id LIMIT %d', $parallel ) ); // phpcs:ignore
			if ( ! $ids ) {
				$s['phase']  = 'pairs_find';
				$s['cursor'] = 0;
				return $s;
			}
			$prepared = array();
			$jobs     = array();
			foreach ( $ids as $id ) {
				$p = self::prepare_source( (int) $id, $furniture, $rubric, $anchor_q );
				if ( $p && $p['job'] ) {
					$prepared[ $id ] = $p;
					$jobs[ $id ]     = $p['job'];
				} else {
					$wpdb->update( Fili_DB::t( 'docs' ), array( 'proposed' => 1 ), array( 'post_id' => $id ) );
					$s['judged']++;
				}
			}
			$busy = 0;
			foreach ( Fili_Jev::ask_many( $jobs ) as $id => $answers ) {
				if ( is_wp_error( $answers ) ) {
					if ( 'fili_retry' === $answers->get_error_code() || 'fili_net' === $answers->get_error_code() ) {
						$busy++; // left unproposed: the next slice picks it up again
						continue;
					}
					$s['phase'] = 'idle';
					$s['error'] = $answers->get_error_message();
					return $s;
				}
				self::store_proposals( $prepared[ $id ]['post'], $prepared[ $id ]['targets'], $answers );
				$wpdb->update( Fili_DB::t( 'docs' ), array( 'proposed' => 1 ), array( 'post_id' => $id ) );
				$s['decisions'] += count( $jobs[ $id ]['questions'] );
				$s['judged']++;
			}
			if ( $busy ) {
				sleep( min( 8, 2 * $busy ) ); // the service pushed back: breathe before the next slice
			}
			unset( $prepared, $jobs );
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
		$parallel = max( 1, min( 8, (int) fili_settings()['parallel'] ) );
		$text     = static fn( $p ) => mb_substr( trim( preg_replace( '~\s+~u', ' ', Fili_Text::mask( $p->post_content ) ) ), 0, 3500 );

		while ( microtime( true ) < $deadline ) {
			$pairs = $wpdb->get_results( $wpdb->prepare( 'SELECT a_id,b_id FROM ' . Fili_DB::t( 'pairs' ) . ' WHERE same_news IS NULL LIMIT %d', $parallel ), ARRAY_A ); // phpcs:ignore
			if ( ! $pairs ) {
				$s['phase']    = 'done';
				$s['finished'] = time();
				return $s;
			}
			$jobs = array();
			foreach ( $pairs as $i => $pair ) {
				$a = get_post( (int) $pair['a_id'] );
				$b = get_post( (int) $pair['b_id'] );
				if ( ! $a || ! $b ) {
					$wpdb->update( Fili_DB::t( 'pairs' ), array( 'same_news' => 0, 'same_content' => 0, 'later_event' => 0 ), $pair );
					continue;
				}
				$jobs[ $i ] = array(
					'state'     => array(
						'first'  => array( 'title' => $a->post_title, 'date' => substr( $a->post_date, 0, 10 ), 'text' => $text( $a ) ),
						'second' => array( 'title' => $b->post_title, 'date' => substr( $b->post_date, 0, 10 ), 'text' => $text( $b ) ),
					),
					'questions' => $questions,
				);
			}
			$busy = 0;
			foreach ( Fili_Jev::ask_many( $jobs ) as $i => $answers ) {
				if ( is_wp_error( $answers ) ) {
					if ( in_array( $answers->get_error_code(), array( 'fili_retry', 'fili_net' ), true ) ) {
						$busy++;
						continue;
					}
					$s['phase'] = 'idle';
					$s['error'] = $answers->get_error_message();
					return $s;
				}
				$wpdb->update( Fili_DB::t( 'pairs' ), array(
					'same_news'    => (float) ( $answers['same_news']['noul'] ?? 0 ),
					'same_content' => (float) ( $answers['same_content']['noul'] ?? 0 ),
					'later_event'  => (float) ( $answers['later_event']['noul'] ?? 0 ),
				), $pairs[ $i ] );
				$s['decisions'] += 3;
			}
			if ( $busy ) {
				sleep( min( 8, 2 * $busy ) );
			}
		}
		return $s;
	}

	/**
	 * Re-run the code guards on proposals still waiting for review. The guards get better
	 * between versions, and a proposal made under older rules should not outlive them.
	 * Costs nothing: no network, a few hundred short strings.
	 */
	public static function refilter(): void {
		global $wpdb;
		$rev = FILI_VERSION . ':' . md5_file( FILI_DIR . 'lang/' . fili_settings()['language'] . '.php' );
		if ( get_option( 'fili_guard_rev' ) === $rev ) {
			return;
		}
		foreach ( $wpdb->get_results( 'SELECT id, anchor FROM ' . Fili_DB::t( 'proposals' ) . " WHERE status='proposed'" ) as $p ) { // phpcs:ignore
			if ( null !== Fili_Text::malformed( $p->anchor ) ) {
				$wpdb->update( Fili_DB::t( 'proposals' ), array( 'status' => 'filtered' ), array( 'id' => $p->id ) );
			}
		}
		update_option( 'fili_guard_rev', $rev, false );
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
