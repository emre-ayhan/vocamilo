<script setup>
import { useI18n } from "../lib/i18n.js";
const {t,locale}=useI18n();defineProps({errors:{type:Object,default:()=>({})}});</script>
<template><ul v-if="Object.keys(errors).length" class="notice error" role="alert"><li v-for="(error,key) in errors" :key="key">{{ t(error) }}</li></ul></template>
