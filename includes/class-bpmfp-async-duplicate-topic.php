<?php

/**
 * Hooks in our asynchronous topic duplication logic.
 *
 * @see WP_Async_Task
**/
class BPMFP_Async_Duplicate_Topic extends WP_Async_Task {
	// The action to hook our asynchronous request to.
	protected $action = 'bbp_new_topic_post_extras';

	/**
	 * Prepare data submitted through New Topic form for the asynchronous duplicate topic request.
	 * And make sure that this is a cross-post request, and that it's valid.
	 *
	 * @param Array $data The raw data passed by the bbp_new_topic_post_extras action hook.
	 * @return Array The data to pass along as POST data in our asynchronous request.
	**/
	protected function prepare_data( $data ) {
		// Check to make sure Buddypress is turned on
		if ( false === function_exists( 'buddypress' ) ) {
			throw new Exception( 'BuddyPress not active' );
		}

		if ( empty( $_POST['groups-to-post-to'] ) ) {
			throw new Exception( 'BPMFP - No groups to post to' );
			return;
		}

		// Nonce check
		if ( ! isset( $_POST['bp_multiple_forum_post'] )
				|| ! wp_verify_nonce( $_POST['bp_multiple_forum_post'], 'post_to_multiple_forums' ) ) {
			throw new Exception( 'BPMFP - Nonce failure' );
		}

		$topic_id = $data[0];

		return array(
			'topic-id'          => $topic_id,
			'groups-to-post-to' => $_POST['groups-to-post-to'],
			'topic-tags'        => $_POST['bbp_topic_tags'],
			'topic-content'     => $_POST['bbp_topic_content'],
			'topic-title'       => $_POST['bbp_topic_title'],
		);
	}

	/**
	 * Do the wp_async_bbp_new_topic_post_extras action.
	 *
	 * Called during the asynchronous wp_http_post() request.
	 * Passes along the data prepared in prepare_data() above to bpmfp_create_duplicate_topics().
	 *
	 * @see bpmfp_create_duplicate_topics()
	**/
	protected function run_action() {
		$args = array();

		$args['topic-id']          = $_POST['topic-id'];
		$args['topic-tags']        = $_POST['topic-tags'];
		$args['topic-content']     = $_POST['topic-content'];
		$args['topic-title']       = $_POST['topic-title'];
		$args['groups-to-post-to'] = $_POST['groups-to-post-to'];

		do_action( 'wp_async_' . $this->action, $args );
	}
}

?>