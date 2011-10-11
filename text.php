<?php

    require("../../../../config.php");
    require("../../lib.php");
    require("assignment.class.php");
 
    $id     = required_param('id', PARAM_INT);      // Course Module ID
    $mode = optional_param('mode','overview',PARAM_ALPHA);
	$userid = optional_param('userid', 0, PARAM_INT);
	$groupid = optional_param('group', 0, PARAM_INT);
	$diffid = optional_param('diffid', -1, PARAM_INT);

	$user = null;

    if (! $cm = get_coursemodule_from_id('assignment', $id)) {
        error("Course Module ID was incorrect");
    }

    if (! $assignment = get_record("assignment", "id", $cm->instance)) {
        error("Assignment ID was incorrect");
    }

    if (! $course = get_record("course", "id", $assignment->course)) {
        error("Course is misconfigured");
    }

	if ($userid && ! $user = get_record("user", "id", $userid)) {
        error("User is misconfigured");
    }	
	$userid = (isset($user) && $user->id)? $user->id : $USER->id;
	
    require_login($course->id, false, $cm);
    

	$context = get_context_instance(CONTEXT_MODULE, $cm->id);
		
    if (!has_capability('mod/assignment:view', $context)) {
        error("You can not view this assignment");
    }
	
	$cangrade = has_capability('mod/assignment:grade',$context);
	if (($USER->id != $userid) && !$cangrade) {
        error("You can not view this assignment");
    }
	
    if ($assignment->assignmenttype != 'rtcollaboration') {
        error("Incorrect assignment type");
    }
    
	
	$assignmentinstance = new assignment_rtcollaboration($cm->id, $assignment, $cm, $course);	
	
    // Checkgroups
    $groupmode    = groups_get_activity_groupmode($cm);
        
    if($mode == 'reviewvisible' && $groupid > 0){
        // In this mode the groupmode should be visible groups
        if(!groups_is_member($groupid, $USER->id) && $groupmode != VISIBLEGROUPS && !has_capability('moodle/site:accessallgroups', $context)){
            error("Bad group");
        }
        $usergroup = $groupid;
    }
    else{
        if($groupmode){
            if ($groupmode == SEPARATEGROUPS){
                if(has_capability('moodle/site:accessallgroups', $context)){
                    // $assignmentinstance->user_group Returns 0 if the user has not edited a text
                    $usergroup = ($groupid)? $groupid : $assignmentinstance->user_group($userid);
                }
                else{
                    $usergroup = ($groupid && groups_is_member($groupid, $USER->id))? $groupid : $assignmentinstance->user_group($userid);
                }
            }
            else{
                // VISIBLEGROUPS
                $usergroup = ($groupid)? $groupid : $assignmentinstance->user_group($userid);
            }			
        }
        else{
            // With no groups, allways the group is 0
            $usergroup = 0;
        }
    }
        
	$groupid = $usergroup;    

    // XHR / AJAX Call
    
    if($mode == 'reviewvisible'){
        $jsonresponse = array();
        
        if ($text = get_record("assignment_rtcollaboration_text", "assignment", $assignment->id,'groupid',$usergroup)) {
			$jsonresponse['text'] = $text->text;
		}        
        
        header('Content-type: application/json');        
        echo json_encode($jsonresponse);
        die;
    }
    
    // XHR / AJAX Call
    if($mode == 'review' && $diffid > -1){		
		$jsonresponse = array();
		if (! $text = get_record("assignment_rtcollaboration_text", "assignment", $assignment->id,'groupid',$usergroup)) {
			echo json_encode($jsonresponse);
			die;
		}
		
        $diffs = get_records_select('assignment_rtcollaboration_diff',"textid = {$text->id} AND id > $diffid LIMIT 5");
        if($diffs){
            foreach($diffs as $d){
				$d->date = userdate($d->timestamp);
                $jsonresponse[] = $d;
            }
        }
        header('Content-type: application/json');        
        echo json_encode($jsonresponse);
        die;
    }
    
	// Normal mode does not require js
	if($mode == 'review'){
		$yuijsfiles = array('yahoo-dom-event/yahoo-dom-event','yahoo/yahoo-min','json/json-min','connection/connection-min');    
		foreach($yuijsfiles as $f)
		   require_js($CFG->wwwroot.'/lib/yui/'.$f.'.js');
	
		require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/diff_match_patch.js');
		require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/replay.js');
	}    
	
	// OUTPUT

	$title = format_string($assignment->name);
	$navlinks = array();
	$navlinks[] = array('name' => get_string('viewusersactivity','assignment_rtcollaboration'), 'link' => null, 'type' => 'title');
	$navigation = build_navigation($navlinks, $cm);
	
	print_header($title, $course->fullname, $navigation, "", "", true);
    
	
	print_simple_box_start('center', '', '', '', 'generalbox', 'dates');
	
	$currenttab = $mode;
	if($cangrade)
		include_once('tabs.php');  

	
	if ($mode == 'overview'){
    
        $usergroup = $groupid = $assignmentinstance->user_group($userid);
        
        if (! $text = get_record("assignment_rtcollaboration_text", "assignment", $assignment->id,'groupid',$usergroup)) {
            echo get_string('noinfo','assignment_rtcollaboration');
            print_footer();
            exit;
        }
			
		$submitedtext = '';
		$currenttext = $text->text;
		
		// The text was submited at least one time
		// Text submission is not done by the student, a cron job or a teacher force it
		if ($submission = $assignmentinstance->get_submission($userid)) {
			$submitedtext = $submission->data1;
		}
		
		if($userid != $USER->id)
			print_heading(fullname($user));
		else
			print_heading(fullname($USER));
			
		list($charsadded, $charsdeleted, $firstedited, $lastedited) = $assignmentinstance->get_chars_edited($userid);
		if(!empty($charsadded) || !empty($charsdeleted)){
			echo get_string('charsadded','assignment_rtcollaboration').' <b>'.$charsadded.'</b><br />';
			echo get_string('charsdeleted','assignment_rtcollaboration').' <b>'.$charsdeleted.'</b><br />';
			echo get_string('firstedited','assignment_rtcollaboration').' <b>'.(userdate($firstedited)).'</b><br />';
			echo get_string('lastedited','assignment_rtcollaboration').' <b>'.(userdate($lastedited)).'</b><br />';
		}
		else{
			echo get_string('userhasnotparticipate','assignment_rtcollaboration');
		}
		
        $textformat = ($assignment->var1)? FORMAT_HTML: FORMAT_PLAIN;
        
		print_heading(get_string('submitedtext','assignment_rtcollaboration').' ('.userdate($submission->timemodified).')');
		if($submitedtext)
			print_simple_box(format_text($submitedtext, $textformat), 'center', '100%');
		else
			echo get_string('none');
			
		print_heading(get_string('currenttext','assignment_rtcollaboration').' ('.userdate($lastedited).')');
		if($currenttext)
			print_simple_box(format_text($currenttext, $textformat), 'center', '100%');
		else
			echo get_string('none');
		
	}
	else if($mode == 'textstatistics' && $cangrade){
	
		/// Check to see if groups are being used here
		$groupmode = groups_get_activity_groupmode($cm);
		$currentgroup = ($groupmode) ? groups_get_activity_group($cm, true) : 0;
		groups_print_activity_menu($cm, $CFG->wwwroot . "/mod/assignment/type/rtcollaboration/text.php?id={$cm->id}&userid=$userid&mode=textstatistics");
	
		echo '<div style="clear: both"></div>';

        $groupsql = ($currentgroup)? " AND t.groupid = $currentgroup AND v.groupid = $currentgroup " : "";                
        $sql = "SELECT u.id, u.firstname, u.lastname, SUM(d.charsadded) as totalcharsadded, SUM(d.charsdeleted) as totalcharsdeleted, MAX(d.timestamp) as lastedited, MIN(d.timestamp) as firstedited FROM {$CFG->prefix}assignment_rtcollaboration_diff d, {$CFG->prefix}assignment_rtcollaboration_view v, {$CFG->prefix}assignment_rtcollaboration_text t, {$CFG->prefix}user u WHERE u.id = d.userid AND v.userid = d.userid AND v.assignment = {$assignment->id} $groupsql AND t.id = d.textid GROUP BY d.userid ORDER BY totalcharsadded DESC";
        
		if($stats = get_records_sql($sql)){
			$table = new stdclass;
			$table->head = array(get_string('user'),get_string('charsadded','assignment_rtcollaboration'),get_string('charsdeleted','assignment_rtcollaboration'),get_string('firstedited','assignment_rtcollaboration'),get_string('lastedited','assignment_rtcollaboration'));
			foreach($stats as $s){
				$table->data[] = array('<a href="text.php?id='.$id.'&userid='.$s->id.'">'.(fullname($s)).'</a>',$s->totalcharsadded,$s->totalcharsdeleted,userdate($s->firstedited),userdate($s->lastedited));
			}
			print_table($table);
		}	
	}	
	else if($mode == 'review' && $cangrade){
        
		
		/// Check to see if groups are being used here
		$groupmode = groups_get_activity_groupmode($cm);
		$currentgroup = ($groupmode) ? groups_get_activity_group($cm, true) : 0;
		groups_print_activity_menu($cm, $CFG->wwwroot . "/mod/assignment/type/rtcollaboration/text.php?id={$cm->id}&userid=$userid&mode=review");
        
        if (! $text = get_record("assignment_rtcollaboration_text", "assignment", $assignment->id,'groupid',$currentgroup)) {
            echo get_string('noinfo','assignment_rtcollaboration');
            print_footer();
            exit;
        }

		echo '<script type="text/javascript"><!--
			var pageId = '.$id.';
			var groupId = '.$currentgroup.';
            var rtcollaborationRTE = '.$assignment->var1.';
		
		--></script>';
		
		echo '<div style="clear: both"></div>';
		
		if($stats = get_record_sql("SELECT MAX(d.timestamp) as lastedited, MIN(d.timestamp) as firstedited FROM {$CFG->prefix}assignment_rtcollaboration_diff d WHERE d.textid = {$text->id}")){
			echo get_string('firstedited','assignment_rtcollaboration').' <b>'.(userdate($stats->firstedited)).'</b><br />';
			echo get_string('lastedited','assignment_rtcollaboration').'  <b>'.(userdate($stats->lastedited)).'</b><br />';		
		}
		echo get_string('currentlyviewing','assignment_rtcollaboration').' <span id="currentedit"></span><br />';
		
		echo '<div style="display: block; width: 99%">';
		echo '<div style="width: 80%; float: left">';
        if($assignment->var1){
            echo '<div id="maintext" style="border: black solid 1px"></div>';
        }
        else{
            echo '<TEXTAREA ID="maintext" STYLE="width: 90%; height: 100%; background-color: white" rows="30" disabled="disabled"></TEXTAREA>';
        }
		echo '</div>';
		// Users table
		
		if($users = get_records_sql("SELECT u.id, u.firstname, u.lastname, SUM(charsadded) as totalcharsadded FROM {$CFG->prefix}assignment_rtcollaboration_diff d, {$CFG->prefix}user u WHERE u.id = d.userid AND d.textid = {$text->id} GROUP BY d.userid ORDER BY totalcharsadded DESC")){
			$table = new stdclass;
			$table->head = array(get_string('user'),'+','-','');
			$table->width = "100%";
			foreach($users as $u){
				$table->data[] = array('<a href="text.php?id='.$id.'&userid='.$u->id.'">'.(fullname($u)).'</a>','<span id="addc'.$u->id.'" class="rtuserrow">0</span>','<span id="delc'.$u->id.'" class="rtuserrow">0</span>','<span id="diffc'.$u->id.'" class="rtuserrow">0</span>');
			}
		}
		
		echo '<div style="float: right; width: 20%">';
		echo '<br />';
		print_table($table);
		echo '</div>';
		echo '<div style="clear: both"></div>';
		echo '</div>';
			
	}
	
    print_simple_box_end();
    print_footer();    

?>