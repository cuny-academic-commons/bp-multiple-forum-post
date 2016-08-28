<?php
/*
	Plugin Name: BuddyPress Multiple Forum Post
	Description: Allows users to post to multiple BP Group forums at once
	Text Domain: bp-multiple-forum-post
	Version: 1.0.0
*/

/**
 * Initialize BP Multiple Forum Post
 *
 * Only if BuddyPress in enabled.
**/
function bpmfp_init() {
	if ( function_exists( 'is_buddypress' ) ) {
		define( 'BPMFP_VERSION', '1.0.0' );

		require( plugin_dir_path( __FILE__ ) . 'includes/functions.php' );
		require( plugin_dir_path( __FILE__ ) . 'includes/activity-feed.php' );
		require( plugin_dir_path( __FILE__ ) . 'includes/email-notifications.php' );
		bpmfp_register_async_action();
	}
}
add_action( 'init', 'bpmfp_init' );

/**
 * Register our asynchronous topic duplication logic.
 *
 * @see BPMFP_Async_Duplicate_Topic
**/
function bpmfp_register_async_action() {
	if( false === class_exists( 'WP_Async_Task' ) ) {
		require( plugin_dir_path( __FILE__ ) . 'includes/lib/wp-async-task.php' );
	}
	if ( false === class_exists( 'BPMFP_Async_Topic_Create' ) ) {
		require( plugin_dir_path( __FILE__ ) . 'includes/class-bpmfp-async-duplicate-topic.php' );
	}
	// We ned to call the BPMFP_Async_Duplicate_Topic constructor to hook in our asynchronous request logic.
	$bpmfp_async_action = new BPMFP_Async_Duplicate_Topic();
}

/**
 * Display groups available for cross-posting.
 *
 * On bbpress topic create forms within a group forum, show a list of
 * other groups the user can cross-post a new topic to.
 *
 * @uses bpmfp_get_other_groups_for_user()
**/
function bpmfp_show_other_groups() {
	if ( false === function_exists( 'buddypress' ) ) {
		return;
	}

	// Bail if we're on a topic edit screen, or not in a group
	if ( bbp_is_topic_edit() || 0 === bp_get_current_group_id() ) {
		return;
	}

	wp_enqueue_script( 'bpmfp', plugins_url( 'bp-multiple-forum-post/bpmfp.js' ), array( 'jquery' ), BPMFP_VERSION, true );
	
	$user_id = bp_loggedin_user_id();
	$user_groups = bpmfp_get_other_groups_for_user( $user_id );
	if ( $user_groups['total'] > 0) {
		echo '<div id="crosspost-div">';
		echo '<fieldset>';
		echo '<legend>' . __( 'Post to multiple groups:', 'bp-multiple-forum-post' ) . '</legend>';
		echo "<div>" . __( 'By selecting other groups below, you can post this same topic on their forums at the same time.', 'bp-multiple-forum-post' ) . "</div>";
		echo '<ul id="crosspost-groups">';
		foreach( $user_groups['groups'] as $group ) {
			if ( empty( $group->enable_forum ) ) {
				continue;
			}
			echo '<li>';
			echo '<input type="checkbox" name="groups-to-post-to[]" value="' . $group->id . '" id="group-' . $group->id . '">  ';
			echo '<label for="group-' . $group->id . '">' . esc_html( stripslashes( $group->name ) ) . '</label>';
			echo '</li>';
		}
		wp_nonce_field( 'post_to_multiple_forums', 'bp_multiple_forum_post' );
		echo '</fieldset>';
		echo '<a id="crosspost-show-more" style="display: none;" href="#">' . __( 'Show all', 'bp-multiple-forum-post' ) . '</a>';
		echo '</div>';
	}
}
add_action( 'bbp_theme_before_topic_form_submit_wrapper', 'bpmfp_show_other_groups', 20 );

