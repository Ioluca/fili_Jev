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
		foreach ( array( 'start', 'step', 'stop', 'decide', 'apply', 'undo', 'threshold', 'decide_all', 'queue', 'key_line', 'key_delete' ) as $a ) {
			add_action( 'wp_ajax_fili_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function menu(): void {
		add_menu_page( 'Fili', 'Fili', self::CAP, 'fili', array( __CLASS__, 'page_run' ), 'dashicons-admin-links', 58 );
		add_submenu_page( 'fili', __( 'The run', 'fili' ), __( 'The run', 'fili' ), self::CAP, 'fili', array( __CLASS__, 'page_run' ) );
		add_submenu_page( 'fili', __( 'Proposals', 'fili' ), __( 'Proposals', 'fili' ), self::CAP, 'fili-proposals', array( __CLASS__, 'page_proposals' ) );
		add_submenu_page( 'fili', __( 'Duplicates', 'fili' ), __( 'Duplicates', 'fili' ), self::CAP, 'fili-duplicates', array( __CLASS__, 'page_duplicates' ) );
		add_submenu_page( 'fili', __( 'Settings', 'fili' ), __( 'Settings', 'fili' ), self::CAP, 'fili-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'fili' ) ) {
			return;
		}
		wp_enqueue_style( 'fili', FILI_URL . 'assets/fili.css', array(), FILI_VERSION );
		wp_enqueue_script( 'fili', FILI_URL . 'assets/fili.js', array(), FILI_VERSION, true );
		wp_localize_script( 'fili', 'FILI', array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'fili' ),
			'state'  => Fili_Engine::state(),
			'queue'  => self::queue_state(),
			'locale' => str_replace( '_', '-', get_user_locale() ),
			// the script says nothing in any language of its own: every line it shows
			// comes from here, so it is translated like the rest of the plugin
			'i18n'   => array(
				'phaseIdle'        => __( 'Stopped.', 'fili' ),
				'phaseIndex'       => __( 'Reading the posts and building the index…', 'fili' ),
				'phaseFinalize'    => __( 'Weighing the words…', 'fili' ),
				'phasePropose'     => __( 'Asking Jev which connections hold…', 'fili' ),
				'phasePairsFind'   => __( 'Looking for posts that resemble each other too closely…', 'fili' ),
				'phasePairsJudge'  => __( 'Asking Jev which ones tell the same news…', 'fili' ),
				'phaseDone'        => __( 'Done. The proposals are waiting for you.', 'fili' ),
				'busyElsewhere'    => __( '(another process is already working: just watching)', 'fili' ),
				'minutes'          => __( 'minutes', 'fili' ),
				'hours'            => __( 'hours', 'fili' ),
				'days'             => __( 'days', 'fili' ),
				'queueEmpty'       => __( 'Nothing left to apply.', 'fili' ),
				/* translators: 1: time of the next batch, 2: how long until the end */
				'queueRunning'     => __( 'Running. Next batch at %1$s, finishing in about %2$s.', 'fili' ),
				/* translators: %s: how long it would take */
				'queuePaused'      => __( 'Paused. Started now, it would finish in about %s.', 'fili' ),
				/* translators: %d: how many proposals */
				'undoKept'         => __( 'Kept %d. Undo', 'fili' ),
				/* translators: %d: how many proposals */
				'undoDropped'      => __( 'Dropped %d. Undo', 'fili' ),
				/* translators: %d: how many proposals */
				'confirmKeepAll'   => __( "Keep all %d proposals, not just the ones on this page?\n\nNothing is applied to the site: you can change your mind afterwards.", 'fili' ),
				/* translators: %d: how many proposals */
				'confirmDropAll'   => __( "Drop all %d proposals, not just the ones on this page?\n\nNothing is applied to the site: you can change your mind afterwards.", 'fili' ),
				'confirmKeyDelete' => __( "Remove the key?\n\nFili stops proposing until you enter another one. Links already applied stay where they are.", 'fili' ),
			),
		) );
	}

	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'fili', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'fili' ) ), 403 );
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
			wp_send_json_error( array( 'message' => __( 'Add your API key in the settings first.', 'fili' ) ) );
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

	/** Decide every proposal of a view at once, not just the ones on this page. */
	public static function ajax_decide_all(): void {
		self::guard();
		global $wpdb;
		$to   = in_array( $_POST['to'] ?? '', array( 'approved', 'rejected' ), true ) ? (string) $_POST['to'] : 'rejected';
		$from = in_array( $_POST['from'] ?? '', array( 'proposed', 'approved', 'rejected' ), true ) ? (string) $_POST['from'] : 'proposed';
		$soglia = 'proposed' === $from ? (float) fili_settings()['threshold'] : 0.0;
		$n = (int) $wpdb->query( $wpdb->prepare(
			'UPDATE ' . Fili_DB::t( 'proposals' ) . ' SET status=%s WHERE status=%s AND score>=%f', // phpcs:ignore
			$to, $from, $soglia
		) );
		wp_send_json_success( array( 'n' => $n, 'to' => $to ) );
	}

	/** Start, stop or read the gradual application. */
	public static function ajax_queue(): void {
		self::guard();
		$cosa = sanitize_key( $_POST['cosa'] ?? 'stato' );
		if ( 'avvia' === $cosa ) {
			$r = Fili_Queue::start();
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
		} elseif ( 'ferma' === $cosa ) {
			Fili_Queue::stop();
		}
		wp_send_json_success( self::queue_state() );
	}

	/** @return array<string,mixed> */
	private static function queue_state(): array {
		return array(
			'attiva'    => Fili_Queue::scheduled(),
			'restano'   => Fili_Queue::pending(),
			'prossima'  => Fili_Queue::next_run(),
			'eta'       => Fili_Queue::eta(),
			'log'       => array_slice( Fili_Queue::log(), 0, 6 ),
		);
	}

	/** The wp-config line, shown only when an admin asks for it. */
	public static function ajax_key_line(): void {
		self::guard();
		$k = fili_api_key();
		if ( '' === $k ) {
			wp_send_json_error( array( 'message' => __( 'There is no saved key.', 'fili' ) ) );
		}
		wp_send_json_success( array( 'line' => Fili_Key::line( $k ) ) );
	}

	public static function ajax_key_delete(): void {
		self::guard();
		if ( Fili_Key::in_config() ) {
			$r = Fili_Key::write_config( '' );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
		}
		delete_option( 'fili_api_key' );
		wp_send_json_success();
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
			wp_send_json_error( array( 'message' => __( 'At least 20 judged proposals are needed.', 'fili' ) ) );
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
			esc_attr__( 'Estimated by Fili from the characters sent: the official service does not report the cost.', 'fili' ),
			esc_html__( 'last run', 'fili' ), esc_html( number_format_i18n( $run, 4 ) ),
			esc_html__( 'this month', 'fili' ), esc_html( number_format_i18n( $month, 4 ) ), esc_html( number_format_i18n( $budget, 2 ) ),
			esc_html__( 'all time', 'fili' ), esc_html( number_format_i18n( array_sum( array_map( 'floatval', (array) $spend ) ), 4 ) )
		);
		echo '</div><h1>' . esc_html( $title ) . '</h1><p class="fili-lead">' . esc_html( $lead ) . '</p></header>';
	}

	public static function page_run(): void {
		self::head( __( 'The run', 'fili' ), __( 'Fili reads your published posts, works out which ones belong together and prepares the proposals. It touches no post at this stage.', 'fili' ) );
		$ro = ! empty( fili_settings()['read_only'] );
		?>
		<section class="fili-panel">
			<div class="fili-tiles">
				<div class="fili-tile"><b id="fili-indexed">0</b><span><?php esc_html_e( 'posts read', 'fili' ); ?></span></div>
				<div class="fili-tile"><b id="fili-judged">0</b><span><?php esc_html_e( 'posts judged', 'fili' ); ?></span></div>
				<div class="fili-tile"><b id="fili-decisions">0</b><span><?php esc_html_e( 'Jev decisions', 'fili' ); ?></span></div>
				<div class="fili-tile"><b id="fili-spend">$0</b><span id="fili-budget"></span></div>
			</div>
			<div class="fili-bar"><i id="fili-progress"></i></div>
			<p class="fili-phase" id="fili-phase"></p>
			<p class="fili-actions">
				<button class="fili-btn fili-btn-go" id="fili-start"><?php esc_html_e( 'Start the run', 'fili' ); ?></button>
				<button class="fili-btn" id="fili-stop" hidden><?php esc_html_e( 'Stop', 'fili' ); ?></button>
			</p>
			<p class="fili-note" id="fili-error" hidden></p>
			<p class="fili-note"><?php echo $ro ? esc_html__( 'Safety catch on: Fili can only propose.', 'fili' ) : esc_html__( 'Safety catch off: approved links can be applied.', 'fili' ); ?></p>
		</section></div>
		<?php
	}

	public static function page_proposals(): void {
		global $wpdb;
		Fili_Engine::refilter(); // before any query: the guards may have improved since last time
		$s      = fili_settings();
		$view   = sanitize_key( $_GET['view'] ?? 'proposed' ); // phpcs:ignore
		$view   = in_array( $view, array( 'proposed', 'approved', 'applied', 'rejected' ), true ) ? $view : 'proposed';
		$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
		$per    = 40;
		$where  = $wpdb->prepare( 'status=%s AND score>=%f', $view, 'proposed' === $view ? (float) $s['threshold'] : 0 );
		$total  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Fili_DB::t( 'proposals' ) . " WHERE $where" ); // phpcs:ignore
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . Fili_DB::t( 'proposals' ) . " WHERE $where ORDER BY score DESC LIMIT " . ( ( $page - 1 ) * $per ) . ",$per" ); // phpcs:ignore
		$counts = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . Fili_DB::t( 'proposals' ) . " WHERE status<>'proposed' OR score>=%f GROUP BY status", (float) $s['threshold'] ), OBJECT_K ); // phpcs:ignore
		$sugg   = self::measured_threshold();

		self::head( __( 'Proposals', 'fili' ), __( 'Every highlighted phrase is already in the post. Keep it or drop it: nothing changes on the site until you apply.', 'fili' ) );
		echo '<nav class="fili-tabs">';
		foreach ( array( 'proposed' => __( 'To review', 'fili' ), 'approved' => __( 'Kept', 'fili' ), 'applied' => __( 'Applied', 'fili' ), 'rejected' => __( 'Dropped', 'fili' ) ) as $k => $label ) {
			printf( '<a class="%s" href="%s">%s <span data-count="%s">%d</span></a>', $k === $view ? 'on' : '', esc_url( admin_url( 'admin.php?page=fili-proposals&view=' . $k ) ), esc_html( $label ), esc_attr( $k ), (int) ( $counts[ $k ]->n ?? 0 ) );
		}
		echo '</nav>';

		printf( '<p class="fili-note">%s <b>%s</b>. ', esc_html__( 'Threshold in use:', 'fili' ), esc_html( number_format_i18n( (float) $s['threshold'], 2 ) ) );
		if ( null !== $sugg ) {
			printf( '%s <b>%s</b>. <button class="fili-link" id="fili-adopt">%s</button>', esc_html__( 'Measured on your decisions, the threshold is', 'fili' ), esc_html( number_format_i18n( $sugg, 2 ) ), esc_html__( 'Use this one', 'fili' ) );
		} else {
			esc_html_e( 'Judge at least 20 proposals and Fili measures the right threshold for this site.', 'fili' );
		}
		echo '</p>';

		if ( 'approved' === $view ) {
			self::queue_panel();
		}
		if ( ! $rows ) {
			echo '<p class="fili-empty">' . esc_html__( 'Nothing here yet.', 'fili' ) . '</p></div>';
			return;
		}
		echo '<div class="fili-toolbar" data-view="' . esc_attr( $view ) . '">';
		if ( 'applied' !== $view ) {
			$all_to = 'approved' === $view ? 'rejected' : 'approved';
			if ( $total > count( $rows ) ) {
				printf(
					'<button class="fili-btn fili-btn-go" data-every="%s" data-from="%s" data-n="%d">%s</button>',
					esc_attr( $all_to ), esc_attr( $view ), $total,
					esc_html( sprintf( 'approved' === $all_to ? __( 'Keep all %d', 'fili' ) : __( 'Drop all %d', 'fili' ), $total ) )
				);
			}
			printf(
				'<button class="fili-btn" data-bulk-all="%s">%s</button>',
				esc_attr( $all_to ),
				esc_html( sprintf( 'approved' === $all_to ? __( 'Keep the %d on this page', 'fili' ) : __( 'Drop the %d on this page', 'fili' ), count( $rows ) ) )
			);
			echo '<span class="fili-sep"></span><button class="fili-btn" data-bulk="approved">' . esc_html__( 'Keep selected', 'fili' ) . '</button><button class="fili-btn" data-bulk="rejected">' . esc_html__( 'Drop selected', 'fili' ) . '</button>';
		}
		echo '<button class="fili-link" id="fili-undo-last" hidden></button></div><ul class="fili-list">';
		foreach ( $rows as $r ) {
			$ctx = esc_html( $r->context );
			$a   = esc_html( $r->anchor );
			$ctx = $a ? preg_replace( '/' . preg_quote( $a, '/' ) . '/u', '<mark>' . $a . '</mark>', $ctx, 1 ) : $ctx;
			printf(
				'<li class="fili-row" data-id="%d"><input type="checkbox" class="fili-pick" value="%d" aria-label="%s"><div class="fili-body"><p class="fili-pair"><span class="fili-score">%s</span><a href="%s" target="_blank" rel="noopener">%s</a><span class="fili-arrow">→</span><a class="to" href="%s" target="_blank" rel="noopener">%s</a></p><p class="fili-ctx">%s</p></div><div class="fili-do">',
				(int) $r->id, (int) $r->id, esc_attr__( 'select', 'fili' ),
				esc_html( number_format_i18n( (float) $r->score, 2 ) ),
				esc_url( get_permalink( (int) $r->source_id ) ), esc_html( get_the_title( (int) $r->source_id ) ),
				esc_url( get_permalink( (int) $r->target_id ) ), esc_html( get_the_title( (int) $r->target_id ) ),
				$ctx // phpcs:ignore -- escaped above, only <mark> added
			);
			if ( 'applied' === $view ) {
				echo '<button class="fili-btn" data-undo>' . esc_html__( 'Undo', 'fili' ) . '</button>';
			} else {
				if ( 'approved' === $view ) {
					echo '<button class="fili-btn fili-btn-go" data-apply>' . esc_html__( 'Apply', 'fili' ) . '</button>';
				} else {
					echo '<button class="fili-btn fili-btn-go" data-to="approved">' . esc_html__( 'Keep', 'fili' ) . '</button>';
				}
				echo '<button class="fili-btn" data-to="rejected">' . esc_html__( 'Drop', 'fili' ) . '</button>';
			}
			echo '</div></li>';
		}
		echo '</ul>';
		echo '<p class="fili-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'total' => (int) ceil( $total / $per ), 'current' => $page ) ) ?? '' ) . '</p></div>';
	}

	/** The gradual application: how many are left, how long it takes, what it has done. */
	private static function queue_panel(): void {
		$s   = fili_settings();
		$ro  = ! empty( $s['read_only'] );
		$n   = Fili_Queue::pending();
		echo '<section class="fili-queue" id="fili-queue">';
		echo '<p class="fili-eyebrow">' . esc_html__( 'Gradual rollout', 'fili' ) . '</p>';
		printf(
			'<p class="fili-queue-lead">%s</p>',
			esc_html( sprintf(
				/* translators: 1: links waiting, 2: per batch, 3: minutes */
				__( '%1$d approved links waiting. Fili applies %2$d every %3$d minutes, oldest posts first.', 'fili' ),
				$n, (int) $s['batch_size'], (int) $s['batch_minutes']
			) )
		);
		echo '<p class="fili-queue-state" id="fili-queue-state"></p>';
		if ( $ro ) {
			echo '<p class="fili-note fili-err">' . esc_html__( 'The safety catch is on: turn it off in the settings to apply links.', 'fili' ) . '</p>';
		} else {
			echo '<p class="fili-actions"><button class="fili-btn fili-btn-go" id="fili-queue-go">' . esc_html__( 'Start the gradual rollout', 'fili' ) . '</button>';
			echo '<button class="fili-btn" id="fili-queue-stop" hidden>' . esc_html__( 'Pause', 'fili' ) . '</button></p>';
		}
		echo '<ul class="fili-queue-log" id="fili-queue-log"></ul>';
		echo '<p class="fili-note">' . esc_html__( 'Wake-ups depend on visits to the site: on a quiet site the queue runs a little later than the interval says, never faster. You can pause and resume whenever you like.', 'fili' ) . '</p>';
		echo '</section>';
	}

	public static function page_duplicates(): void {
		self::head( __( 'Duplicates', 'fili' ), __( 'Groups of posts telling the same piece of news. They compete for the same search and no internal link fixes that: merge them, or make them genuinely different. Fili only points them out, it changes nothing.', 'fili' ) );
		$groups = Fili_Engine::duplicate_groups();
		if ( ! $groups ) {
			echo '<p class="fili-empty">' . esc_html__( 'No group found, or the run has not finished yet.', 'fili' ) . '</p></div>';
			return;
		}
		printf( '<p class="fili-note">%s</p><ul class="fili-groups">', esc_html( sprintf( __( '%1$d groups, %2$d posts. Genuine follow-ups, the ones reporting a later development, are already left out.', 'fili' ), count( $groups ), array_sum( array_map( 'count', $groups ) ) ) ) );
		foreach ( $groups as $g ) {
			usort( $g, static fn( $a, $b ) => strcmp( get_post_field( 'post_date', $a ), get_post_field( 'post_date', $b ) ) );
			printf( '<li class="fili-group"><p class="fili-eyebrow">%s</p><ol>', esc_html( sprintf( __( '%d posts about the same piece of news', 'fili' ), count( $g ) ) ) );
			foreach ( $g as $id ) {
				printf( '<li><span class="fili-date">%s</span><a href="%s" target="_blank" rel="noopener">%s</a> <a class="fili-edit" href="%s">%s</a></li>', esc_html( get_the_date( 'd/m/Y', $id ) ), esc_url( get_permalink( $id ) ), esc_html( get_the_title( $id ) ), esc_url( get_edit_post_link( $id ) ), esc_html__( 'edit', 'fili' ) );
			}
			echo '</ol></li>';
		}
		echo '</ul></div>';
	}

	public static function page_settings(): void {
		$s   = fili_settings();
		$key = fili_api_key();
		self::head( __( 'Settings', 'fili' ), __( 'Fili talks to one service only: the one you pick here, with your key. It sends nothing anywhere else.', 'fili' ) );
		if ( ! empty( $_GET['errore'] ) ) { // phpcs:ignore
			echo '<p class="fili-note fili-err">' . esc_html( sanitize_text_field( wp_unslash( $_GET['errore'] ) ) ) . '</p>'; // phpcs:ignore
		} elseif ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			echo '<p class="fili-note fili-ok">' . esc_html__( 'Saved.', 'fili' ) . '</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fili-form">
			<input type="hidden" name="action" value="fili_save"><?php wp_nonce_field( 'fili_save' ); ?>

			<fieldset><legend><?php esc_html_e( 'Service and key', 'fili' ); ?></legend>
				<label for="fili-route"><?php esc_html_e( 'Which service', 'fili' ); ?></label>
				<select id="fili-route" name="route">
					<option value="typesafe" <?php selected( $s['route'], 'typesafe' ); ?>>TypeSafe (API ufficiale)</option>
					<option value="openrouter" <?php selected( $s['route'], 'openrouter' ); ?>>OpenRouter</option>
				</select>
				<?php
				$in_config = Fili_Key::in_config();
				$dove      = $in_config ? __( 'in wp-config.php', 'fili' ) : __( 'in the site database', 'fili' );
				?>
				<label for="fili-key"><?php esc_html_e( 'API key', 'fili' ); ?></label>
				<?php if ( $key ) : ?>
					<div class="fili-key-now">
						<code>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;<?php echo esc_html( substr( $key, -4 ) ); ?></code>
						<span><?php
						/* translators: %s: where the key is stored */
						printf( esc_html__( 'in use, stored %s', 'fili' ), esc_html( $dove ) );
						?></span>
						<button type="button" class="fili-btn" id="fili-key-change"><?php esc_html_e( 'Change key', 'fili' ); ?></button>
					</div>
				<?php endif; ?>
				<div id="fili-key-box" <?php echo $key ? 'hidden' : ''; ?>>
					<input id="fili-key" type="password" name="api_key" autocomplete="off"
						placeholder="<?php esc_attr_e( 'paste the new key here', 'fili' ); ?>">
					<p class="fili-key-where">
						<label class="fili-check"><input type="radio" name="key_where" value="config" <?php checked( $in_config ); ?> <?php disabled( ! Fili_Key::config_writable() ); ?>>
							<?php esc_html_e( 'in wp-config.php', 'fili' ); ?>
							<em><?php echo Fili_Key::config_writable() ? esc_html__( 'recommended: stays out of the database and out of its backups', 'fili' ) : esc_html__( 'not available: your host protects the file', 'fili' ); ?></em>
						</label>
						<label class="fili-check"><input type="radio" name="key_where" value="db" <?php checked( ! $in_config ); ?>>
							<?php esc_html_e( 'in the database', 'fili' ); ?>
							<em><?php esc_html_e( 'simpler: you manage it from here only', 'fili' ); ?></em>
						</label>
					</p>
					<p class="fili-note"><?php esc_html_e( 'Save to apply. Fili writes a single line in wp-config.php, checks the file is still valid before touching it and puts it back as it was if anything is off. It leaves no copy of the file in your site folder.', 'fili' ); ?></p>
					<?php if ( $key ) : ?>
						<p class="fili-note">
							<button type="button" class="fili-link" id="fili-key-del"><?php esc_html_e( 'Remove the key and stop Fili', 'fili' ); ?></button>
							<?php if ( ! Fili_Key::config_writable() && $in_config ) : ?>
								&middot; <button type="button" class="fili-link" id="fili-key-show"><?php esc_html_e( 'Show the line to copy by hand', 'fili' ); ?></button>
							<?php endif; ?>
						</p>
						<pre class="fili-snippet" id="fili-key-snippet" hidden></pre>
					<?php endif; ?>
				</div>
			</fieldset>

			<fieldset><legend><?php esc_html_e( 'What it reads', 'fili' ); ?></legend>
				<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : if ( 'attachment' === $pt->name ) { continue; } ?>
					<label class="fili-check"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $pt->labels->name ); ?></label>
				<?php endforeach; ?>
				<p class="fili-note"><?php esc_html_e( 'Published content only. Drafts and private content are never read and never sent.', 'fili' ); ?></p>
				<label for="fili-lang"><?php esc_html_e( 'Language of the checks', 'fili' ); ?></label>
				<select id="fili-lang" name="language">
					<option value="it" <?php selected( $s['language'], 'it' ); ?>>Italiano (misurata su un sito vero)</option>
					<option value="en" <?php selected( $s['language'], 'en' ); ?>>English (prima stesura, non ancora misurata)</option>
				</select>
				<label for="fili-themes"><?php esc_html_e( 'This site\'s standing themes', 'fili' ); ?></label>
				<textarea id="fili-themes" name="themes" rows="2" placeholder="'vibe coding', 'intelligenza artificiale', 'fotografia'"><?php echo esc_textarea( $s['themes'] ); ?></textarea>
				<p class="fili-note"><?php esc_html_e( 'The phrases you use across dozens of posts. As an anchor they point at no post in particular: list them here, in quotes and separated by commas, and Fili drops them.', 'fili' ); ?></p>
			</fieldset>

			<fieldset><legend><?php esc_html_e( 'Limits', 'fili' ); ?></legend>
				<label for="fili-max"><?php esc_html_e( 'New links per post, at most', 'fili' ); ?></label>
				<input id="fili-max" type="number" min="1" max="10" name="max_per_post" value="<?php echo esc_attr( (string) $s['max_per_post'] ); ?>">
				<label for="fili-parallel"><?php esc_html_e( 'Requests in parallel', 'fili' ); ?></label>
				<input id="fili-parallel" type="number" min="1" max="8" name="parallel" value="<?php echo esc_attr( (string) $s['parallel'] ); ?>">
				<p class="fili-note"><?php esc_html_e( 'How many questions Fili keeps in flight at once. The cost does not change, the time does: at 1 an 800-post site takes about a dozen minutes, at 4 around three. On fragile shared hosting leave it at 1 or 2.', 'fili' ); ?></p>
				<label for="fili-batch"><?php esc_html_e( 'Links applied per batch', 'fili' ); ?></label>
				<input id="fili-batch" type="number" min="1" max="50" name="batch_size" value="<?php echo esc_attr( (string) $s['batch_size'] ); ?>">
				<label for="fili-minutes"><?php esc_html_e( 'How many minutes between batches', 'fili' ); ?></label>
				<input id="fili-minutes" type="number" min="5" max="1440" name="batch_minutes" value="<?php echo esc_attr( (string) $s['batch_minutes'] ); ?>">
				<p class="fili-note"><?php esc_html_e( 'Approved links go in a few at a time instead of all at once. Mostly this limits the damage when something is wrong: a mistake shows up on ten posts, not on two hundred. It also spreads the modified dates, so your sitemap reads as a site being tended rather than two hundred pages changed in one minute. Google does not penalise internal links to your own pages: this is our caution, not a rule of theirs.', 'fili' ); ?></p>
				<label for="fili-budget-in"><?php esc_html_e( 'Monthly spending cap, in dollars', 'fili' ); ?></label>
				<input id="fili-budget-in" type="number" min="0.05" step="0.05" name="monthly_budget" value="<?php echo esc_attr( (string) $s['monthly_budget'] ); ?>">
				<p class="fili-note"><?php echo esc_html( sprintf( __( 'Spent this month, estimated: $%s. An 800-post site costs around 30 cents for the first full run.', 'fili' ), number_format_i18n( Fili_Jev::month_spend(), 4 ) ) ); ?></p>
				<label class="fili-check"><input type="checkbox" name="read_only" value="1" <?php checked( ! empty( $s['read_only'] ) ); ?>> <?php esc_html_e( 'Safety catch on: Fili can only propose, it cannot change any post', 'fili' ); ?></label>
			</fieldset>
			<p><button class="fili-btn fili-btn-go"><?php esc_html_e( 'Save', 'fili' ); ?></button></p>
		</form></div>
		<?php
	}

	public static function save_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fili' ) );
		}
		check_admin_referer( 'fili_save' );
		$s                   = fili_settings();
		$s['route']          = 'openrouter' === ( $_POST['route'] ?? '' ) ? 'openrouter' : 'typesafe';
		$s['language']       = 'en' === ( $_POST['language'] ?? '' ) ? 'en' : 'it';
		$s['post_types']     = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? array( 'post' ) ) ), get_post_types( array( 'public' => true ) ) ) ) ?: array( 'post' );
		$s['max_per_post']   = min( 10, max( 1, (int) ( $_POST['max_per_post'] ?? 3 ) ) );
		$s['parallel']       = min( 8, max( 1, (int) ( $_POST['parallel'] ?? 4 ) ) );
		$s['batch_size']     = min( 50, max( 1, (int) ( $_POST['batch_size'] ?? 5 ) ) );
		$s['batch_minutes']  = min( 1440, max( 5, (int) ( $_POST['batch_minutes'] ?? 30 ) ) );
		$s['monthly_budget'] = max( 0.05, (float) ( $_POST['monthly_budget'] ?? 1 ) );
		$s['themes']         = sanitize_textarea_field( wp_unslash( $_POST['themes'] ?? '' ) );
		$s['read_only']      = empty( $_POST['read_only'] ) ? 0 : 1;
		update_option( 'fili_settings', $s, false );
		if ( Fili_Queue::scheduled() ) {
			Fili_Queue::stop();
			Fili_Queue::start(); // the interval changed: reschedule on the new one
		}
		$key   = sanitize_text_field( trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) ) );
		$dove  = 'config' === ( $_POST['key_where'] ?? '' ) ? 'config' : 'db';
		$prima = fili_api_key();
		$avviso = '';
		if ( '' !== $key || ( $prima && $dove !== ( Fili_Key::in_config() ? 'config' : 'db' ) ) ) {
			$valore = '' !== $key ? $key : $prima; // nessuna chiave nuova: si sposta quella che c'e'
			if ( 'config' === $dove ) {
				$r = Fili_Key::write_config( $valore );
				if ( is_wp_error( $r ) ) {
					$avviso = $r->get_error_message();
				} else {
					delete_option( 'fili_api_key' ); // una sola copia, mai due
				}
			} else {
				if ( Fili_Key::in_config() ) {
					$r = Fili_Key::write_config( '' ); // toglie la riga
					if ( is_wp_error( $r ) ) {
						$avviso = $r->get_error_message();
					}
				}
				if ( '' === $avviso ) {
					update_option( 'fili_api_key', $valore, false );
				}
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=fili-settings&saved=1' . ( $avviso ? '&errore=' . rawurlencode( $avviso ) : '' ) ) );
		exit;
	}
}
