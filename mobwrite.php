<?php

/* Free PHP Implementation of http://code.google.com/p/google-mobwrite
 * This is not a Daemon. It is an AJAX request Controller, a DataBase backend is needed for data persistency
 * Protocol:  http://code.google.com/p/google-mobwrite/wiki/Protocol
 * More info: http://www.youtube.com/watch?v=S2Hp_1jqpY8
 * @author Juan Leyva <juanleyvadelgado@gmail.com>
*/

// If you want use this PHP code for other projet different of Moodle you must:
// Delete the Moodle related stuff: $USER, optional_param, sesskey checking, ...
// Reimplement the DB backend functions: insert_record, get_record, *_record to match your Database Server


include('../../../../config.php');

// Credits: https://github.com/nuxodin/diff_match_patch-php
include('diff_match_patch.php');

// Singleton for diff_match_patch class
function mobwrite_dmp(){
    static $dmp = null;
    if(!$dmp){
        $dmp = new diff_match_patch();
    }
    return $dmp;
}

// Constants
define('MAX_CHARS', 100000);
define('MAX_LOCK_TIME', 5);
define('EDITOR_PREFIX', 'rteditor');

// For locking
$lockedid = 0;

// An object which stores a text.
class TextObj {

    var $name = '';
    var $text = '';
    var $changed = false;
    var $views = 0;
    var $lasttime = 0;
    var $dbid = 0;
  
    function TextObj($id){
        // Setup this object
        $this->name = $id;
        $this->views = 0;
        $this->lasttime = time();
        $this->load();
    }
    
    // TODO - We need this?
    function lock_acquire(){
        return true;
    }
    
    // TODO - We need this?
    function lock_release(){
        return true;
    }

    function set_text($newtext){
        // Scrub the text before setting it->
        if ($newtext){
            // Keep the text within the length limit->
            if (MAX_CHARS != 0 && strlen($newtext) > MAX_CHARS){
                $newtext = substr($newtext,o,MAX_CHARS);                
            }
            
            // Normalize linebreaks to LF->
            $newtext = str_replace(array("\r\n","\r","\n"), "\n", $newtext);
                
            if ($this->text != $newtext){
              $this->text = $newtext;
              $this->changed = true;
              $this->save();
            }
        }
        $this->lasttime = time();
    }
    
    function cleanup(){
        // Not implemented
        echo "Not implemented";
    }
    
    function load(){
        global $currentgroup, $CFG, $USER, $lockedid;
        
        // This is a kind of a lock that I'm not pretty sure if works fine
        // TODO - Check if this works fine :)
        //begin_sql();
        //commit_sql();
        //roolback_sql();
        $timenow = time();
        $timelocked = $timenow + MAX_LOCK_TIME;
                
        // The Text record is created when configuring the Moodle activity
        
        if($text = get_record('assignment_rtcollab_text','assignment',$this->name,'groupid',$currentgroup)){
            $locksql = "AND (locked = 0 OR timelocked < $timenow)";
            //$lockedtext = get_record_select('assignment_rtcollab_text',"assignment = {$this->name} AND (groupid = 0 OR groupid = $currentgroup) $locksql");        
            execute_sql("UPDATE {$CFG->prefix}assignment_rtcollab_text SET locked = '{$USER->id}', timelocked = '$timelocked' WHERE id = {$text->id} $locksql", false);
            if(! $lockedtext = get_record('assignment_rtcollab_text','id',$text->id,'locked',$USER->id)){
                echo "error: Text locked";
                die;
            }
            $this->set_text($text->text);
            $this->dbid = $text->id;
            $lockedid = $this->dbid;
        }
        else if($text = get_record('assignment_rtcollab_text','assignment',$this->name,'groupid',0)){
            $this->set_text($text->text);
            unset($text->id);
            $text->groupid = $currentgroup;
            $text->text = addslashes($text->text);
            $text->locked = $USER->id;
            $text->timelocked = $timelocked;
            // groupid + assignment is a UNIQUE Key
            if(! $this->dbid = insert_record('assignment_rtcollab_text',$text)){
                echo "error: Text locked";
                die;
            }
            $this->dbid = $text->id;
            $lockedid = $this->dbid;
        }
        else{
            echo "error: Database failure or the server text is not yet created";
            die;     
        }
    }

