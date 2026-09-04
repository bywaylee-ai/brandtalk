<?php
/**
 * Ranking controller — GET /rankings?type=my|friends.
 * 정책 §3.1.2 (나만의 랭킹: 전체, 점수 DESC), §3.1.3 (친구들과의 랭킹: 팔로우 한정).
 *
 * @package BrandTalk\Controllers
 */

namespace BrandTalk\Controllers;

use BrandTalk\Helpers as bt;
use BrandTalk\Options;
use BrandTalk\Components\Trust;
use BrandTalk\Models\Follow;

defined( 'ABSPATH' ) || exit;

/**
 * Serves trust rankings.
 */
final class Ranking extends Controller {

	/**
	 * Constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = bt\merge_arrays(
			[
				'routes' => [
					'rankings_resource' => [
						'path'   => '/rankings',
						'method' => 'GET',
						'action' => [ $this, 'get_rankings' ],
						'rest'   => true,
						'args'   => [
							'type' => [
								'type'    => 'string',
								'default' => 'my',
								'enum'    => [ 'my', 'friends' ],
							],
						],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * GET /rankings
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_rankings( $request ) {
		global $wpdb;

		$type  = $request['type'];
		$limit = Options::ranking_limit();

		$restrict_ids = null;

		if ( 'friends' === $type ) {
			if ( ! is_user_logged_in() ) {
				return bt\rest_error( 401, '친구 랭킹은 로그인이 필요합니다.' );
			}

			$restrict_ids = Follow::following_ids( get_current_user_id() );

			if ( ! $restrict_ids ) {
				return bt\rest_response( 200, [] );
			}
		}

		$sql    = "SELECT u.ID AS user_id, u.display_name AS name, COALESCE(m.meta_value+0, 0) AS score
			FROM {$wpdb->users} u
			LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s";
		$params = [ Trust::META_SCORE ];

		if ( is_array( $restrict_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $restrict_ids ), '%d' ) );
			$sql         .= " WHERE u.ID IN ($placeholders)";
			$params       = array_merge( $params, $restrict_ids );
		}

		$sql     .= ' ORDER BY score DESC, u.ID ASC LIMIT %d';
		$params[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$trust = brandtalk()->trust;
		$out   = [];
		$rank  = 0;

		foreach ( (array) $rows as $row ) {
			$rank++;
			$out[] = [
				'rank'    => $rank,
				'user_id' => (int) $row['user_id'],
				'name'    => $row['name'],
				'score'   => (int) $row['score'],
				'level'   => $trust->level_for_score( (int) $row['score'] ),
			];
		}

		return bt\rest_response( 200, $out );
	}
}
