<?php
/**
 * Review controller — /reviews, /reviews/{id}, /reviews/{id}/reactions.
 * 정책 §12.2 (공개/로그인 구분), §2.1.4 (별점 필수 1~5), §9.3 (카테고리 1개 필수).
 *
 * @package BrandTalk\Controllers
 */

namespace BrandTalk\Controllers;

use BrandTalk\Helpers as bt;
use BrandTalk\Components\Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Manages reviews and reactions.
 */
final class Review extends Controller {

	/**
	 * Constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = bt\merge_arrays(
			[
				'routes' => [
					'reviews_resource'        => [
						'path'   => '/reviews',
						'method' => 'GET',
						'action' => [ $this, 'get_reviews' ],
						'rest'   => true,
						'args'   => $this->list_args(),
					],

					'review_create_action'    => [
						'base'       => 'reviews_resource',
						'method'     => 'POST',
						'action'     => [ $this, 'create_review' ],
						'permission' => [ $this, 'can_review' ],
						'rest'       => true,
						'args'       => $this->create_args(),
					],

					'review_resource'         => [
						'base' => 'reviews_resource',
						'path' => '/(?P<id>\d+)',
					],

					'review_get_action'       => [
						'base'   => 'review_resource',
						'method' => 'GET',
						'action' => [ $this, 'get_review' ],
						'rest'   => true,
					],

					'review_reaction_action'  => [
						'base'       => 'review_resource',
						'path'       => '/reactions',
						'method'     => 'POST',
						'action'     => [ $this, 'create_reaction' ],
						'permission' => [ $this, 'require_login' ],
						'rest'       => true,
						'args'       => [
							'type' => [
								'type'     => 'string',
								'required' => true,
								'enum'     => [ 'like', 'dislike', 'report' ],
							],
						],
					],

					'review_reaction_delete'  => [
						'base'       => 'review_resource',
						'path'       => '/reactions',
						'method'     => 'DELETE',
						'action'     => [ $this, 'delete_reaction' ],
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
	 * List query args.
	 *
	 * @return array
	 */
	protected function list_args() {
		return [
			'category' => [ 'type' => 'string', 'required' => false ],
			'target'   => [ 'type' => 'integer', 'required' => false ],
			'author'   => [ 'type' => 'integer', 'required' => false ],
			'orderby'  => [ 'type' => 'string', 'default' => 'date', 'enum' => [ 'date', 'rating' ] ],
			'order'    => [ 'type' => 'string', 'default' => 'desc', 'enum' => [ 'asc', 'desc' ] ],
			'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
			'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
		];
	}

	/**
	 * Create args.
	 *
	 * @return array
	 */
	protected function create_args() {
		return [
			'rating'   => [
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'maximum'           => 5,
				'validate_callback' => [ Post_Type::class, 'is_valid_rating' ],
			],
			// 대상 글(§9.9). target 이 있으면 category 는 대상 글에서 상속하므로 선택.
			'target'   => [ 'type' => 'integer', 'required' => false ],
			'category' => [ 'type' => 'string', 'required' => false ],
			'content'  => [ 'type' => 'string', 'required' => false ],
			'image_id' => [ 'type' => 'integer', 'required' => false ],
			// 하위 별점 항목별 점수: { "<criterion_id>": 1~5 }
			'criteria' => [ 'type' => 'object', 'required' => false ],
		];
	}

