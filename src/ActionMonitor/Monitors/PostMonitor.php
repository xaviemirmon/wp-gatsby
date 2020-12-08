<?php

namespace WPGatsby\ActionMonitor\Monitors;

use GraphQLRelay\Relay;
use WP_Post;

class PostMonitor extends Monitor {

	/**
	 * @return mixed|void
	 */
	public function init() {

		add_action( 'transition_post_status', [ $this, 'callback_transition_post_status' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'callback_deleted_post' ], 10, 1 );
		add_action( 'updated_post_meta', [ $this, 'callback_updated_post_meta' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'callback_updated_post_meta' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'callback_deleted_post_meta' ], 10, 4 );

	}

	/**
	 * Log all post status changes ( creating / updating / trashing )
	 *
	 * @action transition_post_status
	 *
	 * @param mixed    $new_status  New status.
	 * @param mixed    $old_status  Old status.
	 * @param WP_Post $post Post object.
	 */
	public function callback_transition_post_status( $new_status, $old_status, WP_Post $post ) {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// If the object is not a valid post, ignore it
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		// If the post type is not intentionally tracked, ignore it
		if ( ! $this->is_post_type_tracked( $post->post_type ) ) {
			return;
		}

		$initial_post_statuses = [ 'auto-draft', 'inherit', 'new' ];

		// If the post is a fresh post that hasn't been made public, don't track the action
		if ( in_array( $new_status, $initial_post_statuses, true ) ) {
			return;
		}

		// Saving drafts should not log actions
		if ( 'draft' === $new_status && 'draft' === $old_status ) {
			return;
		}

		// If the post isn't coming from a publish state or going to a publish state
		// we can ignore the action.
		if ( 'publish' !== $old_status && 'publish' !== $new_status ) {
			return;
		}

		$action_type = 'UPDATE';

		// If a post is moved from 'publish' to any other status, set the action_type to delete
		// to let Gatsby know it should no longer cache the post
		if ( 'publish' !== $new_status && 'publish' === $old_status ) {

			$action_type = 'DELETE';


		// If a post that was not published becomes published, set the action_type to create
		// to let Gatsby know it should fetch and cache the post
		} else if ( 'publish' === $new_status && 'publish' !== $old_status ) {

			$action_type = 'CREATE';

		// If a published post is saved, it's an update.
		} else if ( 'publish' === $new_status && 'publish' === $old_status ) {

			$action_type = 'UPDATE';

		}

		if ( 'UPDATE' !== $action_type ) {
			$this->log_user_update( $post );
		}

		$action = [
			'action_type' => $action_type,
			'title' => $action_type . ' ' . $post->post_title,
			'node_id' => $post->ID,
			'relay_id' => Relay::toGlobalId( 'post', $post->ID ),
			'graphql_single_name' => get_post_type_object( $post->post_type )->graphql_single_name,
			'graphql_plural_name' => get_post_type_object( $post->post_type )->graphql_plural_name,
			'status' => $new_status,
		];

		/**
		 * Log an action
		 */
		$this->log_action( $action );

	}

	/**
	 * Logs actions when posts are deleted
	 *
	 * @param int $post_id The ID of the deleted post
	 */
	public function callback_deleted_post( int $post_id ) {

		$post = get_post( $post_id );

		// If there is no post object, do nothing
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		// If the deleted post is of a post type that isn't being tracked, do nothing
		if ( ! $this->is_post_type_tracked( $post->post_type ) ) {
			return;
		}

		// Ignore posts that were deleted that weren't published
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$action = [
			'action_type' => 'DELETE',
			'title' => 'DELETE' . ' ' . $post->post_title,
			'node_id' => $post->ID,
			'relay_id' => Relay::toGlobalId( 'post', $post->ID ),
			'graphql_single_name' => get_post_type_object( $post->post_type )->graphql_single_name,
			'graphql_plural_name' => get_post_type_object( $post->post_type )->graphql_plural_name,
			'status' => 'trash',
		];

		// Log the action
		$this->log_action( $action );

		// Log user update
		$this->log_user_update( $post );

	}

	/**
	 * Whether the post type is tracked
	 *
	 * @param string $post_type The name of the post type to check
	 *
	 * @return bool
	 */
	public function is_post_type_tracked( string $post_type ) {
		return in_array( $post_type, $this->action_monitor->get_tracked_post_types(), true );
	}

