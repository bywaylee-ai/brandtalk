<?php
/**
 * Settings configuration. 값은 단일 옵션 배열 `brandtalk_options` 에 저장된다
 * (정책 코드가 배열 접근자를 쓰던 방식 유지). §11.3 설정 가능 정책값 + §13.1.2.
 *
 * @package BrandTalk\Configs
 */

defined( 'ABSPATH' ) || exit;

return [
	'brandtalk' => [
		'title'    => __( '브랜드톡', 'brandtalk' ),
		'_order'   => 80,

		'sections' => [
			'scope'  => [
				'title'  => __( '적용 범위 (Post)', 'brandtalk' ),
				'_order' => 10,

				'fields' => [
					// §13.1.2 현재 사이트의 포스트 타입 체크박스.
					'enabled_post_types' => [
						'label'       => __( '연동 포스트 타입', 'brandtalk' ),
						'description' => __( '체크한 포스트 타입 편집화면에 “브랜드톡 카테고리”와 “브랜드톡 별점” 항목이 표시됩니다. (§13.1)', 'brandtalk' ),
						'type'        => 'post_types',
						'default'     => [],
						'_order'      => 10,
					],
				],
			],

			'policy' => [
				'title'  => __( '신뢰도·정책 값', 'brandtalk' ),
				'_order' => 20,

				'fields' => [
					// §4.2.3.1
					'report_penalty'  => [
						'label'       => __( '신고 감점', 'brandtalk' ),
						'description' => __( '신고 1건당 게시자 신뢰도 감점(절대값). 정책상 5~10.', 'brandtalk' ),
						'type'        => 'number',
						'default'     => 5,
						'min'         => 5,
						'max'         => 10,
						'_order'      => 10,
					],
					// §4.3.1.2
					'trust_threshold' => [
						'label'       => __( '신뢰 판정 임계값', 'brandtalk' ),
						'description' => __( '좋아요 ÷ (좋아요+싫어요) 가 이 값을 초과하면 “신뢰 높음”. 기본 0.51.', 'brandtalk' ),
						'type'        => 'number',
						'step'        => '0.01',
						'default'     => 0.51,
						'min'         => 0,
						'max'         => 1,
						'_order'      => 20,
					],
						// §4.2.7 리뷰어 신용도 가중치 구간 (순 좋아요 = 좋아요 − 싫어요).
						'credibility_net_lo' => [
							'label'       => __( '신용도 ±0.1 기준 (순 좋아요)', 'brandtalk' ),
							'description' => __( '리뷰어의 리뷰가 받은 (좋아요 − 싫어요) 합이 이 값 이상이면 신용도 +0.1, 음수로 이 값 이하이면 −0.1. 기본 5.', 'brandtalk' ),
							'type'        => 'number',
							'default'     => 5,
							'min'         => 1,
							'_order'      => 22,
						],
						// §4.2.7
						'credibility_net_hi' => [
							'label'       => __( '신용도 ±0.2 기준 (순 좋아요)', 'brandtalk' ),
							'description' => __( '(좋아요 − 싫어요) 합이 이 값 이상이면 신용도 +0.2, 음수로 이 값 이하이면 −0.2. ±0.1 기준보다 커야 한다. 기본 20.', 'brandtalk' ),
							'type'        => 'number',
							'default'     => 20,
							'min'         => 2,
							'_order'      => 24,
						],
						// §3.1.4
						'ranking_limit'   => [
						'label'   => __( '랭킹 노출 상한', 'brandtalk' ),
						'type'    => 'number',
						'default' => 50,
						'min'     => 1,
						'_order'  => 30,
					],
					// §8.3.2~8.3.3
					'region_lock'     => [
						'label'   => __( '지역 재인증 잠금', 'brandtalk' ),
						'caption' => __( '최초 지역 인증 확정 후 재신청을 차단(409)한다.', 'brandtalk' ),
						'type'    => 'checkbox',
						'default' => 1,
						'_order'  => 40,
					],
				],
			],
		],
	],
];
