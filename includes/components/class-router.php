<?php
/**
 * Router component — 컨트롤러의 라우트 정의를 REST 엔드포인트로 등록.
 * HivePress\Components\Router 의 REST 등록 로직 축약본.
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Registers REST routes.
 */
final class Router extends Component {

	const REST_NAMESPACE = 'brandtalk/v1';

	/**
	 * Merged route table.
	 *
	 * @var array
	 */
	protected $routes = [];

	/**
	 * Boot hooks.
	 */
	protected function boot() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Collects routes from every controller.
	 *
	 * @return array
	 */
	public function get_routes() {
		if ( empty( $this->routes ) ) {
			foreach ( brandtalk()->get_controllers() as $controller ) {
				$this->routes = bt\merge_arrays( $this->routes, $controller->get_routes() );
			}

			/**
			 * Filters the full REST route table.
			 *
			 * @hook brandtalk/v1/routes
			 * @param {array} $routes Route definitions.
			 * @return {array} Route definitions.
			 */
			$this->routes = apply_filters( 'brandtalk/v1/routes', $this->routes );
		}

		return $this->routes;
	}

	/**
	 * Composes a route path, walking `base` references.
	 *
	 * @param string $name Route name.
	 * @return string
	 */
	protected function get_path( $name ) {
		$path   = '';
		$routes = $this->get_routes();
		$route  = bt\get_array_value( $routes, $name );

		while ( $route ) {
			if ( isset( $route['path'] ) ) {
				$path = $route['path'] . $path;
			}

			$route = isset( $route['base'] ) ? bt\get_array_value( $routes, $route['base'] ) : null;
		}

		return $path;
	}

	/**
	 * Registers all `rest => true` routes that have an action.
	 */
	public function register_rest_routes() {
		foreach ( $this->get_routes() as $name => $route ) {
			if ( empty( $route['rest'] ) || ! isset( $route['action'] ) ) {
				continue;
			}

			register_rest_route(
				self::REST_NAMESPACE,
				$this->get_path( $name ),
				[
					'methods'             => bt\get_array_value( $route, 'method', 'GET' ),
					'callback'            => $route['action'],
					'args'                => bt\get_array_value( $route, 'args', [] ),
					'permission_callback' => bt\get_array_value( $route, 'permission', '__return_true' ),
				]
			);
		}
	}
}
