<script setup>
import { useI18n } from "../../lib/i18n.js";
import LanguageSwitch from "../../Components/LanguageSwitch.vue";
const {t,locale}=useI18n();
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Errors from '../../Components/Errors.vue';
defineProps({googleEnabled:{type:Boolean,default:false}});
const page=usePage();
const form=useForm({email:'',password:'',remember:false});
const google=useForm({remember:false});
function submitGoogle(){if(form.processing||google.processing)return;google.remember=Boolean(form.remember);google.post('/auth/google/redirect');}
const submit=()=>{if(form.processing||google.processing)return;form.post('/login',{onFinish:()=>form.reset('password')});};
</script>
<template><Head :title="t('Welcome back')"/><div class="auth-shell login-shell"><section class="auth-story"><div class="brand"><span class="brand-symbol">v.</span>vocamilo</div><div><span class="pill">{{t("BUILD YOUR VOCABULARY")}}</span><h1>{{t("Big ideas.")}}<br>{{t("One word")}}<br>{{t("at a time.")}}</h1><p>{{t("Your words, your collections, your way of learning.")}}</p></div><div class="auth-decoration">{{t("Learn. Recall. Repeat.")}}</div></section><main class="auth-form"><div class="auth-language"><LanguageSwitch/></div><div class="eyebrow">{{t("GOOD TO SEE YOU")}}</div><h1>{{t("Welcome back.")}}</h1><p class="muted">{{t("Your next discovery is waiting.")}}</p><p v-if="page.props.errors?.google" class="notice error" role="alert">{{t(page.props.errors.google)}}</p><label class="remember-choice"><input v-model="form.remember" type="checkbox" :disabled="form.processing||google.processing"/><span>{{t("Keep me signed in")}}</span></label><p v-if="googleEnabled" class="muted small">{{t("This choice applies to Google and password sign-in.")}}</p><Errors :errors="google.errors"/><button v-if="googleEnabled" type="button" class="button secondary google-signin" :disabled="form.processing||google.processing" @click="submitGoogle">{{google.processing?t("Signing in…"):t("Continue with Google")}}</button><p v-if="googleEnabled" class="muted small">{{t("Or continue with your email and password")}}</p><form @submit.prevent="submit"><Errors :errors="form.errors"/><label>{{t("Email")}}<input v-model="form.email" type="email" required autocomplete="username" autofocus/></label><label>{{t("Password")}}<input v-model="form.password" type="password" required autocomplete="current-password"/></label><button class="button" :disabled="form.processing||google.processing">{{form.processing?t('Signing in…'):t('Sign in →')}}</button></form><p class="auth-switch">{{t("New here?")}} <Link href="/register">{{t("Create an account")}}</Link></p></main></div></template>

<style scoped>
@media(max-width:760px){.login-shell{position:relative}.login-shell .auth-language{position:absolute;top:25px;right:20px;margin:0}.login-shell .auth-story{padding:25px 20px}.login-shell .brand{padding-right:94px;font-size:23px}}
@media(max-width:360px){.login-shell .brand{font-size:20px;gap:6px}.login-shell .brand-symbol{width:30px;height:34px;font-size:25px}}
</style>

<style scoped>.remember-choice{display:flex;flex-direction:row;align-items:center;gap:12px;cursor:pointer;line-height:1.5}.remember-choice input{width:18px;height:18px;flex:0 0 18px;margin:0;accent-color:var(--teal,#157e73)}.remember-choice span{min-width:0}.remember-choice input:focus-visible{outline:3px solid #45baa8;outline-offset:3px}.google-signin{width:100%;min-height:46px}</style>
