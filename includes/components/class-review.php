<?php
/**
 * Review component — 리뷰 생성/삭제 라이프사이클 + 응답 스키마.
 * 정책 §9 (리뷰 = 사진 + 글 + 평점 + 카테고리), §9.6 (팔로워 노티).
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the review post type lifecycle.
 */
final class Review extends Component {

	const POST_TYPE = 'brandtalk_review';
	const TAXONOMY  = 'brandtalk_category';
	const META_RATING = 'brandtalk_rating';

	/**
	 * Boot hooks.
	 */
	protected function boot() {
		add_action( 'before_delete_post', [ $this, 'cleanup_on_delete' ] );
	}

	/**
	 * Creates a review from validated input.
	 *
	 * @param int   $user_id Author.
	 * @param array $input rating, category, content, image_id.
	 * @return int|\WP_Error Review id.
	 */
	public function create( $user_id, $input ) {
		$rating = (int) \BrandTalk\Components\Post_Type::sanitize_rating( $input['rating'] );

		// 대상 글(§9.9). 있으면 카테고리는 대상 글에서 상속하고, 제목도 대상 글 제목을 따른다.
		$target = isset( $input['target'] ) ? (int) $input['target'] : 0;
		$term   = null;

		if ( ! empty( $input['category'] ) ) {
			$term = $this->resolve_category( $input['category'] );

			if ( is_wp_error( $term ) ) {
				return $term;
			}
		} elseif ( $target ) {
			$inherited = get_the_terms( $target, self::TAXONOMY );

			if ( $inherited && ! is_wp_error( $inherited ) ) {
				$term = $inherited[0];
			}
		}

		if ( ! $term && ! $target ) {
			return new \WP_Error( 'brandtalk_invalid_category', '카테고리 또는 대상 글이 필요합니다.', [ 'status' => 400 ] );
		}

		$content = isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '';

		if ( $target && get_post( $target ) ) {
			$title = get_the_title( $target );
		} else {
			$title = $content ? wp_trim_words( wp_strip_all_tags( $content ), 8, '…' ) : sprintf( '리뷰 #%d', time() );
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_author'  => (int) $user_id,
				'post_title'   => $title,
				'post_content' => $content,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_RATING, $rating );

		if ( $target && get_post( $target ) ) {
			update_post_meta( $post_id, 'brandtalk_target', $target );
		}

		if ( $term ) {
			wp_set_object_terms( $post_id, [ (int) $term->term_id ], self::TAXONOMY, false );
		}

		// 하위 별점 항목 점수(§ 하위 별점): {criterion_id: 1~5}, 해당 카테고리에 연결된 항목만.
		if ( $term && ! empty( $input['criteria'] ) && is_array( $input['criteria'] ) ) {
			$clean = [];

			foreach ( $input['criteria'] as $criterion_id => $score ) {
				$criterion_id = (int) $criterion_id;
				$score        = (int) $score;

				if ( $score < 1 || $score > 5 ) {
					continue;
				}

				if ( \BrandTalk\Components\Criterion::is_valid_for_categories( $criterion_id, [ (int) $term->term_id ] ) ) {
					$clean[ $criterion_id ] = $score;
				}
			}

			if ( $clean ) {
				update_post_meta( $post_id, 'brandtalk_criteria_ratings', $clean );
			}
		}

		// 이미지(여러 장). 첫 장은 대표 이미지로도 지정.
		$image_ids = [];

		foreach ( (array) ( $input['image_ids'] ?? ( ! empty( $input['image_id'] ) ? [ $input['image_id'] ] : [] ) ) as $img ) {
			$img = (int) $img;

			if ( $img && 'attachment' === get_post_type( $img ) ) {
				$image_ids[] = $img;
				wp_update_post( [ 'ID' => $img, 'post_parent' => $post_id ] );
			}
		}

		if ( $image_ids ) {
			update_post_meta( $post_id, 'brandtalk_images', $image_ids );
			set_post_thumbnail( $post_id, $image_ids[0] );
		}

		brandtalk()->trust->recalc_user( (int) $user_id );

		/**
		 * Fires after a review is published. §9.6 팔로워 노티 연결점(채널 미정 — [검토]).
		 *
		 * @hook brandtalk/v1/review/published
		 * @param {int} $post_id Review id.
		 * @param {int} $user_id Author id.
		 */
		do_action( 'brandtalk/v1/review/published', $post_id, (int) $user_id );

		return $post_id;
	}

