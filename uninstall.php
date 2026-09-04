<?php
/**
 * 완전 삭제. 정책정의서 §12.1.2: uninstall 시에만 테이블·리뷰·옵션을 제거한다.
 *
 * @package BrandTalk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1) 커스텀 테이블.
foreach ( [ 'reactions', 'follows', 'verifications', 'trust_log' ] as $suffix ) {
	$table = $wpdb->prefix . 'brandtalk_' . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// 2) 리뷰 CPT 게시물 + 메타.
$review_ids = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'brandtalk_review' )
);

foreach ( (array) $review_ids as $review_id ) {
	wp_delete_post( (int) $review_id, true );
}

// 3) 카테고리 택소노미 텀.
$term_ids = get_terms(
	[
		'taxonomy'   => 'brandtalk_category',
		'hide_empty' => false,
		'fields'     => 'ids',
	]
);

if ( ! is_wp_error( $term_ids ) ) {
	foreach ( (array) $term_ids as $term_id ) {
		wp_delete_term( (int) $term_id, 'brandtalk_category' );
	}
}

// 4) 옵션 + 유저 메타.
delete_option( 'brandtalk_options' );
delete_option( 'brandtalk_version' );
delete_option( 'brandtalk_activated' );
delete_option( 'brandtalk_installed_time' );

$wpdb->query(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('brandtalk_trust_score','brandtalk_trust_level','brandtalk_region_unlock')"
);
