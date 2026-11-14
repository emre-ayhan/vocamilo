<script setup>
import {computed,ref,watch,nextTick} from 'vue';
import {Head,Link,router} from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import StudySummary from '../../Components/StudySummary.vue';
import Errors from '../../Components/Errors.vue';
import {useI18n} from '../../lib/i18n.js';
const {t}=useI18n();
const props=defineProps({selectedMember:{type:Object,default:null},collection:Object,sharing:Object,participants:Object,blocked:Object,filters:{type:Object,default:()=>({q:''})}});
const base=computed(()=>`/collections/${props.collection.id}`);
const query=ref(props.filters.q??''),sharingBusy=ref(false),sharingErrors=ref({});
const selecting=ref(false),progressHeading=ref(null),searchInput=ref(null),progressUnavailable=ref(false);
const progressButtons=new Map();let returnMemberId=null,listScroll=null;
function registerProgressButton(id,element){if(element)progressButtons.set(id,element);else progressButtons.delete(id);}
async function restoreListFocus(){
 await nextTick();if(props.selectedMember)return;
 const button=progressButtons.get(returnMemberId);const target=button&&!button.disabled?button:searchInput.value;
 target?.focus({preventScroll:true});
 if(listScroll!==null)window.scrollTo({top:listScroll,behavior:'instant'});else target?.scrollIntoView({behavior:'instant',block:'center'});
}
watch(()=>props.filters.q,value=>{query.value=value??'';});
function visitMembers(data,focusProgress=false,restoreList=false){
 if(selecting.value||sharingBusy.value)return;
 selecting.value=true;sharingErrors.value={};progressUnavailable.value=false;
 router.get(`${base.value}/sharing/members`,data,{preserveState:true,preserveScroll:true,replace:true,onError:errors=>{sharingErrors.value=errors;},onSuccess:async()=>{
 selecting.value=false;await nextTick();
 if(focusProgress&&props.selectedMember?.id===data.member){progressHeading.value?.focus({preventScroll:true});progressHeading.value?.scrollIntoView({behavior:'instant',block:'start'});}
 else if(focusProgress&&!props.selectedMember){progressUnavailable.value=true;await restoreListFocus();}
 else if(restoreList&&!props.selectedMember)await restoreListFocus();
},onFinish:()=>{selecting.value=false;}});
}
function search(){query.value=query.value.trim();visitMembers({q:query.value});}
function viewProgress(person){if(!person.can_view_progress||selecting.value||sharingBusy.value)return;returnMemberId=person.id;listScroll=window.scrollY;visitMembers({q:props.filters.q??'',member:person.id,participants_page:props.participants.current_page,blocked_page:props.blocked.current_page},true);}
function closeProgress(){visitMembers({q:props.filters.q??'',participants_page:props.participants.current_page,blocked_page:props.blocked.current_page},false,true);}

