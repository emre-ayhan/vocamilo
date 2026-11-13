<script setup>
import { useI18n } from "../../lib/i18n.js";
import LanguageSwitch from "../../Components/LanguageSwitch.vue";
const {t,locale}=useI18n();
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Errors from '../../Components/Errors.vue';
defineProps({googleEnabled:{type:Boolean,default:false}});
const page=usePage();
const form=useForm({name:'',email:'',password:'',password_confirmation:''});
const submit=()=>form.post('/register',{onFinish:()=>form.reset('password','password_confirmation')});
</script>
<template><Head :title="t('Create your space')"/><div class="auth-shell"><section class="auth-story"><div class="brand"><span class="brand-symbol">v.</span>vocamilo</div><div><span class="pill">{{t("A SPACE FOR YOUR WORDS")}}</span><h1>{{t("Curiosity")}}<br>{{t("looks good")}}<br>{{t("on you.")}}</h1><p>{{t("Collect what matters. Practice what you want to remember.")}}</p></div><div class="auth-decoration">{{t("Your learning starts here.")}}</div></section><main class="auth-form"><div class="auth-language"><LanguageSwitch/></div><div class="eyebrow">{{t("LET'S GET STARTED")}}</div><h1>{{t("Create your space.")}}</h1><p class="muted">{{t("A personal word pool, ready to grow.")}}</p><p v-if="page.props.errors?.google" class="notice error" role="alert">{{t(page.props.errors.google)}}</p><a v-if="googleEnabled" href="/auth/google/redirect" class="button secondary google-signin">{{t("Continue with Google")}}</a><p v-if="googleEnabled" class="muted small">{{t("Or continue with your email and password")}}</p><form @submit.prevent="submit"><Errors :errors="form.errors"/><label>{{t("Name")}}<input v-model="form.name" required maxlength="100" autocomplete="name"/></label><label>{{t("Email")}}<input v-model="form.email" type="email" required autocomplete="email"/></label><label>{{t("Password (at least 10 characters)")}}<input v-model="form.password" type="password" required minlength="10" autocomplete="new-password"/></label><label>{{t("Confirm password")}}<input v-model="form.password_confirmation" type="password" required autocomplete="new-password"/></label><button class="button" :disabled="form.processing">{{form.processing?t('Creating…'):t('Create account →')}}</button></form><p class="auth-switch">{{t("Already learning here?")}} <Link href="/login">{{t("Sign in")}}</Link></p></main></div></template>
