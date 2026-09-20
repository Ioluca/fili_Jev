<?php
/**
 * wp fili ...  The same engine as the admin page, for testing over SSH.
 */
final class Fili_CLI {

	/** Run the whole analysis to the end. Proposals only: no post is touched. */
	public function run(): void {
		$s = Fili_Engine::start();
		WP_CLI::log( sprintf( '%d articoli da leggere', $s['total'] ) );
		$last = '';
		while ( ! in_array( $s['phase'], array( 'idle', 'done' ), true ) ) {
			$s = Fili_Engine::step();
			if ( $s['phase'] !== $last ) {
				WP_CLI::log( sprintf( '[%s] letti %d, giudicati %d, decisioni %d, memoria %d MB', $s['phase'], $s['indexed'], $s['judged'], $s['decisions'], memory_get_peak_usage( true ) / 1048576 ) );
				$last = $s['phase'];
			}
		}
		if ( ! empty( $s['error'] ) ) {
			WP_CLI::error( $s['error'] );
		}
		WP_CLI::success( sprintf( 'Finito in %d s. Spesa stimata del mese: $%.4f', time() - $s['started'], Fili_Jev::month_spend() ) );
	}

	/** Show where things stand. */
	public function status(): void {
		global $wpdb;
		WP_CLI::log( wp_json_encode( Fili_Engine::state() ) );
		foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . Fili_DB::t( 'proposals' ) . ' GROUP BY status' ) as $r ) { // phpcs:ignore
			WP_CLI::log( sprintf( '  %-10s %d', $r->status, $r->n ) );
		}
		WP_CLI::log( sprintf( '  gruppi di doppioni: %d', count( Fili_Engine::duplicate_groups() ) ) );
	}

	/**
	 * The test that matters: apply every proposal of the given posts, undo them all,
	 * and prove each post came back byte for byte.
	 *
	 * ## OPTIONS
	 * <ids>...
	 * : ids of the source posts to test on
	 */
	public function roundtrip( array $ids ): void {
		global $wpdb;
		$settings = fili_settings();
		if ( ! empty( $settings['read_only'] ) ) {
			WP_CLI::error( 'Fili è in sola proposta. Togli la sicura nelle impostazioni prima di questa prova.' );
		}
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$before = md5( (string) get_post_field( 'post_content', $id, 'raw' ) );
			$props  = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Fili_DB::t( 'proposals' ) . " WHERE source_id=%d AND status IN ('proposed','approved') ORDER BY score DESC", $id ) ); // phpcs:ignore
			$done   = array();
			foreach ( $props as $pid ) {
				$wpdb->update( Fili_DB::t( 'proposals' ), array( 'status' => 'approved' ), array( 'id' => $pid ) );
				$r = Fili_Apply::apply( (int) $pid );
				if ( true === $r ) {
					$done[] = (int) $pid;
				} else {
					$wpdb->update( Fili_DB::t( 'proposals' ), array( 'status' => 'proposed' ), array( 'id' => $pid ) );
				}
			}
			$mid = md5( (string) get_post_field( 'post_content', $id, 'raw' ) );
			foreach ( array_reverse( $done ) as $pid ) {
				Fili_Apply::undo( $pid );
				$wpdb->update( Fili_DB::t( 'proposals' ), array( 'status' => 'proposed' ), array( 'id' => $pid ) );
			}
			$after = md5( (string) get_post_field( 'post_content', $id, 'raw' ) );
			WP_CLI::log( sprintf( '#%d: %d link applicati, contenuto cambiato: %s, tornato identico: %s', $id, count( $done ), $mid !== $before ? 'sì' : 'no', $after === $before ? 'SÌ' : 'NO !!' ) );
		}
	}
}
WP_CLI::add_command( 'fili', 'Fili_CLI' );
