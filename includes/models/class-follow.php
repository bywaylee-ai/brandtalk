<?php
/**
 * Follow model. 정책 §10, §4.2.4.
 *
 * @package BrandTalk\Models
 */

namespace BrandTalk\Models;

defined( 'ABSPATH' ) || exit;

/**
 * wp_brandtalk_follows.
 */
class Follow extends Model {

	/**
	 * Table name.
	 *
	 * @var string
	 */
	protected static $table_name = 'follows';

	/**
	 * Columns.
	 *
	 * @var array
	 */
	protected static $columns = [ 'follower_id', 'following_id', 'created_at' ];

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
			follower_id bigint(20) unsigned NOT NULL,
			following_id bigint(20) unsigned NOT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY follow_pair (follower_id,following_id),
			KEY following_id (following_id)
		) {$collate};";
	}

	/**
	 * Whether follower already follows the target.
	 *
	 * @param int $follower_id Follower.
	 * @param int $following_id Target.
	 * @return bool
	 */
	public static function exists( $follower_id, $following_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE follower_id = %d AND following_id = %d',
				$follower_id,
				$following_id
			)
		);
	}

	/**
	 * Removes a follow relationship.
	 *
	 * @param int $follower_id Follower.
	 * @param int $following_id Target.
	 * @return int
	 */
	public static function remove( $follower_id, $following_id ) {
		global $wpdb;

		return (int) $wpdb->delete(
			self::table(),
			[
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
			]
		);
	}

	/**
	 * Follower count for a user.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function follower_count( $user_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE following_id = %d', $user_id )
		);
	}

	/**
	 * Count of users a user follows.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function following_count( $user_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE follower_id = %d', $user_id )
		);
	}

	/**
	 * IDs a user follows.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function following_ids( $user_id ) {
		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( 'SELECT following_id FROM ' . self::table() . ' WHERE follower_id = %d', $user_id )
			)
		);
	}

	/**
	 * Bulk follower counts for a set of user ids.
	 *
	 * @param array $user_ids User ids.
	 * @return array id => count
	 */
	public static function follower_counts( array $user_ids ) {
		global $wpdb;

		$user_ids = array_map( 'intval', $user_ids );

		if ( ! $user_ids ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT following_id, COUNT(*) AS cnt FROM ' . self::table() . " WHERE following_id IN ($placeholders) GROUP BY following_id",
				$user_ids
			),
			ARRAY_A
		);

		$counts = [];

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['following_id'] ] = (int) $row['cnt'];
		}

		return $counts;
	}
}
