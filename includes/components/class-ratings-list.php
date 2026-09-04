<?php
/**
 * Ratings list component — "브랜드톡"(brandtalk_review CPT) 목록 한 곳에서
 * 리뷰 글 + 연동 포스트 타입(별점이 등록된 글)을 함께 보여준다.
 *
 * 별도 "브랜드톡 목록" 서브메뉴는 없애고, 기본 CPT 목록 화면을 확장한다.
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Extends the brandtalk_review admin list into a unified view.
 */
final class Ratings_List extends Component {

	const SCREEN     = 'edit-brandtalk_review';
	const META_RATING = 'brandtalk_rating';
	const META_CRIT   = 'brandtalk_criteria_ratings';

	/**
	 * Boot hooks (admin only).
	 */
	protected function boot() {
		if ( ! is_admin() ) {
			return;
		}

		// 목록 쿼리 확장.
		add_action( 'pre_get_posts', [ $this, 'expand_query' ] );
		add_filter( 'posts_where', [ $this, 'unified_where' ], 10, 2 );
		add_action( 'wp', [ $this, 'restore_post_type_global' ] );

		// 컬럼. (렌더는 행의 실제 post_type 기준 훅으로 오므로 제네릭 훅에 스크린 가드.)
		add_filter( 'manage_' . Review::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
		add_action( 'manage_posts_custom_column', [ $this, 'render_column_guarded' ], 10, 2 );
		add_action( 'manage_pages_custom_column', [ $this, 'render_column_guarded' ], 10, 2 );
		add_filter( 'manage_edit-' . Review::POST_TYPE . '_sortable_columns', [ $this, 'sortable_columns' ] );

		// 필터 바 (종류 / 카테고리).
		add_action( 'restrict_manage_posts', [ $this, 'render_filters' ] );

		// 상태 뷰 카운트 보정.
		add_filter( 'views_' . self::SCREEN, [ $this, 'fix_views' ] );
	}

	/* ------------------------------------------------------------------ */
	/* 쿼리 확장                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * 연동 포스트 타입 여부.
	 *
	 * @return array
	 */
	private function extra_types() {
		return Post_Type::enabled_post_types();
	}

	/**
	 * brandtalk_review 목록 메인 쿼리에 연동 포스트 타입을 합친다.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function expand_query( $query ) {
		// 관리자 edit.php?post_type=brandtalk_review 목록 쿼리에만 적용.
		// (admin 에서 is_main_query() 는 신뢰할 수 없으므로 pagenow + post_type 으로 판정.)
		if ( ! is_admin() || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}

		if ( Review::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// 별점 정렬.
		if ( 'brandtalk_rating' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', self::META_RATING );
			$query->set( 'orderby', 'meta_value_num' );
		}

		// 카테고리 필터.
		$cat = isset( $_GET['bt_category'] ) ? sanitize_text_field( wp_unslash( $_GET['bt_category'] ) ) : '';
		if ( '' !== $cat && '0' !== $cat ) {
			$query->set(
				'tax_query',
				[
					[
						'taxonomy' => Criterion::CATEGORY_TAX,
						'field'    => is_numeric( $cat ) ? 'term_id' : 'slug',
						'terms'    => $cat,
					],
				]
			);
		}

		// 종류 필터.
		$type  = isset( $_GET['bt_type'] ) ? sanitize_key( $_GET['bt_type'] ) : '';
		$extra = $this->extra_types();

		if ( Review::POST_TYPE === $type ) {
			$query->set( 'post_type', [ Review::POST_TYPE ] );
			return;
		}

		if ( $type && in_array( $type, $extra, true ) ) {
			// 특정 연동 포스트 타입: 별점이 있는 글만.
			$query->set( 'post_type', [ $type ] );
			$query->set(
				'meta_query',
				[
					'relation' => 'OR',
					[ 'key' => self::META_RATING, 'compare' => 'EXISTS' ],
					[ 'key' => self::META_CRIT, 'compare' => 'EXISTS' ],
				]
			);
			return;
		}

		// 필터 없음: 리뷰 CPT + 연동 포스트 타입 통합.
		if ( $extra ) {
			$query->set( 'post_type', array_merge( [ Review::POST_TYPE ], $extra ) );
			$query->set( 'brandtalk_unified', true );
		}
	}

	/**
	 * expand_query() 가 목록 화면 메인 쿼리의 post_type 을 배열로 바꾸면,
	 * 쿼리 실행 후 WP::register_globals() 가 그 배열을 전역 $post_type 에 복사한다.
	 * 코어 wp-admin/edit.php 는 이 값을 문자열로 이어붙이므로
	 * ("edit_{$post_type}_per_page") "Array to string conversion" 경고가 발생하고,
	 * 그 출력이 헤더 전송보다 먼저 나가 "headers already sent" 로 번진다.
	 *
	 * 목록 결과(배열 post_type)는 그대로 두고, 쿼리 직후 전역만 원래 CPT 슬러그로
	 * 되돌린다. `wp` 액션은 register_globals() 직후에 실행된다.
	 */
	public function restore_post_type_global() {
		if ( is_array( $GLOBALS['post_type'] ?? null ) ) {
			$GLOBALS['post_type'] = Review::POST_TYPE;
		}
	}

	/**
	 * 통합 쿼리에서 "리뷰 CPT 전체 OR 별점 있는 연동 글" 조건을 WHERE 에 추가.
	 *
	 * @param string    $where WHERE clause.
	 * @param \WP_Query  $query Query.
	 * @return string
	 */
	public function unified_where( $where, $query ) {
		if ( ! $query->get( 'brandtalk_unified' ) ) {
			return $where;
		}

		global $wpdb;

		$extra = $this->extra_types();

		if ( ! $extra ) {
			return $where;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $extra ), '%s' ) );

