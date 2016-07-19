<?php
/**
 * Append "This topic also posted in" message to BuddyPress Activity email notifications.
 *
 * @param String $content The original content of the email notification.
 * @param object $activity The activity the notification is being generated for.
 * @return String The modified content with the duplicate topics message appended.
 * 
 * @uses bpmfp_get_this_topic_also_posted_in_message()
**/
function bpmfp_add_duplicate_topics_to_email_notification( $content, $activity ) {	
	if ( 'groups' !== $activity->component || 'bbp_topic_create' !== $activity->type ) {
		return $content;
	}
	$topic_id = $activity->secondary_item_id;
	$all_topic_ids = bpmfp_get_duplicate_topics_ids( $topic_id );
	if ( empty( $all_topic_ids ) ) {
		return $content;
	}
	$duplicate_post_message = "\n" . bpmfp_get_this_topic_also_posted_in_message( $all_topic_ids, 'email' );

	return $content . $duplicate_post_message;
}
add_filter( 'bp_ass_activity_notification_content', 'bpmfp_add_duplicate_topics_to_email_notification', 100, 2 );

/**
 * Ensure that GES does not send multiple emails to a given user for a given event.
 *
 * @since 1.0.0
 *
 * @param bool   $send_it  Whether to send to the given user.
 * @param object $activity Activity object.
 * @param int    $user_id  User ID.
 * @return bool
 */
function bpmfp_send_bpges_notification_for_user( $send_it, $activity, $user_id ) {
	global $_bpmfp_bpges_sent;

	if ( 'groups' !== $activity->component || 'bbp_topic_create' !== $activity->type ) {
		return $send_it;
	}

	$topic_id = $activity->secondary_item_id;
	$all_topic_ids = bpmfp_get_duplicate_topics_ids( $topic_id );
	if ( empty( $all_topic_ids ) ) {
		return $send_it;
	}

	if ( ! isset( $_bpmfp_bpges_sent ) ) {
		$_bpmfp_bpges_sent = array();
	}
	if ( ! isset( $_bpmfp_bpges_sent[ $user_id ] ) ) {
		$_bpmfp_bpges_sent[ $user_id ] = array();
	}

	foreach ( $all_topic_ids as $all_topic_id ) {
		// If an activity corresponding to this activity has already been triggered, bail.
		if ( isset( $_bpmfp_bpges_sent[ $user_id ][ $all_topic_id ] ) ) {
			$send_it = false;
			break;
		}
	}
	$_bpmfp_bpges_sent[ $user_id ][ $topic_id ] = 1;
	return $send_it;
}
add_filter( 'bp_ass_send_activity_notification_for_user', 'bpmfp_send_bpges_notification_for_user', 10, 3 );

/**
 * Prevent notification email from being sent for original topic creation activity.
 * Notification is sent after its duplicates and their activities have been created instead of when
 * the original is first created so that links to its duplicate topics can be added to the message.
 * 
 * @param bool $send_it Whether or not to send the notification
 * @param object $activity The Activity object for he original topic.
 *
 * @uses bpmfp_get_duplicate_topics_ids()
**/
function bpmfp_interrupt_original_activity_notification( $send_it, $activity ) {
	if ( 'groups' !== $activity->component || 'bbp_topic_create' !== $activity->type ) {
		return $send_it;
	}
	$topic_id = $activity->secondary_item_id;
	$duplicate_topic_ids = bpmfp_get_duplicate_topics_ids( $topic_id );
	// If the topic is set to be duplicated, but hasn't been yet, then hold off on sending the notification.
	if ( empty( $duplicate_topic_ids ) && ! empty( $_POST['groups-to-post-to'] ) ) {
		$send_it = false;
	}
	return $send_it;
}
add_filter( 'bp_ass_send_activity_notification_for_user', 'bpmfp_interrupt_original_activity_notification', 10, 2);
?>