<script setup>
defineProps({mode:String});
</script>
<template><svg class="mode-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><g v-if="mode==='flashcards'"><rect x="5" y="6" width="15" height="15" rx="2"/><path d="M16 3H4a2 2 0 0 0-2 2v12"/></g><g v-else-if="mode==='study'"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9Z"/></g><g v-else><circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/></g></svg></template>
<style scoped>.mode-icon{width:22px;height:22px;flex-shrink:0}</style>
