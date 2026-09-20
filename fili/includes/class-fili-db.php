<?php
/**
 * Fili's own tables. Proposals, the word index and the pair judgements live here,
 * so nothing Fili computes ever needs to touch wp_posts until a link is approved.
 */
final class Fili_DB {

	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'fili_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . self::t( 'docs' ) . " (
			post_id BIGINT UNSIGNED NOT NULL,
			content_hash CHAR(32) NOT NULL,
			norm DOUBLE NOT NULL DEFAULT 0,
			proposed TINYINT NOT NULL DEFAULT 0,
			paired TINYINT NOT NULL DEFAULT 0,
			PRIMARY KEY  (post_id)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'terms' ) . " (
			term VARCHAR(48) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			w FLOAT NOT NULL,
			PRIMARY KEY  (term, post_id),
			KEY post_id (post_id)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'df' ) . " (
			term VARCHAR(48) NOT NULL,
			df INT UNSIGNED NOT NULL,
			idf FLOAT NOT NULL,
			PRIMARY KEY  (term)
		) $c;" );

		// One row per distinct sentence, counted once per post: a sentence living in a
		// large share of the posts is site furniture (a signature line) and never an anchor.
		dbDelta( 'CREATE TABLE ' . self::t( 'sentences' ) . " (
			h CHAR(16) NOT NULL,
			n INT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY  (h)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'proposals' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT UNSIGNED NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL,
			anchor VARCHAR(190) NOT NULL,
			context TEXT NOT NULL,
			score FLOAT NOT NULL,
			confidence FLOAT NULL,
			status VARCHAR(12) NOT NULL DEFAULT 'proposed',
			inserted_html TEXT NULL,
			hash_before CHAR(32) NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pair (source_id, target_id),
			KEY status (status),
			KEY score (score)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'pairs' ) . " (
			a_id BIGINT UNSIGNED NOT NULL,
			b_id BIGINT UNSIGNED NOT NULL,
			similarity FLOAT NOT NULL,
			same_news FLOAT NULL,
			same_content FLOAT NULL,
			later_event FLOAT NULL,
			PRIMARY KEY  (a_id, b_id)
		) $c;" );

		add_option( 'fili_api_key', '', '', 'no' );
		add_option( 'fili_state', array( 'phase' => 'idle' ), '', 'no' );
		add_option( 'fili_spend', array(), '', 'no' );
	}

	/** Wipe everything computed, keep settings, key and links already applied. */
	public static function reset_index(): void {
		global $wpdb;
		foreach ( array( 'docs', 'terms', 'df', 'sentences', 'pairs' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . self::t( $t ) ); // phpcs:ignore
		}
		$wpdb->query( 'DELETE FROM ' . self::t( 'proposals' ) . " WHERE status IN ('proposed','rejected')" ); // phpcs:ignore
	}
}
