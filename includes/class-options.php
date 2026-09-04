<?php
/**
 * Policy option accessor. 단일 옵션 `brandtalk_options` (배열) + 설정 컨피그 기본값 병합.
 *
 * @package BrandTalk
 */

namespace BrandTalk;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Reads policy settings.
 */
final class Options {

	const OPTION_KEY = 'brandtalk_options';

	/**
	 * Config-derived + hard-coded defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = [];

		// From settings config (flat merge of all section fields).
		foreach ( brandtalk()->get_config( 'settings' ) as $tab ) {
			foreach ( bt\get_array_value( $tab, 'sections', [] ) as $section ) {
				foreach ( bt\get_array_value( $section, 'fields', [] ) as $key => $field ) {
					$defaults[ $key ] = bt\get_array_value( $field, 'default' );
				}
			}
		}

		// Not exposed in the settings UI (filter `brandtalk/v1/options_defaults` to override).
		$defaults['trust_levels'] = [
			[ 'level' => 1, 'min' => 0, 'max' => 9 ],
			[ 'level' => 2, 'min' => 10, 'max' => 49 ],
			[ 'level' => 3, 'min' => 50, 'max' => 199 ],
			[ 'level' => 4, 'min' => 200, 'max' => 499 ],
			[ 'level' => 5, 'min' => 500, 'max' => PHP_INT_MAX ],
		];

		$defaults['providers'] = [
			'google'    => [ 'label' => 'Google', 'enabled' => false, 'client_id' => '', 'client_secret' => '' ],
			'apple'     => [ 'label' => 'Apple', 'enabled' => false, 'client_id' => '', 'client_secret' => '' ],
			'facebook'  => [ 'label' => 'Facebook', 'enabled' => false, 'client_id' => '', 'client_secret' => '' ],
			'kakao'     => [ 'label' => 'Kakao', 'enabled' => false, 'client_id' => '', 'client_secret' => '' ],
			'naver'     => [ 'label' => 'Naver', 'enabled' => false, 'client_id' => '', 'client_secret' => '' ],
			'wordpress' => [ 'label' => 'WordPress', 'enabled' => false, 'client_id' => '', 'client_secret' => '' ],
		];

		/**
		 * Filters the default policy option values.
		 *
		 * @hook brandtalk/v1/options_defaults
		 * @param {array} $defaults Defaults.
		 * @return {array} Defaults.
		 */
		return apply_filters( 'brandtalk/v1/options_defaults', $defaults );
	}

	/**
	 * All options (stored merged over defaults).
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Single option value.
	 *
	 * @param string $key Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Ensures the option row exists on activation.
	 */
	public static function bootstrap() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
	}

	/* --- typed accessors --------------------------------------------- */

	/**
	 * §4.2.3.1 신고 감점(양수, 5~10).
	 *
	 * @return int
	 */
	public static function report_penalty() {
		return max( 5, min( 10, (int) self::get( 'report_penalty', 5 ) ) );
	}

	/**
	 * §4.3.1.2 신뢰 판정 임계값(0~1).
	 *
	 * @return float
	 */
	public static function trust_threshold() {
		$t = (float) self::get( 'trust_threshold', 0.51 );

		return ( $t <= 0 || $t >= 1 ) ? 0.51 : $t;
	}

	/**
	 * §3.1.4 랭킹 노출 상한.
	 *
	 * @return int
	 */
	public static function ranking_limit() {
		return max( 1, (int) self::get( 'ranking_limit', 50 ) );
	}

	/**
	 * §4.4.2 신뢰도 레벨 구간.
	 *
	 * @return array
	 */
	public static function trust_levels() {
		return (array) self::get( 'trust_levels', [] );
	}

	/**
	 * §13.1.2 연동 포스트 타입(존재하는 것만).
	 *
	 * @return array
	 */
	public static function enabled_post_types() {
		$types = self::get( 'enabled_post_types', [] );

		if ( ! is_array( $types ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'sanitize_key', $types ), 'post_type_exists' ) );
	}

	/**
	 * §6.2 소셜 제공자 공개 목록.
	 *
	 * @return array
	 */
	public static function providers_public() {
		$out = [];

		foreach ( (array) self::get( 'providers', [] ) as $slug => $p ) {
			$out[] = [
				'slug'    => $slug,
				'label'   => bt\get_array_value( $p, 'label', ucfirst( $slug ) ),
				'enabled' => ! empty( $p['enabled'] ),
			];
		}

		return $out;
	}

	/**
	 * 설정 화면에 노출할 후보 포스트 타입.
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function candidate_post_types() {
		$objects = get_post_types( [ 'public' => true ], 'objects' );

		unset( $objects['attachment'], $objects['brandtalk_review'] );

		return $objects;
	}
}
