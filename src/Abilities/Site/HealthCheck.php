<?php
/**
 * Runs WordPress's own Site Health checks.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Site;

use WP_Site_Health;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class HealthCheck extends AbilityBase {

	public function slug(): string {
		return 'health-check';
	}

	public function label(): string {
		return __( 'Health check', 'wproadie' );
	}

	public function description(): string {
		return __( 'Runs the Site Health checks WordPress runs on its Tools > Site Health screen and returns the critical issues and recommendations, each with a description and suggested action. Runs the synchronous checks only (including the REST API check, which makes one request back to the site); the background checks such as loopback and HTTPS are skipped.', 'wproadie' );
	}

	public function group(): string {
		return 'site';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'include_passed' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Also return the checks that passed.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		// Site Health and the helpers its tests call live in wp-admin.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';

		$health         = WP_Site_Health::get_instance();
		$include_passed = ! empty( $input['include_passed'] );
		$counts         = array(
			'critical'    => 0,
			'recommended' => 0,
			'good'        => 0,
		);
		$results        = array();

		foreach ( WP_Site_Health::get_tests()['direct'] as $key => $test ) {
			$result = $this->run( $health, $test['test'] ?? null );
			if ( ! is_array( $result ) || empty( $result['status'] ) ) {
				continue;
			}

			$status = (string) $result['status'];
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
			if ( 'good' === $status && ! $include_passed ) {
				continue;
			}

			$results[] = array(
				'test'        => (string) ( $result['test'] ?? $key ),
				'status'      => $status,
				'label'       => wp_strip_all_tags( (string) ( $result['label'] ?? '' ) ),
				'category'    => wp_strip_all_tags( (string) ( $result['badge']['label'] ?? '' ) ),
				'description' => $this->plain( (string) ( $result['description'] ?? '' ) ),
				'actions'     => $this->plain( (string) ( $result['actions'] ?? '' ) ),
			);
		}

		// Critical first, then recommendations.
		usort(
			$results,
			static function ( $a, $b ) {
				$order = array(
					'critical'    => 0,
					'recommended' => 1,
					'good'        => 2,
				);
				return ( $order[ $a['status'] ] ?? 3 ) <=> ( $order[ $b['status'] ] ?? 3 );
			}
		);

		return array(
			'summary' => $counts,
			'results' => $results,
		);
	}

	/**
	 * Runs one direct test the way the Site Health screen does: a string
	 * names a get_test_* method, anything else must be callable.
	 *
	 * @param WP_Site_Health $health Site Health instance.
	 * @param mixed          $test   Test reference from WP_Site_Health::get_tests().
	 * @return mixed
	 */
	private function run( WP_Site_Health $health, $test ) {
		if ( is_string( $test ) ) {
			$method = 'get_test_' . $test;
			if ( method_exists( $health, $method ) && is_callable( array( $health, $method ) ) ) {
				return apply_filters( 'site_status_test_result', $health->$method() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			}
			return null;
		}
		if ( is_callable( $test ) ) {
			return apply_filters( 'site_status_test_result', call_user_func( $test ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		}
		return null;
	}

	private function plain( string $html ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}
}
