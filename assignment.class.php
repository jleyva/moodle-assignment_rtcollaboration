<?php

/**
 * Collaborative real-time editor assignment
 * @author Juan Leyva <juanleyvadelgado@gmail.com>
 *
 * Protocol:  http://code.google.com/p/google-mobwrite/wiki/Protocol
 * More info: http://www.youtube.com/watch?v=S2Hp_1jqpY8
*/

require_once($CFG->libdir.'/formslib.php');

if( !function_exists('json_encode') ) {
	require_once($CFG->libdir.'/pear/HTML/AJAX/JSON.php');
}


/**
 * Extend the base assignment class for assignments with a real time collaboration editor
 *
 */
class assignment_rtcollaboration extends assignment_base {

    function assignment_rtcollaboration($cmid='staticonly', $assignment=NULL, $cm=NULL, $course=NULL) {
        parent::assignment_base($cmid, $assignment, $cm, $course);
        $this->type = 'rtcollaboration';
    }

    function view() {

        global $USER, $CFG;
        
        $groupid = optional_param('group',-1,PARAM_INT);
        
        require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/diff_match_patch.js');
        require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/mobwrite_core.js');
        require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/mobwrite_form.js');
        

        $context = get_context_instance(CONTEXT_MODULE, $this->cm->id);
        require_capability('mod/assignment:view', $context);  
        $canedit = has_capability('mod/assignment:submit', $context);

        $submission = $this->get_submission();        
        $editable = $this->isopen() && (!$submission || $this->assignment->resubmit || !$submission->timemarked);
        $visible = false;
        
        add_to_log($this->course->id, "assignment", "view", "view.php?id={$this->cm->id}", $this->assignment->id, $this->cm->id);

		
		$this->view_header();        
        
		if(has_capability('mod/assignment:grade', $context)){
			echo '<div class="reportlink"><a href="'.$CFG->wwwroot.'/mod/assignment/type/rtcollaboration/text.php?id='.$this->cm->id.'&mode=textstatistics">'.(get_string('viewusersactivity','assignment_rtcollaboration')).'</a></div>';
		}
		
        $this->view_intro();
        $this->view_dates();
        
        // Check for user group        
        $firstview = false;
        $userview = get_record('assignment_rtcollab_view','assignment',$this->assignment->id,'userid',$USER->id);
        $groupmode = groups_get_activity_groupmode($this->cm); 
        
        if(!$userview){
            if($groupmode){
                $usergroups = groups_get_user_groups($this->course->id);
                $countgroups = count($usergroups[0]);

                if($countgroups == 0){
                    $usergroup = 0;
                }
                else if($countgroups >= 1){
                    $usergroup = $usergroups[0][0];
                }
                // else if(! $usergroup || !group_is_member($usergroup)){ 
                    // echo get_string('chooseyourgroup','assignment_rtcollaboration');
                    // groups_print_activity_menu($this->cm, $CFG->wwwroot . "/mod/assignment/view.php?id={$cm->id}");
                    // $this->view_footer();
                    // die;
                // }    
            }
            else{
                $usergroup = 0;
            }
        }
        else{
            $usergroup = $userview->groupid;
        }
                
        // For visible groups, the text of others groups is displayed
        if($groupid > -1 && $groupid != $usergroup && $groupmode && ($groupmode == VISIBLEGROUPS || has_capability('moodle/site:accessallgroups', $context))){
            $visible = true;
            $editable = false;
        }
        else{
            $groupid = $usergroup;
        }
        
        // The user can edit the text and the text is editable
        if($editable && $canedit){                              
            // Rich text editor
            if($this->assignment->var1){
                $yuicssfiles = array('menu/assets/skins/sam/menu', 'button/assets/skins/sam/button', 'fonts/fonts-min', 'container/assets/skins/sam/container', 'editor/assets/skins/sam/editor');
                $yuijsfiles = array('yahoo-dom-event/yahoo-dom-event', 'element/element-beta-min', 'container/container-min', 'menu/menu-min', 'button/button-min', 'editor/editor-min');
                foreach($yuicssfiles as $f)
                    echo '<link rel="stylesheet" type="text/css" href="'.$CFG->wwwroot.'/lib/yui/'.$f.'.css" /> ';
                foreach($yuijsfiles as $f)
                   require_js($CFG->wwwroot.'/lib/yui/'.$f.'.js');
                
                require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/mobwrite_yuirte.js');
                
                echo "<script type='text/javascript'>  
                window.onload = function(){
                    var Dom = YAHOO.util.Dom,
                        Event = YAHOO.util.Event;
                    
                    var myConfig = {
                        height: '500px',
                        width: '100%',
                        dompath: true,
                        animate: true,
                        focusAtStart: true,
                        autoHeight: true 
                    };
                 
                    var myEditor = new YAHOO.widget.Editor('rteditor".$this->assignment->id."', myConfig);
                    myEditor._defaultToolbar.buttonType = 'basic';
                    myEditor.render();
                    
                    var yuiEditor = myEditor;
                    myEditor.on('editorContentLoaded', function(){
                        mobwrite.syncUsername = '".$USER->id."';     
                        mobwrite.debug = false;
                        mobwrite.syncGateway = 'type/rtcollaboration/mobwrite.php?id=".$this->cm->id."&sesskey=".$USER->sesskey."&groupid=".$groupid."';                        
                        mobwrite.rteeditors = {'rteditor".$this->assignment->id."_editor' : myEditor};                        
                        mobwrite.share('rteditor".$this->assignment->id."_editor');                        
                    });
                };
                </script>";
                
                echo '<form method="post" action="#" id="form1" class="yui-skin-sam">';
                echo '<TEXTAREA ID="rteditor'.$this->assignment->id.'" STYLE="width: 100%; height: 100%" rows="30"></TEXTAREA>';                echo '</form>';
            }
            else{
                echo '<script type="text/javascript"><!--
                window.onload = function(){
                    // We can use the full URL. Mobwrite thinks that is a remote server (no the same) so disabled ajax
                    mobwrite.syncUsername = "'.$USER->id.'";     
                    mobwrite.debug = false;
                    mobwrite.syncGateway = "type/rtcollaboration/mobwrite.php?id='.$this->cm->id.'&sesskey='.$USER->sesskey.'&groupid='.$groupid.'";
                    mobwrite.share("rteditor'.$this->assignment->id.'");
                }    
                --></script>';  
                
                echo '<TEXTAREA ID="rteditor'.$this->assignment->id.'" STYLE="width: 100%; height: 100%" rows="30"></TEXTAREA>';            
            }
        }
        // The user can view the others text (groups are visible)
        else if(($editable && !$canedit) || $visible){
            $yuijsfiles = array('yahoo-dom-event/yahoo-dom-event','yahoo/yahoo-min','json/json-min','connection/connection-min');    
            foreach($yuijsfiles as $f)
               require_js($CFG->wwwroot.'/lib/yui/'.$f.'.js');
            require_js($CFG->wwwroot.'/mod/assignment/type/rtcollaboration/replay.js');
            echo '<script type="text/javascript"><!--                
                    var pageId = '.$this->cm->id.';
                    var groupId = '.$groupid.';
                    var rcollaborationMode = "reviewvisible";
                --></script>';
                
            echo '<p><strong>'.get_string('onlyviewpermissions','assignment_rtcollaboration').'</strong></p>';    
            echo '<TEXTAREA ID="maintext" STYLE="width: 100%; height: 100%; background-color: white" rows="30" disabled="disabled"></TEXTAREA>';
        }
        // Print my submission data
		else{
			print_simple_box(format_text($submission->data1), 'center', '100%');
		}
        
        // Back button
        print_box_start('generalbox centerpara boxwidthnormal boxaligncenter');
		print_single_button("$CFG->wwwroot/course/view.php", array('id'=>$this->course->id), get_string('back'));
		print_box_end();

        $this->view_feedback();
        $this->view_footer();
    }


