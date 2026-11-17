<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import InstallApp from './InstallApp.vue';
import { useI18n } from '../lib/i18n.js';
const {t}=useI18n(),page=usePage(),open=ref(false),root=ref(null),trigger=ref(null);
const user=computed(()=>page.props.auth?.user);
const initials=computed(()=>user.value?.name.trim().split(/\s+/).slice(0,2).map(part=>Array.from(part)[0]).join('').toLocaleUpperCase()||'V');
function outside(event){if(!root.value?.contains(event.target))open.value=false;}
function escape(event){if(open.value&&event.key==='Escape'){event.stopPropagation();open.value=false;trigger.value?.focus();}}
watch(()=>page.url,()=>open.value=false);
watch(()=>page.props.auth?.user?.id,()=>open.value=false);
onMounted(()=>document.addEventListener('pointerdown',outside));
onBeforeUnmount(()=>document.removeEventListener('pointerdown',outside));
</script>
<template><div v-if="user" ref="root" class="profile-menu" @keydown="escape">
<div v-if="open" id="profile-options" class="profile-options" :aria-label="t('Account menu')">
<Link href="/profile" class="profile-option" @click="open=false">{{t('Profile')}}</Link>
<InstallApp/>
<Link href="/logout" method="post" as="button" class="profile-option sign-out">{{t('Sign out')}}</Link>
</div>
<button ref="trigger" type="button" class="profile-trigger" aria-controls="profile-options" :aria-expanded="open" @click="open=!open">
<span class="profile-avatar" aria-hidden="true">{{initials}}</span><span class="profile-identity"><strong>{{user.name}}</strong><span>{{user.email}}</span></span><svg viewBox="0 0 24 24" aria-hidden="true" :class="{expanded:open}"><path d="m7 14 5-5 5 5"/></svg>
</button></div></template>
<style scoped>
.profile-menu{position:relative;margin-top:20px;min-width:0}.profile-trigger{display:flex;align-items:center;gap:10px;width:100%;padding:12px 8px;border:1px solid #3b5662;border-radius:12px;background:#193746;color:#eaf4ed;text-align:left}.profile-trigger:hover,.profile-trigger[aria-expanded=true]{background:#25424c}.profile-avatar{display:grid;place-items:center;width:38px;height:38px;flex:0 0 38px;border-radius:50%;background:#d5f1ad;color:#15372f;font-size:13px;font-weight:800}.profile-identity{display:flex;flex-direction:column;gap:4px;min-width:0;flex:1}.profile-identity strong{font-size:12px;overflow-wrap:anywhere}.profile-identity>span{font-size:10px;color:#aec0c7;overflow-wrap:anywhere}.profile-trigger svg{width:16px;height:16px;flex-shrink:0;fill:none;stroke:currentColor;stroke-width:2}.profile-trigger svg.expanded{transform:rotate(180deg)}.profile-options{position:absolute;bottom:calc(100% + 8px);left:0;right:0;padding:8px;border:1px solid #526f77;border-radius:12px;background:#193746;box-shadow:0 10px 30px #07192340;max-height:50dvh;overflow-y:auto;z-index:2}.profile-option{display:flex;align-items:center;width:100%;min-height:44px;padding:10px 12px;border:0;border-radius:7px;background:transparent;color:#eaf4ed;font-size:13px;text-align:left}.profile-option:hover{background:#25424c;color:#fff}.sign-out{color:#f1c7c4}.profile-options :deep(.install-app){margin:4px 0}.profile-options :deep(.install-app .button){text-align:left;justify-content:flex-start;border:0;min-height:44px;font-size:13px;background:transparent}.profile-options :deep(.install-app p){margin:8px}
</style>
