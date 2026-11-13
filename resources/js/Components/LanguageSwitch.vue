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
.language-switch{display:inline-flex;align-items:center;gap:4px;padding:5px;border:1px solid #e4dfdc;border-radius:15px;background:#f2efed;flex-shrink:0;box-shadow:inset 0 1px 2px #18354406}
.language-switch button{border:0;border-radius:11px;background:transparent;padding:10px 12px;min-width:44px;min-height:44px;color:#66615f;font-size:14px;font-weight:800;line-height:1;transition:box-shadow .15s,color .15s,background .15s}
.language-switch button[aria-pressed=true]{background:#fff;color:#a3212b;box-shadow:0 2px 7px #34252320,0 1px 2px #34252312}
.language-switch button:hover:not([aria-pressed=true]){color:#183544;background:#ffffff70}
.language-switch button:focus-visible{outline:2px solid #157e73;outline-offset:1px}
.language-switch button:disabled{opacity:.65}
@media(prefers-reduced-motion:reduce){.language-switch button{transition:none}}
:global(.auth-language){display:flex;justify-content:flex-end;margin-bottom:1.25rem}
</style>
