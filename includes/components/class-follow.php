<?php
/**
 * Follow component. 정책 §10, §4.2.4, §10.3(본인 팔로우 불가).
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Models\Follow as Follow_Model;

defined( 'ABSPATH' ) || exit;

/**
 * Handles follow relationships.
 */
final class Follow extends Component {

	/**
	 * Follows a user.
	 *
	 * @param int $follower_id Follower.
	 * @param int $following_id Target.
	 * @return true|\WP_Error
	 */
	public function follow( $follower_id, $following_id ) {
		$follower_id  = (int) $follower_id;
		$following_id = (int) $following_id;

		if ( $follower_id === $following_id ) {
			return new \WP_Error( 'brandtalk_self_follow', '본인은 팔로우할 수 없습니다.', [ 'status' => 400 ] );
		}

		if ( ! get_userdata( $following_id ) ) {
			return new \WP_Error( 'brandtalk_user_not_found', '대상 사용자를 찾을 수 없습니다.', [ 'status' => 404 ] );
		}

		if ( ! Follow_Model::exists( $follower_id, $following_id ) ) {
			( new Follow_Model(
				[
					'follower_id'  => $follower_id,
					'following_id' => $following_id,
				]
			) )->save();

			brandtalk()->trust->log( $following_id, 'follow', 1 );
			brandtalk()->trust->recalc_user( $following_id );

			/**
			 * Fires after a follow is created.
			 *
			 * @hook brandtalk/v1/follow/created
			 * @param {int} $following_id Followed user id.
			 * @param {int} $follower_id Follower user id.
			 */
			do_action( 'brandtalk/v1/follow/created', $following_id, $follower_id );
		}

		return true;
	}

	/**
	 * Unfollows a user.
	 *
	 * @param int $follower_id Follower.
	 * @param int $following_id Target.
	 * @return true
	 */
	public function unfollow( $follower_id, $following_id ) {
		$follower_id  = (int) $follower_id;
		$following_id = (int) $following_id;

		if ( Follow_Model::remove( $follower_id, $following_id ) ) {
			brandtalk()->trust->log( $following_id, 'unfollow', -1 );
			brandtalk()->trust->recalc_user( $following_id );

			/**
			 * Fires after a follow is removed.
			 *
			 * @hook brandtalk/v1/follow/deleted
			 * @param {int} $following_id Unfollowed user id.
			 * @param {int} $follower_id Follower user id.
			 */
			do_action( 'brandtalk/v1/follow/deleted', $following_id, $follower_id );
		}

		return true;
	}

	/**
	 * Whether follower follows target.
	 *
	 * @param int $follower_id Follower.
	 * @param int $following_id Target.
	 * @return bool
	 */
	public function is_following( $follower_id, $following_id ) {
		return Follow_Model::exists( (int) $follower_id, (int) $following_id );
	}
}
