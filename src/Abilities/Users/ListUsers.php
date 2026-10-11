<?php
/**
 * Lists user accounts.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Users;

use WP_User_Query;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListUsers extends AbilityBase {

	public function slug(): string {
		return 'list-users';
	}

	public function label(): string {
		return __( 'List users', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists user accounts with id, login, display name, email, roles, registration date (UTC) and number of published posts, newest first. Filter by role slug or search login, email, URL and display name. Paged: returns total and total_pages. Also returns the site\'s roles (slug and name). Never returns passwords or session data.', 'wproadie' );
	}

	public function group(): string {
		return 'users';
	}

	public function risk(): string {
		return Mode::RISK_ADMIN;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'role'     => array(
					'type'        => 'string',
					'description' => __( 'Only users with this role slug, e.g. "editor".', 'wproadie' ),
				),
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Text to find in login, email, URL or display name.', 'wproadie' ),
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Page number.', 'wproadie' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => __( 'Users per page, up to 100.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'list_users' );
	}

	protected function execute( array $input ) {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );

		$args = array(
			'number'      => $per_page,
			'paged'       => $page,
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'count_total' => true,
		);
		if ( ! empty( $input['role'] ) ) {
			$args['role'] = (string) $input['role'];
		}
		if ( isset( $input['search'] ) && '' !== trim( $input['search'] ) ) {
			$args['search']         = '*' . trim( $input['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_url', 'user_nicename', 'display_name' );
		}

		$query  = new WP_User_Query( $args );
		$users  = (array) $query->get_results();
		$counts = $users ? count_many_users_posts( wp_list_pluck( $users, 'ID' ) ) : array();

		$list = array();
		foreach ( $users as $user ) {
			$list[] = array(
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'roles'        => array_values( $user->roles ),
				'registered'   => $user->user_registered,
				'post_count'   => (int) ( $counts[ $user->ID ] ?? 0 ),
			);
		}

		$roles = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[] = array(
				'slug' => $slug,
				'name' => translate_user_role( $name ),
			);
		}

		$total = (int) $query->get_total();
		return array(
			'users'       => $list,
			'total'       => $total,
			'page'        => $page,
			'total_pages' => (int) ceil( $total / $per_page ),
			'roles'       => $roles,
		);
	}
}
