<?php
/**
 * Category controller — GET /categories. 정책 §12.2.1 (공개).
 *
 * @package BrandTalk\Controllers
 */

namespace BrandTalk\Controllers;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Serves BrandTalk categories.
 */
final class Category extends Controller {

	/**
	 * Constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = bt\merge_arrays(
			[
				'routes' => [
					'categories_resource' => [
						'path'   => '/categories',
						'method' => 'GET',
						'action' => [ $this, 'get_categories' ],
						'rest'   => true,
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * GET /categories
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_categories( $request ) {
		$terms = get_terms(
			[
				'taxonomy'   => \BrandTalk\Components\Review::TAXONOMY,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) ) {
			return bt\rest_error( 500, $terms->get_error_message() );
		}

		$out = array_map(
			function ( $t ) {
				return [
					'id'       => $t->term_id,
					'slug'     => $t->slug,
					'name'     => $t->name,
					'parent'   => (int) $t->parent,
					'count'    => (int) $t->count,
					// 이 카테고리에 연결된 재사용 하위 별점 항목(순서 보존).
					'criteria' => \BrandTalk\Components\Criterion::for_category( $t->term_id ),
				];
			},
			$terms
		);

		return bt\rest_response( 200, $out );
	}
}
