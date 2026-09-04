<?php
/**
 * Recommendation controller — GET /recommendations.
 * 정책 §3.2.1.1 (신뢰 높은 리뷰 우선), §3.2.1.2 (정렬: 평점 → 최신순).
 *
 * @package BrandTalk\Controllers
 */

namespace BrandTalk\Controllers;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Serves recommended reviews.
 */
final class Recommendation extends Controller {

	/**
	 * Constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = bt\merge_arrays(
			[
				'routes' => [
					'recommendations_resource' => [
						'path'   => '/recommendations',
						'method' => 'GET',
						'action' => [ $this, 'get_recommendations' ],
						'rest'   => true,
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * GET /recommendations
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_recommendations( $request ) {
		$review = brandtalk()->review;

		$query = new \WP_Query(
			$review->query_args(
				[
					'posts_per_page' => 50,
					'orderby'        => 'date',
					'order'          => 'DESC',
				]
			)
		);

		$items = array_map( [ $review, 'prepare' ], $query->posts );

		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['trusted'] !== $b['trusted'] ) {
					return $a['trusted'] ? -1 : 1;
				}

				if ( $a['rating'] !== $b['rating'] ) {
					return $b['rating'] <=> $a['rating'];
				}

				return strcmp( (string) $b['date'], (string) $a['date'] );
			}
		);

		return bt\rest_response( 200, $items );
	}
}