    function save(){
        global $currentgroup;
        
        $text = new stdclass();
        $text->id = $this->dbid;
        $text->assignment = $this->name;    
        $text->groupid = $currentgroup;
        $text->text = addslashes($this->text);
        $text->timemodified = time();
        
        update_record('assignment_rtcollab_text', $text);
    }    
}

function mobwrite_fetchtextobj($fileid){
    return new TextObj($fileid);
}

 // An object which contains one user's view of one text.
 //  Object properties:
 //  .userid - The name for the user, e.g 'fraser'
 //  .fileid - The name for the file, e.g 'proposal'
 //  .shadow - The last version of the text sent to client.
 //  .backup_shadow - The previous version of the text sent to client.
 //  .shadow_client_version - The client's version for the shadow (n).
 //  .shadow_server_version - The server's version for the shadow (m).
 //  .backup_shadow_server_version - the server's version for the backup
 //      shadow (m).
 //  .edit_stack - List of unacknowledged edits sent to the client.
 //  .changed - Has the view changed since the last time it was saved.
 //  .delta_ok - Did the previous delta match the text length.
  
class ViewObj{
    var $userid = '';
    var $fileid = '';
    var $shadow_client_version = 0;
    var $shadow_server_version = 0;
    var $backup_shadow_server_version = 0;
    var $shadow = '';
    var $backup_shadow = '';
    var $edit_stack = array();
    var $changed = false;
    var $delta_ok = true;
    var $lasttime = 0;
    var $textobj = null;
    var $dbid = 0;
    
    function ViewObj($data){
        $this->userid = $data['userid'];
        $this->fileid = $data['fileid'];
        // TODO Clean times
        if($view = get_record('assignment_rtcollab_view','userid',$this->userid,'assignment',$this->fileid)){            
            $this->shadow_client_version = $view->shadow_client_version;
            $this->shadow_server_version = $view->shadow_server_version;
            $this->backup_shadow_server_version = $view->backup_shadow_server_version;
            $this->shadow = $view->shadow;
            $this->backup_shadow = $view->backup_shadow;
            $this->dbid = $view->id;
        }
        
        if(!$view){
            $view = new stdclass();
            $view->userid = $this->userid;
            $view->assignment = $this->fileid;
            $view->shadow = '';
            $view->backup_shadow = '';
            $view->shadow_client_version = 0;
            $view->shadow_server_version = 0;
            $view->backup_shadow_server_version = 0;
            if(!$this->dbid = insert_record('assignment_rtcollab_view', $view)){
                echo "error: Database failure";
                die;
            }
        }
        
        $this->lasttime = time();
        $this->textobj = mobwrite_fetchtextobj($this->fileid);
    }
    
    // Function not present in mob_write, needed for persistency in DB
    function save(){
        // TODO
        $view = new stdclass();
        $view->id = $this->dbid;
        $view->userid = $this->userid;
        $view->assignment = $this->fileid;
        $view->shadow = addslashes($this->shadow);
        $view->backup_shadow = addslashes($this->backup_shadow);
        $view->shadow_client_version = $this->shadow_client_version;
        $view->shadow_server_version = $this->shadow_server_version;
        $view->backup_shadow_server_version = $this->backup_shadow_server_version;
        update_record('assignment_rtcollab_view', $view);
    }
    
    function cleanup(){
        // Not implemented
        echo "Not implemented";
    }
}

// def fetch_viewobj(username, filename): mobwrite_daemon
function mobwrite_fetchviewobj($userid, $fileid){
    // Retrieve the named view object.  Create it if it doesn't exist.
    // Don't let two simultaneous creations happen, or a deletion during a
    // retrieval.
    $viewobj = new ViewObj(array('userid'=>$userid, "fileid"=>$fileid));
    
    return $viewobj;
}

