<?php
/**
 * Reviewers list component — 관리자 "브랜드톡 › 리뷰어" 목록.
 * 개인(리뷰어)별 리뷰 정보: ID / 이메일 / 닉네임 / 리뷰 수 / 리뷰 평균 점수 /
 * 순 좋아요 / 신용도(§4.2.7) / 신뢰도 점수·레벨(§4.2·§4.4) / 팔로워.
 *
 * "리뷰" 집계 범위: 브랜드톡 리뷰 CPT + 브랜드톡 별점(brandtalk_rating / brandtalk_criteria_ratings)이
 * 등록된 모든 포스트 타입의 글(글·페이지·향후 CPT 포함, attachment 제외). §13.1 참고.
 * 순 좋아요·신용도·신뢰도 점수는 리뷰 CPT 의 반응 기준(§4.2) — 인라인 별점 글은 리뷰 수·평균에만 반영.
 *
 * §11.2.6 "사용자·신뢰도 관리"의 조회 부분(강등 등 편집은 범위 밖).
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Models\Follow;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the per-reviewer admin list.
 */
final class Reviewers_List extends Component {

	const PAGE_SLUG = 'brandtalk-reviewers';
	const PER_PAGE  = 30;

	/**
	 * orderby 화이트리스트: 요청값 => SQL 식.
	 *
	 * @var array
	 */
	private $sortable = [
		'user_id'      => 'r.user_id',
		'user_email'   => 'r.user_email',
		'display_name' => 'r.display_name',
		'review_count' => 'r.review_count',
		'avg_rating'   => 'r.avg_rating',
		'net_likes'    => 'r.net_likes',
		'credibility'  => 'r.credibility',
		'trust_score'  => 'r.trust_score',
		'trust_level'  => 'r.trust_level',
	];

	/**
	 * Boot hooks (admin only).
	 */
	protected function boot() {
		if ( ! is_admin() ) {
			return;
		}

		// 설정(우선순위 10)보다 먼저 등록해 메뉴에서 위에 오도록.
		add_action( 'admin_menu', [ $this, 'add_menu' ], 9 );
	}

