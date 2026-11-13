<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from '../lib/i18n.js';
const {locale,t} = useI18n();
const busy=ref(false);
function change(value){if(busy.value||locale.value===value)return;busy.value=true;router.post('/locale',{locale:value},{preserveState:true,preserveScroll:true,onFinish:()=>busy.value=false});}
</script>
<template><div class="language-switch" role="group" :aria-label="t('Language')"><button v-for="language in ['en','pt']" :key="language" type="button" :aria-pressed="locale===language" :disabled="busy" @click="change(language)">{{language.toUpperCase()}}</button></div></template>
<style scoped>
.language-switch{display:inline-flex;align-items:center;border:1px solid #cdd8d3;border-radius:8px;background:#fff;flex-shrink:0;overflow:hidden}.language-switch button{border:0;background:transparent;padding:8px 11px;min-height:40px;color:#24454b;font-size:12px;font-weight:700}.language-switch button+button{border-left:1px solid #cdd8d3}.language-switch button[aria-pressed=true]{background:#157e73;color:#fff}.language-switch button:focus-visible{outline-offset:-3px}
:global(.auth-language){display:flex;justify-content:flex-end;margin-bottom:1.25rem}
</style>
