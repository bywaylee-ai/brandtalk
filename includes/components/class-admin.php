<?php
/**
 * Admin component — 설정 화면(컨피그 기반) + 별점 메타박스.
 * 정책 §11.3 (설정 가능 정책값), §13.1.2~13.1.3.
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Helpers as bt;
use BrandTalk\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings & meta boxes.
 */
final class Admin extends Component {

	const SETTINGS_GROUP = 'brandtalk_settings';
	const PAGE_SLUG      = 'brandtalk-settings';
	const NONCE_ACTION   = 'brandtalk_save_meta';
	const NONCE_NAME     = 'brandtalk_meta_nonce';

	/**
	 * Boot hooks (admin only).
	 */
	protected function boot() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_meta_boxes' ], 10, 2 );
		add_action( 'save_post', [ $this, 'sync_reviews_for_target' ], 20, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_criteria_script' ] );
		add_action( 'update_option_' . Options::OPTION_KEY, [ $this, 'on_settings_saved' ] );
	}

	/* --- settings page ---------------------------------------------- */

	/**
	 * Adds the settings submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=brandtalk_review',
			__( '브랜드톡 설정', 'brandtalk' ),
			__( '설정', 'brandtalk' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Registers the setting + sections/fields from the settings config.
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			Options::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
			]
		);

		foreach ( brandtalk()->get_config( 'settings' ) as $tab ) {
			foreach ( bt\sort_array( bt\get_array_value( $tab, 'sections', [] ) ) as $section_id => $section ) {
				$section_key = 'brandtalk_' . $section_id;

				add_settings_section(
					$section_key,
					esc_html( bt\get_array_value( $section, 'title', '' ) ),
					function () use ( $section ) {
						if ( ! empty( $section['description'] ) ) {
							echo '<p>' . esc_html( $section['description'] ) . '</p>';
						}
					},
					self::PAGE_SLUG
				);

				foreach ( bt\sort_array( bt\get_array_value( $section, 'fields', [] ) ) as $field_id => $field ) {
					add_settings_field(
						$field_id,
						esc_html( bt\get_array_value( $field, 'label', $field_id ) ),
						[ $this, 'render_field' ],
						self::PAGE_SLUG,
						$section_key,
						array_merge( $field, [ 'id' => $field_id ] )
					);
				}
			}
		}
	}

	/**
	 * Renders a single settings field.
	 *
	 * @param array $field Field args (+ id).
	 */
	public function render_field( $field ) {
		$id      = $field['id'];
		$name    = Options::OPTION_KEY . '[' . $id . ']';
		$value   = Options::get( $id, bt\get_array_value( $field, 'default' ) );
		$type    = bt\get_array_value( $field, 'type', 'text' );

		switch ( $type ) {
			case 'post_types':
				$enabled = (array) $value;

				foreach ( Options::candidate_post_types() as $slug => $obj ) {
					printf(
						'<label style="display:block;margin:4px 0;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s <code>%2$s</code></label>',
						esc_attr( $name ),
						esc_attr( $slug ),
						checked( in_array( $slug, $enabled, true ), true, false ),
						esc_html( $obj->labels->singular_name )
					);
				}
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html( bt\get_array_value( $field, 'caption', '' ) )
				);
				break;

			case 'number':
				printf(
					'<input type="number" name="%1$s" value="%2$s" %3$s %4$s %5$s class="regular-text">',
					esc_attr( $name ),
					esc_attr( (string) $value ),
					isset( $field['min'] ) ? 'min="' . esc_attr( $field['min'] ) . '"' : '',
					isset( $field['max'] ) ? 'max="' . esc_attr( $field['max'] ) . '"' : '',
					isset( $field['step'] ) ? 'step="' . esc_attr( $field['step'] ) . '"' : ''
				);
				break;

			default:
				printf(
					'<input type="text" name="%1$s" value="%2$s" class="regular-text">',
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
		}

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
		}
	}

	/**
	 * Sanitizes submitted settings, preserving keys the form didn't send.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : [];
		$current = Options::all();

		foreach ( brandtalk()->get_config( 'settings' ) as $tab ) {
			foreach ( bt\get_array_value( $tab, 'sections', [] ) as $section ) {
				foreach ( bt\get_array_value( $section, 'fields', [] ) as $key => $field ) {
					$type = bt\get_array_value( $field, 'type', 'text' );

					if ( 'post_types' === $type ) {
						$types = [];

						foreach ( (array) bt\get_array_value( $input, $key, [] ) as $slug ) {
							$slug = sanitize_key( $slug );

							if ( post_type_exists( $slug ) && ! in_array( $slug, [ 'brandtalk_review', 'attachment' ], true ) ) {
								$types[] = $slug;
							}
						}

						$current[ $key ] = array_values( array_unique( $types ) );
					} elseif ( 'checkbox' === $type ) {
						$current[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
					} elseif ( 'number' === $type ) {
						$num = isset( $input[ $key ] ) ? (float) $input[ $key ] : (float) bt\get_array_value( $field, 'default', 0 );

						if ( isset( $field['min'] ) ) {
							$num = max( (float) $field['min'], $num );
						}

						if ( isset( $field['max'] ) ) {
							$num = min( (float) $field['max'], $num );
						}

						$current[ $key ] = ( $num == (int) $num ) ? (int) $num : $num;
					} elseif ( isset( $input[ $key ] ) ) {
						$current[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
					}
				}
			}
		}

		return $current;
	}

	/**
	 * Refreshes permalinks after a settings change (taxonomy↔post-type wiring is init-time).
	 */
	public function on_settings_saved() {
		flush_rewrite_rules( false );
	}

	/**
	 * Renders the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( '브랜드톡 설정', 'brandtalk' ) . '</h1><form action="options.php" method="post">';
		settings_fields( self::SETTINGS_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();
		echo '</form></div>';
	}

	/* --- rating meta box (§13.1.3) ---------------------------------- */

	/**
	 * Post types that carry the rating box.
	 *
	 * @return array
	 */
	public function rating_post_types() {
		return array_values( array_unique( array_merge( [ 'brandtalk_review' ], Options::enabled_post_types() ) ) );
	}

	/**
	 * Registers meta boxes from includes/configs/meta-boxes.php
	 */
	public function add_meta_boxes() {
		foreach ( brandtalk()->get_config( 'meta-boxes' ) as $box_id => $box ) {
			$screens = array_map( 'BrandTalk\\Helpers\\prefix', (array) bt\get_array_value( $box, 'screen', [] ) );

			if ( false !== bt\get_array_value( $box, 'attach_enabled', true ) ) {
				$screens = array_values( array_unique( array_merge( $screens, Options::enabled_post_types() ) ) );
			}

			add_meta_box(
				'brandtalk_' . $box_id,
				esc_html( bt\get_array_value( $box, 'title', $box_id ) ),
				function ( $post ) use ( $box ) {
					$this->render_meta_box( $post, $box );
				},
				$screens,
				bt\get_array_value( $box, 'context', 'side' ),
				bt\get_array_value( $box, 'priority', 'default' )
			);
		}
	}

	/**
	 * Renders a meta box's fields.
	 *
	 * @param \WP_Post $post Post.
	 * @param array    $box Meta box config.
	 */
	public function render_meta_box( $post, $box ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		foreach ( bt\get_array_value( $box, 'fields', [] ) as $meta_key => $field ) {
			$type = bt\get_array_value( $field, 'type' );

			if ( 'rating' === $type ) {
				$value = (int) get_post_meta( $post->ID, $meta_key, true );

				echo '<p><strong>' . esc_html( bt\get_array_value( $field, 'label', '' ) ) . '</strong></p>';
				$this->render_rating_select( $meta_key, $value, (int) bt\get_array_value( $field, 'min', 1 ), (int) bt\get_array_value( $field, 'max', 5 ) );
			} elseif ( 'criteria_rating' === $type ) {
				$this->render_criteria_rating( $post, $meta_key, $field );
			} elseif ( 'target_post' === $type ) {
				$this->render_target_post( $post, $meta_key, $field );
			}

			if ( ! empty( $field['description'] ) ) {
				echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
			}
		}
	}

	/**
	 * 단일 별점 select.
	 *
	 * @param string $name Field name.
	 * @param int    $value Current value.
	 * @param int    $min Min.
	 * @param int    $max Max.
	 * @param bool   $disabled 비활성(폼 전송 제외).
	 */
	private function render_rating_select( $name, $value, $min = 1, $max = 5, $disabled = false ) {
		echo '<select name="' . esc_attr( $name ) . '" style="width:100%"' . ( $disabled ? ' disabled' : '' ) . '>';
		echo '<option value="">' . esc_html__( '— 없음 —', 'brandtalk' ) . '</option>';

		for ( $i = $max; $i >= $min; $i-- ) {
			printf(
				'<option value="%1$d" %2$s>%3$s (%1$d)</option>',
				$i,
				selected( $value, $i, false ),
				esc_html( str_repeat( '★', $i ) )
			);
		}

		echo '</select>';
	}

	/**
	 * 지정된 브랜드톡 카테고리의 하위 별점 항목별 입력.
	 *
	 * 카테고리에 연결된 모든 항목을 렌더링하되, 현재 지정된 카테고리에 해당하는
	 * 행만 보이고 활성화된다. 나머지 행은 hidden + disabled(폼 전송 제외).
	 * 편집화면에서 카테고리 선택이 바뀌면 enqueue_criteria_script 의 JS 가
	 * data-criterion-cats 를 기준으로 즉시 토글한다.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $meta_key Meta key.
	 * @param array    $field Field config.
	 */
	private function render_criteria_rating( $post, $meta_key, $field ) {
		echo '<p><strong>' . esc_html( bt\get_array_value( $field, 'label', '' ) ) . '</strong></p>';

		// criterion_id => [연결된 category_id, ...]  (하나 이상 카테고리에 연결된 항목만).
		$links    = [];
		$cat_ids  = get_terms(
			[
				'taxonomy'   => Criterion::CATEGORY_TAX,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		if ( ! is_wp_error( $cat_ids ) ) {
			foreach ( $cat_ids as $cat_id ) {
				foreach ( Criterion::ids_for_category( (int) $cat_id ) as $criterion_id ) {
					$links[ $criterion_id ][] = (int) $cat_id;
				}
			}
		}

		$assigned = wp_get_object_terms( $post->ID, Criterion::CATEGORY_TAX, [ 'fields' => 'ids' ] );
		$assigned = is_wp_error( $assigned ) ? [] : array_map( 'intval', $assigned );

		$saved = get_post_meta( $post->ID, $meta_key, true );
		$saved = is_array( $saved ) ? $saved : [];

		echo '<div data-brandtalk-criteria>';

		printf(
			'<p class="description" data-brandtalk-criteria-none%s>%s</p>',
			$assigned ? ' hidden' : '',
			esc_html__( '먼저 브랜드톡 카테고리를 지정하면 하위 별점 항목이 표시됩니다.', 'brandtalk' )
		);

		$visible_rows = 0;
		$rows_html    = '';

		foreach ( $links as $criterion_id => $linked_cats ) {
			$term = get_term( (int) $criterion_id, Criterion::TAXONOMY );

			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$show = (bool) array_intersect( $linked_cats, $assigned );
			$cur  = isset( $saved[ $criterion_id ] ) ? (int) $saved[ $criterion_id ] : 0;

			if ( $show ) {
				++$visible_rows;
			}

			ob_start();
			printf(
				'<div data-criterion-cats="%s"%s>',
				esc_attr( implode( ',', array_map( 'absint', $linked_cats ) ) ),
				$show ? '' : ' hidden'
			);
			echo '<p style="margin:.5em 0 .1em;">' . esc_html( $term->name ) . '</p>';
			$this->render_rating_select( $meta_key . '[' . (int) $criterion_id . ']', $cur, 1, 5, ! $show );
			echo '</div>';
			$rows_html .= ob_get_clean();
		}

		printf(
			'<p class="description" data-brandtalk-criteria-empty%s>%s</p>',
			( $assigned && ! $visible_rows ) ? '' : ' hidden',
			esc_html__( '지정한 카테고리에 연결된 하위 별점 항목이 없습니다.', 'brandtalk' )
		);

		echo $rows_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 위에서 조각별로 이스케이프됨.

		if ( ! $links ) {
			printf(
				'<p class="description"><a href="%s">%s</a></p>',
				esc_url( admin_url( 'edit-tags.php?taxonomy=' . Criterion::TAXONOMY . '&post_type=brandtalk_review' ) ),
				esc_html__( '하위 별점 항목을 만들고 브랜드톡 카테고리에 연결하세요.', 'brandtalk' )
			);
		}

		echo '</div>';
	}

	/**
	 * 리뷰 대상 글 선택. 아직 리뷰가 연결되지 않은 글만 포스트타입별로 묶어 보여준다.
	 * (현재 리뷰가 이미 가리키는 글은 선택 상태로 유지.)
	 *
	 * @param \WP_Post $post     Review post.
	 * @param string   $meta_key Meta key.
	 * @param array    $field    Field config.
	 */
	private function render_target_post( $post, $meta_key, $field ) {
		$current = (int) get_post_meta( $post->ID, $meta_key, true );
		$taken   = $this->linked_post_ids( $post->ID );

		$types = get_post_types(
			[
				'public' => true,
			],
			'objects'
		);
		unset( $types['attachment'], $types[ Review::POST_TYPE ] );

		echo '<select name="' . esc_attr( $meta_key ) . '" style="width:100%">';
		echo '<option value="">' . esc_html__( '— 선택 —', 'brandtalk' ) . '</option>';

		$has_option = false;

		foreach ( $types as $type ) {
			$posts = get_posts(
				[
					'post_type'        => $type->name,
					'post_status'      => [ 'publish', 'future', 'draft', 'pending', 'private' ],
					'posts_per_page'   => 200,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'exclude'          => array_diff( $taken, [ $current ] ),
					'suppress_filters' => false,
				]
			);

			if ( ! $posts ) {
				continue;
			}

			printf( '<optgroup label="%s">', esc_attr( $type->labels->singular_name ) );

			foreach ( $posts as $p ) {
				$has_option = true;
				printf(
					'<option value="%1$d" %2$s>%3$s</option>',
					(int) $p->ID,
					selected( $current, $p->ID, false ),
					esc_html( $p->post_title !== '' ? $p->post_title : sprintf( '#%d', $p->ID ) )
				);
			}

			echo '</optgroup>';
		}

		echo '</select>';

		if ( ! $has_option ) {
			echo '<p class="description">' . esc_html__( '연결할 수 있는 글이 없습니다. (남은 글이 이미 다른 리뷰에 연결됐거나 별점이 등록돼 있습니다.)', 'brandtalk' ) . '</p>';
		}
	}

	/**
	 * 이미 브랜드톡에 연결된 글 ID 목록 — 다른 리뷰의 대상이거나(brandtalk_target),
	 * §13.1 별점이 등록된 글(brandtalk_rating / brandtalk_criteria_ratings).
	 *
	 * @param int $exclude_review 제외할 리뷰 ID(현재 편집 중인 리뷰).
	 * @return int[]
	 */
	private function linked_post_ids( $exclude_review = 0 ) {
		global $wpdb;

		$targeted = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'brandtalk_target'
				   AND p.post_type = %s
				   AND p.post_status <> 'trash'
				   AND p.ID <> %d",
				Review::POST_TYPE,
				(int) $exclude_review
			)
		);

		$rated = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key IN ( 'brandtalk_rating', 'brandtalk_criteria_ratings' )"
		);

		return array_values( array_unique( array_filter( array_map( 'intval', array_merge( $targeted, $rated ) ) ) ) );
	}

	/**
	 * 편집화면에서 브랜드톡 카테고리 선택이 바뀔 때 하위 별점 항목 행을
	 * 즉시 토글하는 인라인 스크립트(블록 편집기 + 클래식 모두).
	 *
	 * @param string $hook 현재 admin 페이지 훅.
	 */
	public function enqueue_criteria_script( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, $this->rating_post_types(), true ) ) {
			return;
		}

		$tax       = get_taxonomy( Criterion::CATEGORY_TAX );
		$rest_base = ( $tax && ! empty( $tax->rest_base ) ) ? $tax->rest_base : Criterion::CATEGORY_TAX;

		wp_register_script( 'brandtalk-admin-criteria', false, [ 'wp-data' ], brandtalk()->get_version(), true );
		wp_enqueue_script( 'brandtalk-admin-criteria' );
		wp_add_inline_script(
			'brandtalk-admin-criteria',
			'window.brandtalkCriteria=' . wp_json_encode(
				[
					'boxId'       => 'brandtalk_rating',
					'taxonomy'    => Criterion::CATEGORY_TAX,
					'taxRestBase' => $rest_base,
				]
			) . ';',
			'before'
		);
		wp_add_inline_script( 'brandtalk-admin-criteria', $this->criteria_inline_js() );
	}

	/**
	 * enqueue_criteria_script 의 본문 JS.
	 *
	 * @return string
	 */
	private function criteria_inline_js() {
		return <<<'JS'
( function () {
	var cfg = window.brandtalkCriteria || {};
	var tries = 0;

	function boot() {
		var container = document.querySelector( '#' + cfg.boxId + ' [data-brandtalk-criteria]' );
		if ( ! container ) {
			if ( tries++ < 60 ) { window.setTimeout( boot, 100 ); }
			return;
		}
		init( container );
	}

	function init( container ) {
		var rows = [].slice.call( container.querySelectorAll( '[data-criterion-cats]' ) );
		var noneMsg = container.querySelector( '[data-brandtalk-criteria-none]' );
		var emptyMsg = container.querySelector( '[data-brandtalk-criteria-empty]' );

		function apply( ids ) {
			var sel = {};
			( ids || [] ).forEach( function ( id ) { sel[ String( id ) ] = true; } );

			var anyVisible = false;
			rows.forEach( function ( row ) {
				var cats = ( row.getAttribute( 'data-criterion-cats' ) || '' ).split( ',' );
				var show = cats.some( function ( c ) { return sel[ c ]; } );
				row.hidden = ! show;
				[].forEach.call( row.querySelectorAll( 'select' ), function ( s ) { s.disabled = ! show; } );
				if ( show ) { anyVisible = true; }
			} );

			if ( noneMsg ) { noneMsg.hidden = ( ids && ids.length > 0 ); }
			if ( emptyMsg ) { emptyMsg.hidden = ! ( ids && ids.length > 0 && ! anyVisible ); }
		}

		function wireBlockEditor() {
			var editor;
			try { editor = window.wp.data.select( 'core/editor' ); } catch ( e ) { editor = null; }

			if ( ! editor || typeof editor.getEditedPostAttribute !== 'function' ) {
				return false;
			}

			var read = function () {
				var v = editor.getEditedPostAttribute( cfg.taxRestBase );
				return Array.isArray( v ) ? v.slice() : [];
			};
			var last = JSON.stringify( read() );
			apply( read() );
			window.wp.data.subscribe( function () {
				var now = JSON.stringify( read() );
				if ( now !== last ) { last = now; apply( read() ); }
			} );
			return true;
		}

		function wireClassicEditor() {
			var scope = '#' + cfg.taxonomy + 'div';
			var readClassic = function () {
				var boxes = document.querySelectorAll( scope + ' input[type=checkbox]:checked' );
				return [].map.call( boxes, function ( b ) { return parseInt( b.value, 10 ); } ).filter( Boolean );
			};
			apply( readClassic() );
			document.addEventListener( 'change', function ( e ) {
				if ( e.target && e.target.closest && e.target.closest( scope ) ) {
					apply( readClassic() );
				}
			} );
		}

		if ( document.body.classList.contains( 'block-editor-page' ) ) {
			// core/editor 스토어가 늦게 등록될 수 있어 잠시 재시도.
			var storeTries = 0;
			( function waitForStore() {
				if ( window.wp && window.wp.data && wireBlockEditor() ) { return; }
				if ( storeTries++ < 60 ) { window.setTimeout( waitForStore, 100 ); }
			} )();
		} else {
			wireClassicEditor();
		}
	}

	boot();
} )();
JS;
	}

	/**
	 * Persists meta box values.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post Post.
	 */
	public function save_meta_boxes( $post_id, $post ) {
		if ( ! in_array( $post->post_type, $this->rating_post_types(), true ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( brandtalk()->get_config( 'meta-boxes' ) as $box ) {
			foreach ( bt\get_array_value( $box, 'fields', [] ) as $meta_key => $field ) {
				$type = bt\get_array_value( $field, 'type' );

				if ( 'rating' === $type ) {
					$raw = isset( $_POST[ $meta_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ) : '';

					if ( '' === $raw ) {
						delete_post_meta( $post_id, $meta_key );
					} else {
						update_post_meta( $post_id, $meta_key, Post_Type::sanitize_rating( $raw ) );
					}
				} elseif ( 'criteria_rating' === $type ) {
					$raw   = isset( $_POST[ $meta_key ] ) && is_array( $_POST[ $meta_key ] ) ? wp_unslash( $_POST[ $meta_key ] ) : [];
					$clean = [];

					foreach ( $raw as $criterion_id => $score ) {
						$criterion_id = (int) $criterion_id;
						$score        = (int) $score;

						if ( $criterion_id > 0 && $score >= 1 && $score <= 5 ) {
							$clean[ $criterion_id ] = $score;
						}
					}

					if ( $clean ) {
						update_post_meta( $post_id, $meta_key, $clean );
					} else {
						delete_post_meta( $post_id, $meta_key );
					}
				} elseif ( 'target_post' === $type ) {
					$target = isset( $_POST[ $meta_key ] ) ? (int) $_POST[ $meta_key ] : 0;
					$valid  = $target
						&& get_post( $target )
						&& ! in_array( get_post_type( $target ), [ Review::POST_TYPE, 'attachment' ], true )
						&& ! in_array( $target, $this->linked_post_ids( $post_id ), true );

					if ( $valid ) {
						update_post_meta( $post_id, $meta_key, $target );
					} else {
						delete_post_meta( $post_id, $meta_key );
					}
				}
			}
		}

		$this->sync_review_title( $post_id );
	}

	/**
	 * 리뷰 제목을 연결된 대상 글의 제목으로 맞춘다. (제목 미지원 CPT라 코드로 설정.)
	 *
	 * @param int $review_id Review post id.
	 */
	private function sync_review_title( $review_id ) {
		static $busy = false;

		if ( $busy || Review::POST_TYPE !== get_post_type( $review_id ) ) {
			return;
		}

		$target = (int) get_post_meta( $review_id, 'brandtalk_target', true );
		$title  = $target ? get_the_title( $target ) : '';

		if ( '' === $title || get_post_field( 'post_title', $review_id ) === $title ) {
			return;
		}

		$busy = true;
		wp_update_post(
			[
				'ID'         => $review_id,
				'post_title' => $title,
			]
		);
		$busy = false;
	}

	/**
	 * 대상 글이 저장될 때, 그 글을 가리키는 리뷰들의 제목을 다시 맞춘다.
	 *
	 * @param int      $post_id Saved post id.
	 * @param \WP_Post $post    Saved post.
	 */
	public function sync_reviews_for_target( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || Review::POST_TYPE === $post->post_type ) {
			return;
		}

		$reviews = get_posts(
			[
				'post_type'        => Review::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => 'brandtalk_target',
				'meta_value'       => (int) $post_id,
				'suppress_filters' => false,
			]
		);

		foreach ( $reviews as $rid ) {
			$this->sync_review_title( (int) $rid );
		}
	}
}
