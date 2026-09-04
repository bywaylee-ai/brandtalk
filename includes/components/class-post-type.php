<?php
/**
 * Post type component — registers post types, taxonomies and post meta from config.
 * §13.1: 연동 포스트 타입에 브랜드톡 카테고리·별점 메타를 부착한다.
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Registers post types & taxonomies.
 */
final class Post_Type extends Component {

	const META_RATING = 'brandtalk_rating';

	/**
	 * §1.1.2.1 기본 시드 카테고리.
	 *
	 * @var array
	 */
	const DEFAULT_CATEGORIES = [
		'restaurant' => '맛집',
		'movie'      => '영화',
		'music'      => '음악',
		'news'       => '뉴스',
	];

	/**
	 * Boot hooks.
	 */
	protected function boot() {
		// 우선순위 20: 서드파티 CPT(예: HivePress hp_listing, init:10)가 등록된 뒤
		// 연동 포스트 타입에 택소노미·메타를 부착할 수 있도록.
		add_action( 'init', [ $this, 'register_post_types' ], 20 );
		add_action( 'init', [ $this, 'register_taxonomies' ], 20 );
		add_action( 'init', [ $this, 'register_meta' ], 20 );

		// 활성화 시 기본 카테고리 시드.
		add_action( 'brandtalk/v1/activate', [ $this, 'seed_categories' ], 20 );
	}

	/**
	 * Registers post types from includes/configs/post-types.php
	 */
	public function register_post_types() {
		foreach ( brandtalk()->get_config( 'post-types' ) as $name => $args ) {
			register_post_type( bt\prefix( $name ), $args );
		}
	}

	/**
	 * Registers taxonomies, attaching them to review + enabled post types.
	 */
	public function register_taxonomies() {
		foreach ( brandtalk()->get_config( 'taxonomies' ) as $name => $args ) {
			$object_types = array_map( 'BrandTalk\\Helpers\\prefix', (array) bt\get_array_value( $args, 'post_type', [] ) );

			// 기본적으로 §13.1 연동 포스트 타입에도 부착. `_attach_enabled => false` 면 제외.
			if ( false !== bt\get_array_value( $args, '_attach_enabled', true ) ) {
				$object_types = array_merge( $object_types, self::enabled_post_types() );
			}

			$object_types = array_values( array_unique( $object_types ) );

			unset( $args['post_type'], $args['_attach_enabled'] );

			register_taxonomy( bt\prefix( $name ), $object_types, $args );
		}
	}

	/**
	 * Registers the rating post meta for review + enabled post types.
	 */
	public function register_meta() {
		$types = array_values( array_unique( array_merge( [ bt\prefix( 'review' ) ], self::enabled_post_types() ) ) );

		foreach ( $types as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_RATING,
				[
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => [ __CLASS__, 'sanitize_rating' ],
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				]
			);
		}
	}

	/**
	 * §2.1.4 별점은 1~5 범위를 벗어날 수 없다.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_rating( $value ) {
		return max( 1, min( 5, (int) $value ) );
	}

	/**
	 * True if the value is a valid 1–5 rating.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_valid_rating( $value ) {
		return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 5;
	}

	/**
	 * §13.1.2 연동이 켜진 포스트 타입 슬러그(프리픽스 포함 실제 슬러그).
	 *
	 * @return array
	 */
	public static function enabled_post_types() {
		$types = bt\get_array_value( (array) get_option( 'brandtalk_options', [] ), 'enabled_post_types', [] );

		if ( ! is_array( $types ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'sanitize_key', $types ), 'post_type_exists' ) );
	}

	/**
	 * Seeds the default categories on activation.
	 */
	public function seed_categories() {
		$taxonomy = bt\prefix( 'category' );

		foreach ( self::DEFAULT_CATEGORIES as $slug => $name ) {
			if ( ! term_exists( $slug, $taxonomy ) ) {
				wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
			}
		}
	}
}
