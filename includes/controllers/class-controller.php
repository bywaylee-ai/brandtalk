<?php
/**
 * Abstract controller (HivePress\Controllers\Controller 패턴).
 *
 * @package BrandTalk\Controllers
 */

namespace BrandTalk\Controllers;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Base controller. Subclasses declare `$routes` in their constructor.
 */
abstract class Controller {

	/**
	 * Route definitions keyed by name.
	 *
	 * @var array
	 */
	protected $routes = [];

	/**
	 * Constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		foreach ( $args as $name => $value ) {
			$this->$name = $value;
		}
	}

	/**
	 * Returns the route definitions.
	 *
	 * @return array
	 */
	final public function get_routes() {
		return $this->routes;
	}

	/**
	 * Shorthand: require a logged-in user (route permission callback).
	 *
	 * @return true|\WP_Error
	 */
	public function require_login() {
		return is_user_logged_in() ? true : new \WP_Error( 'brandtalk_auth_required', '로그인이 필요합니다.', [ 'status' => 401 ] );
	}

	/**
	 * Converts a WP_Error (with a `status` data key) into a REST error response.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	protected function error_response( $error ) {
		$data   = $error->get_error_data();
		$status = (int) ( is_array( $data ) && isset( $data['status'] ) ? $data['status'] : 400 );

		return bt\rest_error( $status, $error->get_error_message() );
	}
}
