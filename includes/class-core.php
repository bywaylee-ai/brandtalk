<?php
/**
 * BrandTalk core.
 *
 * HivePress\Core 패턴 참고: 오토로더 + 컴포넌트/컨피그 컨테이너 + 설치 훅.
 *
 * @package BrandTalk
 */

namespace BrandTalk;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Core container / bootstrap.
 */
final class Core {

	/**
	 * Single instance.
	 *
	 * @var Core
	 */
	protected static $instance;

	/**
	 * Loaded configuration arrays.
	 *
	 * @var array
	 */
	protected $configs = [];

	/**
	 * Instantiated objects grouped by type (components, controllers, ...).
	 *
	 * @var array
	 */
	protected $objects = [];

	/**
	 * Discovered class maps grouped by namespace.
	 *
	 * @var array
	 */
	protected $classes = [];

	/**
	 * Plugin version (read from the plugin header).
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Forbid cloning.
	 */
	protected function __clone() {}

	/**
	 * Forbid unserializing.
	 *
	 * @throws \BadMethodCallException Invalid method.
	 */
	public function __wakeup() {
		throw new \BadMethodCallException();
	}

	/**
	 * Constructor — wires the lifecycle hooks.
	 */
	protected function __construct() {

		// Autoload BrandTalk classes.
		spl_autoload_register( [ $this, 'autoload' ] );

		// Activation / deactivation.
		register_activation_hook( BT_FILE, [ __CLASS__, 'activate' ] );
		register_deactivation_hook( BT_FILE, [ __CLASS__, 'deactivate' ] );

		// Run install / upgrade routines late on init.
		add_action( 'init', [ $this, 'install' ], 10000 );

		// Boot components early.
		add_action( 'plugins_loaded', [ $this, 'setup' ], -10 );
	}

