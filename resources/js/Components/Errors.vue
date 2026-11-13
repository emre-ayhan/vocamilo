<script setup>defineProps({errors:{type:Object,default:()=>({})}});</script>
<template><ul v-if="Object.keys(errors).length" class="notice error" role="alert"><li v-for="(error,key) in errors" :key="key">{{ error }}</li></ul></template>
