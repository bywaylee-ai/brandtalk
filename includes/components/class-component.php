<?php
/**
 * Abstract component (HivePress\Components\Component 패턴).
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for components. Constructors register their own hooks.
 */
abstract class Component {

	/**
	 * Constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		foreach ( $args as $name => $value ) {
			$this->$name = $value;
		}

		$this->boot();
	}

	/**
	 * Optional bootstrap step for subclasses.
	 */
	protected function boot() {}
}
