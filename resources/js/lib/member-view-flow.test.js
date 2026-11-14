import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../Pages/Collections/Members.vue',import.meta.url),'utf8');
function mount(){
 const props={collection:{id:9},filters:{q:'lea'},participants:{current_page:3},blocked:{current_page:2},selectedMember:null};
 const requests=[],events=[];const router={get(url,data,options){requests.push({url,data,options});},visit(){}};
 const window={scrollY:420,scrollTo:args=>events.push(['scroll',args.top])};
 const script=source.split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\n/gm,'');
 const setup=new Function('defineProps','ref','computed','watch','nextTick','useI18n','router','window',`${script}\nreturn {viewProgress,closeProgress,registerProgressButton,selecting,progressHeading,searchInput,progressUnavailable,sharingErrors};`);
 const vm=setup(()=>props,value=>({value}),get=>({get value(){return get();}}),()=>{},async()=>{},()=>({t:key=>key}),router,window);
 const button={disabled:false,focus:()=>events.push('button-focus')};
 vm.registerProgressButton(7,button);vm.searchInput.value={focus:()=>events.push('search-focus'),scrollIntoView:()=>events.push('search-scroll')};
 vm.progressHeading.value={focus:()=>events.push('heading-focus'),scrollIntoView:()=>events.push('heading-scroll')};
 return {props,requests,events,vm,button};
}
test('successful close retains query and both pages then restores trigger focus and list scroll',async()=>{
 const {props,requests,events,vm}=mount();vm.viewProgress({id:7,can_view_progress:true});props.selectedMember={id:7};await requests[0].options.onSuccess();requests[0].options.onFinish();
 assert.ok(events.includes('heading-focus'));events.length=0;vm.closeProgress();
 assert.equal(props.selectedMember.id,7);assert.equal(vm.selecting.value,true);assert.deepEqual(requests[1].data,{q:'lea',participants_page:3,blocked_page:2});
 props.selectedMember=null;await requests[1].options.onSuccess();requests[1].options.onFinish();assert.deepEqual(events,['button-focus',['scroll',420]]);
});
test('close error leaves selected data and current focus intact, no list flash',async()=>{
 const {props,requests,events,vm}=mount();props.selectedMember={id:7};vm.closeProgress();requests[0].options.onError({member:'Unavailable'});requests[0].options.onFinish();
 assert.equal(props.selectedMember.id,7);assert.deepEqual(events,[]);assert.equal(vm.sharingErrors.value.member,'Unavailable');
});
test('revoked selection returns list, announces unavailable and falls back to search when trigger cannot be used',async()=>{
 const {requests,events,vm,button}=mount();vm.viewProgress({id:7,can_view_progress:true});button.disabled=true;await requests[0].options.onSuccess();requests[0].options.onFinish();
 assert.equal(vm.progressUnavailable.value,true);assert.ok(events.includes('search-focus'));assert.ok(!events.includes('heading-focus'));
});
