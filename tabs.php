<?php  
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

$baselink = $CFG->wwwroot."/mod/assignment/type/rtcollaboration/text.php?id=$id&userid=$userid";

$top = array();

$top[] = new tabobject('overview',$baselink.'&mode=overview', get_string('overview','assignment_rtcollaboration'));

if($cangrade)
	$top[] = new tabobject('textstatistics',$baselink.'&mode=textstatistics&group='.$groupid, get_string('textstatistics','assignment_rtcollaboration'));

if($cangrade)
	$top[] = new tabobject('review',$baselink.'&mode=review&group='.$groupid, get_string('reviewtextedition','assignment_rtcollaboration'));


$tabs = array($top);

print_tabs($tabs, $currenttab);

?>