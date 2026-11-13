<script setup>
import { useI18n } from "../../lib/i18n.js";
import LanguageSwitch from "../../Components/LanguageSwitch.vue";
const {t,locale}=useI18n();
import {Head,useForm} from '@inertiajs/vue3';
import Errors from '../../Components/Errors.vue';
defineProps({email:String});
const form=useForm({password:''});
const cancel=useForm({});
</script>
<template><Head :title="t('Confirm your account')"/><main class="auth-form" style="max-width:600px;margin:3rem auto"><LanguageSwitch/><div class="brand"><span class="brand-symbol">v.</span>vocamilo</div><h1>{{t("One account. Two ways in.")}}</h1><p>{{t('A Vocamilo account already exists for {email}. Enter its existing password to connect your Google sign-in. Your words and collections stay in the same account.',{email})}}</p><form @submit.prevent="form.post('/auth/google/link',{onFinish:()=>form.reset('password')})"><Errors :errors="form.errors"/><label>{{t("Existing Vocamilo password")}}<input type="password" v-model="form.password" required autocomplete="current-password" autofocus/></label><div class="form-actions"><button type="button" class="button secondary" :disabled="cancel.processing||form.processing" @click="cancel.post('/auth/google/link/cancel')">{{t("Cancel")}}</button><button class="button" :disabled="form.processing||cancel.processing">{{t("Link Google and sign in")}}</button></div></form><p class="muted small">{{t("For your security, matching email addresses alone never link accounts. This request expires in 10 minutes.")}}</p></main></template>
