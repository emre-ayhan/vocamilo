import test from 'node:test';
import assert from 'node:assert/strict';
import {createDrawerFocus} from './drawer-focus.js';
function fixture(){
 const handlers=new Map(),values=new Map([['overflow','auto']]);
 const doc={activeElement:null,body:{style:{getPropertyValue:k=>values.get(k)||'',getPropertyPriority:()=>'',setProperty:(k,v)=>values.set(k,v),removeProperty:k=>values.delete(k)}},addEventListener:(k,v)=>handlers.set(k,v),removeEventListener:k=>handlers.delete(k)};
 const control=()=>({disabled:false,tabIndex:0,isConnected:true,getClientRects:()=>[{}],closest:()=>null,focus(){doc.activeElement=this;}});
 const opener=control(),first=control(),last=control();doc.activeElement=opener;
 const panel={querySelectorAll:()=>[first,last],contains:el=>el===first||el===last,focus(){doc.activeElement=this;}};
 return {doc,handlers,values,opener,first,last,panel};
}
test('drawer focuses first control, traps Tab both ways and Escape requests close',()=>{
 const f=fixture();let closed=0;const drawer=createDrawerFocus(f.doc,()=>closed++);drawer.activate(f.panel);
 assert.equal(f.doc.activeElement,f.first);assert.equal(f.values.get('overflow'),'hidden');
 let prevented=0;const key=(key,shiftKey=false)=>f.handlers.get('keydown')({key,shiftKey,preventDefault:()=>prevented++});
 key('Tab',true);assert.equal(f.doc.activeElement,f.last);key('Tab');assert.equal(f.doc.activeElement,f.first);
 key('Escape');assert.equal(closed,1);assert.equal(prevented,3);
 drawer.deactivate();assert.equal(f.doc.activeElement,f.opener);assert.equal(f.values.get('overflow'),'auto');assert.equal(f.handlers.size,0);
});
test('focus escape is redirected and unmount cleanup does not restore focus',()=>{
 const f=fixture();const drawer=createDrawerFocus(f.doc,()=>{});drawer.activate(f.panel);
 f.doc.activeElement=f.opener;f.handlers.get('focusin')({target:f.opener});assert.equal(f.doc.activeElement,f.first);
 drawer.deactivate(false);assert.equal(f.doc.activeElement,f.first);assert.equal(f.handlers.size,0);assert.equal(f.values.get('overflow'),'auto');drawer.deactivate();
});
test('empty drawer focuses panel and never allows Tab to leak',()=>{
 const f=fixture();f.panel.querySelectorAll=()=>[];const drawer=createDrawerFocus(f.doc,()=>{});drawer.activate(f.panel);assert.equal(f.doc.activeElement,f.panel);
 let prevented=false;f.handlers.get('keydown')({key:'Tab',preventDefault:()=>prevented=true});assert.equal(prevented,true);drawer.deactivate();
});
test('helper can be constructed without browser globals',()=>{const drawer=createDrawerFocus(undefined,()=>{});drawer.activate(null);drawer.deactivate();});

test('explicit opener restores focus even if inert background already blurred it',()=>{const f=fixture();f.doc.activeElement=null;const drawer=createDrawerFocus(f.doc,()=>{});drawer.activate(f.panel,f.opener);drawer.deactivate();assert.equal(f.doc.activeElement,f.opener);});
