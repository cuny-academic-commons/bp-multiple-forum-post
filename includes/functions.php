<?php

/**
 * Get groups this user is a member of, besides the one currently being viewed
 *
 * @param int $user_id The user to get the groups list for
 * @return Array See groups_get_groups documentation for the structure
 * of the returned Array.
**/
function bpmfp_get_other_groups_for_user( $user_id ) {
	if ( ! $user_id || ! bp_get_current_group_id() ) {
		return;
	}
	return groups_get_groups( array(
		'user_id' => $user_id,
		'per_page' => -1,
		'type'=> 'alphabetical',
		'show_hidden' => true,
		'exclude' => array( bp_get_current_group_id() ),
	) );
}

/**
 * Get an Array of topic IDs of the other topics cross-posted with the one given.
 *
 * @param int $topic_id The ID of the topic to get duplicate IDs for.
 * @return Array An array of topic IDs of topics cross-posted with the one given.
**/
function bpmfp_get_duplicate_topics_ids( $topic_id ) {
	$duplicate_of = get_post_meta( $topic_id, '_duplicate_of', true );
	$duplicates = get_post_meta( $topic_id, '_duplicates', false );

	// this topic is a duplicate
	$all_topic_ids = array();
	if ( $duplicate_of ) {
		$other_duplicate_ids = get_post_meta( $duplicate_of, '_duplicates', false );
		$all_topic_ids = array_merge( $other_duplicate_ids, array( $duplicate_of ) );
		$all_topic_ids = array_diff( $all_topic_ids, array( $topic_id ) );
	// this has duplicates
	} else if ( ! empty( $duplicates ) ) {
		$all_topic_ids = $duplicates;
	}
	$all_topic_ids = array_values( $all_topic_ids );
	return $all_topic_ids;
}

/**
 * Return a message (HTML-formatted) letting users know what other forums the topic was posted in.
 *
 * @param Array $topic_ids The Array of topic IDs of duplicates to be included in the message.
 * @param String $context The context for the message - a notification email ('email'); a topic thread ('forum_topic'), or an alert message ('alert')
 * @return String The HTML-formatted "This topic also posted in" message.
**/
function bpmfp_get_this_topic_also_posted_in_message( $topic_ids, $context = 'alert' ) {
	$return_message = '';

	if ( ! in_array( $context, array( 'alert', 'email', 'forum_topic' ) ) ) {
		return;
	}
	if ( ! is_array( $topic_ids ) || count( $topic_ids ) < 1 ) {
		return;
	}
	// make sure the array is 0-indexed, and isn't missing any indices
	$all_topic_ids = array_values( $topic_ids );
	$added_topic_links = array();
	for ( $index = 0; $index < count( $all_topic_ids ); $index++ ) {
		// Get the forum ID for the topic, and the group ID for the forum, and the group object.
		$topic_forum_id = get_post( $all_topic_ids[$index] )->post_parent;
		$forum_group_ids = bbp_get_forum_group_ids( $topic_forum_id );
		$forum_group_id = $forum_group_ids[0];
		$forum_group = groups_get_group( array( 'group_id' => $forum_group_id ) );

		// If the group that the duplicate topic is in is public, or the current user is a member of it,
		// or the current user is a moderator, then include a link to the topic.
		if ( 'public' == $forum_group->status || groups_is_user_member( bp_loggedin_user_id(), $forum_group_id ) || current_user_can( 'bp_moderate' ) ) {
			$topic_link = bbp_get_topic_permalink( $topic_ids[$index] );
		}

		$forum_name = get_post_field( 'post_title', $topic_forum_id, 'raw' );
		if ( $forum_name ) {
			$added_topic_link = esc_html( $forum_name );
			if ( $topic_link && ( $context === 'forum_topic' || $context === 'email' ) ) {
				$added_topic_link = ' <a href="' . esc_url( $topic_link ) . '">' . $added_topic_link . '</a>';
			}
		}
		if ( ! empty( $added_topic_link ) ) {
			$added_topic_links[] = $added_topic_link;
		}
	}
	$return_message = sprintf( esc_html__( 'This topic was also posted in: %1$s.', 'bp-multiple-forum-post' ), implode( ', ', $added_topic_links ) );
	if ( $context === 'forum_topic' ) {
		$return_message = '<div class="posted-in-other-forums">' . $return_message . '</div>';
	}

	// Don't enqueue the styling if we're creating an email notification
	if ( $context != 'email' ) {
		wp_enqueue_style( 'bpmfp-css', plugins_url( 'bp-multiple-forum-post/bpmfp.css' ), array(), BPMFP_VERSION );
	}
	return $return_message;
}

/**
 * Get the ID of the forum associated with a topic create BuddyPress Activity
 * 
 * @param object $activity The activity object
 * @return bool|int If this activity isn't a topic create within a group, return false. Otherwise, return the ID of the forum the topic is in.
**/
function bpmfp_get_forum_id_for_activity( $activity ) {
	if ( 'groups' !== $activity->component || 'bbp_topic_create' !== $activity->type ) {
		return false;
	}
	$activity_group_id = $activity->item_id;
	$activity_forum_ids = groups_get_groupmeta( $activity_group_id, 'forum_id' );
	$activity_forum_id = is_array( $activity_forum_ids ) ? reset( $activity_forum_ids ) : intval( $activity_forum_ids );
	return $activity_forum_id;
}