<?php
/**
 * Taxonomies configuration.
 *
 * post_type 는 unprefixed 슬러그 배열. Post_Type 컴포넌트가 프리픽스를 붙이고,
 * §13.1 연동 설정(enabled_post_types)에 등록된 포스트 타입을 추가 부착한다.
 *
 * @package BrandTalk\Configs
 */

defined( 'ABSPATH' ) || exit;

return [
	'category' => [
		'post_type'         => [ 'review' ],
		'public'            => false,
		'show_ui'           => true,
		'show_in_rest'      => true,
		// §13.1.1 포스트 카테고리에 하위항목으로 연결 → 계층형.
		'hierarchical'      => true,
		'show_admin_column' => true,
		'rewrite'           => false,

		'labels'            => [
			'name'          => _x( '브랜드톡 카테고리', 'taxonomy general name', 'brandtalk' ),
			'singular_name' => _x( '브랜드톡 카테고리', 'taxonomy singular name', 'brandtalk' ),
			'add_new_item'  => __( '카테고리 추가', 'brandtalk' ),
			'edit_item'     => __( '카테고리 편집', 'brandtalk' ),
			'parent_item'   => __( '상위 카테고리', 'brandtalk' ),
			'search_items'  => __( '카테고리 검색', 'brandtalk' ),
		],
	],

	// 재사용 가능한 하위 별점 항목(예: 맛·가격·분위기). 카테고리에 연결되고,
	// 여러 카테고리에서 공유된다. 카테고리↔항목 연결은 텀 메타 `brandtalk_criteria`.
	'criterion' => [
		'post_type'         => [ 'review' ],
		'_attach_enabled'   => false, // 연동 포스트 타입에는 부착하지 않음(분류가 아니라 평가 항목 어휘).
		'public'            => false,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'hierarchical'      => false,
		'show_admin_column' => false,
		'meta_box_cb'       => false, // 리뷰 편집화면에 기본 메타박스 노출 안 함.
		'rewrite'           => false,

		'labels'            => [
			'name'          => _x( '하위 별점 항목', 'taxonomy general name', 'brandtalk' ),
			'singular_name' => _x( '하위 별점 항목', 'taxonomy singular name', 'brandtalk' ),
			'menu_name'     => __( '하위 별점 항목', 'brandtalk' ),
			'all_items'     => __( '모든 하위 별점 항목', 'brandtalk' ),
			'add_new_item'  => __( '하위 별점 항목 추가', 'brandtalk' ),
			'edit_item'     => __( '하위 별점 항목 편집', 'brandtalk' ),
			'update_item'   => __( '하위 별점 항목 갱신', 'brandtalk' ),
			'new_item_name' => __( '새 하위 별점 항목 이름', 'brandtalk' ),
			'search_items'  => __( '하위 별점 항목 검색', 'brandtalk' ),
			'not_found'     => __( '하위 별점 항목이 없습니다.', 'brandtalk' ),
		],
	],
];
