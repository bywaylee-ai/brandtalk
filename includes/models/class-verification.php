<?php
/**
 * Verification model. 정책 §8.
 *
 * @package BrandTalk\Models
 */

namespace BrandTalk\Models;

defined( 'ABSPATH' ) || exit;

/**
 * wp_brandtalk_verifications.
 */
class Verification extends Model {

	const TYPES  = [ 'region', 'friend', 'social', 'info', 'category' ];
	const STATUS = [ 'pending', 'verified', 'rejected' ];

	/**
	 * Table name.
	 *
	 * @var string
	 */
	protected static $table_name = 'verifications';

	/**
	 * Columns.
	 *
	 * @var array
	 */
	protected static $columns = [ 'user_id', 'type', 'status', 'payload', 'created_at', 'updated_at' ];

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
			type varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			payload longtext DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_type (user_id,type),
			KEY user_status (user_id,status)
		) {$collate};";
	}

	/**
	 * Whether a type is valid.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function is_valid_type( $type ) {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * All verifications for a user (newest first).
	 *
	 * @param int $user_id User.
	 * @return Verification[]
	 */
	public static function for_user( $user_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC',
				$user_id
			),
			ARRAY_A
		);

		return array_map(
			function ( $row ) {
				return new self( $row );
			},
			(array) $rows
		);
	}

	/**
	 * Whether the user has a verified region record.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function has_verified_region( $user_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . " WHERE user_id = %d AND type = 'region' AND status = 'verified'",
				$user_id
			)
		);
	}

	/**
	 * Whether the user has a pending record of the given type.
	 *
	 * @param int    $user_id User.
	 * @param string $type Type.
	 * @return bool
	 */
	public static function has_pending( $user_id, $type ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . " WHERE user_id = %d AND type = %s AND status = 'pending'",
				$user_id,
				$type
			)
		);
	}

	/**
	 * Public-facing array representation (payload decoded).
	 *
	 * @return array
	 */
	public function to_public_array() {
		$payload = $this->get( 'payload' );

		return [
			'id'         => $this->get_id(),
			'type'       => $this->get( 'type' ),
			'status'     => $this->get( 'status' ),
			'payload'    => $payload ? json_decode( $payload, true ) : null,
			'created_at' => $this->get( 'created_at' ),
			'updated_at' => $this->get( 'updated_at' ),
		];
	}
}
