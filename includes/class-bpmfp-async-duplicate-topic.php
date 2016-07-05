<?php

class BPMFP_Async_Duplicate_Topic extends WP_Async_Task {
	protected $action = 'bbp_new_topic_post_extras';

	protected function prepare_data( $data ) {
		// Nonce check
		if ( ! isset( $_POST['bp_multiple_forum_post'] )
				|| ! wp_verify_nonce( $_POST['bp_multiple_forum_post'], 'post_to_multiple_forums' ) ) {
			_e( 'Sorry, there was a problem verifying your request.', 'bp-multiple-forum-post' );
			exit();
		}
		// Check to make sure buddypress is turned on
		if ( false === function_exists( 'buddypress' ) ) {
			return;
		}
		$groups_to_post_to = $_POST['groups-to-post-to'];
		if( empty( $groups_to_post_to ) ) {
			return;
		}
		$topic_id = $data[0];
		return array(
					'topic-id' => $topic_id,
					'groups-to-post-to' => $groups_to_post_to,
					'topic-tags' => $_POST['bbp_topic_tags'],
					'topic-content' => $_POST['bbp_topic_content'],
					'topic-title' => $_POST['bbp_topic_title'],
				);
	}

	protected function run_action() {
		$args = array();

		$args['topic-id'] = $_POST['topic-id'];
		$args['topic-tags'] = $_POST['topic-tags'];
		$args['topic-content'] = $_POST['topic-content'];
		$args['topic-title'] = $_POST['topic-title'];
		$args['groups-to-post-to'] = $_POST['groups-to-post-to'];

		do_action( 'wp_async_' . $this->action, $args );
	}
}

?>