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
.language-switch{display:inline-flex;align-items:center;gap:2px;padding:2px;border:1px solid #aec0c7;border-radius:9px;background:#122c3b;flex-shrink:0;box-shadow:inset 0 1px 2px #18354406}
.language-switch button{border:0;border-radius:6px;background:transparent;padding:6px 8px;min-width:32px;min-height:28px;color:#eaf4ed;font-size:11px;font-weight:800;line-height:1;transition:box-shadow .15s,color .15s,background .15s}
.language-switch button[aria-pressed=true]{background:#d5f1ad;color:#15372f;box-shadow:0 2px 5px #15372f20,0 1px 2px #15372f12}
.language-switch button:hover:not([aria-pressed=true]){color:#fff;background:#25424c}
.language-switch button:focus-visible{outline:2px solid #157e73;outline-offset:1px}
.language-switch button:disabled{opacity:.65}
@media(prefers-reduced-motion:reduce){.language-switch button{transition:none}}
:global(.auth-language){display:flex;justify-content:flex-end;margin-bottom:1.25rem}
</style>
