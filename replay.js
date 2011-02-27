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
	var addc, delc, diffc = 0;
	var el = null;
	
	YAHOO.util.Dom.setStyle(YAHOO.util.Dom.getElementsByClassName('rtuserrow'),'background-color','white');
	
	
	if(diffs.length > 0){
		var first = true;
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
			
			if(first){
				el = YAHOO.util.Dom.get('diffc'+diffs[diff].userid);
				el.innerHTML = '0';
			}
			
			addc = parseInt(diffs[diff].charsadded);
			delc = parseInt(diffs[diff].charsdeleted);
			if(addc){
				el = YAHOO.util.Dom.get('addc'+diffs[diff].userid);
				el.innerHTML = parseInt(el.innerHTML) + addc + '';
				YAHOO.util.Dom.setStyle(el,'background-color','yellow');
				el = YAHOO.util.Dom.get('diffc'+diffs[diff].userid);
				el.innerHTML = parseInt(el.innerHTML) + addc + '';
				YAHOO.util.Dom.setStyle(el,'background-color','yellow');
			}
			if(delc){			
				el = YAHOO.util.Dom.get('delc'+diffs[diff].userid);
				el.innerHTML = parseInt(el.innerHTML) + delc + '';
				YAHOO.util.Dom.setStyle('delc'+diffs[diff].userid,'background-color','yellow');
			}			

			first = false;
		}
		YAHOO.util.Dom.get('currentedit').innerHTML = '<b>'+diffs[diff].date+'</b>';
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