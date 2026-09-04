<?php
/**
 * Installer component — 커스텀 테이블 생성/갱신. 정책 §12.1(비활성화 보존, uninstall 제거).
 *
 * @package BrandTalk\Components
 */

namespace BrandTalk\Components;

use BrandTalk\Options;
use BrandTalk\Models\Reaction;
use BrandTalk\Models\Follow;
use BrandTalk\Models\Verification;
use BrandTalk\Models\Trust_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates database tables.
 */
final class Installer extends Component {

	/**
	 * Boot hooks.
	 */
	protected function boot() {
		add_action( 'brandtalk/v1/activate', [ $this, 'install' ], 10 );
		add_action( 'brandtalk/v1/update', [ $this, 'install' ], 10 );

		add_action( 'brandtalk/v1/activate', [ $this, 'flush_rewrite_rules' ], 100 );
		add_action( 'brandtalk/v1/deactivate', [ $this, 'flush_rewrite_rules' ] );
	}

	/**
	 * Runs dbDelta for every model table and bootstraps options.
	 */
	public function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		foreach ( [ Reaction::class, Follow::class, Verification::class, Trust_Log::class ] as $model ) {
			dbDelta( $model::schema( $collate ) );
		}

		Options::bootstrap();
	}

	/**
	 * Refreshes permalinks.
	 */
	public function flush_rewrite_rules() {
		flush_rewrite_rules( false );
	}
}