	/**
	 * Removes reaction / log rows and recalculates the author when a review is deleted.
	 *
	 * @param int $post_id Post id.
	 */
	public function cleanup_on_delete( $post_id ) {
		global $wpdb;

		$post = get_post( $post_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		$wpdb->delete( \BrandTalk\Models\Reaction::table(), [ 'review_id' => (int) $post_id ] );
		\BrandTalk\Models\Trust_Log::purge_review( (int) $post_id );

		// 이 리뷰가 올린 이미지도 함께 삭제.
		foreach ( (array) get_post_meta( $post_id, 'brandtalk_images', true ) as $img_id ) {
			$img_id = (int) $img_id;

			if ( $img_id && 'attachment' === get_post_type( $img_id ) ) {
				wp_delete_attachment( $img_id, true );
			}
		}

		if ( $post->post_author ) {
			brandtalk()->trust->recalc_user( (int) $post->post_author );
		}
	}

	/**
	 * 사용자가 특정 대상 글에 이미 리뷰를 작성했는지.
	 *
	 * @param int $user_id User id.
	 * @param int $target_id 대상 글 id.
	 * @return int 기존 리뷰 id (없으면 0).
	 */
	public function existing_review( $user_id, $target_id ) {
		if ( ! $user_id || ! $target_id ) {
			return 0;
		}

		$found = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => [ 'publish', 'pending', 'draft' ],
				'author'           => (int) $user_id,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'meta_key'         => 'brandtalk_target',
				'meta_value'       => (int) $target_id,
				'suppress_filters' => false,
			]
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * 특정 대상 글에 달린 발행된 리뷰 목록(최신순).
	 *
	 * @param int $target_id 대상 글 id.
	 * @param int $limit 최대 개수.
	 * @return \WP_Post[]
	 */
	public function reviews_for_target( $target_id, $limit = 50 ) {
		if ( ! $target_id ) {
			return [];
		}

		return get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => (int) $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'meta_key'         => 'brandtalk_target',
				'meta_value'       => (int) $target_id,
				'suppress_filters' => false,
			]
		);
	}

	/**
	 * Resolves a category slug/id to a term.
	 *
	 * @param mixed $category Slug or id.
	 * @return \WP_Term|\WP_Error
	 */
	public function resolve_category( $category ) {
		$field = is_numeric( $category ) ? 'id' : 'slug';
		$term  = get_term_by( $field, $category, self::TAXONOMY );

		if ( ! $term ) {
			return new \WP_Error( 'brandtalk_invalid_category', '존재하지 않는 카테고리입니다.', [ 'status' => 400 ] );
		}

		return $term;
	}

	/**
	 * Builds the REST representation of a review.
	 *
	 * @param \WP_Post $post Review post.
	 * @return array
	 */
	public function prepare( $post ) {
		$author_id = (int) $post->post_author;
		$reactions = brandtalk()->trust->review_reactions( $post->ID );

		$terms    = get_the_terms( $post->ID, self::TAXONOMY );
		$category = ( $terms && ! is_wp_error( $terms ) )
			? [
				'id'     => $terms[0]->term_id,
				'slug'   => $terms[0]->slug,
				'name'   => $terms[0]->name,
				'parent' => (int) $terms[0]->parent,
			]
			: null;

		$thumb_id = get_post_thumbnail_id( $post->ID );

		$image_ids = (array) get_post_meta( $post->ID, 'brandtalk_images', true );
		$image_ids = array_values( array_filter( array_map( 'intval', $image_ids ) ) );

		if ( ! $image_ids && $thumb_id ) {
			$image_ids = [ (int) $thumb_id ];
		}

		$images = [];

		foreach ( $image_ids as $img_id ) {
			$url = wp_get_attachment_image_url( $img_id, 'large' );

			if ( $url ) {
				$images[] = [
					'id'    => $img_id,
					'url'   => $url,
					'thumb' => wp_get_attachment_image_url( $img_id, 'medium' ) ?: $url,
				];
			}
		}

		$target_id = (int) get_post_meta( $post->ID, 'brandtalk_target', true );
		$target    = ( $target_id && get_post( $target_id ) )
			? [
				'id'   => $target_id,
				'type' => get_post_type( $target_id ),
				'title' => get_the_title( $target_id ),
				'url'  => get_permalink( $target_id ),
			]
			: null;

		return [
			'id'          => (int) $post->ID,
			'date'        => mysql_to_rfc3339( $post->post_date_gmt ),
			'title'       => get_the_title( $post->ID ),
			'target'      => $target,
			'rating'      => (int) get_post_meta( $post->ID, self::META_RATING, true ),
			'content'     => [
				'raw'      => $post->post_content,
				'rendered' => apply_filters( 'the_content', $post->post_content ),
			],
			'image'       => $images ? $images[0] : null,
			'images'      => $images,
			'category'    => $category,
			'criteria'    => $this->prepare_criteria( $post->ID ),
			'author'      => [
				'id'    => $author_id,
				'name'  => get_the_author_meta( 'display_name', $author_id ),
				'trust' => [
					'score'       => (float) get_user_meta( $author_id, Trust::META_SCORE, true ),
					'level'       => (int) get_user_meta( $author_id, Trust::META_LEVEL, true ) ?: 1,
					'credibility' => (float) get_user_meta( $author_id, Trust::META_CREDIBILITY, true ),
				],
			],
			'reactions'   => [
				'like'    => $reactions['like'],
				'dislike' => $reactions['dislike'],
				'report'  => $reactions['report'],
			],
			'trust_ratio' => $reactions['ratio'],
			'trusted'     => (bool) $reactions['trusted'],
			'me'          => is_user_logged_in() ? brandtalk()->reaction->user_state( $post->ID, get_current_user_id() ) : [],
		];
	}

	/**
	 * 하위 별점 항목별 점수 (REST 응답용).
	 *
	 * @param int $post_id Post id.
	 * @return array [{id, name, slug, rating}]
	 */
	public function prepare_criteria( $post_id ) {
		$saved = get_post_meta( (int) $post_id, 'brandtalk_criteria_ratings', true );

		if ( ! is_array( $saved ) || ! $saved ) {
			return [];
		}

		$out = [];

		foreach ( $saved as $criterion_id => $score ) {
			$term = get_term( (int) $criterion_id, \BrandTalk\Components\Criterion::TAXONOMY );

			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = [
					'id'     => (int) $term->term_id,
					'slug'   => $term->slug,
					'name'   => $term->name,
					'rating' => (int) $score,
				];
			}
		}

		return $out;
	}

	/**
	 * Query args helper shared by list + recommendation endpoints.
	 *
	 * @param array $overrides Extra WP_Query args.
	 * @return array
	 */
	public function query_args( $overrides = [] ) {
		return array_merge(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
			],
			$overrides
		);
	}
}