// def parseRequest(self, data): mobwrite_core.py
function mobwrite_parse_request($r){
    global $USER;
    
    $lines = explode("\n",$r);
    
    if($lines){
        $actions = array();
        $userid = 0;
        $fileid = '';
        $echouserid = false;
        $serverversion = 0;
        
        foreach($lines as $l){
            $name = substr($l,0,1);
            $value = substr($l,2);
            
            $version = 0;
            if(preg_match('/[FfDdRr]/',$name)){
                $div = strpos($value,':');
                if($div > 0){
                    $version = substr($value,0,$div);
                    $value = substr($value,$div+1);
                }
                else{
                    continue;
                }
            }
            
            // Buffers are not supported. TODO Add buffer support using database tmp storage
            if($name == 'b' || $name == 'B'){
                // See http://code.google.com/p/google-mobwrite/wiki/Protocol Buffer section
                echo "error: Server does not support buffers";
                die;
            }
            elseif($name == 'u' || $name == 'U'){
                $userid = $value;
                $echouserid = ($name == 'U');
                if($userid != $USER->id){
                    echo "error: Bad user";
                    die;
                }
            }
            elseif($name == 'f' || $name == 'F'){
                // New, use rteditor as prefix
                $fileid = str_replace(EDITOR_PREFIX,'',$value);
                $serverversion = $version;
            }
            elseif($name == 'n' || $name == 'N'){
                $fileid = str_replace(EDITOR_PREFIX,'',$value);
                if($userid && $fileid){
                    $action = array();
                    $action['userid'] = $userid;
                    $action['fileid'] = $fileid;
                    $action['mode'] = "null";
                    $actions[] = $action;
                }                    
            }
            else{
                $action = array();
                if($name == 'd' || $name == 'D'){
                    $action['mode'] = "delta";
                }
                elseif($name == 'r' or $name == 'R'){
                    $action['mode'] = "raw";
                }
                else{
                    $action['mode'] = '';
                }

                if (preg_match('/[A-Z]$/',$name) == true){
                    $action['force'] = true;
                }
                else{
                    $action['force'] = false;
                }
                
                $action['server_version'] = $serverversion;
                $action['client_version'] = $version;
                $action['data'] = $value;
                $action['echo_userid'] = $echouserid;
                
                if($userid && $fileid && $action['mode']){
                    $action['userid'] = $userid;
                    $action['fileid'] = $fileid;
                    $actions[] = $action;
                }        
            }
        }
        
        return $actions;
        
    }
    else{
        echo "error: No lines";
        die;
    }    
}

// def applyPatches(self, viewobj, diffs, action): mobwrite_core.py
function mobwrite_apply_patches(&$viewobj, $diffs, $action){
    
    // Expand the fragile diffs into a full set of patches.
    $patches = mobwrite_dmp()->patch_make($viewobj->shadow, $diffs);

    // First, update the client's shadow.
    $viewobj->shadow = mobwrite_dmp()->diff_text2($diffs);
    $viewobj->backup_shadow = $viewobj->shadow;
    $viewobj->backup_shadow_server_version = $viewobj->shadow_server_version;
    $viewobj->changed = true;

    // Second, deal with the server's text.
    $textobj = $viewobj->textobj;
    
    if (!$textobj->text){
        // A view is sending a valid delta on a file we've never heard of.
        $textobj->set_text($viewobj->shadow);
        $action["force"] = false;        
    }
    else{
        if ($action["force"]){
            // Clobber the server's text if a change was received.
            if ($patches){
                $mastertext = $viewobj->shadow;
            }    
            else{
                $mastertext = $textobj->text;
            }
        }
        else{
            list($mastertext, $results) = mobwrite_dmp()->patch_apply($patches, $textobj->text);
        }
        $textobj->set_text($mastertext);
    }
}