	/**
	 * Adds the "리뷰어" submenu under 브랜드톡.
	 */
	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . Review::POST_TYPE,
			__( '브랜드톡 리뷰어', 'brandtalk' ),
			__( '리뷰어', 'brandtalk' ),
			'list_users',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/* ------------------------------------------------------------------ */
	/* 쿼리                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * "리뷰"로 집계할 글: 브랜드톡 리뷰 CPT + 브랜드톡 별점(종합/하위)이 등록된
	 * 모든 포스트 타입의 글(글·페이지·향후 추가 CPT 포함, attachment 제외).
	 * 발행 상태만.
	 *
	 * @param string $alias {posts} 별칭.
	 * @return string SQL WHERE 조각(별칭 기준).
	 */
	private function reviewed_where( $alias ) {
		global $wpdb;

		// Review::POST_TYPE 는 하드코딩 슬러그(사용자 입력 아님) — 코드베이스 관례대로 직접 삽입.
		$cpt = "'" . Review::POST_TYPE . "'";

		return "{$alias}.post_status = 'publish' AND {$alias}.post_author > 0 AND (
			{$alias}.post_type = {$cpt}
			OR (
				{$alias}.post_type NOT IN ( {$cpt}, 'attachment' )
				AND EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} btm
					WHERE btm.post_id = {$alias}.ID
					AND btm.meta_key IN ( 'brandtalk_rating', 'brandtalk_criteria_ratings' )
				)
			)
		)";
	}

	/**
	 * 리뷰어(집계 대상 리뷰 1건 이상 작성자) 수.
	 *
	 * @return int
	 */
	private function total_reviewers() {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT( DISTINCT p.post_author )
			 FROM {$wpdb->posts} p
			 WHERE " . $this->reviewed_where( 'p' )
		);
	}

	/**
	 * 한 페이지 분량의 리뷰어 행.
	 *
	 * @param string $orderby SQL 식(화이트리스트 통과값).
	 * @param string $order    ASC|DESC.
	 * @param int    $offset   OFFSET.
	 * @return array
	 */
	private function fetch_rows( $orderby, $order, $offset ) {
		global $wpdb;

		$reactions = $wpdb->prefix . 'brandtalk_reactions';

		// r: 사용자 + 리뷰 집계(모든 포스트 타입) + 유저 메타(신뢰도/신용도) + 순 좋아요 서브쿼리.
		// net_likes/신용도/신뢰도 점수는 리뷰 CPT 반응 기준(§4.2). 인라인 별점 글은 리뷰 수·평균에만 반영.
		$sql = "
			SELECT r.* FROM (
				SELECT
					u.ID            AS user_id,
					u.user_email    AS user_email,
					u.display_name  AS display_name,
					agg.review_count,
					agg.avg_rating,
					COALESCE( nl.net_likes, 0 )        AS net_likes,
					COALESCE( ums.meta_value + 0, 0 )  AS trust_score,
					COALESCE( uml.meta_value + 0, 1 )  AS trust_level,
					COALESCE( umc.meta_value + 0, 0 )  AS credibility
				FROM (
					SELECT p.post_author AS user_id,
					       COUNT(*) AS review_count,
					       AVG( CASE WHEN pm.meta_value + 0 BETWEEN 1 AND 5 THEN pm.meta_value + 0 END ) AS avg_rating
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'brandtalk_rating'
					WHERE {$this->reviewed_where( 'p' )}
					GROUP BY p.post_author
				) agg
				INNER JOIN {$wpdb->users} u ON u.ID = agg.user_id
				LEFT JOIN (
					SELECT p2.post_author AS user_id,
					       SUM( CASE rx.type WHEN 'like' THEN 1 WHEN 'dislike' THEN -1 ELSE 0 END ) AS net_likes
					FROM {$wpdb->posts} p2
					INNER JOIN {$reactions} rx ON rx.review_id = p2.ID
					WHERE p2.post_type = '" . Review::POST_TYPE . "' AND p2.post_status = 'publish'
					GROUP BY p2.post_author
				) nl ON nl.user_id = agg.user_id
				LEFT JOIN {$wpdb->usermeta} ums ON ums.user_id = agg.user_id AND ums.meta_key = 'brandtalk_trust_score'
				LEFT JOIN {$wpdb->usermeta} uml ON uml.user_id = agg.user_id AND uml.meta_key = 'brandtalk_trust_level'
				LEFT JOIN {$wpdb->usermeta} umc ON umc.user_id = agg.user_id AND umc.meta_key = 'brandtalk_trust_credibility'
			) r
			ORDER BY {$orderby} {$order}, r.user_id ASC
			LIMIT %d OFFSET %d
		";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, self::PER_PAGE, (int) $offset ),
			ARRAY_A
		);

		return (array) $rows;
	}

	/* ------------------------------------------------------------------ */
	/* 렌더링                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Renders the list page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( '이 페이지에 접근할 권한이 없습니다.', 'brandtalk' ) );
		}

		$orderby_key = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'review_count';
		$order       = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';

		if ( ! isset( $this->sortable[ $orderby_key ] ) ) {
			$orderby_key = 'review_count';
		}

		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$total  = $this->total_reviewers();
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = min( $paged, $pages );
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$rows       = $this->fetch_rows( $this->sortable[ $orderby_key ], $order, $offset );
		$followers  = Follow::follower_counts( wp_list_pluck( $rows, 'user_id' ) );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( '브랜드톡 리뷰어', 'brandtalk' ) . '</h1>';
		echo '<hr class="wp-header-end">';
		printf(
			'<p class="description">%s</p>',
			esc_html__( '브랜드톡 리뷰(CPT) 또는 브랜드톡 별점이 등록된 글을 1건 이상 작성한 사용자입니다 — 글·페이지·리뷰 및 향후 추가되는 포스트 타입 모두 포함. 리뷰 수·평균 점수는 전체 포스트 타입 기준, 순 좋아요·신용도·신뢰도 점수는 리뷰 CPT 반응 기준(§4.2.7 / §4.2 캐시값).', 'brandtalk' )
		);

		echo '<table class="wp-list-table widefat fixed striped">';
		$this->render_head( $orderby_key, $order );
		echo '<tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="10">' . esc_html__( '리뷰어가 없습니다.', 'brandtalk' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$this->render_row( $row, (int) ( $followers[ (int) $row['user_id'] ] ?? 0 ) );
		}

		echo '</tbody>';
		$this->render_head( $orderby_key, $order );
		echo '</table>';

		$this->render_pagination( $total, $pages, $paged );

		echo '</div>';
	}

	/**
	 * 정렬 가능한 컬럼 헤더(thead/tfoot 공용).
	 *
	 * @param string $current_key 현재 orderby 키.
	 * @param string $order       ASC|DESC.
	 */
	private function render_head( $current_key, $order ) {
		$cols = [
			'user_id'      => __( 'ID', 'brandtalk' ),
			'user_email'   => __( '이메일', 'brandtalk' ),
			'display_name' => __( '닉네임', 'brandtalk' ),
			'review_count' => __( '리뷰 수', 'brandtalk' ),
			'avg_rating'   => __( '리뷰 평균 점수', 'brandtalk' ),
			'net_likes'    => __( '순 좋아요', 'brandtalk' ),
			'credibility'  => __( '신용도', 'brandtalk' ),
			'trust_score'  => __( '신뢰도 점수', 'brandtalk' ),
			'trust_level'  => __( '레벨', 'brandtalk' ),
			'followers'    => __( '팔로워', 'brandtalk' ),
		];

		echo '<tr>';

		foreach ( $cols as $key => $label ) {
			if ( ! isset( $this->sortable[ $key ] ) ) {
				printf( '<th scope="col">%s</th>', esc_html( $label ) );
				continue;
			}

			$is_current = ( $key === $current_key );
			$dir        = 'ASC' === $order ? 'asc' : 'desc';
			$next_order = ( $is_current && 'asc' === $dir ) ? 'desc' : 'asc';
			$classes    = $is_current ? 'sorted ' . $dir : 'sortable asc';
			$url        = add_query_arg(
				[
					'orderby' => $key,
					'order'   => $next_order,
					'paged'   => false,
				]
			);

			printf(
				'<th scope="col" class="manage-column %1$s"><a href="%2$s"><span>%3$s</span><span class="sorting-indicator"></span></a></th>',
				esc_attr( $classes ),
				esc_url( $url ),
				esc_html( $label )
			);
		}

		echo '</tr>';
	}

	/**
	 * 리뷰어 한 행.
	 *
	 * @param array $row       DB 행.
	 * @param int   $followers 팔로워 수.
	 */
	private function render_row( $row, $followers ) {
		$user_id = (int) $row['user_id'];
		$reviews = (int) $row['review_count'];
		$avg     = null !== $row['avg_rating'] ? (float) $row['avg_rating'] : null;
		$cred    = (float) $row['credibility'];

		$review_link = add_query_arg(
			[
				'post_type' => Review::POST_TYPE,
				'author'    => $user_id,
			],
			admin_url( 'edit.php' )
		);

		echo '<tr>';
		printf( '<td>%d</td>', $user_id );
		printf(
			'<td><a href="%s">%s</a></td>',
			esc_url( get_edit_user_link( $user_id ) ),
			esc_html( $row['user_email'] )
		);
		printf( '<td>%s</td>', esc_html( $row['display_name'] ) );
		printf( '<td><a href="%s">%d</a></td>', esc_url( $review_link ), $reviews );
		printf(
			'<td>%s</td>',
			null === $avg
				? '<span aria-hidden="true">—</span>'
				: esc_html( number_format( $avg, 2 ) ) . ' <span style="opacity:.6">' . esc_html( str_repeat( '★', (int) round( $avg ) ) ) . '</span>'
		);
		printf( '<td>%s%d</td>', (int) $row['net_likes'] > 0 ? '+' : '', (int) $row['net_likes'] );
		printf(
			'<td><strong>%s%s</strong></td>',
			$cred > 0 ? '+' : '',
			esc_html( number_format( $cred, 1 ) )
		);
		printf( '<td>%s</td>', esc_html( number_format( (float) $row['trust_score'], 2 ) ) );
		printf( '<td>Lv.%d</td>', (int) $row['trust_level'] );
		printf( '<td>%d</td>', $followers );
		echo '</tr>';
	}

	/**
	 * 하단 페이지네이션.
	 *
	 * @param int $total 전체 건수.
	 * @param int $pages 전체 페이지.
	 * @param int $paged 현재 페이지.
	 */
	private function render_pagination( $total, $pages, $paged ) {
		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		printf(
			'<span class="displaying-num">%s</span>',
			esc_html( sprintf( _n( '%s명', '%s명', $total, 'brandtalk' ), number_format_i18n( $total ) ) )
		);

		if ( $pages > 1 ) {
			echo ' ' . wp_kses_post(
				paginate_links(
					[
						'base'      => esc_url_raw( add_query_arg( 'paged', '%#%' ) ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '‹',
						'next_text' => '›',
					]
				)
			);
		}

		echo '</div></div>';
	}
}
