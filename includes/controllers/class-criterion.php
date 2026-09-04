<?php
/**
 * Criterion controller — GET /criteria (하위 별점 항목 목록). 공개.
 *
 * @package BrandTalk\Controllers
 */

namespace BrandTalk\Controllers;

use BrandTalk\Helpers as bt;
use BrandTalk\Components\Criterion as Criterion_Component;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the reusable rating criteria.
 */
final class Criterion extends Controller {

	/**
	 * Constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = bt\merge_arrays(
			[
				'routes' => [
					'criteria_resource' => [
						'path'   => '/criteria',
						'method' => 'GET',
						'action' => [ $this, 'get_criteria' ],
						'rest'   => true,
						'args'   => [
							'category' => [ 'type' => 'string', 'required' => false ],
						],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * GET /criteria  (선택: ?category=slug|id 로 특정 카테고리에 연결된 항목만)
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_criteria( $request ) {
		if ( ! empty( $request['category'] ) ) {
			$field = is_numeric( $request['category'] ) ? 'id' : 'slug';
			$term  = get_term_by( $field, $request['category'], Criterion_Component::CATEGORY_TAX );

			if ( ! $term ) {
				return bt\rest_error( 404, '카테고리를 찾을 수 없습니다.' );
			}

			return bt\rest_response( 200, Criterion_Component::for_category( $term->term_id ) );
		}

		return bt\rest_response( 200, Criterion_Component::all() );
	}
}
