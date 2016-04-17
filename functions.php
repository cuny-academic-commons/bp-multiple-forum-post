<?php

function bpmfp_get_forum_id_for_activity( $activity ) {
	$activity_group_id = $activity->item_id;
	$activity_forum_ids = groups_get_groupmeta( $activity_group_id, 'forum_id' );
	$activity_forum_id = is_array( $activity_forum_ids ) ? reset( $activity_forum_ids ) : intval( $activity_forum_ids );
	return $activity_forum_id;
}

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