    function print_student_answer($userid, $return=false){
        global $CFG;
        if (!$submission = $this->get_submission($userid)) {
            return '';
        }
		
		list($charsadded, $charsdeleted, $firstedited, $lastedited) = $this->get_chars_edited($userid);
		if(!empty($charsadded) || !empty($charsdeleted)){
			$stats = get_string('charsadded','assignment_rtcollaboration').' <b>'.$charsadded.'</b><br />';
			$stats .= get_string('charsdeleted','assignment_rtcollaboration').' <b>'.$charsdeleted.'</b><br />';
		}
		else{
			$stats = get_string('userhasnotparticipate','assignment_rtcollaboration');
		}		
		
        $output = '<div class="files">'.
                  '<img src="'.$CFG->pixpath.'/f/html.gif" class="icon" alt="html" />'.
                  '<a href="'.$CFG->wwwroot.'/mod/assignment/type/rtcollaboration/text.php?id='.$this->cm->id.'&amp;userid='.
                  $submission->userid.'" target="_blank">'.(get_string('submission', 'assignment')).'</a><br/>'.
                  $stats.'</div>';
		return $output;

    }

    function print_user_files($userid, $return=false) {
        global $CFG;

        echo $this->print_student_answer($userid, $return);
    }


    function setup_elements(&$mform) {
        global $CFG, $COURSE;

        $ynoptions = array( 0 => get_string('no'), 1 => get_string('yes'));

        $mform->addElement('select', 'resubmit', get_string("allowresubmit", "assignment"), $ynoptions);
        $mform->setHelpButton('resubmit', array('resubmit', get_string('allowresubmit', 'assignment'), 'assignment'));
        $mform->setDefault('resubmit', 0);

        $mform->addElement('select', 'emailteachers', get_string("emailteachers", "assignment"), $ynoptions);
        $mform->setHelpButton('emailteachers', array('emailteachers', get_string('emailteachers', 'assignment'), 'assignment'));
        $mform->setDefault('emailteachers', 0);

        $edoptions = array(get_string("plaintext", "assignment_rtcollaboration"),get_string("yui", "assignment_rtcollaboration"));
        $mform->addElement('select', 'var1', get_string("typeofeditor", "assignment_rtcollaboration"), $edoptions);        
        $mform->setDefault('var1', 0);

    }
    
