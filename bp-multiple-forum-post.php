<?php
/*
	Plugin Name: BuddyPress Multiple Forum Post
	Description: Allows users to post to multiple BP Group forums at once
	Text Domain: bp-multiple-forum-post
	Version: 0.1
	License: GPL-3.0
*/

// Global for storing content of email for activity tied to creation of original topic
global $bpmfp_original_activity;

function bpmfp_show_other_groups() {
	if ( bbp_is_topic_edit() ) {
		return;
	}

	if ( false === function_exists( 'buddypress' ) ) {
		return;
	}

	wp_enqueue_script( 'bpmfp', plugins_url( 'bp-multiple-forum-post/bpmfp.js' ), array( 'jquery' ), CAC_VERSION, true );
	$user_id = bp_loggedin_user_id();
	$user_groups = groups_get_groups( array(
		'user_id' => $user_id,
		'per_page' => -1,
		'type'=> 'alphabetical',
		'show_hidden' => true,
		'exclude' => array( bp_get_current_group_id() ),
	) );
	if ( $user_groups['total'] > 0) {
		echo '<div id="crosspost-div">';
		echo '<fieldset>';
		echo '<legend>' . __( 'Post to multiple groups:', 'bp-multiple-forum-post' ) . '</legend>';
		echo "<div>" . __( 'By selecting other groups below, you can post this same topic on their forums at the same time.', 'bp-multiple-forum-post' ) . "</div>";
		echo '<ul id="crosspost-groups">';
		$current_group = groups_get_current_group();
		$current_group_id = $current_group->id;
		foreach( $user_groups['groups'] as $group ) {
			if ($group->id == $current_group_id) {
				continue;
			}
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
		echo '<a id="crosspost-show-more" style="display: none;" href="#">' . 'Show all' . '</a>';
		echo '</div>';
	}
}
add_action( 'bbp_theme_before_topic_form_submit_wrapper', 'bpmfp_show_other_groups', 20 );

// Create the duplicate topics and activities
function bpmfp_create_duplicate_topics( $topic_id ) {
	// Nonce check
	if ( ! isset( $_POST['bp_multiple_forum_post'] )
		|| ! wp_verify_nonce( $_POST['bp_multiple_forum_post'], 'post_to_multiple_forums' ) ) {
		echo "Sorry, there was a problem verifying your request.";
		exit();
	}
	// Don't do anything if the topic isn't being duplicated
	if ( empty( $_POST['groups-to-post-to'] ) ) {
		return;
	}
	// Check to make sure buddypress is turned on
	if ( false === function_exists( 'buddypress' ) ) {
		return;
	}

	// Store the orignal activity for the topic creation for later use
	$original_activity = BP_Activity_Activity::get( array(
		'filter_query' => array(
			'type' => 'bbp_topic_create',
			'secondary_item_id' => $topic_id,
		),
	) );

	// Create an array to hold information abouut the duplicate topics, for us in creating
	// activities for them
	$duplicate_topics = array();

	// Bail if an activity wasn't created for the topic creation
	if ( empty( $original_activity['activities'] ) ) {
		return;
	}
	// Store the original activity ID for later use
	$original_activity_id = $original_activity['activities'][0]->id;

	//** foreach loop creates the duplicate topics
	// An array to store the activities associated with the duplicate topics
	$activities = array();
	foreach( $_POST['groups-to-post-to'] as $group_id ) {
		if( !groups_is_user_member( bp_loggedin_user_id(), $group_id ) ) {
			continue;
		}
		// Get the forum ID for the group to post the duplicate topic in
		$group_forum_ids = groups_get_groupmeta( $group_id, 'forum_id' );
		$group_forum_id = is_array( $group_forum_ids ) ? reset( $group_forum_ids ) : intval( $group_forum_ids );

		// Code for adding tags taken from bbp_new_topic_handler in bbpress/includes/topics/functions.php
		// Set up the arrays of information for and about the duplicate topic
		$terms = '';
		if ( bbp_allow_topic_tags() && !empty( $_POST['bbp_topic_tags'] ) ) {
			// Escape tag input
			$terms = esc_attr( strip_tags( $_POST['bbp_topic_tags'] ) );
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
			'post_content' => esc_attr( $_POST["bbp_topic_content"] ),
			'post_title' => esc_attr( $_POST["bbp_topic_title"] ),
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

		// Update counts, etc. Taken from 'bbp_update_topic' in bbpress/includes/topics/functions.php
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

		// Gather the info we need to create the activity in a separate loop
		$topic_info = array();
		$topic_info['new_topic_id'] = $new_topic_id;
		$topic_info['group_forum_id'] = $group_forum_id;
		$topic_info['group_id'] = $group_id;
		$duplicate_topics[] = $topic_info;
	}

	//* Activities
	// Give the original activity a meta value indicating that is has duplicates
	bp_activity_add_meta( $original_activity_id, '_has_duplicates', true );
	send_email_for_original_activity();
	
	// Create the activities for the duplicate topics
	foreach( $duplicate_topics as $duplicate_topic ) {
		// Create the activity for the topic @TODO - move into separate loop
		$bbp_buddypress_activity = new BBP_BuddyPress_Activity;
		$bbp_buddypress_activity->topic_create( $duplicate_topic['new_topic_id'], $duplicate_topic['group_forum_id'], array(), bp_loggedin_user_id() );
		// Update the last activity time for the group
		groups_update_last_activity( $duplicate_topic['group_id'] );

		// Give the duplicate topic creation activity a meta value pointing to the activity for the topic it's a duplicate of
		$activity_id = get_post_meta( $new_topic_id, '_bbp_activity_id', true );
		bp_activity_add_meta( $activity_id, '_duplicate_of', $original_activity_id );
	}

	// Add an alert for the user on next page load to let them know that the topic was successfully duplicated
	$duplicate_topics = bpmfp_get_duplicate_topics_list( $topic_id );
	$duplicate_topics_message = bpmfp_get_this_topic_also_posted_in_message( $duplicate_topics, 'alert' );
	bp_core_add_message( $duplicate_topics_message, 'success' );
}
add_action( 'bbp_new_topic_post_extras', 'bpmfp_create_duplicate_topics' );

function send_email_for_original_activity() {
	global $bpmfp_original_activity;
	if ( $bpmfp_original_activity ) {
		ass_group_notification_activity( $bpmfp_original_activity );
	}
}

// Hook into the argument parsing for recording the new topic creation activity
// Set the "item id" for the topic creation activity to the group associated with the forum that the topic was posted in
// See "map_activity_to_group" in bbpress/includes/extend/buddypress/groups.php for what we're overriding
function bpmfp_set_activity_group_id( $args = array() ) {
	$topic_id = $args['secondary_item_id'];
	// Get the forum ID based on the topic ID
	$forum_id = bbp_get_topic_forum_id( $topic_id );
	// Get the group ID based on the forum ID
	$group_ids = get_post_meta( $forum_id, '_bbp_group_ids', true );
	$group_id = is_array( $group_ids ) ? reset( $group_ids ) : intval( $group_ids );

	// Replace 'item_id' with the group ID for the group getting the duplicate
	$args['item_id'] = $group_id;
	return $args;
}
add_action( 'bbp_before_record_activity_parse_args', 'bpmfp_set_activity_group_id', 15 );

// Modify the activity strings in feeds (besides a group's activity feed) to include the names of
// and links to forums where a topic was cross-posted
function bpmfp_add_links_to_duplicates_forums_to_activity_action_string( $action, $activity ) {
	// Only fool around with this if we aren't in a groups' activity feed
	if( ! bp_is_group() ) {
		// Determine what activity, if any, we're looking for duplicates of - this activity itself or the activity this one is a duplicate of
		if ( bp_activity_get_meta( $activity->id, '_has_duplicates', true ) ) {
			$get_duplicates_of = $activity->id;
		} elseif ( bp_activity_get_meta( $activity->id, '_duplicate_of', true ) ) {
			$get_duplicates_of = bp_activity_get_meta( $activity->id, '_duplicate_of', true );
		}

		// Get the duplicate activities, excluding this current activity
		if ( isset( $get_duplicates_of ) ) {
			$duplicates = BP_Activity_Activity::get( array( 'exclude' => $activity->id, 'meta_query' => array( array( 'key' => '_duplicate_of', 'value' => $get_duplicates_of ) ) ) );
			$duplicate_activities = $duplicates['activities'];
		}

		if ( isset( $duplicate_activities ) ) {
			// If we have duplicates, go through and get rid of all the ones whose activity this user shouldn't be able to see
			foreach ( $duplicate_activities as $activity_index => $duplicate_activity ) {
				$activity_group_id = $duplicate_activity->item_id;
				$activity_group = groups_get_group( array( 'group_id' => $activity_group_id ) );
				if ( $activity_group->status != 'public' && !groups_is_user_member( bp_loggedin_user_id(), $activity_group_id ) ) {
					unset( $duplicate_activities[$activity_index] );
				}
			}
			// Fill in any blanks in the array resulting from unset-ing
			$duplicate_activities = array_values( $duplicate_activities );

			// If there are any duplicates left, go through and add the forum name and a link to it for each of the duplicate activities,
			// accounting for how many total activities there are and where we are in the list
			$num_duplicates = count( $duplicate_activities );
			if ( $num_duplicates >= 1 ) {
				$action = str_replace('forum', 'forums', $action);
				foreach ( $duplicate_activities as $activity_index => $duplicate_activity ) {
					$activity_group_id = $duplicate_activity->item_id;
					$activity_forum_ids = groups_get_groupmeta( $activity_group_id, 'forum_id' );
					$activity_forum_id = is_array( $activity_forum_ids ) ? reset( $activity_forum_ids ) : intval( $activity_forum_ids );
					$activity_forum_link = bbp_get_forum_permalink( $activity_forum_id );
					$activity_forum_name = get_post_field( 'post_title', $activity_forum_id, 'raw' );

					if ( $num_duplicates == 1 ) {
						$action .= " " . __( 'and', 'bp-multiple-forum-post') . ' <a href="' . esc_url( $activity_forum_link ) . '">' . esc_html( $activity_forum_name ) . "</a>";
					} elseif ( $activity_index == $num_duplicates - 1 ) {
						$action .= ", " . __( 'and', 'bp-multiple-forum-post') . ' <a href="' . esc_url( $activity_forum_link ) . '">' . esc_html( $activity_forum_name ) . "</a>";
					} else {
						$action .= ", " . __( 'and', 'bp-multiple-forum-post') . ' <a href="' . esc_url( $activity_forum_link ) . '">' . esc_html( $activity_forum_name ) . "</a>";
					}
				}
			}
		}
	}
	return $action;
}
add_filter( 'bp_get_activity_action', 'bpmfp_add_links_to_duplicates_forums_to_activity_action_string', 10, 2 );

// A helper function to identify activities that should be hidden from the feed, because
// they're duplicates of another activity
function bpmfp_get_duplicate_activities( $activities, $queried_activity_ids ) {
	// Set up an array for storing the IDs of activities we want to hide
	$activities_to_hide = array();

	foreach ( $activities as $activity_index => $activity_item ) {
		// If we already know we're hiding this activity, go on to the next one
		if ( in_array( $activity_item->id, $activities_to_hide ) ) {
			continue;
		}

		if ( bp_activity_get_meta( $activity_item->id, '_duplicate_of', true ) ) {
			// If this activity is a duplicate, and the original is in the queried activities, hide this one
			$duplicate_of = bp_activity_get_meta( $activity_item->id, '_duplicate_of', true );
			if ( in_array( $duplicate_of, $queried_activity_ids ) ) {
				$activities_to_hide[] = $activity_item->id;
			}

			// If there are other duplicates of the same activity, hide them (regardless of if the original is in the queried activities or not)
			$other_duplicates = BP_Activity_Activity::get( array( 'meta_query' => array( array( 'key' => '_duplicate_of', 'value' => $duplicate_of ) ) ) );
			if ( isset( $other_duplicates ) ) {
				$other_duplicate_activities = $other_duplicates['activities'];
				foreach( $other_duplicate_activities as $duplicate_activity ) {
					// If the duplicate activity is not the same as the activity we started with, and it's in the queried activities, hide it
					if ( $duplicate_activity->id != $activity_item->id && in_array( $duplicate_activity->id, $queried_activity_ids ) ) {
						$activities_to_hide[] = $duplicate_activity->id;
					}
				}
			}
		}
	}

	return $activities_to_hide;
}

// Function to modify activity feeds (beside groups' activity feeds) to remove the duplicates
// activities created by this plugin for cross-posted topics
function bpmfp_remove_duplicates_from_activity_stream( $activity, $r, $iterator = 0 ) {
	// Only do this filter if we aren't on a group's page
	if ( ! bp_is_group() ) {
		// Create an array of the activity IDs for later use
		$activity_ids = wp_list_pluck( $activity['activities'], 'id' );

		// Get a list of queried activity IDs for later use, and append them to the original 'exlude' argument
		$exclude = (array) $r['exclude'];
		$exclude = array_merge( $exclude, $activity_ids );

		// Get a list of the IDs of duplicate activities among the ones that have been queried
		$activities_to_hide = bpmfp_get_duplicate_activities( $activity['activities'], $activity_ids );

		// Go through and hide the activities that we wanted to hide
		$removed = 0;
		foreach( $activity['activities'] as $activity_index => $activity_item ) {
			if( in_array( $activity_item->id, $activities_to_hide ) ) {
				unset( $activity['activities'][$activity_index] );
				$removed++;
			}
		}

		if( $removed ) {
			// Backfill to get us back up to the originally queries number of activity items
			$deduped_activity_count = count( $activity['activities'] );
			$original_activity_count = count( $activity_ids );

			while( $deduped_activity_count < $original_activity_count ) {
				// Start with the same arguments as the original query
				$backfill_args = $r;
				// Exclude all the activity items we've already queried
				$backfill_args['exclude'] = $exclude;
				// Call for the number of items that we're now short of the original query, plus some extras
				// because some of the ones we pull in might be duplicates themselves.
				$backfill_args['per_page'] = $removed + 10;
				// Set a couple other arguments
				$backfill_args['update_meta_cache'] = false;
				$backfill_args['display_comments'] = false;

				// Get the activity items we'll use to backfill and update the total activities queried
				// We're using BP_Activity_Activity::get because the function we're in is hooked to bp_activity_get().
				// so calling it would cause unnecessary and probably infinite recursion
				$backfill = BP_Activity_Activity::get( $backfill_args );

				// Add the newly queried IDs to the exclude list
				$backfill_ids = wp_list_pluck( $backfill['activities'], 'id' );
				$exclude = array_merge( $exclude, $backfill_ids );
				// Update the total queried activities count
				$activity['total'] += $backfill['total'];
				// Merge our backfilled items into the queried activities list
				$activity['activities'] = array_merge( $activity['activities'], $backfill['activities'] );

				// To-do: This could probably be DRY-ed out using a do-while loop, but I think it works well for now
				// Repeat the deduplication routine as above, with our newly merged array of activities
				$merged_activity_ids = wp_list_pluck( $activity['activities'], 'id' );
				$merged_activities_to_hide = bpmfp_get_duplicate_activities( $activity['activities'], $merged_activity_ids );
				// We need to re-set remove here because we're going to re-use in the query above if the loop comes around again
				$removed = 0;
				if ( !empty( $merged_activities_to_hide ) ) {
					foreach( $activity['activities'] as $activity_index => $activity_item ) {
						if( in_array( $activity_item->id, $merged_activities_to_hide ) ) {
							unset( $activity['activities'][$activity_index] );
							$removed++;
						}
					}
				}
				// If we have more than originally queried after all the deduplication, cut it down
				if( count( $activity['activities'] > $original_activity_count ) ) {
					$activity['activities'] = array_slice( $activity['activities'], 0, $original_activity_count );
				}

				// Update our current activity count, for use in the while-loop condition check at the top
				$deduped_activity_count = count( $activity['activities'] );
			}
		}
	}
	return $activity;
}

/**
 * Hook the duplicate-removing logic
*/
function bpmfp_hook_duplicate_removing_for_activity_template( $args ) {
	add_filter( 'bp_activity_get', 'bpmfp_remove_duplicates_from_activity_stream', 10, 2 );
	return $args;
}
add_filter( 'bp_before_has_activities_parse_args', 'bpmfp_hook_duplicate_removing_for_activity_template' );
/**
 * Unhook the duplicate-removing logic.
 */
function bpmfp_unhook_duplicate_removing_for_activity_template( $retval ) {
	remove_filter( 'bp_activity_get', 'bpmfp_remove_duplicates_from_activity_stream', 10, 2 );
	return $retval;
}
add_filter( 'bp_has_activities', 'bpmfp_unhook_duplicate_removing_for_activity_template' );


/**
 * Append duplicate posts message to email notifications, and save the content of the email
 * for the activity tied to the original topic, for until after its duplicates have been created
 */
function bpmfp_duplicate_post_message_notification( $content, $activity ) {
	global $bpmfp_original_activity;
	
	if ( 'groups' !== $activity->component || 'bbp_topic_create' !== $activity->type ) {
		return $content;
	}
	$topic_id = $activity->secondary_item_id;
	$all_topic_ids = bpmfp_get_duplicate_topics_list( $topic_id );

	// If this is an original topic that's being duplicated, save its content for sending later
	if ( empty( $all_topic_ids ) && !empty( $_POST['groups-to-post-to'] ) ) {
		$bpmfp_original_activity = $activity;
		return $content;
	}
	$duplicate_post_message = "\n" . bpmfp_get_this_topic_also_posted_in_message( $all_topic_ids, 'email' );

	return $content . $duplicate_post_message;
}
add_filter( 'bp_ass_activity_notification_content', 'bpmfp_duplicate_post_message_notification', 100, 2 );

/**
 * Ensure that GES does not send multiple emails to a given user for a given event.
 * And interrupt sending of email for activity tied to orginal topic, for sending after
 * its duplicates have been createds
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
	// If it's an original that's being duplicated, don't send it just yet!
	$topic_id = $activity->secondary_item_id;
	$all_topic_ids = bpmfp_get_duplicate_topics_list( $topic_id );
	if ( empty( $all_topic_ids ) && !empty( $_POST['groups-to-post-to'] ) ) {
		$send_it = false;
		return $send_it;
	}
	if ( ! isset( $_bpmfp_bpges_sent ) ) {
		$_bpmfp_bpges_sent = array();
	}
	if ( ! isset( $_bpmfp_bpges_sent[ $user_id ] ) ) {
		$_bpmfp_bpges_sent[ $user_id ] = array();
	}

	// Get a list of all topics corresponding to this topic.
	$all_topic_ids = bpmfp_get_duplicate_topics_list( $topic_id );

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

function bpmfp_show_duplicate_groups_on_topics() {
	// Only show on the first item in a thread - the topic.
	$reply_id = bbp_get_reply_id();
	$reply_post = get_post( $reply_id );
	if ( 0 !== $reply_post->menu_order ) {
		return;
	}

	$topic_id = $reply_id;

	$all_topic_ids = bpmfp_get_duplicate_topics_list( $topic_id );
	echo bpmfp_get_this_topic_also_posted_in_message( $all_topic_ids, 'forum_topic' );
}
add_action( 'bbp_theme_after_reply_content', 'bpmfp_show_duplicate_groups_on_topics' );

/**
 * Returns a list of topic IDs for topics that are either the duplicates of the one provided,
 * or the original that the one provided is a duplicate of, plus the other duplicates
**/
function bpmfp_get_duplicate_topics_list( $topic_id ) {
	$duplicate_of = get_post_meta( $topic_id, '_duplicate_of', true );
	$duplicates = get_post_meta($topic_id, '_duplicates', false );
	// don't enqueue the styling if we're creating an email notification
	if ( current_filter() != 'bp_ass_activity_notification_content' ) {
		wp_enqueue_style( 'bpmfp-css', plugins_url( 'bp-multiple-forum-post/bpmfp.css' ), array(), CAC_VERSION );
	}

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

function bpmfp_get_this_topic_also_posted_in_message( $topic_ids, $context = 'alert' ) {
	$return_message = '';

	// make sure we have an array, and that it isn't empty
	if ( is_array( $topic_ids ) && count( $topic_ids ) >= 1 ) {
		// make sure the array is 0-indexed, and isn't missing any indices
		$all_topic_ids = array_values( $topic_ids );

		$return_message = __( 'This topic was also posted in:', 'bp-multiple-forum-post' );
		if ( $context === 'forum_topic' ) {
			// set up the return div
			$return_message = '<div class="posted-in-other-forums">' . $return_message;
		}

		for ( $index = 0; $index < count( $all_topic_ids ); $index++ ) {
			// get the forum id for the topic, and the group id for the forum, and the group object
			$topic_forum_id = get_post( $all_topic_ids[$index] )->post_parent;
			$forum_group_ids = bbp_get_forum_group_ids( $topic_forum_id );
			$forum_group_id = $forum_group_ids[0];
			$forum_group = groups_get_group( array( 'group_id' => $forum_group_id ) );

			// If we're creating an email notification, or the group that the deuplicate topic is in is public, or the current user is a member
			// or the current user is a moderator, then include a link to the topic
			if ( 'bp_ass_activity_notification_content' === current_filter() || 'public' == $forum_group->status || groups_is_user_member( bp_loggedin_user_id(), $forum_group_id ) || current_user_can( 'bp_moderate' ) ) {
				$topic_link = bbp_get_topic_permalink( $topic_ids[$index] );
			}
			$forum_name = get_post_field( 'post_title', $topic_forum_id, 'raw' );

			// print the forum name, linking it to the other topic's permalink if the user has permission to access it
			if ( $forum_name ) {
				if ( $topic_link && ( $context === 'forum_topic' || $context === 'email' ) ) {
					$return_message .= ' <a href="' . esc_url( $topic_link ) . '">';
				}
				$return_message .= esc_html( $forum_name );
				if ( $topic_link && ( $context === 'forum_topic' || $context === 'email' ) ) {
					$return_message .= '</a>';
				}

				if ( $index < count( $topic_ids ) - 2 ) {
					$return_message .= ", ";
				} elseif ( $index == count( $topic_ids ) - 2 ) {
					$return_message .= ", and ";
				} elseif ( $index == count( $topic_ids ) - 1 ) {
					$return_message .= ".";
				}
			}
		}
		if ( $context === 'forum_topic' ) {
			$return_message .= '</div>';
		}

	}
	return $return_message;
}

function bpmfp_load_textdomain() {
	load_plugin_textdomain( 'bp-multiple-forum-post' );
}
add_action( 'plugins_loaded', 'bpmfp_load_textdomain' );
