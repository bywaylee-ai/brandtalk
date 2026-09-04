<?php
/**
 * Provider controller — GET /auth/providers. 정책 §6.2 (소셜 로그인 6종).
 *
 * OAuth 흐름 구현은 이번 범위 밖 — 제공자 목록·활성화 상태만 노출한다.
 *
 * @package BrandTalk\Controllers
 */

namespace BrandTalk\Controllers;

use BrandTalk\Helpers as bt;
use BrandTalk\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the social login provider list.
 */
final class Provider extends Controller {

	/**
	 * Constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = bt\merge_arrays(
			[
				'routes' => [
					'auth_providers_resource' => [
						'path'   => '/auth/providers',
						'method' => 'GET',
						'action' => [ $this, 'get_providers' ],
						'rest'   => true,
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * GET /auth/providers
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_providers( $request ) {
		return bt\rest_response( 200, Options::providers_public() );
	}
}
