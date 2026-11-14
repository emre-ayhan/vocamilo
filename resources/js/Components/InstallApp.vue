<script setup>
import { ref } from 'vue';
import { appInstalled, installPrompt } from '../lib/install-app.js';
import { useI18n } from '../lib/i18n.js';
const {t}=useI18n();
const help=ref(false),busy=ref(false);
async function install(){
 if(busy.value)return;
 const prompt=installPrompt.value;
 if(!prompt){help.value=!help.value;return;}
 busy.value=true;
 try{await prompt.prompt();await prompt.userChoice;}catch{help.value=true;}
 finally{installPrompt.value=null;busy.value=false;}
}
</script>
<template><div v-if="!appInstalled" class="install-app"><button type="button" class="button secondary" :disabled="busy" @click="install">{{t('Install Vocamilo')}}</button><p v-if="help" role="status">{{t('Use your browser’s Install app option. On iPhone or iPad, open Safari, tap Share, then Add to Home Screen.')}} {{t('An internet connection is required. No notifications or offline storage are enabled.')}}</p></div></template>
<style scoped>
.install-app{margin:16px 0}.install-app .button{border-color:#7c9a94;color:#eaf4ed;padding:9px 12px;font-size:12px}.install-app p{font-size:12px;line-height:1.5;color:#c5d4ce;margin-top:12px}.install-app .button:hover{background:#25424c}@media(max-width:760px){.install-app{margin:0 0 14px}.install-app .button{width:100%}}
</style>
