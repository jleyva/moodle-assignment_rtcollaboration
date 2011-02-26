<?php  // $Id: file.php,v 1.6 2006/08/31 08:51:09 toyomoyo Exp $

    require("../../../../config.php");
    require("../../lib.php");
    require("assignment.class.php");
 
    $id     = required_param('id', PARAM_INT);      // Course Module ID
    $mode = optional_param('mode','normal',PARAM_ALPHA);
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
	
	if (! $text = get_record("assignment_rtcollab_text", "assignment", $assignment->id,'groupid',$assignmentinstance->user_group($userid))) {
        error("Text ID was incorrect");
    }	

    require_login($course->id, false, $cm);

	$context = get_context_instance(CONTEXT_MODULE, $cm->id);
	
    if (!has_capability('mod/assignment:view', $context)) {
        error("You can not view this assignment");
    }
	
	if (($USER->id != $user->id) && !has_capability('mod/assignment:grade',$context)) {
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
    if($diffid > -1){
        $diffs = get_records_select('assignment_rtcollab_diff',"id > $diffid LIMIT 1");
        $jsonresponse = array();
        if($diffs){
            foreach($diffs as $d){
                $jsonresponse[] = $d;
            }
        }
        header('Content-type: application/json');        
        echo json_encode($jsonresponse);
        die;
    }
    
	// Normal mode does not require js
	if($mode != 'normal'){
		$yuijsfiles = array('yahoo-dom-event/yahoo-dom-event','yahoo/yahoo-min','json/json-min','connection/connection-min');    
		foreach($yuijsfiles as $f)
		   require_js($CFG->wwwroot.'/lib/yui/'.$f.'.js');
	
		require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/replay.js');
	}
    require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/diff_match_patch.js');
    
	
	// OUTPUT
    print_header(format_string($assignment->name));
    print_simple_box_start('center', '', '', '', 'generalbox', 'dates');
    
	if($mode != 'normal'){
		echo '<script type="text/javascript"><!--
			var pageId = '.$id.';
			var textId = '.$text->id.';
		
		--></script>';
		echo '<div id="maintext" style="overflow: auto; width: 100%; height:600px"></div>';		
    }
	else{
			
		$usertext = '';
		// The text was submited at least one time
		// Text submission is not done by the student, a cron job or a teacher force it
		if ($submission = $assignmentinstance->get_submission($userid)) {
			$usertext = $submission->data1;
		}
		else{
			$usertext = $text->text;
		}
		
		if($chars = get_record_sql("SELECT SUM(charsadded) as charsadded, SUM(charsdeleted) as charsdeleted FROM {$CFG->prefix}assignment_rtcollab_diff WHERE textid = {$text->id}")){
			echo get_string('charsadded','assignment_rtcollaboration').': <b>'.$chars->charsadded.'</b><br />';
			echo get_string('charsdeleted','assignment_rtcollaboration').': <b>'.$chars->charsdeleted.'</b><br />';
		}		
		
		print_simple_box(format_text($text), 'center', '100%');
    }
	
    print_simple_box_end();
    print_footer();    

?>