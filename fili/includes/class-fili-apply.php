<?php
/**
 * The only file that changes a post, and only for a proposal the owner approved.
 *
 * It writes the content directly instead of going through wp_update_post(): save filters
 * may rewrite other parts of the content, and then undo could no longer give back the
 * post byte for byte. Revisions are saved by hand before and after, so the change is
 * also visible, and reversible, from WordPress' own revisions screen.
 */
final class Fili_Apply {

	/** @return true|WP_Error */
	public static function apply( int $proposal_id ) {
		global $wpdb;
		if ( ! empty( fili_settings()['read_only'] ) ) {
			return new WP_Error( 'fili_read_only', __( 'Fili è in sola proposta: per applicare i link togli la sicura nelle impostazioni.', 'fili' ) );
		}
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Fili_DB::t( 'proposals' ) . ' WHERE id=%d', $proposal_id ) ); // phpcs:ignore
		if ( ! $p || 'approved' !== $p->status ) {
			return new WP_Error( 'fili_state', __( 'La proposta non è fra quelle approvate.', 'fili' ) );
		}
		$post   = get_post( (int) $p->source_id );
		$target = get_post( (int) $p->target_id );
		if ( ! $post || ! $target || 'publish' !== $post->post_status || 'publish' !== $target->post_status ) {
			return new WP_Error( 'fili_gone', __( 'Uno dei due articoli non è più pubblicato.', 'fili' ) );
		}
		$applied = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Fili_DB::t( 'proposals' ) . " WHERE source_id=%d AND status='applied'", $post->ID ) ); // phpcs:ignore
		if ( $applied >= (int) fili_settings()['max_per_post'] ) {
			return new WP_Error( 'fili_cap', __( 'Questo articolo ha già il massimo di link nuovi.', 'fili' ) );
		}
		$url = get_permalink( $target );
		if ( str_contains( $post->post_content, '/' . $target->post_name . '/' ) ) {
			return new WP_Error( 'fili_linked', __( 'Il link verso quell\'articolo c\'è già.', 'fili' ) );
		}
		$at = Fili_Text::locate( $post->post_content, $p->anchor );
		if ( null === $at ) {
			return new WP_Error( 'fili_moved', __( 'La frase non è più disponibile: o l\'articolo è cambiato dopo la proposta, o cade dentro un link appena applicato. Fili non mette mai un link dentro un altro.', 'fili' ) );
		}

		$html = '<a href="' . esc_url( $url ) . '">' . $p->anchor . '</a>';
		$new  = substr_replace( $post->post_content, $html, $at, strlen( $p->anchor ) );

		wp_save_post_revision( $post->ID ); // the state before, if no revision holds it yet
		// Google's own documentation counts a change to the links on a page as a significant
		// update, and an inaccurate lastmod is worse than none (Illyes, 2026). So the modified
		// date moves, and undo puts the original one back.
		$now = current_time( 'mysql' );
		$ok  = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $new, 'post_modified' => $now, 'post_modified_gmt' => get_gmt_from_date( $now ) ),
			array( 'ID' => $post->ID )
		);
		if ( false === $ok ) {
			return new WP_Error( 'fili_db', __( 'Il database ha rifiutato la modifica.', 'fili' ) );
		}
		clean_post_cache( $post->ID );
		wp_save_post_revision( $post->ID ); // the state after
		$wpdb->update( Fili_DB::t( 'proposals' ), array(
			'status'        => 'applied',
			'inserted_html' => $html,
			'hash_before'   => md5( $post->post_content ),
			'modified_before' => $post->post_modified,
			'applied_at'    => $now,
		), array( 'id' => $proposal_id ) );
		do_action( 'fili_post_changed', $post->ID );
		return true;
	}

	/** @return array{identical:bool}|WP_Error */
	public static function undo( int $proposal_id ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Fili_DB::t( 'proposals' ) . ' WHERE id=%d', $proposal_id ) ); // phpcs:ignore
		if ( ! $p || 'applied' !== $p->status ) {
			return new WP_Error( 'fili_state', __( 'Questo link non risulta applicato.', 'fili' ) );
		}
		$post = get_post( (int) $p->source_id );
		$at   = $post ? strpos( $post->post_content, $p->inserted_html ) : false;
		if ( false === $at ) {
			return new WP_Error( 'fili_edited', __( 'Il link è stato modificato a mano: toglilo dall\'editor.', 'fili' ) );
		}
		$old  = substr_replace( $post->post_content, $p->anchor, $at, strlen( $p->inserted_html ) );
		$campi = array( 'post_content' => $old );
		if ( $p->modified_before ) {
			$campi['post_modified']     = $p->modified_before;
			$campi['post_modified_gmt'] = get_gmt_from_date( $p->modified_before );
		}
		$wpdb->update( $wpdb->posts, $campi, array( 'ID' => $post->ID ) );
		clean_post_cache( $post->ID );
		wp_save_post_revision( $post->ID );
		$wpdb->update( Fili_DB::t( 'proposals' ), array( 'status' => 'undone' ), array( 'id' => $proposal_id ) );
		do_action( 'fili_post_changed', $post->ID );
		return array( 'identical' => md5( $old ) === $p->hash_before );
	}
}
