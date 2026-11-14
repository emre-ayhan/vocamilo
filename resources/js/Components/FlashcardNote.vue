<script setup>
import {ref,onBeforeUnmount} from 'vue';
import {wordNote} from '../lib/word-note.js';
import {useI18n} from '../lib/i18n.js';
const props=defineProps({collectionId:[Number,String],wordId:[Number,String],note:String});
const emit=defineEmits(['saved','busy']);
const {t}=useI18n();
const loaded=ref(false);
const open=ref(false),draft=ref(''),loading=ref(false),saving=ref(false),error=ref(''),success=ref(false);
let controller;
const url=()=>`/collections/${props.collectionId}/words/${props.wordId}/note`;
async function edit(){
 if(loading.value||saving.value)return;
 open.value=true;loaded.value=false;loading.value=true;emit('busy',true);error.value='';success.value=false;
 controller=new AbortController();
 try{const result=await wordNote(url(),{signal:controller.signal});draft.value=result.note??'';loaded.value=true;emit('saved',result.note);}
 catch(e){if(e.name!=='AbortError')error.value=t(e.message);}
 finally{loading.value=false;emit('busy',false);}
}
async function save(){
 if(loading.value||saving.value)return;
 saving.value=true;emit('busy',true);error.value='';success.value=false;
 controller=new AbortController();
 try{const result=await wordNote(url(),{note:draft.value,signal:controller.signal});emit('saved',result.note);success.value=true;open.value=false;}
 catch(e){if(e.name!=='AbortError')error.value=t(e.message);}
 finally{saving.value=false;emit('busy',false);}
}
onBeforeUnmount(()=>{controller?.abort();emit('busy',false);});
</script>
<template><section class="flash-note">
<button v-if="!open" type="button" class="text-button" @click="edit">{{t(note?'Edit note':'Add note')}}</button>
<p v-if="success" class="notice success" role="status">{{t('Note saved.')}}</p>
<p v-if="error" class="notice error" role="alert">{{error}}</p>
<form v-if="open" @submit.prevent="save"><p class="muted small">{{t('Your note is private and follows this word across your collections.')}}</p><p v-if="loading" role="status">{{t('Loading…')}}</p><template v-else-if="!loaded"><button type="button" class="text-button" @click="edit">{{t('Retry')}}</button></template><label v-else>{{t('Note')}}<textarea v-model="draft" rows="3" maxlength="5000" :disabled="saving"/></label><div class="actions"><button type="button" class="button secondary" :disabled="loading||saving" @click="open=false;error=''">{{t('Cancel')}}</button><button type="submit" class="button" :disabled="loading||saving||!loaded">{{t(saving?'Saving…':'Save note')}}</button></div></form>
</section></template>
<style scoped>.flash-note{margin:1rem 0 1.5rem}.flash-note form{padding:1rem;background:#eef5ef;border:1px solid #d1e2d5;border-radius:10px}.flash-note .notice{margin-top:1rem}.flash-note .actions{justify-content:flex-end}</style>
