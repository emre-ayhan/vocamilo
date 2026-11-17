// Focus/scroll ownership for the mobile drawer. No browser globals at module load.
export function createDrawerFocus(doc, onClose) {
    let panel=null, previous=null, overflow='', priority='';
    const controls=()=>panel ? [...panel.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')].filter(node=>!node.disabled&&node.tabIndex>=0&&node.getClientRects().length>0&&!node.closest('[inert]')) : [];
    function keydown(event){
        if(!panel)return;
        if(event.key==='Escape'){event.preventDefault();onClose();return;}
        if(event.key!=='Tab')return;
        const items=controls(),first=items[0],last=items.at(-1);
        if(!first){event.preventDefault();panel.focus();return;}
        if(event.shiftKey&&(doc.activeElement===first||!items.includes(doc.activeElement))){event.preventDefault();last.focus();}
        else if(!event.shiftKey&&(doc.activeElement===last||!items.includes(doc.activeElement))){event.preventDefault();first.focus();}
    }
    function focusin(event){if(panel&&!panel.contains(event.target))(controls()[0]??panel).focus();}
    function deactivate(restore=true){
        if(!panel)return;
        panel=null;doc.removeEventListener('keydown',keydown);doc.removeEventListener('focusin',focusin);
        if(overflow)doc.body.style.setProperty('overflow',overflow,priority);else doc.body.style.removeProperty('overflow');
        if(restore&&previous?.isConnected&&previous.getClientRects().length)previous.focus();
        previous=null;
    }
    function activate(element,restoreTarget){
        if(!doc||!element)return;
        deactivate(false);panel=element;previous=restoreTarget??doc.activeElement;
        overflow=doc.body.style.getPropertyValue('overflow');priority=doc.body.style.getPropertyPriority('overflow');
        doc.body.style.setProperty('overflow','hidden');
        doc.addEventListener('keydown',keydown);doc.addEventListener('focusin',focusin);
        (controls()[0]??panel).focus();
    }
    return {activate,deactivate};
}
