<?php
/**
 * Meta boxes configuration. §13.1.3 별점 항목.
 *
 * @package BrandTalk\Configs
 */

defined( 'ABSPATH' ) || exit;

return [
	// 리뷰 대상: 아직 리뷰가 없는 기존 글 1개를 연결. 리뷰 제목은 연결된 글 제목을 따른다.
	// review CPT 전용 (연동 포스트타입에는 붙이지 않음).
	'target'  => [
		'title'          => __( '리뷰 대상', 'brandtalk' ),
		'screen'         => [ 'review' ],
		'attach_enabled' => false,
		'context'        => 'side',
		'priority'       => 'high',

		'fields'         => [
			'brandtalk_target' => [
				'label'       => __( '대상 글', 'brandtalk' ),
				'description' => __( '이 리뷰가 다루는 글입니다. 리뷰 제목은 선택한 글의 제목으로 저장됩니다. 이미 별점이 등록됐거나 다른 리뷰가 연결된 글은 목록에 나오지 않습니다.', 'brandtalk' ),
				'type'        => 'target_post',
			],
		],
	],

	'rating' => [
		'title'      => __( '브랜드톡 별점', 'brandtalk' ),
		'screen'     => [ 'review' ], // + enabled_post_types (Admin 컴포넌트가 병합)
		'context'    => 'side',
		'priority'   => 'default',

		'fields'     => [
			'brandtalk_rating'           => [
				'label'       => __( '종합 별점', 'brandtalk' ),
				'description' => __( '브랜드톡 카테고리에 연결되는 별점(1~5).', 'brandtalk' ),
				'type'        => 'rating',
				'min'         => 1,
				'max'         => 5,
			],

			// 지정된 브랜드톡 카테고리의 하위 별점 항목별 점수 (JSON: {criterion_id: 1~5}).
			'brandtalk_criteria_ratings' => [
				'label'       => __( '하위 별점', 'brandtalk' ),
				'description' => __( '지정한 브랜드톡 카테고리에 연결된 하위 항목별 별점입니다.', 'brandtalk' ),
				'type'        => 'criteria_rating',
			],
		],
	],
];
