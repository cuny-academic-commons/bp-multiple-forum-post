<?php
/**
 * In activity feeds, add links to the duplicates of a topic to its topic create activity's action string.
 *
 * If the activity being displayed is an original with duplicates, the function gets an Array of the 
 * duplicate topics. If it's a duplicate, if gets an Array of the other duplicates plus the original.
 * Then it reconstructs the activity string displayed to the user, adding links to the duplicate topics
 * and/or the original.
 * 
 * @param String $action The original action string, passed to the bp_get_activity_action filter.
 * @param BP_Activity_Activity $displayed_activity The activity being displayed in the feed.
 * @uses bpmfp_get_forum_id_for_activity()
 * @return String A string to be displayed to the user, describing the topic create activity with relevant links.
**/
function bpmfp_add_duplicate_topics_to_activity_action_string( $action, $displayed_activity ) {
	// Only if we aren't in a BuddyPress Group's activity feed
	if( ! bp_is_group() ) {
		$displayed_activity_is_original = false;
		$activities_to_add = array();
		// Get the ID of the activity for the original topic
		if ( bp_activity_get_meta( $displayed_activity->id, '_has_duplicates', true ) ) {
			$get_duplicates_of = $displayed_activity->id;
			$displayed_activity_is_original = true;
		} elseif ( bp_activity_get_meta( $displayed_activity->id, '_duplicate_of', true ) ) {
			$get_duplicates_of = bp_activity_get_meta( $displayed_activity->id, '_duplicate_of', true );
		}

		if ( isset( $get_duplicates_of ) ) {
			$query_duplicates_of_original_activity = BP_Activity_Activity::get( array(
				'exclude' => $displayed_activity->id,
				'meta_query' => array(
					array(
						'key' => '_duplicate_of',
						'value' => $get_duplicates_of
					)
				)
			) );
			$duplicates_of_original_activity = $query_duplicates_of_original_activity['activities'];
			if ( ! empty( $duplicates_of_original_activity ) && is_array( $duplicates_of_original_activity ) ) {
				$activities_to_add = $duplicates_of_original_activity;
				// If the activity being displayed is itself a duplicate, then add its original to the beginning
				// of the Array of activities to add to the action string.
				if ( ! $displayed_activity_is_original ) {
					array_unshift( $activities_to_add, new BP_Activity_Activity( $get_duplicates_of ) );
				}
			}
		}

		if ( ! empty( $activities_to_add ) && is_array( $activities_to_add ) ) {
			// If we have activities to add, get rid of all the ones whose activity this user shouldn't be able to see
			foreach ( $activities_to_add as $activity_index => $activity_to_add ) {
				$activity_group_id = $activity_to_add->item_id;
				$activity_group = groups_get_group( array( 'group_id' => $activity_group_id ) );
				if ( $activity_group->status != 'public' && ! groups_is_user_member( bp_loggedin_user_id(), $activity_group_id ) ) {
					unset( $activities_to_add[$activity_index] );
				}
			}
			// Fill in any blanks in the array resulting from unset-ing
			$activities_to_add = array_values( $activities_to_add );

			// If we have activities to add, reconstruct the action string to be displayed to the user
			if ( count( $activities_to_add ) >= 1 ) {
				$topic_author_link = bbp_get_user_profile_link( $displayed_activity->user_id  );
				$displayed_topic_permalink = bbp_get_topic_permalink( $displayed_activity->secondary_item_id );
				$topic_title = $displayed_activity->content;
				$displayed_topic_link      = '<a href="' . $displayed_topic_permalink . '">' . $topic_title . '</a>';
				
				$displayed_forum_id = bpmfp_get_forum_id_for_activity( $displayed_activity );
				$displayed_forum_name = get_post_field( 'post_title', $displayed_forum_id, 'raw' );
				$displayed_forum_permalink = bbp_get_forum_permalink( $displayed_forum_id );
				$displayed_forum_link = '<a href="' . esc_url( $displayed_forum_permalink ) . '">' . $displayed_forum_name . '</a>';
				
				$added_topic_links = array();
				foreach( $activities_to_add as $activity_to_add ) {
					$added_topic_permalink = bbp_get_topic_permalink( $activity_to_add->secondary_item_id );
					$added_topic_forum_id = bpmfp_get_forum_id_for_activity( $activity_to_add );
					$added_topic_forum_name = get_post_field( 'post_title', $added_topic_forum_id, 'raw' );
					$added_topic_links[] = '<a href="' . esc_url( $added_topic_permalink ) . '">' . $added_topic_forum_name . "</a>";
				}
				$action = sprintf( esc_html__( '%1$s started the topic %2$s in the forums: %3$s, %4$s.', 'bp-multiple-forum-post' ), $topic_author_link, $displayed_topic_link, $displayed_forum_link, implode( ', ', $added_topic_links ) );
			}
		}
	}
	return $action;
}
add_filter( 'bp_get_activity_action', 'bpmfp_add_duplicate_topics_to_activity_action_string', 10, 2 );

