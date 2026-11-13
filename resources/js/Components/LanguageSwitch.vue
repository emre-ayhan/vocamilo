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
.language-switch{display:inline-flex;align-items:center;gap:3px;padding:3px;border:1px solid #bfd8ce;border-radius:11px;background:#e5eee8;flex-shrink:0;box-shadow:inset 0 1px 2px #18354406}
.language-switch button{border:0;border-radius:8px;background:transparent;padding:8px 10px;min-width:36px;min-height:32px;color:#4d6d62;font-size:12px;font-weight:800;line-height:1;transition:box-shadow .15s,color .15s,background .15s}
.language-switch button[aria-pressed=true]{background:#fff;color:#157e73;box-shadow:0 2px 5px #15372f20,0 1px 2px #15372f12}
.language-switch button:hover:not([aria-pressed=true]){color:#183544;background:#ffffff70}
.language-switch button:focus-visible{outline:2px solid #157e73;outline-offset:1px}
.language-switch button:disabled{opacity:.65}
@media(prefers-reduced-motion:reduce){.language-switch button{transition:none}}
:global(.auth-language){display:flex;justify-content:flex-end;margin-bottom:1.25rem}
</style>
