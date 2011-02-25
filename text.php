<?php  // $Id: file.php,v 1.6 2006/08/31 08:51:09 toyomoyo Exp $

    require("../../../../config.php");
    require("../../lib.php");
    require("assignment.class.php");
 
    $id     = required_param('id', PARAM_INT);      // Course Module ID
    $textid = required_param('textid', PARAM_INT);
    $diffid = optional_param('diffid', -1, PARAM_INT);


    if (! $cm = get_coursemodule_from_id('assignment', $id)) {
        error("Course Module ID was incorrect");
    }

    if (! $assignment = get_record("assignment", "id", $cm->instance)) {
        error("Assignment ID was incorrect");
    }

    if (! $course = get_record("course", "id", $assignment->course)) {
        error("Course is misconfigured");
    }


    require_login($course->id, false, $cm);

    if (!has_capability('mod/assignment:grade', get_context_instance(CONTEXT_MODULE, $cm->id))) {
        error("You can not view this assignment");
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
    
    $yuijsfiles = array('yahoo-dom-event/yahoo-dom-event','yahoo/yahoo-min','json/json-min','connection/connection-min');    
    foreach($yuijsfiles as $f)
       require_js($CFG->wwwroot.'/lib/yui/'.$f.'.js');
    
    require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/diff_match_patch.js');
    require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/replay.js');
    
    print_header(fullname($user,true).': '.$assignment->name);
    print_simple_box_start('center', '', '', '', 'generalbox', 'dates');
    
    echo '<script type="text/javascript"><!--
        var pageId = '.$id.';
        var textId = '.$textid.';
    
    --></script>';
            
    echo '<div id="maintext"></div>';
    
    print_simple_box_end();
    print_footer('none');    

?>