		$clause = $wpdb->prepare(
			" AND ( {$wpdb->posts}.post_type = %s
				OR ( {$wpdb->posts}.post_type IN ($placeholders)
					AND EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} btm
						WHERE btm.post_id = {$wpdb->posts}.ID
						AND btm.meta_key IN ( %s, %s )
					)
				)
			)",
			array_merge( [ Review::POST_TYPE ], $extra, [ self::META_RATING, self::META_CRIT ] )
		);

		return $where . $clause;
	}

	/* ------------------------------------------------------------------ */
	/* 컬럼                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$show_type = ! empty( $this->extra_types() );
		$new       = [];

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				if ( $show_type ) {
					$new['btrl_type'] = __( '종류', 'brandtalk' );
				}
				$new['btrl_rating']   = __( '별점', 'brandtalk' );
				$new['btrl_criteria'] = __( '하위 별점 평균', 'brandtalk' );
			}
		}

		return $new;
	}

	/**
	 * 제네릭 컬럼 훅 — 이 스크린에서만 동작.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post id.
	 */
	public function render_column_guarded( $column, $post_id ) {
		if ( ! in_array( $column, [ 'btrl_type', 'btrl_rating', 'btrl_criteria' ], true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit-' . Review::POST_TYPE !== $screen->id ) {
			return;
		}

		if ( 'btrl_type' === $column ) {
			$obj = get_post_type_object( get_post_type( $post_id ) );
			echo esc_html( $obj ? $obj->labels->singular_name : get_post_type( $post_id ) );
		} elseif ( 'btrl_rating' === $column ) {
			$rating = Frontend::get_rating( $post_id );
			echo $rating >= 1
				? '<span title="' . esc_attr( $rating . ' / 5' ) . '">' . esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ) . '</span> ' . esc_html( number_format( $rating, 1 ) )
				: '<span aria-hidden="true">—</span>';
		} elseif ( 'btrl_criteria' === $column ) {
			$data = Frontend::criteria_average( $post_id );
			echo $data['count'] >= 1
				? '<strong>' . esc_html( number_format( $data['avg'], 1 ) ) . '</strong> <span style="opacity:.6">(' . esc_html( (string) $data['count'] ) . '개)</span>'
				: '<span aria-hidden="true">—</span>';
		}
	}

	/**
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( $columns ) {
		$columns['btrl_rating'] = 'brandtalk_rating';

		return $columns;
	}

	/* ------------------------------------------------------------------ */
	/* 필터 바 & 상태 뷰                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $post_type Current screen post type.
	 */
	public function render_filters( $post_type ) {
		if ( Review::POST_TYPE !== $post_type ) {
			return;
		}

		$extra = $this->extra_types();

		if ( $extra ) {
			$sel  = isset( $_GET['bt_type'] ) ? sanitize_key( $_GET['bt_type'] ) : '';
			$list = array_merge( [ Review::POST_TYPE ], $extra );

			echo '<select name="bt_type"><option value="">' . esc_html__( '모든 종류', 'brandtalk' ) . '</option>';
			foreach ( $list as $pt ) {
				$obj = get_post_type_object( $pt );
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $pt ),
					selected( $sel, $pt, false ),
					esc_html( $obj ? $obj->labels->singular_name : $pt )
				);
			}
			echo '</select>';
		}

		$terms = get_terms( [ 'taxonomy' => Criterion::CATEGORY_TAX, 'hide_empty' => false ] );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$sel_cat = isset( $_GET['bt_category'] ) ? sanitize_text_field( wp_unslash( $_GET['bt_category'] ) ) : '';

			echo '<select name="bt_category"><option value="">' . esc_html__( '모든 브랜드톡 카테고리', 'brandtalk' ) . '</option>';
			foreach ( $terms as $t ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $t->slug ),
					selected( $sel_cat, $t->slug, false ),
					esc_html( $t->name )
				);
			}
			echo '</select>';
		}
	}

	/**
	 * 통합 목록이므로 상태별 서브뷰 카운트가 어긋난다 — "전체" 만 실제 수로 보정하고
	 * 상태별 뷰는 감춘다(휴지통은 유지).
	 *
	 * @param array $views Status views.
	 * @return array
	 */
	public function fix_views( $views ) {
		if ( ! $this->extra_types() ) {
			return $views;
		}

		$total = $this->unified_total();

		$current = ( empty( $_GET['post_status'] ) || 'all' === $_GET['post_status'] ) ? ' class="current"' : '';
		$url     = admin_url( 'edit.php?post_type=' . Review::POST_TYPE );

		$new = [
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$current,
				esc_html__( '전체', 'brandtalk' ),
				number_format_i18n( $total )
			),
		];

		if ( isset( $views['trash'] ) ) {
			$new['trash'] = $views['trash'];
		}

		return $new;
	}

	/**
	 * 통합 목록 총 개수(휴지통 제외).
	 *
	 * @return int
	 */
	private function unified_total() {
		$extra = $this->extra_types();

		$query = new \WP_Query(
			[
				'post_type'              => array_merge( [ Review::POST_TYPE ], $extra ),
				'post_status'            => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'brandtalk_unified'      => true,
			]
		);

		return (int) $query->found_posts;
	}
}
