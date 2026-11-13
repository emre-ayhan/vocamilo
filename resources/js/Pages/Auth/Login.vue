<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import Errors from '../../Components/Errors.vue';
const form=useForm({email:'',password:'',remember:false});
const submit=()=>form.post('/login',{onFinish:()=>form.reset('password')});
</script>
<template><Head title="Welcome back"/><div class="auth-shell"><section class="auth-story"><div class="brand"><span class="brand-symbol">v.</span>vocamilo</div><div><span class="pill">BUILD YOUR VOCABULARY</span><h1>Big ideas.<br>One word<br>at a time.</h1><p>Your words, your collections, your way of learning.</p></div><div class="auth-decoration">Learn. Recall. Repeat.</div></section><main class="auth-form"><div class="eyebrow">GOOD TO SEE YOU</div><h1>Welcome back.</h1><p class="muted">Your next discovery is waiting.</p><form @submit.prevent="submit"><Errors :errors="form.errors"/><label>Email<input v-model="form.email" type="email" required autocomplete="username" autofocus/></label><label>Password<input v-model="form.password" type="password" required autocomplete="current-password"/></label><label class="check"><input v-model="form.remember" type="checkbox"/> Keep me signed in</label><button class="button" :disabled="form.processing">{{form.processing?'Signing in…':'Sign in →'}}</button></form><p class="auth-switch">New here? <Link href="/register">Create an account</Link></p></main></div></template>
