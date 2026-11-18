<script setup>
defineProps({ name: { type: String, default: 'collection' } });
</script>
<template>
<svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
<g v-if="name === 'overview'"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></g>
<g v-else-if="name === 'words'"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></g>
<g v-else-if="name === 'import'"><path d="m8 10 4-4 4 4M4 15v5a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-5"/></g>
<g v-else><rect x="5" y="6" width="16" height="15" rx="2"/><path d="M17 3H5a2 2 0 0 0-2 2v12M9 11h8M9 16h5"/></g>
</svg>
</template>
<style scoped>.app-icon{width:22px;height:22px;flex-shrink:0;vertical-align:middle}</style>