	/**
	 * 리뷰 작성 권한: 로그인 + `edit_posts` (구독자 제외).
	 *
	 * @return true|\WP_Error
	 */
	public function can_review() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'brandtalk_auth_required', '로그인이 필요합니다.', [ 'status' => 401 ] );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'brandtalk_review_forbidden', '리뷰를 작성할 권한이 없습니다. (좋아요·나빠요는 가능합니다.)', [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * GET /reviews
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_reviews( $request ) {
		$review = brandtalk()->review;

		$args = $review->query_args(
			[
				'posts_per_page' => (int) $request['per_page'],
				'paged'          => (int) $request['page'],
			]
		);

		if ( 'rating' === $request['orderby'] ) {
			$args['meta_key'] = \BrandTalk\Components\Review::META_RATING;
			$args['orderby']  = [ 'meta_value_num' => strtoupper( $request['order'] ), 'date' => 'DESC' ];
		} else {
			$args['orderby'] = 'date';
			$args['order']   = strtoupper( $request['order'] );
		}

		if ( ! empty( $request['author'] ) ) {
			$args['author'] = (int) $request['author'];
		}

		if ( ! empty( $request['target'] ) ) {
			$args['meta_query'] = [
				[
					'key'   => 'brandtalk_target',
					'value' => (int) $request['target'],
				],
			];
		}

		if ( ! empty( $request['category'] ) ) {
			$args['tax_query'] = [
				[
					'taxonomy' => \BrandTalk\Components\Review::TAXONOMY,
					'field'    => is_numeric( $request['category'] ) ? 'term_id' : 'slug',
					'terms'    => $request['category'],
				],
			];
		}

		$query = new \WP_Query( $args );
		$items = array_map( [ $review, 'prepare' ], $query->posts );

		$response = bt\rest_response( 200, $items );
		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );

		return $response;
	}

	/**
	 * GET /reviews/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_review( $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post || 'brandtalk_review' !== $post->post_type || 'publish' !== $post->post_status ) {
			return bt\rest_error( 404, '리뷰를 찾을 수 없습니다.' );
		}

		return bt\rest_response( 200, brandtalk()->review->prepare( $post ) );
	}

	/**
	 * POST /reviews
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_review( $request ) {
		$user_id = get_current_user_id();
		$target  = (int) $request['target'];

		if ( $target ) {
			$target_post = get_post( $target );

			if ( ! $target_post || in_array( $target_post->post_type, [ 'brandtalk_review', 'attachment' ], true ) || 'publish' !== $target_post->post_status ) {
				return bt\rest_error( 404, '리뷰할 글을 찾을 수 없습니다.' );
			}

			if ( (int) $target_post->post_author === $user_id ) {
				return bt\rest_error( 403, '자기 글에는 리뷰할 수 없습니다.' );
			}

			if ( brandtalk()->review->existing_review( $user_id, $target ) ) {
				return bt\rest_error( 409, '이미 이 글에 리뷰를 작성했습니다.' );
			}
		}

		$image_ids = $this->handle_uploaded_images( $request );

		if ( is_wp_error( $image_ids ) ) {
			return $this->error_response( $image_ids );
		}

		$result = brandtalk()->review->create(
			$user_id,
			[
				'rating'    => $request['rating'],
				'target'    => $target,
				'category'  => $request['category'],
				'content'   => $request['content'],
				'image_id'  => $request['image_id'],
				'image_ids' => $image_ids,
				'criteria'  => $request['criteria'],
			]
		);

		if ( is_wp_error( $result ) ) {
			foreach ( $image_ids as $img ) {
				wp_delete_attachment( (int) $img, true );
			}

			return $this->error_response( $result );
		}

		return bt\rest_response( 201, brandtalk()->review->prepare( get_post( $result ) ) );
	}

	/**
	 * 업로드된 이미지 파일을 처리한다(제어된 업로더 — 구독자 권한 없이).
	 * 로그인 사용자만, 이미지 MIME 만, 최대 5장.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int[]|\WP_Error attachment id 배열.
	 */
	protected function handle_uploaded_images( $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['images'] ) ) {
			return [];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$allowed = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
		$entries = $this->normalize_files( $files['images'] );

		if ( count( $entries ) > 5 ) {
			return new \WP_Error( 'brandtalk_too_many_images', '이미지는 최대 5장까지 올릴 수 있습니다.', [ 'status' => 400 ] );
		}

		$ids = [];

		foreach ( $entries as $i => $file ) {
			if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
				continue;
			}

			$type = wp_check_filetype( $file['name'] );

			if ( ! in_array( (string) $type['type'], $allowed, true ) ) {
				$this->cleanup_attachments( $ids );

				return new \WP_Error( 'brandtalk_bad_image', '이미지 파일(jpg·png·gif·webp)만 올릴 수 있습니다.', [ 'status' => 400 ] );
			}

			// media_handle_upload 는 $_FILES 의 특정 키에서 읽으므로 임시로 재배치.
			$key            = 'brandtalk_upload_' . $i;
			$_FILES[ $key ] = $file;
			$attach_id      = media_handle_upload( $key, 0 );
			unset( $_FILES[ $key ] );

			if ( is_wp_error( $attach_id ) ) {
				$this->cleanup_attachments( $ids );

				return new \WP_Error( 'brandtalk_upload_failed', '이미지 업로드에 실패했습니다: ' . $attach_id->get_error_message(), [ 'status' => 400 ] );
			}

			$ids[] = (int) $attach_id;
		}

		return $ids;
	}

	/**
	 * `images[]` 형태의 $_FILES 항목을 파일별 배열로 정규화.
	 *
	 * @param array $field $_FILES['images'].
	 * @return array
	 */
	private function normalize_files( $field ) {
		if ( ! isset( $field['name'] ) ) {
			return [];
		}

		if ( ! is_array( $field['name'] ) ) {
			return [ $field ];
		}

		$out = [];

		foreach ( array_keys( $field['name'] ) as $i ) {
			if ( '' === $field['name'][ $i ] ) {
				continue;
			}

			$out[] = [
				'name'     => $field['name'][ $i ],
				'type'     => $field['type'][ $i ] ?? '',
				'tmp_name' => $field['tmp_name'][ $i ] ?? '',
				'error'    => $field['error'][ $i ] ?? UPLOAD_ERR_NO_FILE,
				'size'     => $field['size'][ $i ] ?? 0,
			];
		}

		return $out;
	}

	/**
	 * @param int[] $ids Attachment ids.
	 */
	private function cleanup_attachments( $ids ) {
		foreach ( $ids as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
	}

	/**
	 * POST /reviews/{id}/reactions
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_reaction( $request ) {
		$result = brandtalk()->reaction->set( (int) $request['id'], get_current_user_id(), $request['type'] );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return bt\rest_response(
			200,
			[
				'review_id' => (int) $request['id'],
				'reactions' => $result,
				'me'        => brandtalk()->reaction->user_state( (int) $request['id'], get_current_user_id() ),
			]
		);
	}

	/**
	 * DELETE /reviews/{id}/reactions
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_reaction( $request ) {
		$result = brandtalk()->reaction->clear( (int) $request['id'], get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return bt\rest_response(
			200,
			[
				'review_id' => (int) $request['id'],
				'reactions' => $result,
				'me'        => brandtalk()->reaction->user_state( (int) $request['id'], get_current_user_id() ),
			]
		);
	}
}
