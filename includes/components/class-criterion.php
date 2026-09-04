<?php
/**
 * Criterion component — 재사용 가능한 하위 별점 항목과 카테고리 연결.
 *
 *  - 하위 별점 항목 = `brandtalk_criterion` 택소노미 텀 (WP 기본 CRUD 화면 사용).
 *  - 카테고리 ↔ 항목 연결 = `brandtalk_category` 텀 메타 `brandtalk_criteria` (항목 ID 배열, 순서 보존).
 *  - 동일 항목을 여러 카테고리에서 재사용할 수 있다.
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Manages rating criteria and their category links.
 */
final class Criterion extends Component {

	const TAXONOMY      = 'brandtalk_criterion';
	const CATEGORY_TAX  = 'brandtalk_category';
	const TERM_META_KEY = 'brandtalk_criteria';
	const NONCE_ACTION  = 'brandtalk_category_criteria';
	const NONCE_NAME    = 'brandtalk_category_criteria_nonce';

	/**
	 * Boot hooks (admin term-form integration).
	 */
	protected function boot() {
		// 항목이 삭제되면 카테고리 연결에서도 제거(프론트/REST 는 자체적으로 stale 를 걸러냄).
		add_action( 'delete_' . self::TAXONOMY, [ $this, 'prune_deleted_criterion' ], 10, 3 );

		if ( ! is_admin() ) {
			return;
		}

		add_action( self::CATEGORY_TAX . '_add_form_fields', [ $this, 'render_add_field' ] );
		add_action( self::CATEGORY_TAX . '_edit_form_fields', [ $this, 'render_edit_field' ], 10, 2 );
		add_action( 'created_' . self::CATEGORY_TAX, [ $this, 'save_field' ] );
		add_action( 'edited_' . self::CATEGORY_TAX, [ $this, 'save_field' ] );
	}

