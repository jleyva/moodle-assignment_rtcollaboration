<?php

    require("../../../../config.php");
    require("../../lib.php");
    require("assignment.class.php");
 
    $id     = required_param('id', PARAM_INT);      // Course Module ID
    $mode = optional_param('mode','overview',PARAM_ALPHA);
	$userid = optional_param('userid', 0, PARAM_INT);
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
	
	$assignmentinstance = new assignment_rtcollaboration($cm->id, $assignment, $cm, $course);	
	
	$usergroup = $assignmentinstance->user_group($userid);
	if (! $text = get_record("assignment_rtcollab_text", "assignment", $assignment->id,'groupid',$usergroup)) {
        error("Text ID was incorrect");
    }	

    require_login($course->id, false, $cm);

	$context = get_context_instance(CONTEXT_MODULE, $cm->id);
	
    if (!has_capability('mod/assignment:view', $context)) {
        error("You can not view this assignment");
    }
	
	$cangrade = has_capability('mod/assignment:grade',$context);
	if (($USER->id != $user->id) && !$cangrade) {
        error("You can not view this assignment");
    }

	$groupmode    = groups_get_activity_groupmode($cm);
	if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)){
		if(! group_is_member($text->groupid, $USER->id)){
			error("You can not view this user assignment");
		}
	}
	
    if ($assignment->assignmenttype != 'rtcollaboration') {
        error("Incorrect assignment type");
    }

    // XHR / AJAX Call
    if($mode == 'review' && $diffid > -1){
        $diffs = get_records_select('assignment_rtcollab_diff',"textid = {$text->id} AND id > $diffid LIMIT 5");
        $jsonresponse = array();
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
    print_header(format_string($assignment->name));
    print_simple_box_start('center', '', '', '', 'generalbox', 'dates');
	
	$currenttab = $mode;
	if($cangrade)
		include_once('tabs.php');
    
	if ($mode == 'overview'){
			
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
		
		print_heading(get_string('submitedtext','assignment_rtcollaboration').' ('.userdate($submission->timemodified).')');
		if($submitedtext)
			print_simple_box(format_text($submitedtext, FORMAT_PLAIN), 'center', '100%');
		else
			echo get_string('none');
			
		print_heading(get_string('currenttext','assignment_rtcollaboration').' ('.userdate($lastedited).')');
		if($currenttext)
			print_simple_box(format_text($currenttext, FORMAT_PLAIN), 'center', '100%');
		else
			echo get_string('none');
		
	}
	else if($mode == 'textstatistics' && $cangrade){
		if($stats = get_records_sql("SELECT u.id, u.firstname, u.lastname, SUM(d.charsadded) as totalcharsadded, SUM(d.charsdeleted) as totalcharsdeleted, MAX(d.timestamp) as lastedited, MIN(d.timestamp) as firstedited FROM {$CFG->prefix}assignment_rtcollab_diff d, {$CFG->prefix}assignment_rtcollab_view v, {$CFG->prefix}user u WHERE u.id = d.userid AND v.userid = d.userid AND d.textid = {$text->id} GROUP BY d.userid ORDER BY totalcharsadded DESC")){
			$table = new stdclass;
			$table->head = array(get_string('user'),get_string('charsadded','assignment_rtcollaboration'),get_string('charsdeleted','assignment_rtcollaboration'),get_string('firstedited','assignment_rtcollaboration'),get_string('lastedited','assignment_rtcollaboration'));
			foreach($stats as $s){
				$table->data[] = array('<a href="text.php?id='.$id.'&userid='.$s->id.'">'.(fullname($s)).'</a>',$s->totalcharsadded,$s->totalcharsdeleted,userdate($s->firstedited),userdate($s->lastedited));
			}
			print_table($table);
		}	
	}	
	else if($mode == 'review' && $cangrade){
		echo '<script type="text/javascript"><!--
			var pageId = '.$id.';
			var textId = '.$text->id.';
		
		--></script>';
		
		if($stats = get_record_sql("SELECT MAX(d.timestamp) as lastedited, MIN(d.timestamp) as firstedited FROM {$CFG->prefix}assignment_rtcollab_diff d WHERE d.textid = {$text->id}")){
			echo get_string('firstedited','assignment_rtcollaboration').' <b>'.(userdate($stats->firstedited)).'</b><br />';
			echo get_string('lastedited','assignment_rtcollaboration').'  <b>'.(userdate($stats->lastedited)).'</b><br />';		
		}
		echo get_string('currentlyviewing','assignment_rtcollaboration').' <span id="currentedit"></span><br />';
		
		echo '<div style="display: block; width: 99%">';
		echo '<div style="width: 80%; float: left">';
		echo '<TEXTAREA ID="maintext" STYLE="width: 90%; height: 100%" rows="30" disabled="disabled"></TEXTAREA>';
		echo '</div>';
		// Users table
		
		if($users = get_records_sql("SELECT u.id, u.firstname, u.lastname, SUM(charsadded) as totalcharsadded FROM {$CFG->prefix}assignment_rtcollab_diff d, {$CFG->prefix}user u WHERE u.id = d.userid AND d.textid = {$text->id} GROUP BY d.userid ORDER BY totalcharsadded DESC")){
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