var rcollaborationDiffId = 0;

var rcollaborationDMP = new diff_match_patch();

var rcollaborationCallback = {
  success: rcollaborationGetDiffSuccess,
  failure: rcollaborationGetDiffFailure
};

var rcollaborationMaintext = null;

function rcollaborationGetDiff(){
    YAHOO.util.Connect.asyncRequest('GET', 'text.php?id='+pageId+'&mode=review&diffid='+rcollaborationDiffId, rcollaborationCallback, null);
}

function rcollaborationGetDiffSuccess(o){    
    var diffs = YAHOO.lang.JSON.parse(o.responseText);
	if(diffs.length > 0){
		for(var diff in diffs){
			if(diffs[diff].fulldump == 1){
				rcollaborationMaintext.value = decodeURI(diffs[diff].diff);
			}    
			else{
				//rcollaborationMaintext.value += decodeURI(diffs[diff].diff);
				var dmpDiffs = rcollaborationDMP.diff_fromDelta(rcollaborationMaintext.value, diffs[diff].diff);if(dmpDiffs && (dmpDiffs.length != 1 || dmpDiffs[0][0] != DIFF_EQUAL)) {
					var patches = rcollaborationDMP.patch_make(rcollaborationMaintext.value, dmpDiffs);
					var serverResult = rcollaborationDMP.patch_apply(patches, rcollaborationMaintext.value);
					rcollaborationMaintext.value = serverResult[0];
				}          
			}
			rcollaborationDiffId = diffs[diff].id;
		}
		setTimeout('rcollaborationGetDiff()', 2000);
	}
	else{
		setTimeout('rcollaborationGetDiff()', 30000);
	}
}

function rcollaborationGetDiffFailure(o){
    setTimeout('rcollaborationGetDiff()', 2000);
}

(function() {
    var Dom = YAHOO.util.Dom,
        Event = YAHOO.util.Event;
 
    Event.onDOMReady(function() {        
        rcollaborationMaintext = YAHOO.util.Dom.get('maintext');
        rcollaborationGetDiff();        
    });
})();