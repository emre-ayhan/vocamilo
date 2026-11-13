<script setup>
import { useI18n } from "../../lib/i18n.js";
import LanguageSwitch from "../../Components/LanguageSwitch.vue";
const {t,locale}=useI18n();
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Errors from '../../Components/Errors.vue';
defineProps({googleEnabled:{type:Boolean,default:false}});
const page=usePage();
const form=useForm({email:'',password:'',remember:false});
const submit=()=>form.post('/login',{onFinish:()=>form.reset('password')});
</script>
<template><Head :title="t('Welcome back')"/><div class="auth-shell"><section class="auth-story"><div class="brand"><span class="brand-symbol">v.</span>vocamilo</div><div><span class="pill">{{t("BUILD YOUR VOCABULARY")}}</span><h1>{{t("Big ideas.")}}<br>{{t("One word")}}<br>{{t("at a time.")}}</h1><p>{{t("Your words, your collections, your way of learning.")}}</p></div><div class="auth-decoration">{{t("Learn. Recall. Repeat.")}}</div></section><main class="auth-form"><div class="auth-language"><LanguageSwitch/></div><div class="eyebrow">{{t("GOOD TO SEE YOU")}}</div><h1>{{t("Welcome back.")}}</h1><p class="muted">{{t("Your next discovery is waiting.")}}</p><p v-if="page.props.errors?.google" class="notice error" role="alert">{{t(page.props.errors.google)}}</p><a v-if="googleEnabled" href="/auth/google/redirect" class="button secondary google-signin">{{t("Continue with Google")}}</a><p v-if="googleEnabled" class="muted small">{{t("Or continue with your email and password")}}</p><form @submit.prevent="submit"><Errors :errors="form.errors"/><label>{{t("Email")}}<input v-model="form.email" type="email" required autocomplete="username" autofocus/></label><label>{{t("Password")}}<input v-model="form.password" type="password" required autocomplete="current-password"/></label><label class="check"><input v-model="form.remember" type="checkbox"/> {{t("Keep me signed in")}}</label><button class="button" :disabled="form.processing">{{form.processing?t('Signing in…'):t('Sign in →')}}</button></form><p class="auth-switch">{{t("New here?")}} <Link href="/register">{{t("Create an account")}}</Link></p></main></div></template>
