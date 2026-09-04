<?php
/**
 * Trust engine component. 정책 §4.
 *  - §4.2  좋아요 +1 / 싫어요 -1 / 신고 -(5~10) / 팔로워 +1
 *  - §4.3  신뢰 판정: 좋아요 비율 > 임계값(기본 0.51), 신고는 분모 제외
 *  - §4.4  신뢰도 레벨 5단계
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Options;
use BrandTalk\Models\Reaction;
use BrandTalk\Models\Follow;
use BrandTalk\Models\Trust_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Computes and caches user trust.
 */
final class Trust extends Component {

	const META_SCORE = 'brandtalk_trust_score';
	const META_LEVEL = 'brandtalk_trust_level';

	/**
	 * Records an audit-log delta.
	 *
	 * @param int      $user_id Affected author.
	 * @param string   $source Source event.
	 * @param int      $delta Delta.
	 * @param int|null $review_id Related review.
	 */
	public function log( $user_id, $source, $delta, $review_id = null ) {
		Trust_Log::record( $user_id, $source, $delta, $review_id );
	}

	/**
	 * Recomputes a user's trust score from the ledger and caches it.
	 *
	 * @param int $user_id User id.
	 * @return array{score:int,level:int}
	 */
	public function recalc_user( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return [ 'score' => 0, 'level' => 1 ];
		}

		$counts    = Reaction::counts_for_author( $user_id );
		$followers = Follow::follower_count( $user_id );
		$penalty   = Options::report_penalty();

		$score = ( $counts['like'] * 1 )
			+ ( $counts['dislike'] * -1 )
			+ ( $counts['report'] * -1 * $penalty )
			+ ( $followers * 1 );

		$level = $this->level_for_score( $score );

		update_user_meta( $user_id, self::META_SCORE, $score );
		update_user_meta( $user_id, self::META_LEVEL, $level );

		/**
		 * Fires after a user's trust score is recalculated.
		 *
		 * @hook brandtalk/v1/trust/recalculated
		 * @param {int} $user_id User id.
		 * @param {int} $score New score.
		 * @param {int} $level New level.
		 */
		do_action( 'brandtalk/v1/trust/recalculated', $user_id, $score, $level );

		return [ 'score' => $score, 'level' => $level ];
	}

	/**
	 * §4.4.2 score → level band.
	 *
	 * @param int $score Score.
	 * @return int
	 */
	public function level_for_score( $score ) {
		$score = (int) $score;

		foreach ( Options::trust_levels() as $band ) {
			$min = isset( $band['min'] ) ? (int) $band['min'] : 0;
			$max = isset( $band['max'] ) ? (float) $band['max'] : PHP_INT_MAX;

			if ( $score >= $min && $score <= $max ) {
				return (int) $band['level'];
			}
		}

		return 1;
	}

	/**
	 * User trust summary (REST GET /users/{id}/trust).
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public function user_summary( $user_id ) {
		$user_id = (int) $user_id;
		$score   = get_user_meta( $user_id, self::META_SCORE, true );

		if ( '' === $score ) {
			$recalc = $this->recalc_user( $user_id );
			$score  = $recalc['score'];
			$level  = $recalc['level'];
		} else {
			$score = (int) $score;
			$level = (int) get_user_meta( $user_id, self::META_LEVEL, true );

			if ( $level < 1 ) {
				$level = $this->level_for_score( $score );
			}
		}

		return [
			'user_id'   => $user_id,
			'score'     => $score,
			'level'     => $level,
			'followers' => Follow::follower_count( $user_id ),
			'following' => Follow::following_count( $user_id ),
		];
	}

	/**
	 * §4.3 review reaction counts + trust judgement.
	 *
	 * @param int $review_id Review id.
	 * @return array{like:int,dislike:int,report:int,ratio:float,trusted:bool}
	 */
	public function review_reactions( $review_id ) {
		$counts = Reaction::counts_for_review( (int) $review_id );

		// §4.3.1.1 ratio = like / (like + dislike), report excluded.
		$denominator = $counts['like'] + $counts['dislike'];
		$ratio       = $denominator > 0 ? $counts['like'] / $denominator : 0.0;
		$trusted     = $denominator > 0 && $ratio > Options::trust_threshold();

		return [
			'like'    => $counts['like'],
			'dislike' => $counts['dislike'],
			'report'  => $counts['report'],
			'ratio'   => round( $ratio, 4 ),
			'trusted' => $trusted,
		];
	}
}
