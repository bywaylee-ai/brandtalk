<?php
/**
 * Verification component. 정책 §8.
 *  - 유형: region/friend/social/info/category
 *  - 지역: 최초 verified 이후 재신청 차단(409), 관리자만 해제 (§8.3.2~8.3.3)
 *  - 신규 제출은 status=pending
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Options;
use BrandTalk\Models\Verification as Verification_Model;

defined( 'ABSPATH' ) || exit;

/**
 * Handles personal verifications.
 */
final class Verification extends Component {

	const META_REGION_UNLOCK = 'brandtalk_region_unlock';

	/**
	 * A user's verifications (public arrays).
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public function for_user( $user_id ) {
		return array_map(
			function ( $v ) {
				return $v->to_public_array();
			},
			Verification_Model::for_user( (int) $user_id )
		);
	}

	/**
	 * Whether region re-verification is currently locked for a user.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function region_locked( $user_id ) {
		if ( ! Options::get( 'region_lock', 1 ) ) {
			return false;
		}

		if ( get_user_meta( (int) $user_id, self::META_REGION_UNLOCK, true ) ) {
			return false;
		}

		return Verification_Model::has_verified_region( (int) $user_id );
	}

	/**
	 * Submits a verification request.
	 *
	 * @param int    $user_id User id.
	 * @param string $type Verification type.
	 * @param array  $payload Type-specific payload.
	 * @return array|\WP_Error
	 */
	public function submit( $user_id, $type, $payload ) {
		$user_id = (int) $user_id;

		if ( ! Verification_Model::is_valid_type( $type ) ) {
			return new \WP_Error(
				'brandtalk_invalid_verification_type',
				'type은 region/friend/social/info/category 중 하나여야 합니다.',
				[ 'status' => 400 ]
			);
		}

		if ( 'region' === $type && $this->region_locked( $user_id ) ) {
			return new \WP_Error(
				'brandtalk_region_locked',
				'지역 인증은 최초 확정 후 변경할 수 없습니다. 관리자에게 문의하세요.',
				[ 'status' => 409 ]
			);
		}

		if ( Verification_Model::has_pending( $user_id, $type ) ) {
			return new \WP_Error(
				'brandtalk_verification_pending',
				'이미 검토 대기 중인 동일 유형 인증이 있습니다.',
				[ 'status' => 409 ]
			);
		}

		$model = new Verification_Model(
			[
				'user_id' => $user_id,
				'type'    => $type,
				'status'  => 'pending',
				'payload' => wp_json_encode( $payload ),
			]
		);
		$model->save();

		/**
		 * Fires when a verification is submitted.
		 *
		 * @hook brandtalk/v1/verification/submitted
		 * @param {int} $id Verification id.
		 * @param {int} $user_id User id.
		 * @param {string} $type Verification type.
		 */
		do_action( 'brandtalk/v1/verification/submitted', $model->get_id(), $user_id, $type );

		return $model->to_public_array();
	}

	/**
	 * Applies an admin review decision. (관리자 UI는 범위 밖 — 프로그램적 훅)
	 *
	 * @param int    $verification_id Verification id.
	 * @param string $status pending|verified|rejected.
	 * @return true|\WP_Error
	 */
	public function set_status( $verification_id, $status ) {
		if ( ! in_array( $status, Verification_Model::STATUS, true ) ) {
			return new \WP_Error( 'brandtalk_invalid_status', '허용되지 않는 상태입니다.', [ 'status' => 400 ] );
		}

		( new Verification_Model( [ 'id' => (int) $verification_id, 'status' => $status ] ) )->save();

		/**
		 * Fires after an admin review decision.
		 *
		 * @hook brandtalk/v1/verification/reviewed
		 * @param {int} $verification_id Verification id.
		 * @param {string} $status New status.
		 */
		do_action( 'brandtalk/v1/verification/reviewed', (int) $verification_id, $status );

		return true;
	}
}
