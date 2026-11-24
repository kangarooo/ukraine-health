<?php
/**
 * Public REST endpoint for the live "how many are waiting" counter.
 *
 * GET /wp-json/ukraine-health/v1/count?hospital=ID
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Rest {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			'ukraine-health/v1',
			'/count',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'hospital' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
				'callback'            => array( $this, 'count' ),
			)
		);
	}

	public function count( WP_REST_Request $req ) {
		global $wpdb;
		$hospital = (int) $req['hospital'];
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT g.service_id, g.status, COUNT(r.id) AS members
				FROM ' . UH_Repo::t( 'groups' ) . ' g
				LEFT JOIN ' . UH_Repo::t( 'requests' ) . " r ON r.group_id=g.id AND r.status<>'withdrawn'
				WHERE g.hospital_id=%d AND g.status IN ('open','ready')
				GROUP BY g.id",
				$hospital
			)
		);
		$out = array();
		foreach ( UH_Repo::services() as $s ) {
			$out[ $s->id ] = array(
				'members' => 0,
				'min'     => (int) $s->min_group,
				'status'  => 'open',
			);
		}
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r->service_id ] ) ) {
				$out[ $r->service_id ]['members'] = (int) $r->members;
				$out[ $r->service_id ]['status']  = $r->status;
			}
		}
		return rest_ensure_response( $out );
	}
}
