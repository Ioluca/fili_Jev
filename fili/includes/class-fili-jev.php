<?php
/**
 * The only file that talks to the network, and only to the API the owner chose.
 * No telemetry, no callbacks: grep this file to verify it.
 */
final class Fili_Jev {

	private const ROUTES = array(
		'typesafe'   => array( 'url' => 'https://api.typesafe.ai/v1/systemone', 'model' => 'jev-1.13.0' ),
		'openrouter' => array( 'url' => 'https://openrouter.ai/api/alpha/decisions', 'model' => 'typesafe/jev-1.13' ),
	);
	private const USD_PER_MTOK = 0.042;

	/**
	 * @param mixed               $state
	 * @param array<string,mixed> $questions
	 * @return array<string,mixed>|WP_Error  the 'answers' map
	 */
	public static function ask( $state, array $questions ) {
		$key = fili_api_key();
		if ( '' === $key ) {
			return new WP_Error( 'fili_no_key', __( 'Manca la chiave API.', 'fili' ) );
		}
		$route = self::ROUTES[ fili_settings()['route'] ] ?? self::ROUTES['typesafe'];
		$body  = wp_json_encode( array( 'state' => $state, 'model' => $route['model'], 'questions' => $questions ) );

		if ( self::month_spend() + self::estimate( $body ) > (float) fili_settings()['monthly_budget'] ) {
			return new WP_Error( 'fili_budget', __( 'Tetto di spesa mensile raggiunto: Fili si ferma qui.', 'fili' ) );
		}

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$r = wp_remote_post( $route['url'], array(
				'timeout' => 60,
				'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
				'body'    => $body,
			) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( in_array( $code, array( 429, 529 ), true ) ) {
				sleep( 2 ** $attempt );
				continue;
			}
			if ( 200 !== $code ) {
				// never echo the body verbatim: a gateway error can quote the request headers
				return new WP_Error( 'fili_http', sprintf( 'HTTP %d', $code ) );
			}
			$data = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( ! is_array( $data ) || ! isset( $data['answers'] ) ) {
				return new WP_Error( 'fili_shape', __( 'Risposta inattesa dal servizio.', 'fili' ) );
			}
			self::record_spend( (float) ( $data['usage']['cost'] ?? 0 ) ?: self::estimate( $body ) );
			return $data['answers'];
		}
		return new WP_Error( 'fili_retry', __( 'Il servizio è occupato, riprova più tardi.', 'fili' ) );
	}

	/**
	 * The same as ask(), for several independent jobs at once. One request at a time spends
	 * almost all of its time waiting on the network; a handful in flight cuts a run from
	 * minutes to a fraction, at the same cost. How many is the owner's call: a fragile
	 * shared host may want 1.
	 *
	 * @param array<int|string,array{state:mixed,questions:array}> $jobs
	 * @return array<int|string,array|WP_Error> answers per job, same keys
	 */
	public static function ask_many( array $jobs ): array {
		if ( count( $jobs ) <= 1 || (int) fili_settings()['parallel'] <= 1 ) {
			return array_map( static fn( $job ) => self::ask( $job['state'], $job['questions'] ), $jobs );
		}
		$key = fili_api_key();
		if ( '' === $key ) {
			return array_fill_keys( array_keys( $jobs ), new WP_Error( 'fili_no_key', __( 'Manca la chiave API.', 'fili' ) ) );
		}
		if ( ! class_exists( '\WpOrg\Requests\Requests' ) ) {
			require_once ABSPATH . WPINC . '/Requests/src/Autoload.php';
			\WpOrg\Requests\Autoload::register();
		}
		$route    = self::ROUTES[ fili_settings()['route'] ] ?? self::ROUTES['typesafe'];
		$requests = array();
		$bodies   = array();
		$planned  = 0.0;
		foreach ( $jobs as $k => $job ) {
			$bodies[ $k ] = wp_json_encode( array( 'state' => $job['state'], 'model' => $route['model'], 'questions' => $job['questions'] ) );
			$planned     += self::estimate( $bodies[ $k ] );
			$requests[ $k ] = array(
				'url'     => $route['url'],
				'type'    => 'POST',
				'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
				'data'    => $bodies[ $k ],
			);
		}
		if ( self::month_spend() + $planned > (float) fili_settings()['monthly_budget'] ) {
			return array_fill_keys( array_keys( $jobs ), new WP_Error( 'fili_budget', __( 'Tetto di spesa mensile raggiunto: Fili si ferma qui.', 'fili' ) ) );
		}
		$responses = \WpOrg\Requests\Requests::request_multiple( $requests, array(
			'timeout' => 60,
			'verify'  => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
		) );
		$out   = array();
		$spent = 0.0;
		foreach ( $jobs as $k => $_ ) {
			$r = $responses[ $k ] ?? null;
			if ( ! $r instanceof \WpOrg\Requests\Response ) {
				$out[ $k ] = new WP_Error( 'fili_net', __( 'Connessione non riuscita.', 'fili' ) );
				continue;
			}
			if ( in_array( (int) $r->status_code, array( 429, 529 ), true ) ) {
				$out[ $k ] = new WP_Error( 'fili_retry', __( 'Il servizio è occupato.', 'fili' ) );
				continue;
			}
			$data = 200 === (int) $r->status_code ? json_decode( $r->body, true ) : null;
			if ( ! is_array( $data ) || ! isset( $data['answers'] ) ) {
				$out[ $k ] = new WP_Error( 'fili_http', sprintf( 'HTTP %d', (int) $r->status_code ) );
				continue;
			}
			$spent    += (float) ( $data['usage']['cost'] ?? 0 ) ?: self::estimate( $bodies[ $k ] );
			$out[ $k ] = $data['answers'];
		}
		if ( $spent > 0 ) {
			self::record_spend( $spent );
		}
		return $out;
	}

	/** The official API returns no cost, so it is estimated from what is sent. */
	public static function estimate( string $body ): float {
		return strlen( $body ) / 3.5 / 1e6 * self::USD_PER_MTOK;
	}

	public static function month_spend(): float {
		$s = get_option( 'fili_spend', array() );
		return (float) ( $s[ gmdate( 'Y-m' ) ] ?? 0 );
	}

	private static function record_spend( float $usd ): void {
		$s                   = get_option( 'fili_spend', array() );
		$s[ gmdate( 'Y-m' ) ] = (float) ( $s[ gmdate( 'Y-m' ) ] ?? 0 ) + $usd;
		update_option( 'fili_spend', array_slice( $s, -12, null, true ), false );
	}
}
