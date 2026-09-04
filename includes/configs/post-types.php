<?php
/**
 * Post types configuration. Keys are unprefixed (brandtalk_ added on register).
 *
 * @package BrandTalk\Configs
 */

defined( 'ABSPATH' ) || exit;

return [
	'review' => [
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'show_in_rest'    => true,
		'menu_icon'       => 'dashicons-star-filled',
		'has_archive'     => false,
		'rewrite'         => false,
		'capability_type' => 'post',
		'map_meta_cap'    => true,
		'supports'        => [ 'editor', 'author', 'thumbnail', 'custom-fields' ],

		'labels'          => [
			'name'          => _x( '리뷰', 'post type general name', 'brandtalk' ),
			'singular_name' => _x( '리뷰', 'post type singular name', 'brandtalk' ),
			'menu_name'     => _x( '브랜드톡', 'admin menu', 'brandtalk' ),
			// 첫 서브메뉴(전체 목록) 라벨을 "브랜드톡" 으로 — 리뷰 + 별점 등록 글이 함께 보이는 통합 목록.
			'all_items'     => __( '브랜드톡', 'brandtalk' ),
			'add_new_item'  => __( '리뷰 추가', 'brandtalk' ),
			'edit_item'     => __( '리뷰 편집', 'brandtalk' ),
			'search_items'  => __( '리뷰 검색', 'brandtalk' ),
			'not_found'     => __( '항목이 없습니다.', 'brandtalk' ),
		],
	],
];
