<?php
/**
 * Plugin Name: BrandTalk
 * Plugin URI: https://todaymeal.example/brandtalk
 * Description: 신뢰도 기반 별점·리뷰 서비스. 리뷰/반응/팔로우/인증 데이터 레이어 + REST API(brandtalk/v1).
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Version: 0.6.1
 * Author: todaymeal
 * Text Domain: brandtalk
 * Domain Path: /languages/
 *
 * 아키텍처: HivePress(1.7.x)의 코어/컴포넌트/컨피그/모델/컨트롤러 구조를 참고해 재작성.
 * 기능·정책: 브랜드톡 기능정의서 v0.3 + 정책정의서 v0.2 (§13.1 포함) 유지.
 *
 * @package BrandTalk
 */

defined( 'ABSPATH' ) || exit;

// Define the core file.
if ( ! defined( 'BT_FILE' ) ) {
	define( 'BT_FILE', __FILE__ );
}

// Include the core class.
require_once __DIR__ . '/includes/class-core.php';

/**
 * Returns the BrandTalk core instance.
 *
 * @return \BrandTalk\Core
 */
function brandtalk() {
	return \BrandTalk\Core::instance();
}

// Initialize BrandTalk.
brandtalk();
