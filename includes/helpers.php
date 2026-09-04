<?php
/**
 * Helper functions (HivePress\Helpers 패턴 참고).
 *
 * @package BrandTalk
 */

namespace BrandTalk\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the BrandTalk prefix to a name (or array of names).
 *
 * @param string|array $names Name(s).
 * @return string|array
 */
function prefix( $names ) {
	if ( is_array( $names ) ) {
		return array_map( __NAMESPACE__ . '\\prefix', $names );
	}

	return 'brandtalk_' . $names;
}

/**
 * Removes the BrandTalk prefix.
 *
 * @param string|array $names Name(s).
 * @return string|array
 */
function unprefix( $names ) {
	if ( is_array( $names ) ) {
		return array_map( __NAMESPACE__ . '\\unprefix', $names );
	}

	return preg_replace( '/^brandtalk_/', '', $names );
}

/**
 * Gets an array value by key with a default.
 *
 * @param mixed  $array Source.
 * @param string $key Key.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_array_value( $array, $key, $default = null ) {
	if ( is_array( $array ) && array_key_exists( $key, $array ) ) {
		return $array[ $key ];
	}

	return $default;
}

/**
 * Gets the first value of an array.
 *
 * @param mixed $array Source.
 * @param mixed $default Default.
 * @return mixed
 */
function get_first_array_value( $array, $default = null ) {
	if ( is_array( $array ) && $array ) {
		return reset( $array );
	}

	return $default;
}

/**
 * Recursively merges arrays (later values win, numeric keys append).
 *
 * @return array
 */
function merge_arrays() {
	$merged = [];

	foreach ( func_get_args() as $array ) {
		foreach ( (array) $array as $key => $value ) {
			if ( is_int( $key ) ) {
				$merged[] = $value;
			} elseif ( isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) && is_array( $value ) ) {
				$merged[ $key ] = merge_arrays( $merged[ $key ], $value );
			} else {
				$merged[ $key ] = $value;
			}
		}
	}

	return $merged;
}

/**
 * Sorts an array of arrays by their `_order` key.
 *
 * @param array $array Source.
 * @return array
 */
function sort_array( $array ) {
	$array = (array) $array;

	uasort(
		$array,
		function ( $a, $b ) {
			return get_array_value( (array) $a, '_order', 100 ) <=> get_array_value( (array) $b, '_order', 100 );
		}
	);

	return $array;
}

/**
 * Sanitizes a config/slug name (dashes and lowercase).
 *
 * @param string $text Text.
 * @return string
 */
function sanitize_slug( $text ) {
	return str_replace( [ '_', ' ' ], '-', strtolower( trim( $text ) ) );
}

/**
 * Gets the short class name.
 *
 * @param string|object $class Class or instance.
 * @return string
 */
function get_class_name( $class ) {
	if ( is_object( $class ) ) {
		$class = get_class( $class );
	}

	return strtolower( ( new \ReflectionClass( $class ) )->getShortName() );
}

/**
 * Instantiates a class if it exists and is concrete.
 *
 * @param string $class FQCN.
 * @param array  $args Constructor args (spread).
 * @return object|null
 */
function create_class_instance( $class, $args = [] ) {
	if ( ! class_exists( $class ) || ( new \ReflectionClass( $class ) )->isAbstract() ) {
		return null;
	}

	return $args ? new $class( ...$args ) : new $class();
}

/**
 * True during a REST request.
 *
 * @return bool
 */
function is_rest() {
	return defined( 'REST_REQUEST' ) && REST_REQUEST;
}

/**
 * Builds a REST success response: { "data": ... }
 *
 * @param int   $code HTTP status.
 * @param mixed $data Payload.
 * @return \WP_REST_Response
 */
function rest_response( $code, $data = null ) {
	if ( is_null( $data ) ) {
		return new \WP_REST_Response( (object) [], $code );
	}

	return new \WP_REST_Response( [ 'data' => $data ], $code );
}

/**
 * Builds a REST error response: { "error": { "code": N, "errors": [ { "message": ... } ] } }
 *
 * @param int          $code HTTP status.
 * @param string|array $errors Message(s).
 * @return \WP_REST_Response
 */
function rest_error( $code, $errors = [] ) {
	$error = [ 'code' => $code ];

	if ( $errors ) {
		$error['errors'] = array_map(
			function ( $message ) {
				return [ 'message' => $message ];
			},
			(array) $errors
		);
	}

	return new \WP_REST_Response( [ 'error' => $error ], $code );
}
