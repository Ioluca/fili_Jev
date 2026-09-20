<?php
/**
 * The admin screens. Every action is behind manage_options and a nonce, and every
 * value that reaches the page is escaped where it is printed.
 */
final class Fili_Admin {

	private const CAP = 'manage_options';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_fili_save', array( __CLASS__, 'save_settings' ) );
		foreach ( array( 'start', 'step', 'stop', 'decide', 'apply', 'undo', 'threshold' ) as $a ) {
			add_action( 'wp_ajax_fili_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function menu(): void {
		add_menu_page( 'Fili', 'Fili', self::CAP, 'fili', array( __CLASS__, 'page_run' ), 'dashicons-admin-links', 58 );
		add_submenu_page( 'fili', __( 'Il giro', 'fili' ), __( 'Il giro', 'fili' ), self::CAP, 'fili', array( __CLASS__, 'page_run' ) );
		add_submenu_page( 'fili', __( 'Proposte', 'fili' ), __( 'Proposte', 'fili' ), self::CAP, 'fili-proposals', array( __CLASS__, 'page_proposals' ) );
		add_submenu_page( 'fili', __( 'Doppioni', 'fili' ), __( 'Doppioni', 'fili' ), self::CAP, 'fili-duplicates', array( __CLASS__, 'page_duplicates' ) );
		add_submenu_page( 'fili', __( 'Impostazioni', 'fili' ), __( 'Impostazioni', 'fili' ), self::CAP, 'fili-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'fili' ) ) {
			return;
		}
		wp_enqueue_style( 'fili', FILI_URL . 'assets/fili.css', array(), FILI_VERSION );
		wp_enqueue_script( 'fili', FILI_URL . 'assets/fili.js', array(), FILI_VERSION, true );
		wp_localize_script( 'fili', 'FILI', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'fili' ),
			'state' => Fili_Engine::state(),
		) );
	}

	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'fili', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Non autorizzato.', 'fili' ) ), 403 );
		}
	}

	private static function state_out( array $s ): array {
		$s['spend']  = round( Fili_Jev::month_spend(), 4 );
		$s['budget'] = (float) fili_settings()['monthly_budget'];
		return $s;
	}

	public static function ajax_start(): void {
		self::guard();
		if ( '' === fili_api_key() ) {
			wp_send_json_error( array( 'message' => __( 'Prima inserisci la chiave API nelle impostazioni.', 'fili' ) ) );
		}
		wp_send_json_success( self::state_out( Fili_Engine::start() ) );
	}

	public static function ajax_step(): void {
		self::guard();
		wp_send_json_success( self::state_out( Fili_Engine::step() ) );
	}

	public static function ajax_stop(): void {
		self::guard();
		wp_send_json_success( self::state_out( Fili_Engine::stop() ) );
	}

	public static function ajax_decide(): void {
		self::guard();
		global $wpdb;
		$to  = in_array( $_POST['to'] ?? '', array( 'approved', 'rejected', 'proposed' ), true ) ? (string) $_POST['to'] : 'rejected';
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		foreach ( $ids as $id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Fili_DB::t( 'proposals' ) . " SET status=%s WHERE id=%d AND status IN ('proposed','approved','rejected','undone')", $to, $id ) ); // phpcs:ignore
		}
		wp_send_json_success( array( 'ids' => array_values( $ids ), 'to' => $to ) );
	}

	public static function ajax_apply(): void {
		self::guard();
		$r = Fili_Apply::apply( (int) ( $_POST['id'] ?? 0 ) );
		is_wp_error( $r ) ? wp_send_json_error( array( 'message' => $r->get_error_message() ) ) : wp_send_json_success();
	}

	public static function ajax_undo(): void {
		self::guard();
		$r = Fili_Apply::undo( (int) ( $_POST['id'] ?? 0 ) );
		is_wp_error( $r ) ? wp_send_json_error( array( 'message' => $r->get_error_message() ) ) : wp_send_json_success( $r );
	}

	/** Adopt the threshold measured on the owner's own decisions. */
	public static function ajax_threshold(): void {
		self::guard();
		$t = self::measured_threshold();
		if ( null === $t ) {
			wp_send_json_error( array( 'message' => __( 'Servono almeno 20 proposte giudicate.', 'fili' ) ) );
		}
		$s              = fili_settings();
		$s['threshold'] = $t;
		update_option( 'fili_settings', $s, false );
		wp_send_json_success( array( 'threshold' => $t ) );
	}

	/**
	 * The lowest score above which the owner kept at least 85% of what they judged.
	 * Null until 20 decisions exist: a threshold is measured on the site, never copied.
	 */
	private static function measured_threshold(): ?float {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT score, status FROM ' . Fili_DB::t( 'proposals' ) . " WHERE status IN ('approved','applied','rejected') ORDER BY score DESC" ); // phpcs:ignore
		if ( count( $rows ) < 20 ) {
			return null;
		}
		$kept = 0;
		$best = (float) $rows[0]->score;
		foreach ( $rows as $i => $r ) {
			$kept += 'rejected' === $r->status ? 0 : 1;
			if ( $i >= 9 && $kept / ( $i + 1 ) >= 0.85 ) {
				$best = (float) $r->score;
			}
		}
		return round( $best, 2 );
	}

	/* ------------------------------------------------------------- screens */

	private static function head( string $title, string $lead ): void {
		// the spend is on every screen, not tucked away in the settings: whoever pays sees it
		$spend  = get_option( 'fili_spend', array() );
		$month  = Fili_Jev::month_spend();
		$budget = (float) fili_settings()['monthly_budget'];
		$state  = Fili_Engine::state();
		$run    = isset( $state['spend_at_start'] ) ? max( 0, $month - (float) $state['spend_at_start'] ) : 0;
		echo '<div class="wrap fili"><header class="fili-head"><div class="fili-top"><p class="fili-eyebrow">Fili</p>';
		printf(
			'<dl class="fili-spend" title="%s"><div><dt>%s</dt><dd>$%s</dd></div><div><dt>%s</dt><dd>$%s <span>/ $%s</span></dd></div><div><dt>%s</dt><dd>$%s</dd></div></dl>',
			esc_attr__( 'Stima calcolata da Fili sui caratteri inviati: il servizio ufficiale non comunica il costo.', 'fili' ),
			esc_html__( 'ultimo giro', 'fili' ), esc_html( number_format_i18n( $run, 4 ) ),
			esc_html__( 'questo mese', 'fili' ), esc_html( number_format_i18n( $month, 4 ) ), esc_html( number_format_i18n( $budget, 2 ) ),
			esc_html__( 'da sempre', 'fili' ), esc_html( number_format_i18n( array_sum( array_map( 'floatval', (array) $spend ) ), 4 ) )
		);
		echo '</div><h1>' . esc_html( $title ) . '</h1><p class="fili-lead">' . esc_html( $lead ) . '</p></header>';
	}

	public static function page_run(): void {
		self::head( __( 'Il giro', 'fili' ), __( 'Fili legge gli articoli pubblicati, cerca quali si parlano fra loro e prepara le proposte. In questa fase non tocca nessun articolo.', 'fili' ) );
		$ro = ! empty( fili_settings()['read_only'] );
		?>
		<section class="fili-panel">
			<div class="fili-tiles">
				<div class="fili-tile"><b id="fili-indexed">0</b><span><?php esc_html_e( 'articoli letti', 'fili' ); ?></span></div>
				<div class="fili-tile"><b id="fili-judged">0</b><span><?php esc_html_e( 'articoli giudicati', 'fili' ); ?></span></div>
				<div class="fili-tile"><b id="fili-decisions">0</b><span><?php esc_html_e( 'decisioni di Jev', 'fili' ); ?></span></div>
				<div class="fili-tile"><b id="fili-spend">$0</b><span id="fili-budget"></span></div>
			</div>
			<div class="fili-bar"><i id="fili-progress"></i></div>
			<p class="fili-phase" id="fili-phase"></p>
			<p class="fili-actions">
				<button class="fili-btn fili-btn-go" id="fili-start"><?php esc_html_e( 'Avvia il giro', 'fili' ); ?></button>
				<button class="fili-btn" id="fili-stop" hidden><?php esc_html_e( 'Ferma', 'fili' ); ?></button>
			</p>
			<p class="fili-note" id="fili-error" hidden></p>
			<p class="fili-note"><?php echo $ro ? esc_html__( 'Sicura inserita: Fili può solo proporre.', 'fili' ) : esc_html__( 'Sicura tolta: i link approvati si possono applicare.', 'fili' ); ?></p>
		</section></div>
		<?php
	}

	public static function page_proposals(): void {
		global $wpdb;
		$s      = fili_settings();
		$view   = sanitize_key( $_GET['view'] ?? 'proposed' ); // phpcs:ignore
		$view   = in_array( $view, array( 'proposed', 'approved', 'applied', 'rejected' ), true ) ? $view : 'proposed';
		$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
		$per    = 40;
		$where  = $wpdb->prepare( 'status=%s AND score>=%f', $view, 'proposed' === $view ? (float) $s['threshold'] : 0 );
		$total  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Fili_DB::t( 'proposals' ) . " WHERE $where" ); // phpcs:ignore
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . Fili_DB::t( 'proposals' ) . " WHERE $where ORDER BY score DESC LIMIT " . ( ( $page - 1 ) * $per ) . ",$per" ); // phpcs:ignore
		Fili_Engine::refilter();
		$counts = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . Fili_DB::t( 'proposals' ) . " WHERE status<>'proposed' OR score>=%f GROUP BY status", (float) $s['threshold'] ), OBJECT_K ); // phpcs:ignore
		$sugg   = self::measured_threshold();

		self::head( __( 'Proposte', 'fili' ), __( 'Ogni frase evidenziata esiste già nell\'articolo. Tieni o butta: niente cambia sul sito finché non applichi.', 'fili' ) );
		echo '<nav class="fili-tabs">';
		foreach ( array( 'proposed' => __( 'Da vedere', 'fili' ), 'approved' => __( 'Tenute', 'fili' ), 'applied' => __( 'Applicate', 'fili' ), 'rejected' => __( 'Buttate', 'fili' ) ) as $k => $label ) {
			printf( '<a class="%s" href="%s">%s <span data-count="%s">%d</span></a>', $k === $view ? 'on' : '', esc_url( admin_url( 'admin.php?page=fili-proposals&view=' . $k ) ), esc_html( $label ), esc_attr( $k ), (int) ( $counts[ $k ]->n ?? 0 ) );
		}
		echo '</nav>';

		printf( '<p class="fili-note">%s <b>%s</b>. ', esc_html__( 'Soglia in uso:', 'fili' ), esc_html( number_format_i18n( (float) $s['threshold'], 2 ) ) );
		if ( null !== $sugg ) {
			printf( '%s <b>%s</b>. <button class="fili-link" id="fili-adopt">%s</button>', esc_html__( 'Sulle tue decisioni la soglia misurata è', 'fili' ), esc_html( number_format_i18n( $sugg, 2 ) ), esc_html__( 'Usa questa', 'fili' ) );
		} else {
			esc_html_e( 'Giudica almeno 20 proposte e Fili misura la soglia giusta per questo sito.', 'fili' );
		}
		echo '</p>';

		if ( ! $rows ) {
			echo '<p class="fili-empty">' . esc_html__( 'Niente qui, per ora.', 'fili' ) . '</p></div>';
			return;
		}
		echo '<div class="fili-toolbar" data-view="' . esc_attr( $view ) . '">';
		if ( 'applied' !== $view ) {
			$all_to = 'approved' === $view ? 'rejected' : 'approved';
			printf(
				'<button class="fili-btn fili-btn-go" data-bulk-all="%s">%s</button>',
				esc_attr( $all_to ),
				esc_html( sprintf( 'approved' === $all_to ? __( 'Tieni tutte le %d in pagina', 'fili' ) : __( 'Butta tutte le %d in pagina', 'fili' ), count( $rows ) ) )
			);
			echo '<span class="fili-sep"></span><button class="fili-btn" data-bulk="approved">' . esc_html__( 'Tieni le selezionate', 'fili' ) . '</button><button class="fili-btn" data-bulk="rejected">' . esc_html__( 'Butta le selezionate', 'fili' ) . '</button>';
		}
		echo '<button class="fili-link" id="fili-undo-last" hidden></button></div><ul class="fili-list">';
		foreach ( $rows as $r ) {
			$ctx = esc_html( $r->context );
			$a   = esc_html( $r->anchor );
			$ctx = $a ? preg_replace( '/' . preg_quote( $a, '/' ) . '/u', '<mark>' . $a . '</mark>', $ctx, 1 ) : $ctx;
			printf(
				'<li class="fili-row" data-id="%d"><input type="checkbox" class="fili-pick" value="%d" aria-label="%s"><div class="fili-body"><p class="fili-pair"><span class="fili-score">%s</span><a href="%s" target="_blank" rel="noopener">%s</a><span class="fili-arrow">→</span><a class="to" href="%s" target="_blank" rel="noopener">%s</a></p><p class="fili-ctx">%s</p></div><div class="fili-do">',
				(int) $r->id, (int) $r->id, esc_attr__( 'seleziona', 'fili' ),
				esc_html( number_format_i18n( (float) $r->score, 2 ) ),
				esc_url( get_permalink( (int) $r->source_id ) ), esc_html( get_the_title( (int) $r->source_id ) ),
				esc_url( get_permalink( (int) $r->target_id ) ), esc_html( get_the_title( (int) $r->target_id ) ),
				$ctx // phpcs:ignore -- escaped above, only <mark> added
			);
			if ( 'applied' === $view ) {
				echo '<button class="fili-btn" data-undo>' . esc_html__( 'Annulla', 'fili' ) . '</button>';
			} else {
				if ( 'approved' === $view ) {
					echo '<button class="fili-btn fili-btn-go" data-apply>' . esc_html__( 'Applica', 'fili' ) . '</button>';
				} else {
					echo '<button class="fili-btn fili-btn-go" data-to="approved">' . esc_html__( 'Tieni', 'fili' ) . '</button>';
				}
				echo '<button class="fili-btn" data-to="rejected">' . esc_html__( 'Butta', 'fili' ) . '</button>';
			}
			echo '</div></li>';
		}
		echo '</ul>';
		echo '<p class="fili-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'total' => (int) ceil( $total / $per ), 'current' => $page ) ) ?? '' ) . '</p></div>';
	}

	public static function page_duplicates(): void {
		self::head( __( 'Doppioni', 'fili' ), __( 'Gruppi di articoli che raccontano la stessa notizia. Si contendono la stessa ricerca e nessun link rimedia: vanno uniti o distinti. Fili li segnala soltanto, non tocca niente.', 'fili' ) );
		$groups = Fili_Engine::duplicate_groups();
		if ( ! $groups ) {
			echo '<p class="fili-empty">' . esc_html__( 'Nessun gruppo trovato, o il giro non è ancora finito.', 'fili' ) . '</p></div>';
			return;
		}
		printf( '<p class="fili-note">%s</p><ul class="fili-groups">', esc_html( sprintf( __( '%1$d gruppi, %2$d articoli. I seguiti veri, quelli che raccontano uno sviluppo successivo, sono già esclusi.', 'fili' ), count( $groups ), array_sum( array_map( 'count', $groups ) ) ) ) );
		foreach ( $groups as $g ) {
			usort( $g, static fn( $a, $b ) => strcmp( get_post_field( 'post_date', $a ), get_post_field( 'post_date', $b ) ) );
			printf( '<li class="fili-group"><p class="fili-eyebrow">%s</p><ol>', esc_html( sprintf( __( '%d pezzi sulla stessa notizia', 'fili' ), count( $g ) ) ) );
			foreach ( $g as $id ) {
				printf( '<li><span class="fili-date">%s</span><a href="%s" target="_blank" rel="noopener">%s</a> <a class="fili-edit" href="%s">%s</a></li>', esc_html( get_the_date( 'd/m/Y', $id ) ), esc_url( get_permalink( $id ) ), esc_html( get_the_title( $id ) ), esc_url( get_edit_post_link( $id ) ), esc_html__( 'modifica', 'fili' ) );
			}
			echo '</ol></li>';
		}
		echo '</ul></div>';
	}

	public static function page_settings(): void {
		$s   = fili_settings();
		$key = fili_api_key();
		self::head( __( 'Impostazioni', 'fili' ), __( 'Fili parla con un solo servizio: quello che scegli qui, con la tua chiave. Non manda niente a nessun altro.', 'fili' ) );
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			echo '<p class="fili-note fili-ok">' . esc_html__( 'Salvato.', 'fili' ) . '</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fili-form">
			<input type="hidden" name="action" value="fili_save"><?php wp_nonce_field( 'fili_save' ); ?>

			<fieldset><legend><?php esc_html_e( 'Servizio e chiave', 'fili' ); ?></legend>
				<label for="fili-route"><?php esc_html_e( 'Da dove passa', 'fili' ); ?></label>
				<select id="fili-route" name="route">
					<option value="typesafe" <?php selected( $s['route'], 'typesafe' ); ?>>TypeSafe (API ufficiale)</option>
					<option value="openrouter" <?php selected( $s['route'], 'openrouter' ); ?>>OpenRouter</option>
				</select>
				<label for="fili-key"><?php esc_html_e( 'Chiave API', 'fili' ); ?></label>
				<?php if ( defined( 'FILI_API_KEY' ) && FILI_API_KEY ) : ?>
					<p class="fili-note"><?php esc_html_e( 'Definita in wp-config.php con FILI_API_KEY: è il posto più sicuro, qui non serve altro.', 'fili' ); ?></p>
				<?php else : ?>
					<input id="fili-key" type="password" name="api_key" autocomplete="off" placeholder="<?php echo esc_attr( $key ? '••••••••' . substr( $key, -4 ) : '' ); ?>">
					<p class="fili-note"><?php esc_html_e( 'La chiave non viene mai rimostrata per intero. Lascia vuoto per tenere quella salvata. È conservata nel database del sito: chi ha accesso al database può leggerla. Per tenerla fuori dal database, definisci FILI_API_KEY in wp-config.php.', 'fili' ); ?></p>
				<?php endif; ?>
			</fieldset>

			<fieldset><legend><?php esc_html_e( 'Cosa legge', 'fili' ); ?></legend>
				<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : if ( 'attachment' === $pt->name ) { continue; } ?>
					<label class="fili-check"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $pt->labels->name ); ?></label>
				<?php endforeach; ?>
				<p class="fili-note"><?php esc_html_e( 'Solo contenuti già pubblicati. Bozze e contenuti privati non vengono mai letti né inviati.', 'fili' ); ?></p>
				<label for="fili-lang"><?php esc_html_e( 'Lingua dei controlli', 'fili' ); ?></label>
				<select id="fili-lang" name="language">
					<option value="it" <?php selected( $s['language'], 'it' ); ?>>Italiano (misurata su un sito vero)</option>
					<option value="en" <?php selected( $s['language'], 'en' ); ?>>English (prima stesura, non ancora misurata)</option>
				</select>
				<label for="fili-themes"><?php esc_html_e( 'I temi fissi di questo sito', 'fili' ); ?></label>
				<textarea id="fili-themes" name="themes" rows="2" placeholder="'vibe coding', 'intelligenza artificiale', 'fotografia'"><?php echo esc_textarea( $s['themes'] ); ?></textarea>
				<p class="fili-note"><?php esc_html_e( 'Le espressioni che usi in decine di articoli. Come ancora non indicano nessun articolo in particolare: scrivile qui, fra apici e separate da virgole, e Fili le scarta.', 'fili' ); ?></p>
			</fieldset>

			<fieldset><legend><?php esc_html_e( 'Limiti', 'fili' ); ?></legend>
				<label for="fili-max"><?php esc_html_e( 'Link nuovi per articolo, al massimo', 'fili' ); ?></label>
				<input id="fili-max" type="number" min="1" max="10" name="max_per_post" value="<?php echo esc_attr( (string) $s['max_per_post'] ); ?>">
				<label for="fili-parallel"><?php esc_html_e( 'Richieste insieme', 'fili' ); ?></label>
				<input id="fili-parallel" type="number" min="1" max="8" name="parallel" value="<?php echo esc_attr( (string) $s['parallel'] ); ?>">
				<p class="fili-note"><?php esc_html_e( 'Quante domande Fili tiene in volo nello stesso momento. Il costo non cambia, cambia il tempo: con 1 un sito da 800 articoli impiega una dozzina di minuti, con 4 circa tre. Su un hosting condiviso fragile lascia 1 o 2.', 'fili' ); ?></p>
				<label for="fili-budget-in"><?php esc_html_e( 'Tetto di spesa al mese, in dollari', 'fili' ); ?></label>
				<input id="fili-budget-in" type="number" min="0.05" step="0.05" name="monthly_budget" value="<?php echo esc_attr( (string) $s['monthly_budget'] ); ?>">
				<p class="fili-note"><?php echo esc_html( sprintf( __( 'Speso questo mese, stimato: $%s. Un sito da 800 articoli costa circa 30 centesimi per il primo giro completo.', 'fili' ), number_format_i18n( Fili_Jev::month_spend(), 4 ) ) ); ?></p>
				<label class="fili-check"><input type="checkbox" name="read_only" value="1" <?php checked( ! empty( $s['read_only'] ) ); ?>> <?php esc_html_e( 'Sicura inserita: Fili può solo proporre, non può modificare nessun articolo', 'fili' ); ?></label>
			</fieldset>
			<p><button class="fili-btn fili-btn-go"><?php esc_html_e( 'Salva', 'fili' ); ?></button></p>
		</form></div>
		<?php
	}

	public static function save_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non autorizzato.', 'fili' ) );
		}
		check_admin_referer( 'fili_save' );
		$s                   = fili_settings();
		$s['route']          = 'openrouter' === ( $_POST['route'] ?? '' ) ? 'openrouter' : 'typesafe';
		$s['language']       = 'en' === ( $_POST['language'] ?? '' ) ? 'en' : 'it';
		$s['post_types']     = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? array( 'post' ) ) ), get_post_types( array( 'public' => true ) ) ) ) ?: array( 'post' );
		$s['max_per_post']   = min( 10, max( 1, (int) ( $_POST['max_per_post'] ?? 3 ) ) );
		$s['parallel']       = min( 8, max( 1, (int) ( $_POST['parallel'] ?? 4 ) ) );
		$s['monthly_budget'] = max( 0.05, (float) ( $_POST['monthly_budget'] ?? 1 ) );
		$s['themes']         = sanitize_textarea_field( wp_unslash( $_POST['themes'] ?? '' ) );
		$s['read_only']      = empty( $_POST['read_only'] ) ? 0 : 1;
		update_option( 'fili_settings', $s, false );
		$key = trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( '' !== $key ) {
			update_option( 'fili_api_key', sanitize_text_field( $key ), false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=fili-settings&saved=1' ) );
		exit;
	}
}
