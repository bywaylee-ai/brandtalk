<?php
/**
 * User controller — /users/{id}/trust, /users/{id}/follow.
 * 정책 §4.4 (신뢰도 조회), §10 (팔로우).
 *
 * @package BrandTalk\Controllers
 */

namespace BrandTalk\Controllers;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Manages user trust and follow relationships.
 */
final class User extends Controller {

	/**
	 * Constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = bt\merge_arrays(
			[
				'routes' => [
					'user_resource'          => [
						'path' => '/users/(?P<id>\d+)',
					],

					'user_trust_action'      => [
						'base'   => 'user_resource',
						'path'   => '/trust',
						'method' => 'GET',
						'action' => [ $this, 'get_trust' ],
						'rest'   => true,
					],

					'user_follow_action'     => [
						'base'       => 'user_resource',
						'path'       => '/follow',
						'method'     => 'POST',
						'action'     => [ $this, 'follow' ],
						'permission' => [ $this, 'require_login' ],
						'rest'       => true,
					],

					'user_unfollow_action'   => [
						'base'       => 'user_resource',
						'path'       => '/follow',
						'method'     => 'DELETE',
						'action'     => [ $this, 'unfollow' ],
						'permission' => [ $this, 'require_login' ],
						'rest'       => true,
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * GET /users/{id}/trust
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_trust( $request ) {
		$user_id = (int) $request['id'];

		if ( ! get_userdata( $user_id ) ) {
			return bt\rest_error( 404, '사용자를 찾을 수 없습니다.' );
		}

		return bt\rest_response( 200, brandtalk()->trust->user_summary( $user_id ) );
	}

	/**
	 * POST /users/{id}/follow
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function follow( $request ) {
		$result = brandtalk()->follow->follow( get_current_user_id(), (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return bt\rest_response(
			200,
			[
				'following' => true,
				'user_id'   => (int) $request['id'],
				'trust'     => brandtalk()->trust->user_summary( (int) $request['id'] ),
			]
		);
	}

	/**
	 * DELETE /users/{id}/follow
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function unfollow( $request ) {
		brandtalk()->follow->unfollow( get_current_user_id(), (int) $request['id'] );

		return bt\rest_response(
			200,
			[
				'following' => false,
				'user_id'   => (int) $request['id'],
				'trust'     => brandtalk()->trust->user_summary( (int) $request['id'] ),
			]
		);
	}
}