	/**
	 * Returns the single instance.
	 *
	 * @return Core
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * PSR-ish autoloader: \BrandTalk\Models\Reaction → includes/models/class-reaction.php
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public function autoload( $class ) {
		$parts = explode( '\\', str_replace( '_', '-', strtolower( $class ) ) );

		if ( count( $parts ) < 2 || reset( $parts ) !== 'brandtalk' ) {
			return;
		}

		$filename = 'class-' . end( $parts ) . '.php';

		array_shift( $parts );
		array_pop( $parts );

		$filepath = rtrim( __DIR__ . '/' . implode( '/', $parts ), '/' ) . '/' . $filename;

		if ( file_exists( $filepath ) ) {
			require_once $filepath;

			// Auto-run a static init() like HivePress does for models.
			if ( class_exists( $class, false ) ) {
				$ref = new \ReflectionClass( $class );

				if ( ! $ref->isAbstract() && $ref->hasMethod( 'init' ) && $ref->getMethod( 'init' )->isStatic() ) {
					call_user_func( [ $class, 'init' ] );
				}
			}
		}
	}

	/**
	 * Activation hook — set a flag, install() picks it up on init.
	 */
	public static function activate() {
		update_option( 'brandtalk_activated', '1' );
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {

		/**
		 * Fires on plugin deactivation.
		 *
		 * @hook brandtalk/v1/deactivate
		 */
		do_action( 'brandtalk/v1/deactivate' );
	}

	/**
	 * Install / upgrade routine (fires the activate/update actions once).
	 */
	public function install() {
		if ( get_option( 'brandtalk_activated' ) ) {

			/**
			 * Fires when the plugin is activated (schema install, seeding).
			 *
			 * @hook brandtalk/v1/activate
			 */
			do_action( 'brandtalk/v1/activate' );

			update_option( 'brandtalk_activated', '' );

			if ( ! get_option( 'brandtalk_installed_time' ) ) {
				update_option( 'brandtalk_installed_time', time() );
			}
		}

		$stored = get_option( 'brandtalk_version' );

		if ( ! $stored || version_compare( $stored, $this->get_version(), '<' ) ) {

			/**
			 * Fires when the plugin is updated to a newer version.
			 *
			 * @hook brandtalk/v1/update
			 * @param {string} $version Previously installed version.
			 */
			do_action( 'brandtalk/v1/update', (string) $stored );

			update_option( 'brandtalk_version', $this->get_version() );
		}
	}

	/**
	 * Boots helpers, textdomain and components.
	 */
	public function setup() {
		require_once __DIR__ . '/helpers.php';

		load_plugin_textdomain( 'brandtalk', false, dirname( plugin_basename( BT_FILE ) ) . '/languages' );

		// Instantiate all components (their constructors register hooks).
		$this->get_components();

		/**
		 * Fires once BrandTalk has finished booting.
		 *
		 * @hook brandtalk/v1/setup
		 */
		do_action( 'brandtalk/v1/setup' );
	}

	/**
	 * Magic accessor for object groups and configs.
	 *
	 * brandtalk()->get_components(), ->get_controllers(), ->get_version()
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @throws \BadMethodCallException Invalid method.
	 * @return mixed
	 */
	public function __call( $name, $args ) {
		if ( strpos( $name, 'get_' ) !== 0 ) {
			throw new \BadMethodCallException( esc_html( $name ) );
		}

		$property = substr( $name, 4 );

		if ( 'version' === $property ) {
			return $this->get_version();
		}

		// Treat the rest as an object group under includes/<group>/.
		if ( ! isset( $this->objects[ $property ] ) ) {
			$this->objects[ $property ] = [];

			foreach ( $this->get_classes( $property ) as $object_name => $class ) {
				$object = bt\create_class_instance( $class );

				if ( $object ) {
					$this->objects[ $property ][ $object_name ] = $object;
				}
			}
		}

		return $this->objects[ $property ];
	}

	/**
	 * Magic accessor: brandtalk()->trust returns the Trust component.
	 *
	 * @param string $name Component name.
	 * @return mixed
	 */
	public function __get( $name ) {
		return bt\get_array_value( $this->get_components(), $name );
	}

	/**
	 * Loads and merges a configuration array from includes/configs/<name>.php
	 *
	 * @param string $name Config name.
	 * @return array
	 */
	public function get_config( $name ) {
		if ( ! isset( $this->configs[ $name ] ) ) {
			$this->configs[ $name ] = [];

			$filepath = __DIR__ . '/configs/' . bt\sanitize_slug( $name ) . '.php';

			if ( file_exists( $filepath ) ) {
				$this->configs[ $name ] = (array) include $filepath;
			}

			/**
			 * Filters a BrandTalk configuration array. Dynamic part = config name
			 * (post-types, taxonomies, settings, meta-boxes).
			 *
			 * @hook brandtalk/v1/{config_name}
			 * @param {array} $config Configuration array.
			 * @return {array} Configuration array.
			 */
			$this->configs[ $name ] = apply_filters( 'brandtalk/v1/' . str_replace( '-', '_', $name ), $this->configs[ $name ] );
		}

		return $this->configs[ $name ];
	}

	/**
	 * Discovers concrete classes in includes/<namespace>/.
	 *
	 * @param string $namespace Directory / namespace segment.
	 * @return array name => FQCN
	 */
	public function get_classes( $namespace ) {
		if ( ! isset( $this->classes[ $namespace ] ) ) {
			$this->classes[ $namespace ] = [];

			foreach ( glob( __DIR__ . '/' . $namespace . '/*.php' ) as $filepath ) {
				$name  = str_replace( '-', '_', preg_replace( '/^class-/', '', basename( $filepath, '.php' ) ) );
				$class = '\\BrandTalk\\' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $namespace ) ) ) . '\\' . implode( '_', array_map( 'ucfirst', explode( '_', $name ) ) );

				if ( class_exists( $class ) && ! ( new \ReflectionClass( $class ) )->isAbstract() ) {
					$this->classes[ $namespace ][ $name ] = $class;
				}
			}
		}

		return $this->classes[ $namespace ];
	}

	/**
	 * Reads the plugin version from its header once.
	 *
	 * @return string
	 */
	public function get_version() {
		if ( is_null( $this->version ) ) {
			if ( ! function_exists( 'get_file_data' ) ) {
				require_once ABSPATH . 'wp-includes/functions.php';
			}

			$data          = get_file_data( BT_FILE, [ 'version' => 'Version' ] );
			$this->version = $data['version'] ? $data['version'] : '0.0.0';
		}

		return $this->version;
	}

	/**
	 * Absolute plugin directory path.
	 *
	 * @return string
	 */
	public function get_path() {
		return dirname( BT_FILE );
	}

	/**
	 * Plugin directory URL (no trailing slash).
	 *
	 * @return string
	 */
	public function get_url() {
		return rtrim( plugin_dir_url( BT_FILE ), '/' );
	}
}
