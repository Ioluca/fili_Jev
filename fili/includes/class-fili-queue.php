<?php
/**
 * Applying the approved links a few at a time, over hours or days.
 *
 * Three reasons for the slow drip, in order of weight:
 *
 * 1. If something is wrong with the links, it shows up on ten posts, not on two hundred.
 *    This is the one that matters, and it is ours, not Google's.
 * 2. Adding links to a page is a significant update by Google's own definition, so the
 *    modified date moves; spread over days, the sitemap shows a site being tended rather
 *    than two hundred pages changed in one minute.
 * 3. Shared hosting. The write itself is small, but there is no reason to bunch it up.
 *
 * What is NOT a reason: Google does not penalise internal links on your own site, and
 * nobody should be told it does.
 *
 * WP-Cron only wakes up when somebody visits the site. On a quiet site the queue drifts
 * later than the interval says; it never applies more than a batch per wake-up.
 */
final class Fili_Queue {

	private const HOOK = 'fili_apply_batch';

	public static function scheduled(): bool {
		return (bool) wp_next_scheduled( self::HOOK );
	}

	public static function next_run(): int {
		return (int) wp_next_scheduled( self::HOOK );
	}

	/** @return true|WP_Error */
	public static function start() {
		if ( ! empty( fili_settings()['read_only'] ) ) {
			return new WP_Error( 'fili_read_only', __( 'Fili is in propose-only mode: turn off the safety catch in the settings to apply links.', 'fili' ) );
		}
		if ( ! self::pending() ) {
			return new WP_Error( 'fili_empty', __( 'There is nothing to apply: keep a few proposals first.', 'fili' ) );
		}
		self::stop();
		wp_schedule_event( time() + 60, 'fili_interval', self::HOOK );
		update_option( 'fili_queue_log', array(), false );
		return true;
	}

	public static function stop(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Fili_DB::t( 'proposals' ) . " WHERE status='approved'" ); // phpcs:ignore
	}

	/** How long the queue needs, in seconds, at the current settings. */
	public static function eta(): int {
		$s = fili_settings();
		return (int) ceil( self::pending() / max( 1, (int) $s['batch_size'] ) ) * max( 5, (int) $s['batch_minutes'] ) * 60;
	}

	/** One wake-up: a handful of links, then back to sleep. */
	public static function run(): void {
		global $wpdb;
		$s = fili_settings();
		if ( ! empty( $s['read_only'] ) ) {
			self::stop();
			self::note( __( 'Queue stopped: the safety catch is back on.', 'fili' ) );
			return;
		}
		$size = max( 1, min( 50, (int) $s['batch_size'] ) );
		$ids  = $wpdb->get_col( $wpdb->prepare(
			// oldest posts first: the archive that nobody reaches gets its links before the
			// recent pieces, which already have readers
			'SELECT p.id FROM ' . Fili_DB::t( 'proposals' ) . " p JOIN {$wpdb->posts} w ON w.ID=p.source_id" // phpcs:ignore
			. " WHERE p.status='approved' ORDER BY w.post_date ASC, p.score DESC LIMIT %d",
			$size
		) );
		if ( ! $ids ) {
			self::stop();
			self::note( __( 'Queue finished: nothing left to apply.', 'fili' ) );
			return;
		}
		$fatti = 0;
		$falliti = array();
		foreach ( $ids as $id ) {
			$r = Fili_Apply::apply( (int) $id );
			if ( true === $r ) {
				$fatti++;
				continue;
			}
			$falliti[] = $r->get_error_code();
			// a proposal that cannot be applied goes back to the review list instead of
			// blocking the queue for ever
			$wpdb->update( Fili_DB::t( 'proposals' ), array( 'status' => 'proposed' ), array( 'id' => $id ) );
		}
		self::note( sprintf(
			/* translators: 1: links applied, 2: how many went back to review, 3: how many are left */
			__( 'Applied %1$d links, sent %2$d back for review, %3$d left.', 'fili' ),
			$fatti, count( $falliti ), self::pending()
		) );
		if ( ! self::pending() ) {
			self::stop();
		}
	}

	/** @return array<int,array{t:int,m:string}> newest first */
	public static function log(): array {
		$l = get_option( 'fili_queue_log', array() );
		return is_array( $l ) ? $l : array();
	}

	private static function note( string $message ): void {
		$l = self::log();
		array_unshift( $l, array( 't' => time(), 'm' => $message ) );
		update_option( 'fili_queue_log', array_slice( $l, 0, 30 ), false );
	}

	/** The interval is a setting, so it has to be declared to WordPress at runtime. */
	public static function schedules( array $schedules ): array {
		$m = max( 5, (int) fili_settings()['batch_minutes'] );
		$schedules['fili_interval'] = array(
			'interval' => $m * 60,
			'display'  => sprintf( __( 'Every %d minutes (Fili)', 'fili' ), $m ),
		);
		return $schedules;
	}
}

add_filter( 'cron_schedules', array( 'Fili_Queue', 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
