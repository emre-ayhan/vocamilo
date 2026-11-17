<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Errors from '../../Components/Errors.vue';
import { useI18n } from '../../lib/i18n.js';
defineProps({googleLinked:Boolean});
const {t,locale}=useI18n(),page=usePage();
const language=useForm({locale:locale.value});
function changeLanguage(event){language.locale=event.target.value;language.post('/locale',{preserveScroll:true,onFinish:()=>language.locale=locale.value});}
const form=useForm({current_password:'',password:'',password_confirmation:''});
function save(){form.put('/profile/password',{preserveScroll:true,onSuccess:()=>form.reset(),onFinish:()=>form.reset('current_password')});}
</script>
<template><Head :title="t('Profile')"/><AppLayout :title="t('Profile')" :subtitle="t('Manage your language and password.')"><div class="profile-settings">
<section class="panel"><h2>{{t('Account')}}</h2><dl><dt>{{t('Name')}}</dt><dd>{{page.props.auth.user.name}}</dd><dt>{{t('Email')}}</dt><dd>{{page.props.auth.user.email}}</dd></dl></section>
<section class="panel"><h2>{{t('Language')}}</h2><p class="muted small">{{t('Choose the language used throughout Vocamilo.')}}</p><label>{{t('Language')}}<select :value="locale" :disabled="language.processing" @change="changeLanguage"><option value="en">English</option><option value="pt">Português</option><option value="tr">Türkçe</option></select></label><Errors :errors="language.errors"/></section>
<section class="panel"><h2>{{t('Change password')}}</h2><p v-if="googleLinked" class="muted small">{{t('If you signed up with Google and have no Vocamilo password, continue signing in with Google.')}}</p><form @submit.prevent="save"><Errors :errors="form.errors"/><label>{{t('Current password')}}<input v-model="form.current_password" type="password" autocomplete="current-password" required :disabled="form.processing"/></label><label>{{t('New password')}}<input v-model="form.password" type="password" autocomplete="new-password" required minlength="10" :disabled="form.processing"/></label><p class="muted small">{{t('Use at least 10 characters.')}}</p><label>{{t('Confirm new password')}}<input v-model="form.password_confirmation" type="password" autocomplete="new-password" required minlength="10" :disabled="form.processing"/></label><button class="button" :disabled="form.processing">{{t('Update password')}}</button></form></section>
</div></AppLayout></template>
<style scoped>.profile-settings{display:grid;gap:20px;max-width:680px}.profile-settings .panel{margin:0;min-width:0}.profile-settings h2{font-size:20px}.profile-settings dt{font-size:12px;color:var(--muted);margin-top:14px}.profile-settings dd{margin:5px 0 0;overflow-wrap:anywhere}.profile-settings input{width:100%}</style>