function sharingAction(method,url,data={}){
 if(sharingBusy.value)return;
 sharingBusy.value=true;sharingErrors.value={};
 router.visit(url,{method,data,preserveScroll:true,onError:errors=>{sharingErrors.value=errors;},onFinish:()=>{sharingBusy.value=false;}});
}
function removeMember(person){
 if(confirm(t('Remove {name}? They lose membership but can join again using an active link.',{name:person.name})))sharingAction('delete',`${base.value}/members/${person.id}`);
}
function blockMember(person){
 if(confirm(t('Block {name}? This account will lose access and cannot rejoin, even with a refreshed link. In guest mode they can still view while signed out.',{name:person.name})))sharingAction('post',`${base.value}/blocks/${person.id}`);
}
function unblockMember(person){
 if(confirm(t('Unblock {name}? They can join again using an active link. Membership is not restored automatically.',{name:person.name})))sharingAction('delete',`${base.value}/blocks/${person.id}`);
}
</script>
<template>
<Head :title="t('Manage members')"/><AppLayout :title="t('Manage members')" :subtitle="collection.title"><template #actions><Link class="button secondary" :href="base">{{t('← Collection')}}</Link></template>
<div class="members-page" :aria-busy="sharingBusy||selecting"><Errors :errors="sharingErrors"/>
<p v-if="sharing.access==='guests'" class="notice">{{t('Guest access cannot identify signed-out visitors. A blocked account can still view the collection while signed out. Choose signed-in access to enforce account blocking.')}}</p>
<form v-show="!selectedMember" class="member-search panel" @submit.prevent="search"><label>{{t('Search accounts by name or email')}}<input ref="searchInput" v-model="query" type="search" maxlength="100" :placeholder="t('Search accounts by name or email')"/></label><button class="button" :disabled="sharingBusy||selecting">{{t('Search')}}</button><button v-if="query" type="button" class="button secondary" :disabled="sharingBusy||selecting" @click="query='';search()">{{t('Clear search')}}</button></form>
<p v-if="progressUnavailable" class="notice" role="status">{{t('Progress unavailable. Current sharing consent and an active account are required.')}}</p><p v-if="selecting" role="status" class="muted">{{t('Loading…')}}</p>
<section v-if="selectedMember" class="panel selected-progress" aria-labelledby="selected-progress-title"><div class="section-heading"><h2 ref="progressHeading" id="selected-progress-title" tabindex="-1">{{t('Progress for {name}',{name:selectedMember.name})}}</h2><button class="button secondary" :disabled="sharingBusy||selecting" @click="closeProgress">{{t('Close progress')}}</button></div><p class="muted small">{{t('Only current members who agreed to share progress appear here. Personal notes are never shown.')}}</p><StudySummary :summary="selectedMember.summary" :base="base" read-only/></section>
<div v-show="!selectedMember" class="member-lists"><section v-for="group in [{key:'participants',page:participants,title:'Joined accounts ({count})'},{key:'blocked',page:blocked,title:'Blocked accounts ({count})'}]" :key="group.key" class="panel member-group"><h2>{{t(group.title,{count:group.page.total})}}</h2><p v-if="group.key==='participants'" class="muted small">{{t('Newest members first')}}</p>
<p v-if="!group.page.data.length" class="muted">{{t(query?'No accounts match your search.':group.key==='participants'?'No accounts have joined this collection.':'No blocked accounts.')}}</p>
<article v-for="person in group.page.data" :key="person.id" class="member-row"><div class="member-identity"><strong>{{person.name}}</strong><p v-if="group.key==='participants'&&!person.can_view_progress" class="muted small">{{t('Progress unavailable. Current sharing consent and an active account are required.')}}</p></div><div class="member-actions"><template v-if="group.key==='participants'"><button :ref="element=>registerProgressButton(person.id,element)" type="button" class="button" :disabled="sharingBusy||selecting||!person.can_view_progress" :aria-pressed="selectedMember?.id===person.id" @click="viewProgress(person)">{{t('View progress')}}</button><button type="button" class="button secondary" :disabled="sharingBusy||selecting" @click="removeMember(person)">{{t('Remove member')}}</button><button type="button" class="text-button danger" :disabled="sharingBusy||selecting" @click="blockMember(person)">{{t('Block account')}}</button></template><button v-else type="button" class="button secondary" :disabled="sharingBusy||selecting" @click="unblockMember(person)">{{t('Unblock account')}}</button></div></article>
<nav v-if="group.page.last_page>1" class="member-pagination" :aria-label="t(group.key==='participants'?'Joined accounts pages':'Blocked accounts pages')"><Link v-if="group.page.prev_page_url" :href="group.page.prev_page_url" preserve-scroll class="button secondary">{{t('← Previous')}}</Link><span>{{t('Page {page} of {total}',{page:group.page.current_page,total:group.page.last_page})}}</span><Link v-if="group.page.next_page_url" :href="group.page.next_page_url" preserve-scroll class="button secondary">{{t('Next →')}}</Link></nav>
</section></div></div></AppLayout>
</template>
<style scoped>.members-page,.member-lists{display:grid;gap:20px}.member-search{display:flex;align-items:flex-end;gap:12px}.member-search label{flex:1;margin:0}.member-row{display:flex;align-items:center;justify-content:space-between;gap:16px;border-top:1px solid #e3e7e4;padding:16px 0}.member-row strong{overflow-wrap:anywhere;min-width:0}.member-actions{display:flex;align-items:center;gap:16px;flex-shrink:0}.member-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:20px}.member-group h2{font-size:1.15rem}@media(max-width:600px){.member-search,.member-row{flex-direction:column;align-items:stretch}.member-actions{justify-content:space-between;flex-wrap:wrap}.member-pagination{flex-wrap:wrap}.member-pagination .button{padding:10px}.member-group{padding:18px}}.member-identity{min-width:0;flex:1}.member-identity p{margin:8px 0 0;max-width:38ch}.member-actions{gap:10px}.member-actions button{min-height:44px}.selected-progress .section-heading{flex-wrap:wrap;gap:12px}.selected-progress :deep(.study-history){margin-bottom:0}@media(max-width:760px){.member-row{flex-direction:column;align-items:stretch}.member-actions{display:flex;flex-direction:column;align-items:stretch;width:100%}.member-actions button,.member-search .button{width:100%;min-height:46px}.member-search{flex-direction:column;align-items:stretch}.member-search label{width:100%}.selected-progress{padding:16px}.selected-progress .section-heading .button{width:100%}}
.member-row{min-width:0;flex-wrap:wrap}.member-actions{max-width:100%;flex-wrap:wrap}.member-identity{min-width:min(180px,100%)}.member-actions button{white-space:normal}.selected-progress h2{scroll-margin-top:24px}.selected-progress h2:focus-visible{outline:2px solid var(--teal,#157e73);outline-offset:5px}@media(min-width:761px) and (max-width:1024px){.member-actions{flex:1 1 100%;justify-content:flex-start}.member-actions button{flex:1 1 120px}}</style>
