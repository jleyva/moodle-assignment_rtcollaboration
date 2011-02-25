var rcollaborationDiffId = 0;

var rcollaborationDMP = new diff_match_patch();

var rcollaborationCallback = {
  success: rcollaborationGetDiffSuccess,
  failure: rcollaborationGetDiffFailure
};

var rcollaborationMaintext = null;

function rcollaborationGetDiff(){
    YAHOO.util.Connect.asyncRequest('GET', 'text.php?id='+pageId+'&textid='+textId+'&diffid='+rcollaborationDiffId, rcollaborationCallback, null);
}

function rcollaborationGetDiffSuccess(o){    
    var diffs = YAHOO.lang.JSON.parse(o.responseText);
    for(var diff in diffs){
        if(diffs[diff].fulldump == 1){
            rcollaborationMaintext.innerHTML = decodeURI(diffs[diff].diff);
        }    
        else{
            //rcollaborationMaintext.innerHTML += decodeURI(diffs[diff].diff);
            diffs = rcollaborationDMP.diff_fromDelta(rcollaborationMaintext.innerHTML, diffs[diff].diff);            
        }
        rcollaborationDiffId = diffs[diff].id;
    }
    setTimeout('rcollaborationGetDiff()', 2000);
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