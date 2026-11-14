<script setup>
import {computed,ref} from 'vue';
import {Head,Link,router} from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Errors from '../../Components/Errors.vue';
import {useI18n} from '../../lib/i18n.js';
const {t}=useI18n();
const props=defineProps({collection:Object,sharing:Object,participants:Object,blocked:Object,filters:{type:Object,default:()=>({q:''})}});
const base=computed(()=>`/collections/${props.collection.id}`);
const query=ref(props.filters.q??''),sharingBusy=ref(false),sharingErrors=ref({});
function search(){router.get(`${base.value}/sharing/members`,{q:query.value},{preserveState:true,preserveScroll:true,replace:true});}
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
<div class="members-page" :aria-busy="sharingBusy"><Errors :errors="sharingErrors"/>
<p v-if="sharing.access==='guests'" class="notice">{{t('Guest access cannot identify signed-out visitors. A blocked account can still view the collection while signed out. Choose signed-in access to enforce account blocking.')}}</p>
<form class="member-search panel" @submit.prevent="search"><label>{{t('Search accounts by name')}}<input v-model="query" type="search" maxlength="100" :placeholder="t('Enter a name')"/></label><button class="button" :disabled="sharingBusy">{{t('Search')}}</button><button v-if="query" type="button" class="button secondary" :disabled="sharingBusy" @click="query='';search()">{{t('Clear search')}}</button></form>
<section v-for="group in [{key:'participants',page:participants,title:'Joined accounts ({count})'},{key:'blocked',page:blocked,title:'Blocked accounts ({count})'}]" :key="group.key" class="panel member-group"><h2>{{t(group.title,{count:group.page.total})}}</h2>
<p v-if="!group.page.data.length" class="muted">{{t(query?'No accounts match your search.':group.key==='participants'?'No accounts have joined this collection.':'No blocked accounts.')}}</p>
<article v-for="person in group.page.data" :key="person.id" class="member-row"><strong>{{person.name}}</strong><div class="member-actions"><template v-if="group.key==='participants'"><button type="button" class="button secondary" :disabled="sharingBusy" @click="removeMember(person)">{{t('Remove member')}}</button><button type="button" class="text-button danger" :disabled="sharingBusy" @click="blockMember(person)">{{t('Block account')}}</button></template><button v-else type="button" class="button secondary" :disabled="sharingBusy" @click="unblockMember(person)">{{t('Unblock account')}}</button></div></article>
<nav v-if="group.page.last_page>1" class="member-pagination" :aria-label="t(group.key==='participants'?'Joined accounts pages':'Blocked accounts pages')"><Link v-if="group.page.prev_page_url" :href="group.page.prev_page_url" preserve-scroll class="button secondary">{{t('← Previous')}}</Link><span>{{t('Page {page} of {total}',{page:group.page.current_page,total:group.page.last_page})}}</span><Link v-if="group.page.next_page_url" :href="group.page.next_page_url" preserve-scroll class="button secondary">{{t('Next →')}}</Link></nav>
</section></div></AppLayout>
</template>
<style scoped>.members-page{display:grid;gap:20px}.member-search{display:flex;align-items:flex-end;gap:12px}.member-search label{flex:1;margin:0}.member-row{display:flex;align-items:center;justify-content:space-between;gap:16px;border-top:1px solid #e3e7e4;padding:16px 0}.member-row strong{overflow-wrap:anywhere;min-width:0}.member-actions{display:flex;align-items:center;gap:16px;flex-shrink:0}.member-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:20px}.member-group h2{font-size:1.15rem}@media(max-width:600px){.member-search,.member-row{flex-direction:column;align-items:stretch}.member-actions{justify-content:space-between;flex-wrap:wrap}.member-pagination{flex-wrap:wrap}.member-pagination .button{padding:10px}.member-group{padding:18px}}</style>
