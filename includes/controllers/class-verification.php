<?php
/**
 * Verification controller — GET/POST /verifications. 정책 §8.
 *
 * @package BrandTalk\Controllers
 */

namespace BrandTalk\Controllers;

use BrandTalk\Helpers as bt;
use BrandTalk\Models\Verification as Verification_Model;

defined( 'ABSPATH' ) || exit;

/**
 * Manages personal verifications.
 */
final class Verification extends Controller {

	/**
	 * Constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = bt\merge_arrays(
			[
				'routes' => [
					'verifications_resource' => [
						'path'       => '/verifications',
						'method'     => 'GET',
						'action'     => [ $this, 'get_verifications' ],
						'permission' => [ $this, 'require_login' ],
						'rest'       => true,
					],

					'verification_create'    => [
						'base'       => 'verifications_resource',
						'method'     => 'POST',
						'action'     => [ $this, 'create_verification' ],
						'permission' => [ $this, 'require_login' ],
						'rest'       => true,
						'args'       => [
							'type'    => [
								'type'     => 'string',
								'required' => true,
								'enum'     => Verification_Model::TYPES,
							],
							'payload' => [
								'type'     => 'object',
								'required' => false,
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
	 * GET /verifications
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_verifications( $request ) {
		$user_id = get_current_user_id();

		return bt\rest_response(
			200,
			[
				'items'         => brandtalk()->verification->for_user( $user_id ),
				'region_locked' => brandtalk()->verification->region_locked( $user_id ),
			]
		);
	}

	/**
	 * POST /verifications
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_verification( $request ) {
		$payload = $request['payload'];

		if ( ! is_array( $payload ) ) {
			$payload = [];
		}

		$result = brandtalk()->verification->submit( get_current_user_id(), $request['type'], $payload );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return bt\rest_response( 201, $result );
	}
}