	/**
	 * 삭제된 항목 ID를 모든 카테고리의 연결 목록에서 제거.
	 *
	 * @param int $term_id Deleted criterion term id.
	 */
	public function prune_deleted_criterion( $term_id ) {
		$term_id = (int) $term_id;

		$categories = get_terms(
			[
				'taxonomy'   => self::CATEGORY_TAX,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => [
					[
						'key'     => self::TERM_META_KEY,
						'value'   => 'i:' . $term_id . ';',
						'compare' => 'LIKE',
					],
				],
			]
		);

		if ( is_wp_error( $categories ) ) {
			return;
		}

		foreach ( $categories as $cat_id ) {
			$ids = array_values( array_diff( self::ids_for_category( $cat_id ), [ $term_id ] ) );

			if ( $ids ) {
				update_term_meta( (int) $cat_id, self::TERM_META_KEY, $ids );
			} else {
				delete_term_meta( (int) $cat_id, self::TERM_META_KEY );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* 조회 헬퍼                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * 모든 하위 별점 항목.
	 *
	 * @return array [{id, slug, name, description}]
	 */
	public static function all() {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		return array_map( [ __CLASS__, 'shape' ], $terms );
	}

	/**
	 * 카테고리에 연결된 항목 ID 배열(저장된 순서).
	 *
	 * @param int $category_id Category term id.
	 * @return int[]
	 */
	public static function ids_for_category( $category_id ) {
		$ids = get_term_meta( (int) $category_id, self::TERM_META_KEY, true );

		if ( ! is_array( $ids ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/**
	 * 카테고리에 연결된 항목(존재하는 텀만, 순서 보존).
	 *
	 * @param int $category_id Category term id.
	 * @return array [{id, slug, name, description}]
	 */
	public static function for_category( $category_id ) {
		$out = [];

		foreach ( self::ids_for_category( $category_id ) as $criterion_id ) {
			$term = get_term( $criterion_id, self::TAXONOMY );

			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = self::shape( $term );
			}
		}

		return $out;
	}

	/**
	 * 여러 카테고리에 연결된 항목의 합집합(순서 유지, 중복 제거).
	 *
	 * @param int[] $category_ids Category term ids.
	 * @return array [{id, slug, name, description}]
	 */
	public static function for_categories( array $category_ids ) {
		$seen = [];
		$out  = [];

		foreach ( $category_ids as $cat_id ) {
			foreach ( self::for_category( $cat_id ) as $criterion ) {
				if ( ! isset( $seen[ $criterion['id'] ] ) ) {
					$seen[ $criterion['id'] ] = true;
					$out[]                    = $criterion;
				}
			}
		}

		return $out;
	}

	/**
	 * 항목이 특정 카테고리에서 유효한지.
	 *
	 * @param int   $criterion_id Criterion term id.
	 * @param int[] $category_ids Category term ids.
	 * @return bool
	 */
	public static function is_valid_for_categories( $criterion_id, array $category_ids ) {
		foreach ( $category_ids as $cat_id ) {
			if ( in_array( (int) $criterion_id, self::ids_for_category( $cat_id ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 텀 → 표준 배열.
	 *
	 * @param \WP_Term $term Term.
	 * @return array
	 */
	public static function shape( $term ) {
		return [
			'id'          => (int) $term->term_id,
			'slug'        => $term->slug,
			'name'        => $term->name,
			'description' => $term->description,
		];
	}

	/* ------------------------------------------------------------------ */
	/* 카테고리 편집 화면: 연결 항목 선택 UI                               */
	/* ------------------------------------------------------------------ */

	/**
	 * 카테고리 "추가" 폼.
	 */
	public function render_add_field() {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="form-field">
			<label><?php esc_html_e( '하위 별점 항목', 'brandtalk' ); ?></label>
			<?php $this->render_checkboxes( [] ); ?>
			<p class="description"><?php esc_html_e( '이 카테고리의 리뷰에서 별점을 매길 하위 항목입니다. 항목은 여러 카테고리에서 공유됩니다.', 'brandtalk' ); ?></p>
		</div>
		<?php
	}

	/**
	 * 카테고리 "편집" 폼.
	 *
	 * @param \WP_Term $term Category term.
	 */
	public function render_edit_field( $term ) {
		$selected = self::ids_for_category( $term->term_id );
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( '하위 별점 항목', 'brandtalk' ); ?></label></th>
			<td>
				<?php
				wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
				$this->render_checkboxes( $selected );
				?>
				<p class="description">
					<?php esc_html_e( '체크한 항목이 이 카테고리의 리뷰 별점 항목이 됩니다. 항목 자체는', 'brandtalk' ); ?>
					<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=brandtalk_review' ) ); ?>"><?php esc_html_e( '하위 별점 항목', 'brandtalk' ); ?></a>
					<?php esc_html_e( '화면에서 추가·편집·삭제합니다.', 'brandtalk' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * 체크박스 목록(순서 = 선택 순서를 hidden 필드로 보존).
	 *
	 * @param int[] $selected Selected criterion ids (ordered).
	 */
	private function render_checkboxes( $selected ) {
		$all = self::all();

		if ( ! $all ) {
			printf(
				'<p><em>%s</em> <a href="%s">%s</a></p>',
				esc_html__( '등록된 하위 별점 항목이 없습니다.', 'brandtalk' ),
				esc_url( admin_url( 'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=brandtalk_review' ) ),
				esc_html__( '지금 추가', 'brandtalk' )
			);
			return;
		}

		// 선택된 것 먼저(저장 순서), 나머지 뒤.
		$ordered = [];
		foreach ( $selected as $id ) {
			foreach ( $all as $c ) {
				if ( $c['id'] === (int) $id ) {
					$ordered[] = $c;
				}
			}
		}
		foreach ( $all as $c ) {
			if ( ! in_array( $c['id'], $selected, true ) ) {
				$ordered[] = $c;
			}
		}

		echo '<ul style="margin:.25em 0;max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:.5em .75em;border-radius:4px;">';
		foreach ( $ordered as $c ) {
			printf(
				'<li style="margin:.15em 0;"><label><input type="checkbox" name="brandtalk_criteria[]" value="%1$d" %2$s> %3$s</label></li>',
				(int) $c['id'],
				checked( in_array( $c['id'], $selected, true ), true, false ),
				esc_html( $c['name'] )
			);
		}
		echo '</ul>';
	}

	/**
	 * 카테고리 저장 시 연결 항목을 텀 메타에 기록.
	 *
	 * @param int $term_id Category term id.
	 */
	public function save_field( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$raw   = isset( $_POST['brandtalk_criteria'] ) ? (array) wp_unslash( $_POST['brandtalk_criteria'] ) : [];
		$valid = [];

		foreach ( $raw as $id ) {
			$id   = (int) $id;
			$term = $id ? get_term( $id, self::TAXONOMY ) : null;

			if ( $term && ! is_wp_error( $term ) ) {
				$valid[] = $id;
			}
		}

		$valid = array_values( array_unique( $valid ) );

		if ( $valid ) {
			update_term_meta( (int) $term_id, self::TERM_META_KEY, $valid );
		} else {
			delete_term_meta( (int) $term_id, self::TERM_META_KEY );
		}

		/**
		 * Fires after a category's linked criteria change.
		 *
		 * @hook brandtalk/v1/category/criteria_updated
		 * @param {int}   $term_id Category term id.
		 * @param {int[]} $valid Linked criterion ids.
		 */
		do_action( 'brandtalk/v1/category/criteria_updated', (int) $term_id, $valid );
	}
}
