<?php
/**
 * Abstract model — 커스텀 테이블용 경량 액티브레코드.
 * HivePress\Models\Model 의 개념(테이블 매핑 + 속성 접근자)만 축약해 반영.
 *
 * @package BrandTalk\Models
 */

namespace BrandTalk\Models;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Base model.
 */
abstract class Model {

	/**
	 * Unprefixed table name — subclasses override.
	 *
	 * @var string
	 */
	protected static $table_name = '';

	/**
	 * Column list — subclasses override.
	 *
	 * @var array
	 */
	protected static $columns = [];

	/**
	 * Row id.
	 *
	 * @var int|null
	 */
	protected $id;

	/**
	 * Attribute values.
	 *
	 * @var array
	 */
	protected $attributes = [];

	/**
	 * Constructor.
	 *
	 * @param array $attributes Initial attributes.
	 */
	public function __construct( $attributes = [] ) {
		$this->fill( $attributes );
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'brandtalk_' . static::$table_name;
	}

	/**
	 * Column names.
	 *
	 * @return array
	 */
	public static function columns() {
		return static::$columns;
	}

	/**
	 * Mass-assigns attributes.
	 *
	 * @param array $attributes Attributes.
	 * @return static
	 */
	public function fill( $attributes ) {
		foreach ( (array) $attributes as $key => $value ) {
			if ( 'id' === $key ) {
				$this->id = (int) $value;
			} else {
				$this->attributes[ $key ] = $value;
			}
		}

		return $this;
	}

	/**
	 * Gets the row id.
	 *
	 * @return int|null
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Gets an attribute.
	 *
	 * @param string $key Attribute.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return bt\get_array_value( $this->attributes, $key, $default );
	}

	/**
	 * Sets an attribute.
	 *
	 * @param string $key Attribute.
	 * @param mixed  $value Value.
	 * @return static
	 */
	public function set( $key, $value ) {
		$this->attributes[ $key ] = $value;

		return $this;
	}

	/**
	 * Array representation (id + attributes).
	 *
	 * @return array
	 */
	public function to_array() {
		return array_merge( [ 'id' => $this->id ], $this->attributes );
	}

	/**
	 * Inserts or updates the row, returns the id.
	 *
	 * @return int
	 */
	public function save() {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = [];

		foreach ( static::$columns as $column ) {
			if ( array_key_exists( $column, $this->attributes ) ) {
				$data[ $column ] = $this->attributes[ $column ];
			}
		}

		if ( in_array( 'updated_at', static::$columns, true ) ) {
			$data['updated_at'] = $now;
		}

		if ( $this->id ) {
			$wpdb->update( static::table(), $data, [ 'id' => $this->id ] );
		} else {
			if ( in_array( 'created_at', static::$columns, true ) && ! isset( $data['created_at'] ) ) {
				$data['created_at'] = $now;
			}

			$wpdb->insert( static::table(), $data );
			$this->id = (int) $wpdb->insert_id;
		}

		return $this->id;
	}

	/**
	 * Deletes the row.
	 *
	 * @return bool
	 */
	public function delete() {
		global $wpdb;

		if ( ! $this->id ) {
			return false;
		}

		return (bool) $wpdb->delete( static::table(), [ 'id' => $this->id ] );
	}

	/**
	 * Hydrates a model from a DB row.
	 *
	 * @param array|object|null $row Row.
	 * @return static|null
	 */
	protected static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}

		return new static( (array) $row );
	}
}
