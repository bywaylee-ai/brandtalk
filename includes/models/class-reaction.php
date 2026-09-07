<?php
/**
 * Reaction model — 좋아요/싫어요/신고. 정책 §4.2, §4.2.5.
 *
 * @package BrandTalk\Models
 */

namespace BrandTalk\Models;

defined( 'ABSPATH' ) || exit;

/**
 * wp_brandtalk_reactions.
 */
class Reaction extends Model {

	const TYPES = [ 'like', 'dislike', 'report' ];

	/**
	 * Table name (unprefixed).
	 *
	 * @var string
	 */
	protected static $table_name = 'reactions';

	/**
	 * Columns.
	 *
	 * @var array
	 */
	protected static $columns = [ 'review_id', 'user_id', 'type', 'created_at', 'updated_at' ];

	/**
	 * DDL for the table.
	 *
	 * @param string $collate Charset collate.
	 * @return string
	 */
	public static function schema( $collate ) {
		$table = self::table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			review_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'like',
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_review_type (user_id,review_id,type),
			KEY review_type (review_id,type)
		) {$collate};";
	}

	/**
	 * Whether a reaction type is valid.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function is_valid_type( $type ) {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Finds one reaction by user + review + type.
	 *
	 * @param int    $user_id User.
	 * @param int    $review_id Review.
	 * @param string $type Type.
	 * @return Reaction|null
	 */
	public static function find( $user_id, $review_id, $type ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND review_id = %d AND type = %s',
				$user_id,
				$review_id,
				$type
			)
		);

		return self::hydrate( $row );
	}

	/**
	 * Deletes a user's reaction of the given type(s) on a review.
	 *
	 * @param int          $user_id User.
	 * @param int          $review_id Review.
	 * @param string|array $types Type(s).
	 * @return int Rows deleted.
	 */
	public static function remove( $user_id, $review_id, $types ) {
		global $wpdb;

		$types        = (array) $types;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . " WHERE user_id = %d AND review_id = %d AND type IN ($placeholders)",
				array_merge( [ $user_id, $review_id ], $types )
			)
		);
	}

	/**
	 * Counts reactions on a review grouped by type.
	 *
	 * @param int $review_id Review.
	 * @return array{like:int,dislike:int,report:int}
	 */
	public static function counts_for_review( $review_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, COUNT(*) AS cnt FROM ' . self::table() . ' WHERE review_id = %d GROUP BY type',
				$review_id
			),
			ARRAY_A
		);

		$counts = [ 'like' => 0, 'dislike' => 0, 'report' => 0 ];

		foreach ( (array) $rows as $row ) {
			$counts[ $row['type'] ] = (int) $row['cnt'];
		}

		return $counts;
	}

	/**
	 * §4.2.7 순 좋아요(좋아요 − 싫어요) — 게시자의 발행된 리뷰 전체 합.
	 * 리뷰어 신용도 가중치 산출의 입력값.
	 *
	 * @param int $author_id Author user id.
	 * @return int
	 */
	public static function net_likes_for_author( $author_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( CASE r.type WHEN 'like' THEN 1 WHEN 'dislike' THEN -1 ELSE 0 END ), 0 )
				 FROM " . self::table() . " r
				 INNER JOIN {$wpdb->posts} p ON p.ID = r.review_id
				 WHERE p.post_author = %d AND p.post_type = %s AND p.post_status = 'publish'",
				$author_id,
				'brandtalk_review'
			)
		);
	}

	/**
	 * §4.2 + §4.2.7 게시자의 리뷰에 달린 반응 집계에, 반응자별 신용도 가중치 합을 더해 반환.
	 * like/dislike 는 (건수) + (반응자 신용도 합) 으로 게시자 신뢰도에 반영된다.
	 *
	 * @param int $author_id Author user id.
	 * @return array{like:int,dislike:int,report:int,like_weight:float,dislike_weight:float}
	 */
	public static function weighted_counts_for_author( $author_id ) {
		global $wpdb;

		// 반응자의 캐시된 신용도 가중치 (유저 메타 Trust::META_CREDIBILITY). 없으면 0.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.type AS type, COUNT(*) AS cnt, COALESCE( SUM( um.meta_value + 0 ), 0 ) AS weight
				 FROM ' . self::table() . " r
				 INNER JOIN {$wpdb->posts} p ON p.ID = r.review_id
				 LEFT JOIN {$wpdb->usermeta} um ON um.user_id = r.user_id AND um.meta_key = %s
				 WHERE p.post_author = %d AND p.post_type = %s AND p.post_status = 'publish'
				 GROUP BY r.type",
				'brandtalk_trust_credibility',
				$author_id,
				'brandtalk_review'
			),
			ARRAY_A
		);

		$out = [
			'like'           => 0,
			'dislike'        => 0,
			'report'         => 0,
			'like_weight'    => 0.0,
			'dislike_weight' => 0.0,
		];

		foreach ( (array) $rows as $row ) {
			$type = $row['type'];

			if ( ! array_key_exists( $type, $out ) ) {
				continue;
			}

			$out[ $type ] = (int) $row['cnt'];

			if ( 'like' === $type ) {
				$out['like_weight'] = (float) $row['weight'];
			} elseif ( 'dislike' === $type ) {
				$out['dislike_weight'] = (float) $row['weight'];
			}
		}

		return $out;
	}

	/**
	 * Lists a user's reaction types on a review.
	 *
	 * @param int $user_id User.
	 * @param int $review_id Review.
	 * @return array
	 */
	public static function user_types( $user_id, $review_id ) {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return [];
		}

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT type FROM ' . self::table() . ' WHERE user_id = %d AND review_id = %d',
					$user_id,
					$review_id
				)
			)
		);
	}
}