//  def generateDiffs(self, viewobj, print_username, print_filename, force): mobwrite_daemon.py
function mobwrite_generate_diffs($viewobj, $printuserid, $printfileid, $force){
    $output = array();
    
    if($printuserid){
      $output[] = "u:$printuserid\n";
    }
    if($printfileid){
      // New, we must add the prefix
      $printfileid = EDITOR_PREFIX.$printfileid;
      $output[] = "F:{$viewobj->shadow_client_version}:$printfileid\n";
    }
    
    $textobj = $viewobj->textobj;
    $mastertext = $textobj->text;

    if($viewobj->delta_ok){
        if($mastertext == null){
            $mastertext = "";
        }
        // Create the diff between the view's text and the master text.
        $diffs = mobwrite_dmp()->diff_main($viewobj->shadow, $mastertext);
        mobwrite_dmp()->diff_cleanupEfficiency($diffs);
        $text = mobwrite_dmp()->diff_toDelta($diffs);
        
        if($force){
            // Client sending 'D' means number, no error.
            // Client sending 'R' means number, client error.
            // Both cases involve numbers, so send back an overwrite delta.
            $viewobj->edit_stack[] = array($viewobj->shadow_server_version,"D:{$viewobj->shadow_server_version}:$text\n");
        }    
        else{
            // Client sending 'd' means text, no error.
            // Client sending 'r' means text, client error.
            // Both cases involve text, so send back a merge delta.
            $viewobj->edit_stack[] = array($viewobj->shadow_server_version, "d:{$viewobj->shadow_server_version}:$text\n");
            $viewobj->shadow_server_version += 1;        
        }
    }
    else{
        // Error; server could not parse client's delta.
        // Send a raw dump of the text.
        $viewobj->shadow_client_version += 1;
        if($mastertext == null){
            $mastertext = "";
            $viewobj->edit_stack[] = array($viewobj->shadow_server_version,"r:{$viewobj->shadow_server_version}:\n");
            
        }
        else{
            // Force overwrite of client.
            $text = $mastertext;
            //$text = text.encode("utf-8")
            //$text = urlrawencode($text);
            $text = urlencode($text);
            $viewobj->edit_stack[] = array($viewobj->shadow_server_version, "R:{$viewobj->shadow_server_version}:$text\n");
        }    
    }
    
    $viewobj->shadow = $mastertext;
    $viewobj->changed = true;

    foreach($viewobj->edit_stack as $edit){
      $output[] = $edit[1];
    }
    
    return implode("",$output);
}    

//def doActions(self, actions): mobwrite_daemon.py
function mobwrite_do_actions($actions){
    
    $output = array();
    $viewobj = null;
    $lastuserid = '';
    $lastfileid = '';

    foreach($actions as $actionindex =>$action){
        $userid = $action["userid"];
        $fileid = $action["fileid"];

        // Fetch the requested view object.
        if(! $viewobj){
            $viewobj = mobwrite_fetchviewobj($userid, $fileid);
            if(! $viewobj){
                // Too many views connected at once.
                // Send back nothing.  Pretend the return packet was lost.
                return "";
            }
            $viewobj->delta_ok = true;
            $textobj = $viewobj->textobj;
        }
        
        if ($action["mode"] == "null"){
            // Nullify the text.            
            $textobj->lock_acquire();            
            $textobj->set_text(null);
            $textobj->lock_release();
            $viewobj->nullify();
            $viewobj = null;
            continue;
        }
        
        if ($action["server_version"] != $viewobj->shadow_server_version && $action["server_version"] == $viewobj->backup_shadow_server_version){
            // Client did not receive the last response->  Roll back the shadow->            
            $viewobj->shadow = $viewobj->backup_shadow;
            $viewobj->shadow_server_version = $viewobj->backup_shadow_server_version;
            $viewobj->edit_stack = array();
        }
        
        // Remove any elements from the edit stack with low version numbers which
        // have been acked by the client.
        $x = 0;
        while ($x < count($viewobj->edit_stack)){
            if($viewobj->edit_stack[$x][0] <= $action["server_version"]){
                unset($viewobj->edit_stack[$x]);
            }
            else{
                $x += 1;
            }
        }

        if ($action["mode"] == "raw"){
            // It's a raw text dump.
            $data = stripslashes($action["data"]); 
            $data = urldecode($data);
            $viewobj->delta_ok = true;
            // First, update the client's shadow.
            $viewobj->shadow = $data;
            $viewobj->shadow_client_version = $action["client_version"];
            $viewobj->shadow_server_version = $action["server_version"];
            $viewobj->backup_shadow = $viewobj->shadow;
            $viewobj->backup_shadow_server_version = $viewobj->shadow_server_version;
            $viewobj->edit_stack = array();
            if ($action["force"] || $textobj->text){
                // Clobber the server's text.
                $textobj->lock_acquire();
                if ($textobj->text != $data){
                  $textobj->set_text($data);
                }
                $textobj->lock_release();
            }
        }        
        elseif ($action["mode"] == "delta"){
            // It's a delta.
            
            if($action["server_version"] != $viewobj->shadow_server_version){
                // Can't apply a delta on a mismatched shadow version.
                $viewobj->delta_ok = false;
            }  
            elseif($action["client_version"] > $viewobj->shadow_client_version){
                // Client has a version in the future?
                $viewobj->delta_ok = false;              
            }
            elseif($action["client_version"] < $viewobj->shadow_client_version){
              // We've already seen this diff.        
            
            }
            else{
                // Expand the delta into a diff using the client shadow.
                try{                    
                    $diffs = mobwrite_dmp()->diff_fromDelta($viewobj->shadow, $action["data"]);
                }
                catch (Exception $e){
                    $diffs = null;
                    $viewobj->delta_ok = false;
                }
              
                $viewobj->shadow_client_version += 1;
                if($diffs != null){
                  // Textobj lock required for read/patch/write cycle.
                  $textobj->lock_acquire();
                  mobwrite_apply_patches($viewobj, $diffs, $action);
                  $textobj->lock_release();
                }
            }
        }
        
        // Generate output if(this is the last action or the userid/fileid
        // will change in the next iteration.
        if(($actionindex + 1 == count($actions)) || $actions[$actionindex + 1]["userid"] != $userid || $actions[$actionindex + 1]["fileid"] != $fileid){
            $printuserid = '';
            $printfileid = '';
            
            if($action["echo_userid"] && $lastuserid != $userid){
              // Print the $userid if(the previous action was for a different user.
              $printuserid = $userid;
            }  
            if($lastfileid != $fileid || $lastuserid  != $userid){
                // Print the $fileid if(the previous action was for a different user
                // or file.
                $printfileid = $fileid;
            }
            
            $output[] = mobwrite_generate_diffs($viewobj, $printuserid, $printfileid, $action["force"]);
            
            $lastuserid = $userid;
            $lastfileid = $fileid;
            
            // New: Save the object in the DB
            $viewobj->save();
            // Dereference the view object so that a new one can be created.
            $viewobj = null;
        }        
        
    }
    return implode("",$output);
}