    // We only submit assignments of users with some work done
    function submit_pending_assignments($assignments){
        if($assignments){
            foreach($assignments as $a){
                //TODO Add indexes to assignment_rtcollab_view
                $users = get_records('assignment_rtcollab_view','assignment',$a->id);
                if($users){
                    foreach($users as $u){
						//TODO Get text using group
						if(! $text = get_record('assignment_rtcollab_text','assignment', $a->id))
							continue;
							
                        if(! $submission = get_record('assignment_submissions','assignment',$a->id,'userid',$USER->id)){
							$submission = new stdclass;
							$submission->assignment   = $a->id;
							$submission->userid       = $u->userid;
							$submission->timecreated  = time();
							$submission->timemodified = $submission->timecreated;							
							$submission->numfiles     = 0;
							$submission->data1        = addslashes($text->text);
							$submission->data2        = '';
							$submission->grade        = -1;
							$submission->submissioncomment      = '';
							$submission->format       = 0;
							$submission->teacher      = 0;
							$submission->timemarked   = 0;
							$submission->mailed       = 0;
							insert_record('assignment_submissions',$submission);
                        }
						else{
							$submission->data1        = addslashes($text->text);
							$submission->timemodified = time();
							update_record('assignment_submissions',$submission);
						}
                    }
                }
            }
        }
    }
    
    // Check for pending submissions
    // Users does not submit theirself theirs assignments
    function submit_pending_submissions(){
		global $CFG;
		
        $timenow = time();
		$daysecs = 24*60*60;
        // In date assignments        
        $assignments = get_records_sql("SELECT a.*,t.text,t.groupid FROM {$CFG->prefix}assignment a LEFT JOIN {$CFG->prefix}assignment_rtcollab_text t ON a.id = t.assignment WHERE $timenow > a.timeavailable AND (($timenow < a.timedue AND a.timedue - $timenow < $daysecs) OR ($timenow > a.timedue AND a.preventlate = 0))");
        $this->submit_pending_assignments($assignments);
    }
    
    
    function cron(){
        $this->submit_pending_submissions();
    }
	
	// User group for a existing Text / view
	function user_group($userid=0){
		global $USER;
	
		if(!$userid)
			$userid = $USER->id;
			
		$userview = get_record('assignment_rtcollab_view','assignment',$this->assignment->id,'userid',$userid);
        if($userview){
            return $userview->groupid;
        }
        else{
            return 0;
        }
    
	}
	
	function get_chars_edited($userid){
		global $CFG;
		if($text = get_record("assignment_rtcollab_text", "assignment", $this->assignment->id,'groupid',$this->user_group($userid))){
			if($chars = get_record_sql("SELECT SUM(charsadded) as charsadded, SUM(charsdeleted) as charsdeleted, MAX(timestamp) as lastedited, MIN(timestamp) as firstedited FROM {$CFG->prefix}assignment_rtcollab_diff WHERE textid = {$text->id} AND userid = $userid")){
				if(!empty($chars->charsadded) || !empty($chars->charsdeleted)){
					return array($chars->charsadded, $chars->charsdeleted, $chars->firstedited, $chars->lastedited);
				}
			}
		}
		return array(0,0,0,0);
	}

}


?>