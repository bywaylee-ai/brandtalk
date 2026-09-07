<?php
/**
 * Trust engine component. 정책 §4.
 *  - §4.2   좋아요 +1 / 싫어요 -1 / 신고 -(5~10) / 팔로워 +1
 *  - §4.2.7 리뷰어 신용도 가중치(-0.2~+0.2): 반응자가 남긴 좋아요/싫어요는
 *           ±1 이 아니라 (±1 ± 반응자 신용도) 로 게시자 신뢰도에 반영된다.
 *  - §4.3   신뢰 판정: 좋아요 비율 > 임계값(기본 0.51), 신고는 분모 제외
 *  - §4.4   신뢰도 레벨 5단계
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
	const META_CREDIBILITY = 'brandtalk_trust_credibility';

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
	 * §4.2.7 순 좋아요(좋아요 − 싫어요)를 구간으로 나눠 리뷰어 신용도 가중치로 매긴다.
	 * 구간: net ≥ hi → +0.2 / net ≥ lo → +0.1 / |net| < lo → 0 / net ≤ -lo → -0.1 / net ≤ -hi → -0.2.
	 *
	 * @param int $net 순 좋아요.
	 * @return float -0.2 ~ +0.2
	 */
	public function credibility_for_net( $net ) {
		$net = (int) $net;
		$lo  = Options::credibility_net_lo();
		$hi  = Options::credibility_net_hi();

		if ( $net >= $hi ) {
			return 0.2;
		}

		if ( $net >= $lo ) {
			return 0.1;
		}

		if ( $net <= -$hi ) {
			return -0.2;
		}

		if ( $net <= -$lo ) {
			return -0.1;
		}

		return 0.0;
	}

	/**
	 * §4.2.7 리뷰어 신용도 가중치를 재계산해 캐시한다(자기 리뷰가 받은 순 좋아요 기준).
	 *
	 * @param int $user_id User id.
	 * @return float
	 */
	public function recalc_credibility( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return 0.0;
		}

		$weight = $this->credibility_for_net( Reaction::net_likes_for_author( $user_id ) );

		update_user_meta( $user_id, self::META_CREDIBILITY, $weight );

		return $weight;
	}

	/**
	 * §4.2.7 리뷰어의 캐시된 신용도 가중치(없으면 계산). 이 값은 그 리뷰어가 다른 리뷰에
	 * 남기는 좋아요/싫어요의 무게(±1 ± 신용도)에 반영된다.
	 *
	 * @param int $user_id User id.
	 * @return float
	 */
	public function credibility_weight( $user_id ) {
		$cached = get_user_meta( (int) $user_id, self::META_CREDIBILITY, true );

		if ( '' === $cached ) {
			return $this->recalc_credibility( $user_id );
		}

		return (float) $cached;
	}

	/**
	 * Recomputes a user's trust score from the ledger and caches it.
	 *
	 * 반응자별 신용도 가중치는 각자의 캐시된 값을 그 시점에 읽는다(§4.2.7). 어떤 반응자의
	 * 신용도가 바뀌면 그가 반응했던 게시자 점수는 다음 재계산 때 반영된다(지연 정합).
	 *
	 * @param int $user_id User id.
	 * @return array{score:float,level:int,credibility:float}
	 */
	public function recalc_user( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return [ 'score' => 0.0, 'level' => 1, 'credibility' => 0.0 ];
		}

		// §4.2.7 이 리뷰어 본인의 신용도 가중치를 먼저 갱신한다.
		$credibility = $this->recalc_credibility( $user_id );

		$counts    = Reaction::weighted_counts_for_author( $user_id );
		$followers = Follow::follower_count( $user_id );
		$penalty   = Options::report_penalty();

		// §4.2 건수(±1) + §4.2.7 반응자 신용도 가중(±0.2) 합.
		$score = ( $counts['like'] - $counts['dislike'] )
			+ ( $counts['like_weight'] - $counts['dislike_weight'] )
			+ ( $counts['report'] * -1 * $penalty )
			+ ( $followers * 1 );

		$score = round( $score, 2 );
		$level = $this->level_for_score( $score );

		update_user_meta( $user_id, self::META_SCORE, $score );
		update_user_meta( $user_id, self::META_LEVEL, $level );

		/**
		 * Fires after a user's trust score is recalculated.
		 *
		 * @hook brandtalk/v1/trust/recalculated
		 * @param {int}   $user_id User id.
		 * @param {float} $score New score (소수 2자리 — §4.2.7 반응자 신용도 가중 반영).
		 * @param {int}   $level New level.
		 */
		do_action( 'brandtalk/v1/trust/recalculated', $user_id, $score, $level );

		return [ 'score' => $score, 'level' => $level, 'credibility' => $credibility ];
	}

	/**
	 * §4.4.2 score → level band.
	 *
	 * @param int $score Score.
	 * @return int
	 */
	public function level_for_score( $score ) {
		$score = (float) $score;

		foreach ( Options::trust_levels() as $band ) {
			$min = isset( $band['min'] ) ? (float) $band['min'] : 0.0;
			$max = isset( $band['max'] ) ? (float) $band['max'] : (float) PHP_INT_MAX;

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
			$score = (float) $score;
			$level = (int) get_user_meta( $user_id, self::META_LEVEL, true );

			if ( $level < 1 ) {
				$level = $this->level_for_score( $score );
			}
		}

		return [
			'user_id'     => $user_id,
			'score'       => $score,
			'level'       => $level,
			'credibility' => $this->credibility_weight( $user_id ),
			'followers'   => Follow::follower_count( $user_id ),
			'following'   => Follow::following_count( $user_id ),
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
