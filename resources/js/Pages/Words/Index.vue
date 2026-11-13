<script setup>
import { useDialogEscape } from '../../lib/dialog.js';
import { useI18n } from '../../lib/i18n.js';
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Errors from '../../Components/Errors.vue';
const { t } = useI18n();
const props = defineProps({ words: { type: Object, default: () => ({ data: [], links: [] }) }, filters: { type: Object, default: () => ({}) } });
const search = ref(props.filters.search ?? '');
const editing = ref(null); const open = ref(false);
const form = useForm({ term: '', definition: '', note: '' });
function edit(word = null) { editing.value = word?.id ?? null; form.clearErrors(); form.term = word?.term ?? ''; form.definition = word?.definition ?? ''; form.note = word?.note ?? ''; open.value = true; }
function save() { const options = { onSuccess: () => { open.value = false; form.reset(); } }; editing.value ? form.put(`/words/${editing.value}`, options) : form.post('/words', options); }
function remove(word) { if (confirm(t('Delete “{term}” from your pool and collections?', { term: word.term }))) router.delete(`/words/${word.id}`); }
function pageLabel(label) { if (/previous/i.test(label)) return t('← Previous'); if (/next/i.test(label)) return t('Next →'); return label; }
useDialogEscape(() => { open.value = false; });
</script>
<template>
    <Head :title="t('Word pool')" />
    <AppLayout :title="t('Your word pool.')" :subtitle="t('Every word is a small discovery.')">
        <template #actions><div class="actions"><a href="/words/export" class="button secondary">{{ t('Export CSV') }}</a><Link href="/imports" class="button secondary">{{ t('Import CSV') }}</Link><button class="button" @click="edit()">{{ t('+ Add a word') }}</button></div></template>
        <form class="search-row" @submit.prevent="router.get('/words', { search }, { preserveState: true, replace: true })"><input v-model="search" type="search" :placeholder="t('Search words and definitions…')" :aria-label="t('Search words')" /><button class="button secondary">{{ t('Search') }}</button><span class="muted">{{ t('{count} words', { count: words.total ?? words.data.length }) }}</span></form>
        <div class="word-list"><article class="word-row editable" v-for="word in words.data" :key="word.id"><div><strong>{{ word.term }}</strong><p>{{ word.definition }}</p><div v-if="word.note" class="personal-note"><span>{{ t('YOUR NOTE') }}</span>{{ word.note }}</div></div><div class="actions"><button class="text-button" @click="edit(word)">{{ t('Edit') }}</button><button class="text-button danger" @click="remove(word)">{{ t('Delete') }}</button></div></article><div v-if="!words.data.length" class="empty"><span>≋</span><h3>{{ t(search ? 'No matching words.' : 'A blank page, full of possibility.') }}</h3><p>{{ t(search ? 'Try another search.' : 'Add a word or bring your vocabulary in with a CSV.') }}</p><button v-if="!search" class="button" @click="edit()">{{ t('Add your first word') }}</button></div></div>
        <nav class="pagination" :aria-label="t('Pagination')"><template v-for="(link, index) in words.links" :key="index"><Link v-if="link.url" :href="link.url" :class="{ active: link.active }" :aria-current="link.active ? 'page' : undefined">{{ pageLabel(link.label) }}</Link><span v-else>{{ pageLabel(link.label) }}</span></template></nav>
        <div v-if="open" class="modal-backdrop" @click.self="open = false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="word-title"><div class="section-heading"><h2 id="word-title">{{ t(editing ? 'Edit word' : 'A new discovery') }}</h2><button class="icon-button" :aria-label="t('Close')" @click="open = false">×</button></div><form @submit.prevent="save"><Errors :errors="form.errors" /><label>{{ t('Word or phrase') }}<input v-model="form.term" required maxlength="500" autofocus /></label><label>{{ t('Original definition') }}<textarea v-model="form.definition" maxlength="5000" required rows="3" /></label><label>{{ t('Your private note') }}<textarea v-model="form.note" maxlength="5000" rows="2" :placeholder="t('A translation, a memory, a little context…')" /></label><p class="muted small">{{ t('Personal notes never appear on shared collection links.') }}</p><div class="form-actions"><button type="button" class="button secondary" @click="open = false">{{ t('Cancel') }}</button><button class="button" :disabled="form.processing">{{ t('Save word') }}</button></div></form></section></div>
    </AppLayout>
</template>
