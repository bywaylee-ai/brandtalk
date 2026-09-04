<?php
/**
 * Frontend component — 연동 포스트 타입의 글에 브랜드톡 별점을 노출.
 * 정책 §13.1.3 (브랜드톡 카테고리에 연결되는 별점 항목) 의 프론트 표시.
 *
 * "포스트에 브랜드톡이 등록되어 있으면" =
 *   - 글의 포스트 타입이 brandtalk_options.enabled_post_types 에 포함되고
 *   - 글에 brandtalk_rating(1~5) 값이 있을 때
 * 본문에 별점 블록을 함께 보여준다.
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Helpers as bt;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the BrandTalk rating on the frontend.
 */
final class Frontend extends Component {

	const META_RATING = 'brandtalk_rating';
	const TAXONOMY    = 'brandtalk_category';

	/**
	 * 리뷰 섹션 렌더링 중 플래그. 리뷰 카드의 the_content 필터가
	 * 별점·리뷰 블록을 재귀로 다시 붙이는 것을 막는다.
	 *
	 * @var bool
	 */
	private static $in_reviews = false;

	/**
	 * Boot hooks.
	 */
	protected function boot() {
		add_filter( 'the_content', [ $this, 'append_rating' ], 20 );
		add_filter( 'the_content', [ $this, 'append_reviews' ], 21 );
		add_filter( 'the_excerpt', [ $this, 'append_rating_excerpt' ], 20 );
		add_shortcode( 'brandtalk_rating', [ $this, 'shortcode' ] );
		add_shortcode( 'brandtalk_reviews', [ $this, 'reviews_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_reviews_assets' ] );

		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'register_admin_column' ] );
		}
	}

	/* ------------------------------------------------------------------ */
	/* 조건 판정                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * 글에 브랜드톡 별점이 "등록"되어 있는지.
	 *
	 * @param int|\WP_Post|null $post Post.
	 * @return bool
	 */
	public static function has_rating( $post = null ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return false;
		}

		// 리뷰 CPT 는 자체 화면에서 다루므로 여기서는 제외.
		if ( Review::POST_TYPE === $post->post_type ) {
			return false;
		}

		if ( ! in_array( $post->post_type, Post_Type::enabled_post_types(), true ) ) {
			return false;
		}

		if ( self::get_rating( $post->ID ) >= 1 ) {
			return true;
		}

		// 종합 별점이 없어도 하위 별점 항목 점수가 있으면 노출한다.
		$criteria = get_post_meta( $post->ID, 'brandtalk_criteria_ratings', true );

		return is_array( $criteria ) && ! empty( $criteria );
	}

	/**
	 * 별점 값(1~5, 없으면 0).
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function get_rating( $post_id ) {
		$rating = (int) get_post_meta( (int) $post_id, self::META_RATING, true );

		return ( $rating >= 1 && $rating <= 5 ) ? $rating : 0;
	}

	/**
	 * 하위 별점 평균과 항목 수.
	 *
	 * @param int $post_id Post id.
	 * @return array{avg:float,count:int}
	 */
	public static function criteria_average( $post_id ) {
		$saved = get_post_meta( (int) $post_id, 'brandtalk_criteria_ratings', true );

		if ( ! is_array( $saved ) || ! $saved ) {
			return [ 'avg' => 0.0, 'count' => 0 ];
		}

		$scores = array_filter(
			array_map( 'intval', array_values( $saved ) ),
			function ( $s ) {
				return $s >= 1 && $s <= 5;
			}
		);

		if ( ! $scores ) {
			return [ 'avg' => 0.0, 'count' => 0 ];
		}

		return [
			'avg'   => round( array_sum( $scores ) / count( $scores ), 1 ),
			'count' => count( $scores ),
		];
	}

	/* ------------------------------------------------------------------ */
	/* 렌더링                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * 별점 블록 HTML.
	 *
	 * @param int|\WP_Post|null $post Post.
	 * @return string
	 */
	public static function render( $post = null ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return '';
		}

		$rating   = self::get_rating( $post->ID );
		$criteria = self::render_criteria( $post->ID );

		if ( $rating < 1 && '' === $criteria ) {
			return '';
		}

		// 연결된 브랜드톡 카테고리(있으면 함께 표시).
		$terms    = get_the_terms( $post->ID, self::TAXONOMY );
		$cat_html = '';

		if ( $terms && ! is_wp_error( $terms ) ) {
			$cat_html = ' <span class="brandtalk-rating__category">' . esc_html( $terms[0]->name ) . '</span>';
		}

		$html = '';

		if ( $rating >= 1 ) {
			$html = sprintf(
				'<div class="brandtalk-rating" data-rating="%1$d">'
					. '<span class="brandtalk-rating__stars" role="img" aria-label="%2$s">'
					. '<span class="brandtalk-rating__stars-full">%3$s</span><span class="brandtalk-rating__stars-empty">%4$s</span>'
					. '</span>'
					. '<span class="brandtalk-rating__value">%5$s</span>%6$s'
					. '</div>',
				$rating,
				esc_attr( sprintf( __( '별점 %d / 5', 'brandtalk' ), $rating ) ),
				str_repeat( '★', $rating ),
				str_repeat( '☆', 5 - $rating ),
				esc_html( number_format( $rating, 1 ) . ' / 5' ),
				$cat_html
			);
		} elseif ( $cat_html ) {
			$html = '<div class="brandtalk-rating brandtalk-rating--criteria-only">' . $cat_html . '</div>';
		}

		$html .= $criteria;

		/**
		 * Filters the BrandTalk rating HTML shown on the frontend.
		 *
		 * @hook brandtalk/v1/rating_html
		 * @param {string} $html Rendered HTML.
		 * @param {int}    $post_id Post id.
		 * @param {int}    $rating Rating value.
		 * @return {string} Rendered HTML.
		 */
		return apply_filters( 'brandtalk/v1/rating_html', $html, $post->ID, $rating );
	}

	/**
	 * 하위 별점 항목별 점수 목록 HTML (`brandtalk_criteria_ratings` 메타).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function render_criteria( $post_id ) {
		$saved = get_post_meta( (int) $post_id, 'brandtalk_criteria_ratings', true );

		if ( ! is_array( $saved ) || ! $saved ) {
			return '';
		}

		$rows = '';

		foreach ( $saved as $criterion_id => $score ) {
			$score = (int) $score;

			if ( $score < 1 || $score > 5 ) {
				continue;
			}

			$term = get_term( (int) $criterion_id, Criterion::TAXONOMY );

			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$rows .= sprintf(
				'<li class="brandtalk-criteria__item"><span class="brandtalk-criteria__name">%1$s</span>'
					. '<span class="brandtalk-rating__stars"><span class="brandtalk-rating__stars-full">%2$s</span>'
					. '<span class="brandtalk-rating__stars-empty">%3$s</span></span>'
					. '<span class="brandtalk-criteria__value">%4$s</span></li>',
				esc_html( $term->name ),
				str_repeat( '★', $score ),
				str_repeat( '☆', 5 - $score ),
				esc_html( number_format( $score, 1 ) )
			);
		}

		return $rows ? '<ul class="brandtalk-criteria">' . $rows . '</ul>' : '';
	}

	/**
	 * 본문에 별점 블록을 붙인다.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_rating( $content ) {
		if ( self::$in_reviews || is_admin() || is_feed() || bt\is_rest() ) {
			return $content;
		}

		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( ! self::has_rating( get_post() ) ) {
			return $content;
		}

		$block = self::render( get_post() );

		if ( ! $block ) {
			return $content;
		}

		/**
		 * Filters where the rating block is placed relative to the content.
		 *
		 * @hook brandtalk/v1/rating_position
		 * @param {string} $position `before` 또는 `after` (기본 `before`).
		 * @param {int}    $post_id Post id.
		 * @return {string} Position.
		 */
		$position = apply_filters( 'brandtalk/v1/rating_position', 'before', get_the_ID() );

		return 'after' === $position ? $content . $block : $block . $content;
	}

	/**
	 * 발췌문에도 짧은 별점 표기.
	 *
	 * @param string $excerpt Excerpt.
	 * @return string
	 */
	public function append_rating_excerpt( $excerpt ) {
		if ( is_admin() || is_feed() || bt\is_rest() || ! is_main_query() ) {
			return $excerpt;
		}

		if ( ! self::has_rating( get_post() ) ) {
			return $excerpt;
		}

		$rating = self::get_rating( get_the_ID() );
		$badge  = '<span class="brandtalk-rating brandtalk-rating--inline" data-rating="' . $rating . '">'
			. '<span class="brandtalk-rating__stars"><span class="brandtalk-rating__stars-full">' . str_repeat( '★', $rating ) . '</span>'
			. '<span class="brandtalk-rating__stars-empty">' . str_repeat( '☆', 5 - $rating ) . '</span></span></span> ';

		return $badge . $excerpt;
	}

	/**
	 * [brandtalk_rating] 또는 [brandtalk_rating id="123"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'brandtalk_rating' );
		$post = $atts['id'] ? get_post( (int) $atts['id'] ) : get_post();

		return self::render( $post );
	}

	/**
	 * [brandtalk_reviews] / [brandtalk_reviews id="123"] — 리뷰 목록 + 작성 폼.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function reviews_shortcode( $atts ) {
		$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'brandtalk_reviews' );
		$post = $atts['id'] ? get_post( (int) $atts['id'] ) : get_post();

		return $post ? self::render_reviews( $post ) : '';
	}

	/* ------------------------------------------------------------------ */
	/* 리뷰 섹션 (§ 프론트 리뷰)                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * 글에 프론트 리뷰 섹션을 노출할지. 연동 포스트 타입이고,
	 * 브랜드톡 카테고리가 지정됐거나 이미 리뷰가 하나라도 있으면 노출한다.
	 *
	 * @param int|\WP_Post|null $post Post.
	 * @return bool
	 */
	public static function is_reviewable( $post = null ) {
		$post = get_post( $post );

		if ( ! $post || Review::POST_TYPE === $post->post_type ) {
			return false;
		}

		if ( ! in_array( $post->post_type, Post_Type::enabled_post_types(), true ) ) {
			return false;
		}

		$terms = get_the_terms( $post->ID, self::TAXONOMY );

		if ( $terms && ! is_wp_error( $terms ) ) {
			return true;
		}

		return (bool) brandtalk()->review->reviews_for_target( $post->ID, 1 );
	}

	/**
	 * 본문 뒤에 리뷰 섹션을 붙인다.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_reviews( $content ) {
		if ( self::$in_reviews || is_admin() || is_feed() || bt\is_rest() || ! is_singular() ) {
			return $content;
		}

		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( ! self::is_reviewable( get_post() ) ) {
			return $content;
		}

		return $content . self::render_reviews( get_post() );
	}

	/**
	 * 리뷰 섹션 HTML (목록 + 작성 폼).
	 *
	 * @param int|\WP_Post|null $post Post.
	 * @return string
	 */
	public static function render_reviews( $post = null ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return '';
		}

		$reviews    = brandtalk()->review->reviews_for_target( $post->ID );
		$user_id    = get_current_user_id();
		$is_author  = $user_id && (int) $post->post_author === $user_id;
		$existing   = $user_id ? brandtalk()->review->existing_review( $user_id, $post->ID ) : 0;
		$can_review = $user_id && ! $is_author && current_user_can( 'edit_posts' ) && ! $existing;

		$was              = self::$in_reviews;
		self::$in_reviews = true;

		$items = '';

		foreach ( $reviews as $review ) {
			$items .= self::render_review_item( brandtalk()->review->prepare( $review ), $user_id );
		}

		self::$in_reviews = $was;

		if ( '' === $items ) {
			$items = '<p class="brandtalk-reviews__empty">' . esc_html__( '아직 리뷰가 없습니다.', 'brandtalk' ) . '</p>';
		}

		$action = '';

		if ( $can_review ) {
			$action = self::render_review_form( $post );
		} elseif ( ! $user_id ) {
			$action = sprintf(
				'<p class="brandtalk-reviews__note"><a href="%s">%s</a></p>',
				esc_url( wp_login_url( get_permalink( $post ) ) ),
				esc_html__( '로그인하고 리뷰 남기기', 'brandtalk' )
			);
		} elseif ( $existing ) {
			$action = '<p class="brandtalk-reviews__note">' . esc_html__( '이미 이 글에 리뷰를 남겼습니다.', 'brandtalk' ) . '</p>';
		} elseif ( $is_author ) {
			$action = '<p class="brandtalk-reviews__note">' . esc_html__( '자기 글에는 리뷰할 수 없습니다.', 'brandtalk' ) . '</p>';
		}

		return sprintf(
			'<section class="brandtalk-reviews" data-post="%1$d"><h2 class="brandtalk-reviews__title">%2$s <span class="brandtalk-reviews__count">%3$d</span></h2>%4$s%5$s</section>',
			(int) $post->ID,
			esc_html__( '리뷰', 'brandtalk' ),
			count( $reviews ),
			$action,
			'<div class="brandtalk-reviews__list">' . $items . '</div>'
		);
	}

	/**
	 * 리뷰 카드 하나.
	 *
	 * @param array $r 리뷰 표시 데이터 (Review::prepare()).
	 * @param int   $user_id 현재 사용자 id.
	 * @return string
	 */
	private static function render_review_item( $r, $user_id ) {
		$rating = (int) $r['rating'];
		$stars  = '<span class="brandtalk-rating__stars"><span class="brandtalk-rating__stars-full">' . str_repeat( '★', $rating )
			. '</span><span class="brandtalk-rating__stars-empty">' . str_repeat( '☆', 5 - $rating ) . '</span></span>';

		$imgs = '';

		foreach ( (array) $r['images'] as $img ) {
			$imgs .= sprintf(
				'<a class="brandtalk-review__image" href="%s" target="_blank" rel="noopener"><img src="%s" alt="" loading="lazy"></a>',
				esc_url( $img['url'] ),
				esc_url( $img['thumb'] )
			);
		}

		if ( $imgs ) {
			$imgs = '<div class="brandtalk-review__images">' . $imgs . '</div>';
		}

		$crit = '';

		foreach ( (array) $r['criteria'] as $c ) {
			$score = (int) $c['rating'];
			$crit .= sprintf(
				'<li class="brandtalk-criteria__item"><span class="brandtalk-criteria__name">%1$s</span>'
					. '<span class="brandtalk-rating__stars"><span class="brandtalk-rating__stars-full">%2$s</span>'
					. '<span class="brandtalk-rating__stars-empty">%3$s</span></span></li>',
				esc_html( $c['name'] ),
				str_repeat( '★', $score ),
				str_repeat( '☆', 5 - $score )
			);
		}

		if ( $crit ) {
			$crit = '<ul class="brandtalk-criteria brandtalk-review__criteria">' . $crit . '</ul>';
		}

		$me       = is_array( $r['me'] ) ? $r['me'] : [];
		$is_mine  = $user_id && (int) $r['author']['id'] === $user_id;
		$disabled = ( ! $user_id || $is_mine ) ? ' disabled' : '';

		$buttons = sprintf(
			'<div class="brandtalk-review__reactions" data-review="%1$d">'
				. '<button type="button" class="brandtalk-react%2$s" data-type="like"%6$s aria-pressed="%7$s">👍 <span class="brandtalk-react__count">%3$d</span></button>'
				. '<button type="button" class="brandtalk-react%4$s" data-type="dislike"%6$s aria-pressed="%8$s">👎 <span class="brandtalk-react__count">%5$d</span></button>'
				. '</div>',
			(int) $r['id'],
			! empty( $me['like'] ) ? ' is-active' : '',
			(int) $r['reactions']['like'],
			! empty( $me['dislike'] ) ? ' is-active' : '',
			(int) $r['reactions']['dislike'],
			$disabled,
			! empty( $me['like'] ) ? 'true' : 'false',
			! empty( $me['dislike'] ) ? 'true' : 'false'
		);

		$body = $r['content']['raw'] ? wpautop( wp_kses_post( $r['content']['raw'] ) ) : '';

		return sprintf(
			'<article class="brandtalk-review%1$s">'
				. '<header class="brandtalk-review__head"><span class="brandtalk-review__author">%2$s</span>'
				. '<span class="brandtalk-review__level" title="%3$s">Lv.%4$d</span>'
				. '<time class="brandtalk-review__date" datetime="%5$s">%6$s</time></header>'
				. '<div class="brandtalk-review__rating">%7$s</div>%8$s%9$s%10$s%11$s</article>',
			$is_mine ? ' is-mine' : '',
			esc_html( $r['author']['name'] ),
			esc_attr__( '신뢰도 레벨', 'brandtalk' ),
			(int) $r['author']['trust']['level'],
			esc_attr( $r['date'] ),
			esc_html( date_i18n( get_option( 'date_format' ), strtotime( $r['date'] ) ) ),
			$stars,
			$crit,
			$body,
			$imgs,
			$buttons
		);
	}

	/**
	 * "나도 리뷰하기" 폼. 브랜드톡 카테고리는 대상 글에서 상속하며(표시만, 선택 불가),
	 * 그 카테고리에 연결된 하위 별점 항목 입력란을 함께 제공한다.
	 *
	 * @param \WP_Post $post 대상 글.
	 * @return string
	 */
	private static function render_review_form( $post ) {
		$opts = '';

		for ( $i = 5; $i >= 1; $i-- ) {
			$opts .= sprintf( '<option value="%1$d">%2$s (%1$d)</option>', $i, str_repeat( '★', $i ) );
		}

		$terms = get_the_terms( $post->ID, self::TAXONOMY );
		$cat   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;

		$cat_html  = '';
		$crit_html = '';

		if ( $cat ) {
			$cat_html = sprintf(
				'<div class="brandtalk-review-form__cat"><span>%s</span> <strong>%s</strong></div>',
				esc_html__( '브랜드톡 카테고리', 'brandtalk' ),
				esc_html( $cat->name )
			);

			$rows = '';

			foreach ( Criterion::for_category( $cat->term_id ) as $c ) {
				$rows .= sprintf(
					'<label class="brandtalk-review-form__crit"><span>%1$s</span>'
						. '<select name="criteria[%2$d]"><option value="">%3$s</option>%4$s</select></label>',
					esc_html( $c['name'] ),
					(int) $c['id'],
					esc_html__( '선택 안 함', 'brandtalk' ),
					$opts
				);
			}

			if ( $rows ) {
				$crit_html = '<fieldset class="brandtalk-review-form__criteria"><legend>'
					. esc_html__( '하위 별점 항목', 'brandtalk' ) . '</legend>' . $rows . '</fieldset>';
			}
		}

		return sprintf(
			'<form class="brandtalk-review-form" hidden>'
				. '%1$s'
				. '<label class="brandtalk-review-form__row"><span>%2$s</span><select name="rating" required><option value="">%3$s</option>%4$s</select></label>'
				. '%5$s'
				. '<label class="brandtalk-review-form__row"><span>%6$s</span><textarea name="content" rows="3" maxlength="2000" placeholder="%7$s"></textarea></label>'
				. '<label class="brandtalk-review-form__row"><span>%8$s</span><input type="file" name="images[]" accept="image/*" multiple></label>'
				. '<div class="brandtalk-review-form__actions"><button type="submit">%9$s</button> <button type="button" class="brandtalk-review-form__cancel">%10$s</button></div>'
				. '<p class="brandtalk-review-form__msg" role="alert" hidden></p>'
				. '</form>'
				. '<p class="brandtalk-reviews__action"><button type="button" class="brandtalk-review-form__toggle">%11$s</button></p>',
			$cat_html,
			esc_html__( '종합 별점', 'brandtalk' ),
			esc_html__( '선택', 'brandtalk' ),
			$opts,
			$crit_html,
			esc_html__( '내용', 'brandtalk' ),
			esc_attr__( '간단한 후기를 남겨 주세요.', 'brandtalk' ),
			esc_html__( '사진 (최대 5장)', 'brandtalk' ),
			esc_html__( '리뷰 등록', 'brandtalk' ),
			esc_html__( '취소', 'brandtalk' ),
			esc_html__( '나도 리뷰하기', 'brandtalk' )
		);
	}

	/**
	 * 리뷰 섹션 스크립트(인라인).
	 */
	public function enqueue_reviews_assets() {
		if ( is_admin() || ! is_singular() || ! self::is_reviewable( get_queried_object() ) ) {
			return;
		}

		$handle = 'brandtalk-reviews';

		wp_register_script( $handle, false, [], brandtalk()->get_version(), true );
		wp_enqueue_script( $handle );
		wp_add_inline_script(
			$handle,
			'window.brandtalkReviews=' . wp_json_encode(
				[
					'rest'    => esc_url_raw( rest_url( 'brandtalk/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'loginUrl' => wp_login_url( get_permalink() ),
				]
			) . ';',
			'before'
		);
		wp_add_inline_script( $handle, self::reviews_inline_js() );
	}

	/**
	 * enqueue_reviews_assets 의 본문 JS.
	 *
	 * @return string
	 */
	private static function reviews_inline_js() {
		return <<<'JS'
( function () {
	var cfg = window.brandtalkReviews || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); } else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		var section = document.querySelector( '.brandtalk-reviews' );
		if ( ! section ) { return; }

		// 좋아요 / 나빠요
		section.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.brandtalk-react' ) : null;
			if ( ! btn || btn.disabled ) { return; }

			var wrap = btn.closest( '.brandtalk-review__reactions' );
			var id = wrap.getAttribute( 'data-review' );
			var type = btn.getAttribute( 'data-type' );
			var active = btn.classList.contains( 'is-active' );
			var method = active ? 'DELETE' : 'POST';

			btn.disabled = true;
			fetch( cfg.rest + '/reviews/' + id + '/reactions', {
				method: method,
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: method === 'POST' ? JSON.stringify( { type: type } ) : null
			} )
				.then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, d: d }; } ); } )
				.then( function ( res ) {
					if ( ! res.ok ) { throw res.d; }
					var data = res.d.data || res.d;
					wrap.querySelectorAll( '.brandtalk-react' ).forEach( function ( b ) {
						var t = b.getAttribute( 'data-type' );
						b.querySelector( '.brandtalk-react__count' ).textContent = ( data.reactions && data.reactions[ t ] != null ) ? data.reactions[ t ] : b.querySelector( '.brandtalk-react__count' ).textContent;
						var on = data.me && data.me[ t ];
						b.classList.toggle( 'is-active', !! on );
						b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
					} );
				} )
				.catch( function () { /* no-op */ } )
				.finally( function () { btn.disabled = false; } );
		} );

		// 폼 토글
		var toggle = section.querySelector( '.brandtalk-review-form__toggle' );
		var form = section.querySelector( '.brandtalk-review-form' );
		if ( toggle && form ) {
			toggle.addEventListener( 'click', function () {
				form.hidden = ! form.hidden;
				if ( ! form.hidden ) { form.querySelector( '[name=rating]' ).focus(); }
			} );
			var cancel = form.querySelector( '.brandtalk-review-form__cancel' );
			if ( cancel ) { cancel.addEventListener( 'click', function () { form.hidden = true; } ); }

			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var msg = form.querySelector( '.brandtalk-review-form__msg' );
				var submit = form.querySelector( 'button[type=submit]' );
				var files = form.querySelector( '[name="images[]"]' );

				if ( files && files.files.length > 5 ) {
					msg.hidden = false; msg.textContent = '이미지는 최대 5장까지 올릴 수 있습니다.'; return;
				}

				var fd = new FormData();
				fd.append( 'rating', form.querySelector( '[name=rating]' ).value );
				fd.append( 'content', form.querySelector( '[name=content]' ).value );
				fd.append( 'target', section.getAttribute( 'data-post' ) );
				form.querySelectorAll( 'select[name^="criteria["]' ).forEach( function ( s ) {
					if ( s.value ) { fd.append( s.name, s.value ); }
				} );
				if ( files ) {
					Array.prototype.forEach.call( files.files, function ( f ) { fd.append( 'images[]', f ); } );
				}

				submit.disabled = true;
				msg.hidden = true;
				fetch( cfg.rest + '/reviews', { method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce }, body: fd } )
					.then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, d: d }; } ); } )
					.then( function ( res ) {
						if ( ! res.ok ) {
							var m = ( res.d && res.d.error && res.d.error.errors && res.d.error.errors[0] && res.d.error.errors[0].message ) || ( res.d && res.d.message ) || '리뷰 등록에 실패했습니다.';
							throw new Error( m );
						}
						window.location.reload();
					} )
					.catch( function ( err ) {
						msg.hidden = false;
						msg.textContent = err.message || '리뷰 등록에 실패했습니다.';
						submit.disabled = false;
					} );
			} );
		}
	} );
} )();
JS;
	}

	/**
	 * 프론트 스타일(인라인).
	 */
	public function enqueue_styles() {
		$handle = 'brandtalk-frontend';

		wp_register_style( $handle, false, [], brandtalk()->get_version() );
		wp_enqueue_style( $handle );

		$css = '
.brandtalk-rating{display:flex;align-items:center;gap:.5em;flex-wrap:wrap;margin:0 0 1.25em;font-size:1.05rem;line-height:1}
.brandtalk-rating--inline{display:inline-flex;margin:0;font-size:.95em}
.brandtalk-rating__stars{letter-spacing:.05em}
.brandtalk-rating__stars-full{color:#f5a623}
.brandtalk-rating__stars-empty{color:#c9c9c9}
.brandtalk-rating__value{font-weight:600;font-size:.85em;color:inherit;opacity:.75}
.brandtalk-rating__category{font-size:.8em;padding:.15em .55em;border:1px solid currentColor;border-radius:999px;opacity:.65}
.brandtalk-criteria{list-style:none;margin:-.5em 0 1.25em;padding:0;display:flex;flex-direction:column;gap:.2em}
.brandtalk-criteria__item{display:flex;align-items:center;gap:.5em;font-size:.92rem}
.brandtalk-criteria__name{min-width:5em;opacity:.8}
.brandtalk-criteria__value{font-size:.82em;font-weight:600;opacity:.6}
.brandtalk-reviews{margin:2.5em 0;border-top:1px solid #e2e2e2;padding-top:1.5em}
.brandtalk-reviews__title{font-size:1.3rem;margin:0 0 1em;display:flex;align-items:baseline;gap:.4em}
.brandtalk-reviews__count{font-size:.9rem;font-weight:600;opacity:.55}
.brandtalk-reviews__empty,.brandtalk-reviews__note{opacity:.7;font-size:.95rem}
.brandtalk-reviews__action{margin:0 0 1.5em}
.brandtalk-reviews__list{display:flex;flex-direction:column;gap:1.25em}
.brandtalk-review{border:1px solid #e6e6e6;border-radius:8px;padding:1em 1.1em}
.brandtalk-review.is-mine{border-color:#c7d7ef;background:#f7faff}
.brandtalk-review__head{display:flex;align-items:baseline;gap:.6em;flex-wrap:wrap;font-size:.9rem;margin-bottom:.35em}
.brandtalk-review__author{font-weight:600}
.brandtalk-review__level{font-size:.78em;padding:.05em .5em;border:1px solid currentColor;border-radius:999px;opacity:.6}
.brandtalk-review__date{margin-left:auto;opacity:.55;font-size:.85em}
.brandtalk-review__rating{margin-bottom:.4em}
.brandtalk-review__criteria{margin:.2em 0 .5em!important}
.brandtalk-review__images{display:flex;gap:.5em;flex-wrap:wrap;margin:.6em 0}
.brandtalk-review__image img{width:96px;height:96px;object-fit:cover;border-radius:6px;display:block}
.brandtalk-review__reactions{display:flex;gap:.5em;margin-top:.7em}
.brandtalk-react{border:1px solid #dcdcde;background:#fff;border-radius:999px;padding:.25em .8em;font-size:.88rem;cursor:pointer;line-height:1.4}
.brandtalk-react:disabled{cursor:default;opacity:.55}
.brandtalk-react.is-active{border-color:#2271b1;background:#eef5fb;font-weight:600}
.brandtalk-review-form{display:flex;flex-direction:column;gap:.75em;border:1px solid #e0e0e0;border-radius:8px;padding:1em;margin:0 0 1.5em}
.brandtalk-review-form__row{display:flex;flex-direction:column;gap:.3em;font-size:.9rem;font-weight:600}
.brandtalk-review-form__row select,.brandtalk-review-form__row textarea{font:inherit;font-weight:400;padding:.5em;border:1px solid #ccc;border-radius:6px;width:100%}
.brandtalk-review-form__cat{font-size:.9rem}
.brandtalk-review-form__cat span{opacity:.7}
.brandtalk-review-form__criteria{border:1px solid #e0e0e0;border-radius:6px;padding:.5em .75em;margin:0;display:flex;flex-direction:column;gap:.4em}
.brandtalk-review-form__criteria legend{font-size:.85rem;font-weight:600;opacity:.7;padding:0 .3em}
.brandtalk-review-form__crit{display:flex;align-items:center;justify-content:space-between;gap:.75em;font-size:.88rem;font-weight:400}
.brandtalk-review-form__crit select{font:inherit;padding:.35em;border:1px solid #ccc;border-radius:6px;min-width:9em}
.brandtalk-review-form__actions{display:flex;gap:.5em}
.brandtalk-review-form__actions button{font:inherit;padding:.45em 1.1em;border-radius:6px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer}
.brandtalk-review-form__actions .brandtalk-review-form__cancel{background:#fff;color:#2271b1}
.brandtalk-review-form__toggle{font:inherit;padding:.45em 1.1em;border-radius:6px;border:1px solid #2271b1;background:#fff;color:#2271b1;cursor:pointer}
.brandtalk-review-form__msg{color:#b32d2e;font-size:.88rem;margin:0}
@media (prefers-color-scheme:dark){
.brandtalk-review{border-color:#3a3a3a}
.brandtalk-review.is-mine{border-color:#3d5a80;background:#1e2733}
.brandtalk-react{background:#2a2a2a;border-color:#444;color:inherit}
.brandtalk-react.is-active{background:#24384a;border-color:#4a8fd0}
}
';

		wp_add_inline_style( $handle, $css );
	}

	/* ------------------------------------------------------------------ */
	/* 관리자 글 목록 컬럼                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * 연동 포스트 타입의 글 목록에 "브랜드톡 별점" 컬럼 추가.
	 */
	public function register_admin_column() {
		foreach ( Post_Type::enabled_post_types() as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", [ $this, 'add_column' ] );
			add_action( "manage_{$pt}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		}
	}

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['brandtalk_rating'] = __( '브랜드톡 별점', 'brandtalk' );

		return $columns;
	}

	/**
	 * @param string $column Column key.
	 * @param int    $post_id Post id.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'brandtalk_rating' !== $column ) {
			return;
		}

		$rating = self::get_rating( $post_id );

		echo $rating >= 1
			? '<span title="' . esc_attr( $rating . ' / 5' ) . '">' . esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ) . '</span>'
			: '<span aria-hidden="true">—</span>';
	}
}