/*
*
* MAIN CODE
*
*/

$id = optional_param('id', 0, PARAM_INT);  // Course Module ID
$a  = optional_param('a', 0, PARAM_INT);   // Assignment ID

$r = (isset($_POST['q']))? $_POST['q'] : '';  // Request
// TODO - Sanitize var, take care with diff positions and contents

if ($id) {
    if (! $cm = get_coursemodule_from_id('assignment', $id)) {
        echo "error: Course Module ID was incorrect";
        die;
    }

    if (! $assignment = get_record("assignment", "id", $cm->instance)) {
        echo "error: assignment ID was incorrect";
        die;
    }

    if (! $course = get_record("course", "id", $assignment->course)) {
        echo "error: Course is misconfigured";
        die;
    }
} else {
    if (!$assignment = get_record("assignment", "id", $a)) {
        echo "error: Course module is incorrect";
        die;
    }
    if (! $course = get_record("course", "id", $assignment->course)) {
        echo "error: Course is misconfigured";
        die;
    }
    if (! $cm = get_coursemodule_from_instance("assignment", $assignment->id, $course->id)) {
        echo "error: Course Module ID was incorrect";
        die;
    }
}

require_login($course, true, $cm);

if(!confirm_sesskey()){
    echo "error: Invalid sesskey";
    die;
}

// Check if the assignment is still open
$time = time();
if ($assignment->preventlate && $assignment->timedue) {
    $isopen = ($assignment->timeavailable <= $time && $time <= $assignment->timedue);
} else {
    $isopen = ($assignment->timeavailable <= $time);
}
if(! $isopen){
    echo "error: Assignment closed";
    die;
}

if($r){
    // Users group
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);
    
    //$r = preg_replace('/%([0-9a-f]{2})/ie', 'chr(hexdec($1))', (string) $r);
    //$r = rawurldecode($r);
    $actions = mobwrite_parse_request($r);
    // Actions are performed over a row in the database with the Shared Text locked.
    echo mobwrite_do_actions($actions)."\n\n";
    // The lock is create when the Text object is loaded (last moment)
    // We release the lock just in the last moment
    execute_sql("UPDATE {$CFG->prefix}assignment_rtcollab_text SET locked = 0 WHERE id = $lockedid", false);
    die;
}
else{
    echo "error: No request received";
    die;
}

?>