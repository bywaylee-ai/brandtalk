<?php
/**
 * Trust log model — 신뢰도 변동 감사 이력. 정책 §4.2.
 *
 * @package BrandTalk\Models
 */

namespace BrandTalk\Models;

defined( 'ABSPATH' ) || exit;

/**
 * wp_brandtalk_trust_log.
 */
class Trust_Log extends Model {

	/**
	 * Table name.
	 *
	 * @var string
	 */
	protected static $table_name = 'trust_log';

	/**
	 * Columns.
	 *
	 * @var array
	 */
	protected static $columns = [ 'user_id', 'review_id', 'source', 'delta', 'created_at' ];

	/**
	 * DDL.
	 *
	 * @param string $collate Charset collate.
	 * @return string
	 */
	public static function schema( $collate ) {
		$table = self::table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			review_id bigint(20) unsigned DEFAULT NULL,
			source varchar(20) NOT NULL,
			delta int(11) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) {$collate};";
	}

	/**
	 * Records a log row.
	 *
	 * @param int      $user_id Affected user.
	 * @param string   $source Event source.
	 * @param int      $delta Score delta.
	 * @param int|null $review_id Related review.
	 */
	public static function record( $user_id, $source, $delta, $review_id = null ) {
		( new self(
			[
				'user_id'   => (int) $user_id,
				'review_id' => $review_id ? (int) $review_id : null,
				'source'    => sanitize_key( $source ),
				'delta'     => (int) $delta,
			]
		) )->save();
	}

	/**
	 * Deletes log rows for a review.
	 *
	 * @param int $review_id Review.
	 */
	public static function purge_review( $review_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), [ 'review_id' => (int) $review_id ] );
	}
}
