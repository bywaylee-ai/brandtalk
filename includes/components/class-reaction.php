<?php
/**
 * Reaction component. 정책 §4.2, §4.2.5.
 *  - 좋아요↔싫어요 상호배타(전환 시 갱신)
 *  - 신고는 별개 플래그(사용자·리뷰당 1건, 취소 불가 — §4.5.2)
 *  - 변경 후 리뷰 작성자 신뢰도 재계산
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Options;
use BrandTalk\Models\Reaction as Reaction_Model;

defined( 'ABSPATH' ) || exit;

/**
 * Handles review reactions.
 */
final class Reaction extends Component {

	/**
	 * Registers or switches a reaction.
	 *
	 * @param int    $review_id Review id.
	 * @param int    $user_id User id.
	 * @param string $type like|dislike|report.
	 * @return array|\WP_Error Reaction summary.
	 */
	public function set( $review_id, $user_id, $type ) {
		$review_id = (int) $review_id;
		$user_id   = (int) $user_id;
		$review    = get_post( $review_id );

		if ( ! $review || 'brandtalk_review' !== $review->post_type || 'publish' !== $review->post_status ) {
			return new \WP_Error( 'brandtalk_review_not_found', '리뷰를 찾을 수 없습니다.', [ 'status' => 404 ] );
		}

		if ( ! Reaction_Model::is_valid_type( $type ) ) {
			return new \WP_Error( 'brandtalk_invalid_reaction', 'type은 like/dislike/report 중 하나여야 합니다.', [ 'status' => 400 ] );
		}

		if ( (int) $review->post_author === $user_id ) {
			return new \WP_Error( 'brandtalk_self_reaction', '자기 리뷰에는 반응할 수 없습니다.', [ 'status' => 403 ] );
		}

		$author_id = (int) $review->post_author;
		$trust     = brandtalk()->trust;

		if ( 'report' === $type ) {
			if ( ! Reaction_Model::find( $user_id, $review_id, 'report' ) ) {
				( new Reaction_Model(
					[
						'review_id' => $review_id,
						'user_id'   => $user_id,
						'type'      => 'report',
					]
				) )->save();

				$trust->log( $author_id, 'report', -1 * Options::report_penalty(), $review_id );
			}
		} else {
			$opposite = 'like' === $type ? 'dislike' : 'like';
			Reaction_Model::remove( $user_id, $review_id, $opposite );

			if ( ! Reaction_Model::find( $user_id, $review_id, $type ) ) {
				( new Reaction_Model(
					[
						'review_id' => $review_id,
						'user_id'   => $user_id,
						'type'      => $type,
					]
				) )->save();

				$trust->log( $author_id, $type, 'like' === $type ? 1 : -1, $review_id );
			}
		}

		$trust->recalc_user( $author_id );

		/**
		 * Fires after a reaction changes.
		 *
		 * @hook brandtalk/v1/reaction/changed
		 * @param {int} $review_id Review id.
		 * @param {int} $user_id User id.
		 * @param {string} $type Reaction type.
		 */
		do_action( 'brandtalk/v1/reaction/changed', $review_id, $user_id, $type );

		return $trust->review_reactions( $review_id );
	}

	/**
	 * Clears the current user's like/dislike (report is not clearable).
	 *
	 * @param int $review_id Review id.
	 * @param int $user_id User id.
	 * @return array|\WP_Error
	 */
	public function clear( $review_id, $user_id ) {
		$review_id = (int) $review_id;
		$review    = get_post( $review_id );

		if ( ! $review || 'brandtalk_review' !== $review->post_type ) {
			return new \WP_Error( 'brandtalk_review_not_found', '리뷰를 찾을 수 없습니다.', [ 'status' => 404 ] );
		}

		Reaction_Model::remove( (int) $user_id, $review_id, [ 'like', 'dislike' ] );
		brandtalk()->trust->recalc_user( (int) $review->post_author );

		return brandtalk()->trust->review_reactions( $review_id );
	}

	/**
	 * The current user's reaction types on a review.
	 *
	 * @param int $review_id Review id.
	 * @param int $user_id User id.
	 * @return array
	 */
	public function user_state( $review_id, $user_id ) {
		return Reaction_Model::user_types( (int) $user_id, (int) $review_id );
	}
}
