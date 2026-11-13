<script setup>
import {Head,useForm} from '@inertiajs/vue3';
import Errors from '../../Components/Errors.vue';
defineProps({email:String});
const form=useForm({password:''});
const cancel=useForm({});
</script>
<template><Head title="Confirm your account"/><main class="auth-form" style="max-width:600px;margin:3rem auto"><div class="brand"><span class="brand-symbol">v.</span>vocamilo</div><h1>One account. Two ways in.</h1><p>A Vocamilo account already exists for <strong>{{email}}</strong>. Enter its existing password to connect your Google sign-in. Your words and collections stay in the same account.</p><form @submit.prevent="form.post('/auth/google/link',{onFinish:()=>form.reset('password')})"><Errors :errors="form.errors"/><label>Existing Vocamilo password<input type="password" v-model="form.password" required autocomplete="current-password" autofocus/></label><div class="form-actions"><button type="button" class="button secondary" :disabled="cancel.processing||form.processing" @click="cancel.post('/auth/google/link/cancel')">Cancel</button><button class="button" :disabled="form.processing||cancel.processing">Link Google and sign in</button></div></form><p class="muted small">For your security, matching email addresses alone never link accounts. This request expires in 10 minutes.</p></main></template>