/**
 * In activity feeds, only show the user the first activity associated with a topic that was cross-posted.
 * 
 * This way, users only see one entry, even if a topic was cross-posted to many groups that they're a part of.
 *
 * @param Array $activity The Array returned by bp_activity_get(), filtered by this function
 * @param Array $r The Array of arguments passed by bp_activity_get() to BP_Activity_Activity::get()
 * @return Array The modified Array, structured the same as the one passed to this function but without duplicate activity entries.
 *
 * @see bp_activity_get() in buddypress/activity/bp-activity-functions.php
 * @uses bpmfp_get_duplicate_activities()
**/
function bpmfp_remove_duplicate_activities_from_activity_stream( $activity, $r ) {
	// Only if we aren't in a BuddyPress Group's activity feed
	if ( ! bp_is_group() ) {
		// Get a list of queried activity IDs for later use, and append them to the original 'exlude' argument
		$activity_ids = wp_list_pluck( $activity['activities'], 'id' );
		$exclude = (array) $r['exclude'];
		$exclude = array_merge( $exclude, $activity_ids );
		// Get a list of the IDs of duplicate activities among the ones that have been queried
		$activities_to_hide = bpmfp_get_duplicate_activities( $activity['activities'] );

		// Remove the duplicate activities, so that users see only one activity entry for each topic
		// instead of a separate activity entry for each forum it was posted to.
		// And keep track of the total number of activities removed from the feed in that way.
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
				$merged_activities_to_hide = bpmfp_get_duplicate_activities( $activity['activities'] );
				// We need to re-set the removed activities count here because we're going to re-use in the query
				// above if the loop comes around again
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
 * Hook the duplicate-removing logic.
**/
function bpmfp_hook_duplicate_removing_for_activity_template( $args ) {
	add_filter( 'bp_activity_get', 'bpmfp_remove_duplicate_activities_from_activity_stream', 10, 2 );
	return $args;
}
add_filter( 'bp_before_has_activities_parse_args', 'bpmfp_hook_duplicate_removing_for_activity_template' );
/**
 * Unhook the duplicate-removing logic.
**/
function bpmfp_unhook_duplicate_removing_for_activity_template( $retval ) {
	remove_filter( 'bp_activity_get', 'bpmfp_remove_duplicate_activities_from_activity_stream', 10, 2 );
	return $retval;
}
add_filter( 'bp_has_activities', 'bpmfp_unhook_duplicate_removing_for_activity_template' );

/**
 * Get a list of IDs of the duplicates in an Array of BuddyPress Activity objects.
 *
 * @param Array $activities An Array of ativity objects to de-duplicate.
 * @return Array An Array of IDs of the duplicate activities.
**/
function bpmfp_get_duplicate_activities( $activities ) {
	$activity_ids = wp_list_pluck( $activities, 'id' );
	// Set up an array for storing the IDs of activities we want to hide
	$duplicate_activities = array();
	foreach ( $activities as $activity_index => $activity_item ) {
		// If we already know we're hiding this activity, go on to the next one
		if ( in_array( $activity_item->id, $duplicate_activities ) ) {
			continue;
		}

		// If this activity is a duplicate, and the original is in the queried activities, hide this one
		if ( bp_activity_get_meta( $activity_item->id, '_duplicate_of', true ) ) {
			$duplicate_of = bp_activity_get_meta( $activity_item->id, '_duplicate_of', true );
			if ( in_array( $duplicate_of, $activity_ids ) ) {
				$duplicate_activities[] = $activity_item->id;
			}
			// If there are other duplicates of the same activity, hide them (regardless of if the original is in the queried activities or not)
			$query_duplicates_of_original = BP_Activity_Activity::get( array(
				'meta_query' => array( array(
					'key' => '_duplicate_of',
					'value' => $duplicate_of ) ) 
			) );
			if ( ! empty( $query_duplicates_of_original['activities'] ) && is_array( $query_duplicates_of_original['activities'] ) ) {
				$duplicates_of_original = $query_duplicates_of_original['activities'];
				foreach( $duplicates_of_original as $duplicate_activity ) {
					// If the duplicate activity is not the same as the activity we started with, and it's in the queried activities, hide it
					if ( $duplicate_activity->id != $activity_item->id && in_array( $duplicate_activity->id, $activity_ids ) ) {
						$duplicate_activities[] = $duplicate_activity->id;
					}
				}
			}
		}
	}

	return $duplicate_activities;
}
?>