	/**
	 * Logs activity when meta is updated on posts
	 *
	 * @param int $meta_id ID of updated metadata entry.
	 * @param int $object_id ID of the object metadata is for.
	 * @param string $meta_key Metadata key.
	 * @param mixed $meta_value Metadata value. Serialized if non-scalar.
	 */
	public function callback_updated_post_meta( int $meta_id, int $object_id, string $meta_key, $meta_value ) {

		if ( empty( $post = get_post( $object_id ) ) || ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		// If the deleted post is of a post type that isn't being tracked, do nothing
		if ( ! $this->is_post_type_tracked( $post->post_type ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( false === $this->should_track_meta( $meta_key, $meta_value, $post ) ) {
			return;
		}

		$action = [
			'action_type' => 'UPDATE',
			'title' => 'UPDATE' . ' ' . $post->post_title,
			'node_id' => $post->ID,
			'relay_id' => Relay::toGlobalId( 'post', $post->ID ),
			'graphql_single_name' => get_post_type_object( $post->post_type )->graphql_single_name,
			'graphql_plural_name' => get_post_type_object( $post->post_type )->graphql_plural_name,
			'status' => $post->post_status,
		];

		// Log the action
		$this->log_action( $action );

	}

	/**
	 * Logs activity when meta is updated on posts
	 *
	 * @param string[] $meta_ids    An array of metadata entry IDs to delete.
	 * @param int      $object_id   ID of the object metadata is for.
	 * @param string   $meta_key    Metadata key.
	 * @param mixed    $meta_value Metadata value. Serialized if non-scalar.
	 */
	public function callback_deleted_post_meta( array $meta_ids, int $object_id, string $meta_key, $meta_value ) {

		if ( empty( $post = get_post( $object_id ) ) || ! is_a( $post, 'WP_Post' ) ) {
			return;
		}

		// If the deleted post is of a post type that isn't being tracked, do nothing
		if ( ! $this->is_post_type_tracked( $post->post_type ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( false === $this->should_track_meta( $meta_key, $meta_value, $post ) ) {
			return;
		}

		$action = [
			'action_type' => 'UPDATE',
			'title' => 'UPDATE' . ' ' . $post->post_title,
			'node_id' => $post->ID,
			'relay_id' => Relay::toGlobalId( 'post', $post->ID ),
			'graphql_single_name' => get_post_type_object( $post->post_type )->graphql_single_name,
			'graphql_plural_name' => get_post_type_object( $post->post_type )->graphql_plural_name,
			'status' => $post->post_status,
		];

		// Log the action
		$this->log_action( $action );

	}

	/**
	 * Log a user update when a post is created or deleted, telling Gatsby to
	 * invalidate user caches
	 *
	 * @param WP_Post $post The post object being updated
	 *
	 * @todo:
	 *      This should be able to be removed at some point as Gatsby
	 *      _should_ be able to handle bi-directional relationships implicitly. When a Post is
	 *      created, Gatsby queries the full post fields, including the Author.node.id, and should
	 *      be able to handle the relationship between the new post and the author. When a post is
	 *      deleted, Gatsby should remove the post node and any queries (such as author archive pages)
	 *      that include references to the deleted post node should automatically be updated by Gatsby.
	 *
	 */
	public function log_user_update( WP_Post $post ) {

		if ( empty( $post->post_author ) || ! absint( $post->post_author ) ) {
			return;
		}

		if ( ! $user = get_user_by( 'id', absint( $post->post_author ) ) ) {
			return;
		}

		$user_monitor = $this->action_monitor->get_action_monitor( 'UserMonitor' );

		if ( empty( $user_monitor ) || ! $user_monitor instanceof UserMonitor ) {
			return;
		}

		if ( ! $user_monitor->is_published_author( $user->ID ) ) {
			$action_type = 'DELETE';
			$title = __( sprintf( 'Author "%s" has no more published posts', $user->display_name ), 'WPGatsby' );
		} else {
			$action_type = 'UPDATE';
			$title = __( sprintf( 'A published post by author "%s" was updated', $user->display_name ), 'WPGatsby' );
		}

		$this->log_action( [
			'action_type'         => $action_type,
			'title'               => $title,
			'node_id'             => $user->ID,
			'relay_id'            => Relay::toGlobalId( 'user', $user->ID ),
			'graphql_single_name' => 'user',
			'graphql_plural_name' => 'users',
			'status'              => 'none',
		] );
	}

}
