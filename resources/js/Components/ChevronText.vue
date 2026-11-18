<script setup>
import { computed } from 'vue';
const props = defineProps({ text: { type: String, default: '' } });
const parts = computed(() => props.text.split(/([←→])/).filter(part => part.trim()));
</script>
<template><span class="chevron-text"><template v-for="(part, index) in parts" :key="index"><svg v-if="part === '←' || part === '→'" class="chevron-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="part === '←' ? 'm10 4-4 4 4 4' : 'm6 4 4 4-4 4'"/></svg><span v-else>{{ part.trim() }}</span></template></span></template>
<style scoped>.chevron-text{display:inline-flex;align-items:center;justify-content:inherit;gap:6px;max-width:100%;vertical-align:middle}.chevron-text>span{min-width:0}.chevron-icon{width:16px;height:16px;flex:0 0 16px;vertical-align:middle}</style>