/**
 * Duplicate the new topic into the other group forums chosen by the user.
 *
 * Copies over title, content, tags, and attachments from original topic. Also
 * updates bbpress metadata for the forums the topic is being cross-posted to,
 * uses metadata to save the relationship between original and duplicate topics,
 * and creates the BuddyPress Activities for the duplicate topics.
 * 
 * @param Array $args An array of arguments passed by BPMFP_Async_Duplicate_Topic::run_action()
 *
 * @see BPMFP_Async_Duplicate_Topic
 * @uses bpmfp_create_duplicate_activities()
**/
function bpmfp_create_duplicate_topics( $args ) {
	if ( empty( $args['topic-id'] ) ||
		empty( $args['topic-title'] ) ||
		empty( $args['topic-content'] ) ||
		empty( $args['groups-to-post-to'] ) ||
		! is_array( $args['groups-to-post-to'] )
	) {
		return;
	}

	$topic_id          = $args['topic-id'];
	$topic_tags        = $args['topic-tags'];
	$topic_title       = $args['topic-title'];
	$topic_content     = $args['topic-content'];
	$groups_to_post_to = $args['groups-to-post-to'];

	// An array to hold information about the duplicate topics, for creating activities for them later
	$duplicate_topics = array();
	foreach( $groups_to_post_to as $group_id ) {
		if( ! groups_is_user_member( bp_loggedin_user_id(), $group_id ) ) {
			continue;
		}
		// Get the forum ID for the group to post the duplicate topic in
		$group_forum_ids = groups_get_groupmeta( $group_id, 'forum_id' );
		$group_forum_id = is_array( $group_forum_ids ) ? reset( $group_forum_ids ) : intval( $group_forum_ids );
		
		// Add tags to duplicate topic.
		// Taken from bbp_new_topic_handler() in bbpress/includes/topics/functions.php
		$terms = '';
		if ( bbp_allow_topic_tags() && ! empty( $topic_tags ) ) {
			// Escape tag input
			$terms = esc_attr( strip_tags( $topic_tags ) );
			// Explode by comma
			if ( strstr( $terms, ',' ) ) {
				$terms = explode( ',', $terms );
			}
			// Add topic tag ID as main key
			$terms = array( bbp_get_topic_tag_tax_id() => $terms );
		}
		$topic_data = array(
			// Parent of the topic is the forum itself, not the group
			'post_parent' => $group_forum_id,
			'post_content' => esc_attr( $topic_content ),
			'post_title' => esc_attr( $topic_title ),
			'tax_input' => $terms,
		);
		$topic_meta = array(
			'forum_id' => $group_forum_id,
		);
		// Create the duplicate topic for this group
		$new_topic_id = bbp_insert_topic( $topic_data, $topic_meta );

		// Copy the attachments, keeping them linked to the original file
		// Make sure the attachments plugin is activated
		if ( function_exists( 'd4p_get_post_attachments' ) ) {
			// Get the original attachments
			$original_attachments = d4p_get_post_attachments( $topic_id );
			if( !empty( $original_attachments ) ) {
				// For each attachment, copy its MIME type, title, etc
				foreach( $original_attachments as $attachment ) {
					$new_attachment_info = array(
						'post_mime_type' => $attachment->post_mime_type,
						'post_title' => $attachment->post_title,
						'post_content' => '',
						'post_status' => 'inherit',
					);
					// Create the new attachment, linking it to the original file
					$new_attach_id = wp_insert_attachment( $new_attachment_info, get_attached_file( $attachment->ID ), $new_topic_id );
					$new_attach_data = wp_generate_attachment_metadata( $new_attach_id, get_attached_file( $attachment->ID ) );
					wp_update_attachment_metadata( $new_attach_id, $new_attach_data );
					update_post_meta( $new_attach_id, '_bbp_attachment', '1' );
				}
			}
		}

		// Update forum metadata for duplicate topic
		// Taken from bbp_update_topic() in bbpress/includes/topics/functions.php
		// Update poster IP
		update_post_meta( $new_topic_id, '_bbp_author_ip', bbp_current_author_ip(), false );
		// Last active time
		$last_active = current_time( 'mysql' );
		// Reply topic meta
		bbp_update_topic_last_reply_id      ( $new_topic_id, 0            );
		bbp_update_topic_last_active_id     ( $new_topic_id, $new_topic_id    );
		bbp_update_topic_last_active_time   ( $new_topic_id, $last_active );
		bbp_update_topic_reply_count        ( $new_topic_id, 0            );
		bbp_update_topic_reply_count_hidden ( $new_topic_id, 0            );
		bbp_update_topic_voice_count        ( $new_topic_id               );
		// Not in 'bbp_update_topic', but needed so that bbp_update_topic_walker below
		// will actually update counts, etc., for parent forum of topic
		bbp_clean_post_cache( $group_forum_id );
		// Walk up ancestors and do the dirty work
		bbp_update_topic_walker( $new_topic_id, $last_active, $group_forum_id, 0, false );

		// Record that this topic is a duplicate of the original.
		add_post_meta( $new_topic_id, '_duplicate_of', $topic_id );
		// Record on the original topic that this is a duplicate of it
		add_post_meta( $topic_id, '_duplicates', $new_topic_id );

		// Gather the info we need to create the activity for this duplicate topic in
		// a separate loop
		$topic_info = array();
		$topic_info['new_topic_id'] = $new_topic_id;
		$topic_info['group_forum_id'] = $group_forum_id;
		$topic_info['group_id'] = $group_id;
		$duplicate_topics[] = $topic_info;
	}
	bpmfp_create_duplicate_activities( $topic_id, $duplicate_topics );
}
add_action( 'wp_async_bbp_new_topic_post_extras', 'bpmfp_create_duplicate_topics' );
/**
 * Create BuddyPress Activities for duplicate topics.
 *
 * Besides creating the duplicate activities, also saves the relationship between
 * original and duplicate activities in metadata, and sends the notification for
 * the original topic creation activity, which was interrupted earlier. 
 *
 * @param int $original_topic_id The ID of the original topic being duplicated.
 * @param Array $duplicate_topics An Array of information about the duplicate topics
**/
function bpmfp_create_duplicate_activities( $original_topic_id, $duplicate_topics ) {
	// Get the activity for the original topic being duplicated
	$original_activity_query_results = BP_Activity_Activity::get( array(
		'filter_query' => array(
				'relation'	=> 'and',
				array(
					'column' 	=> 'type',
					'value' 	=> 'bbp_topic_create'
				),
				array(
					'column'	=> 'secondary_item_id',
					'value'		=> $original_topic_id
				),
		),
	) );
	// Bail if an activity wasn't created for the original topic creation
	if ( empty( $original_activity_query_results['activities'] ) ) {
		return;
	}

	$original_activity_id = $original_activity_query_results['activities'][0]->id;
	// Give the original activity a meta value indicating that is has duplicates
	bp_activity_add_meta( $original_activity_id, '_has_duplicates', true );

	// Send the email for the original activity, which was interrupted earlier
	$original_activity = new BP_Activity_Activity( $original_activity_id );
	ass_group_notification_activity( $original_activity );
	
	$bp = buddypress();
	// Create the activities for the duplicate topics
	foreach( $duplicate_topics as $duplicate_topic ) {
		// We need to manually set the current BuddyPress group, because this is running in an asynchronous request.
		$bp->groups->current_group = groups_get_group( array( 'group_id' => $duplicate_topic['group_id'] ) );
		$bbp_buddypress_activity = new BBP_BuddyPress_Activity;
		$bbp_buddypress_activity->topic_create( $duplicate_topic['new_topic_id'], $duplicate_topic['group_forum_id'], array(), bp_loggedin_user_id() );
		// Update the last activity time for the group
		groups_update_last_activity( $duplicate_topic['group_id'] );

		// Give the duplicate topic creation activity a meta value pointing to the original activity
		// for the topic being duplicated
		$new_activity_id = get_post_meta( $duplicate_topic['new_topic_id'], '_bbp_activity_id', true );
		$new_activity = new BP_Activity_Activity( $new_activity_id );
		bp_activity_add_meta( $new_activity_id, '_duplicate_of', $original_activity_id );
	}
}

/**
 * Append "This topic also posted in" message to the initial post in a topic that's been cross-posted.
 *
 * @uses bpmfp_get_duplicate_topics_ids()
 * @uses bpmfp_get_this_topic_also_posted_in_message()
**/
function bpmfp_add_duplicate_topics_to_forum_topic() {
	$reply_id = bbp_get_reply_id();
	$reply_post = get_post( $reply_id );
	// Only show on the first item in a thread - the topic.
	if ( 0 !== $reply_post->menu_order ) {
		return;
	}
	$topic_id = $reply_id;
	$all_topic_ids = bpmfp_get_duplicate_topics_ids( $topic_id );
	if ( ! empty( $all_topic_ids ) ) {
		echo bpmfp_get_this_topic_also_posted_in_message( $all_topic_ids, 'forum_topic' );
	}
}
add_action( 'bbp_theme_after_reply_content', 'bpmfp_add_duplicate_topics_to_forum_topic' );

function bpmfp_load_textdomain() {
	load_plugin_textdomain( 'bp-multiple-forum-post' );
}
add_action( 'plugins_loaded', 'bpmfp_load_textdomain' );
