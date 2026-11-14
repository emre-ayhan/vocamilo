<script setup>
import LocalVoicePicker from '../../Components/LocalVoicePicker.vue';
import {collectionVoicePreference} from '../../lib/speech-preference.js';
import ModeIcon from "../../Components/ModeIcon.vue";
import StartIcon from "../../Components/StartIcon.vue";
import StudySummary from '../../Components/StudySummary.vue';
import WordPicker from '../../Components/WordPicker.vue';
import { useDialogEscape } from '../../lib/dialog.js';
import { useI18n } from '../../lib/i18n.js';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Errors from '../../Components/Errors.vue';
const { t } = useI18n();
const page = usePage();
const props = defineProps({
    collection: Object, shared: { type: Boolean, default: false }, isOwner: { type: Boolean, default: false },
    availableWords: { type: Array, default: () => [] }, next_cursor: { type: String, default: null },
    summary: { type: Object, default: () => ({ modes: {}, recent: [], total_sessions: 0 }) },
});
let storage;try{storage=window.localStorage;}catch{}
const voiceMemory=collectionVoicePreference(storage,props.collection,page.props.auth?.user?.id??'guest',props.shared);
const voiceDraft=ref(voiceMemory.read()),showVoice=ref(false);
function openVoice(){voiceDraft.value=voiceMemory.read();showVoice.value=true;}
function persistVoice(uri){const saved=voiceMemory.write(uri);if(!saved)window.alert(t('Your browser could not save the voice preference. Allow browser storage and try again.'));return saved;}
function saveVoice(){if(persistVoice(voiceDraft.value))showVoice.value=false;}
const authenticated = computed(() => Boolean(page.props.auth?.user));
const owner = computed(() => !props.shared && props.isOwner);
const showAdd = ref(false), showEdit = ref(false), showNote = ref(false), query = ref(''), copyStatus = ref('');
const visibleWords = ref([]), nextCursor = ref(null), loading = ref(false), loadError = ref(false);
let requestController = null, generation = 0;
watch(() => [props.collection, props.next_cursor], () => {
    generation++; requestController?.abort(); loading.value = false; loadError.value = false;
    visibleWords.value = [...(props.collection.words ?? [])];
    nextCursor.value = props.collection.next_cursor ?? props.next_cursor ?? null;
}, { immediate: true });
onBeforeUnmount(() => requestController?.abort());
const add = useForm({ word_ids: [] });
const edit = useForm({ title: props.collection.title, description: props.collection.description ?? '' });
const note = useForm({ note: '' }); const noteWord = ref(null);
const base = computed(() => props.shared ? `/s/${props.collection.share_uuid}` : `/collections/${props.collection.id}`);
const candidates = computed(() => props.availableWords.filter(w => !visibleWords.value.some(x => x.id === w.id) && `${w.term} ${w.definition}`.toLowerCase().includes(query.value.toLowerCase())));
const shareUrl = computed(() => typeof window === 'undefined' ? '' : `${window.location.origin}/s/${props.collection.share_uuid}`);
const totalWords = computed(() => props.collection.words_count ?? props.collection.words.length);
async function loadMore() {
    if (loading.value || !nextCursor.value) return;
    loading.value = true; loadError.value = false;
    const currentGeneration = generation;
    requestController = new AbortController();
    try {
        const response = await fetch(`${base.value}/words?cursor=${encodeURIComponent(nextCursor.value)}`, {
            headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: requestController.signal,
        });
        if (!response.ok) throw new Error('Unable to load words');
        const result = await response.json();
        if (!Array.isArray(result.data)) throw new Error('Invalid word list');
        if (currentGeneration !== generation) return;
        const ids = new Set(visibleWords.value.map(w => w.id));
        for (const word of result.data) { if (!ids.has(word.id)) { visibleWords.value.push(word); ids.add(word.id); } }
        nextCursor.value = result.next_cursor ?? null;
    } catch (error) {
        if (error.name !== 'AbortError' && currentGeneration === generation) loadError.value = true;
    } finally { if (currentGeneration === generation) loading.value = false; }
}
async function copy() {
    try { await navigator.clipboard.writeText(shareUrl.value); copyStatus.value = t('Link copied'); }
    catch { copyStatus.value = t('Select and copy the link below.'); }
}
function disable() {
    if (confirm(t('Turn sharing off? Anyone using this link will lose access. A new link is created if you share again.'))) router.delete(`${base.value}/sharing`);
}
function remove(word) {
    if (confirm(t('Remove “{term}” from this collection? It stays in your word pool.', { term: word.term }))) router.delete(`${base.value}/words/${word.id}`);
}
function destroy() { if (confirm(t('Delete this collection? Your words will stay in your pool.'))) router.delete(base.value); }
function editNote(word) { noteWord.value = word; note.note = word.note ?? ''; note.clearErrors(); showNote.value = true; }
function saveNote() { note.put(`/collections/${props.collection.id}/words/${noteWord.value.id}/note`, { preserveScroll: true, onSuccess: () => { showNote.value = false; } }); }
function join() { router.post(`/s/${props.collection.share_uuid}/join`); }
useDialogEscape(() => { showAdd.value = false; showEdit.value = false; showNote.value = false;showVoice.value=false; });
const modes = [{ mode: 'flashcards', icon: '▱', name: 'Flashcards', text: 'Flip. Recall. Repeat.' }, { mode: 'study', icon: '✦', name: 'Study', text: 'Practice at your own pace.' }, { mode: 'test', icon: '✓', name: 'Test', text: 'Find out what sticks.' }];
</script>
<template>
    <Head :title="collection.title" />
    <AppLayout :title="collection.title" :subtitle="collection.description" :shared="shared">
        <template #actions><div class="actions">
            <button class="button secondary" @click="openVoice">{{t('Voice settings')}}</button><a v-if="!shared" :href="`/collections/${collection.id}/export`" class="button secondary">{{ t('Export CSV') }}</a>
            <button v-if="owner" class="button secondary" @click="voiceDraft=voiceMemory.read();showEdit = true">{{ t('Edit collection') }}</button>
            <span v-else class="pill dark">{{ t(shared ? 'SHARED COLLECTION' : 'SHARED WITH ME') }}</span>
        </div></template>
        <section v-if="shared" class="panel join-collection">
            <div><h2>{{ t('Make this part of your learning.') }}</h2><p class="muted">{{ t('Join this collection to save your progress and add private notes. The original words stay connected to the owner.') }}</p></div>
            <button class="button" @click="join">{{ t(authenticated ? 'Join collection' : 'Sign in to join') }}</button>
        </section>
        <StudySummary v-if="!shared" :summary="summary" :base="base" />
        <div v-if="shared" class="mode-grid guest-mode-grid"><Link v-for="item in modes" :key="item.mode" :href="`${base}/study?mode=${item.mode}`" class="mode-card" :class="`mode-card--${item.mode}`"><div class="guest-mode-copy"><h3><ModeIcon :mode="item.mode"/>{{ t(item.name) }}</h3><p>{{ t(item.text) }}</p></div><b class="guest-start">{{ t('Start') }}<StartIcon/></b></Link></div>
        <section v-if="owner" class="sharing panel">
            <div><span class="eyebrow">{{ t('KNOWLEDGE IS BETTER SHARED') }}</span><h3>{{ t(collection.share_uuid ? 'Your link is ready.' : 'Keep it private. Or pass it on.') }}</h3><p class="muted">{{ t(collection.share_uuid ? 'Anyone with this link can study without signing in. Your private notes stay private.' : 'Turn on link sharing whenever you want. Your personal notes are never shared.') }}</p></div>
            <div class="actions"><button v-if="!collection.share_uuid" class="button secondary" @click="router.post(`${base}/sharing`)">{{ t('Enable link sharing') }}</button><template v-else><button class="button" @click="copy">{{ t('Copy link') }}</button><button class="text-button danger" @click="disable">{{ t('Turn off') }}</button></template></div>
            <div v-if="collection.share_uuid" class="share-link"><input readonly :value="shareUrl" :aria-label="t('Collection share link')" @focus="$event.target.select()" /><small role="status">{{ copyStatus }}</small></div>
        </section>
        <div class="section-heading"><div><h2>{{ t('{count} words to discover', { count: totalWords }) }}</h2><p class="muted small word-order">{{ t('Newest words first') }}</p></div><button v-if="owner" class="button secondary" @click="showAdd = true">{{ t('+ Add from pool') }}</button></div>
        <div class="word-list">
            <article v-for="word in visibleWords" :key="word.id" class="word-row editable"><div><strong>{{ word.term }}</strong><p>{{ word.definition }}</p><div v-if="!shared && word.note" class="personal-note"><span>{{ t('YOUR NOTE') }}</span>{{ word.note }}</div></div><div v-if="!shared" class="actions"><button class="text-button" @click="editNote(word)">{{ t(word.note ? 'Edit note' : 'Add note') }}</button><button v-if="owner" class="text-button" @click="remove(word)">{{ t('Remove') }}</button></div></article>
            <div v-if="!visibleWords.length" class="empty"><span>▤</span><h3>{{ t('This collection is waiting for words.') }}</h3><p v-if="owner">{{ t('Add words from your pool to start practicing.') }}</p></div>
        </div>
        <div v-if="nextCursor || loadError" class="load-more"><p v-if="loadError" class="notice error" role="alert">{{ t('Could not load more words. Please try again.') }}</p><button class="button secondary" :disabled="loading" @click="loadMore">{{ t(loading ? 'Loading…' : 'Load more') }}</button><small class="muted">{{ t('Showing {shown} of {total}', { shown: visibleWords.length, total: totalWords }) }}</small></div>
        <div v-if="owner" class="form-actions"><button class="text-button danger" @click="destroy">{{ t('Delete collection') }}</button></div>
        <div v-if="showAdd && owner" class="modal-backdrop" @click.self="showAdd = false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="add-title"><div class="section-heading"><h2 id="add-title">{{ t('Add from your pool') }}</h2><button class="icon-button" :aria-label="t('Close')" @click="showAdd = false">×</button></div><form @submit.prevent="add.post(`${base}/words`, { onSuccess: () => { showAdd = false; add.reset(); } })"><Errors :errors="add.errors" /><input type="search" v-model="query" :placeholder="t('Find a word…')" :aria-label="t('Find a word')" /><WordPicker :words="candidates" :filter="query" :disabled="add.processing" v-model="add.word_ids" /><div class="form-actions"><button type="button" class="button secondary" @click="showAdd = false">{{ t('Cancel') }}</button><button class="button" :disabled="!add.word_ids.length || add.processing">{{ t('Add {count} words', { count: add.word_ids.length }) }}</button></div></form></section></div>
        <div v-if="showEdit && owner" class="modal-backdrop" @click.self="showEdit = false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="edit-title"><h2 id="edit-title">{{ t('Edit collection') }}</h2><form @submit.prevent="edit.put(base, { onSuccess: () => {persistVoice(voiceDraft);showEdit = false;} })"><Errors :errors="edit.errors" /><LocalVoicePicker v-model="voiceDraft"/><label>{{ t('Title') }}<input v-model="edit.title" required maxlength="150" /></label><label>{{ t('Description') }}<textarea v-model="edit.description" maxlength="2000" rows="3" /></label><div class="form-actions"><button type="button" class="button secondary" @click="showEdit = false">{{ t('Cancel') }}</button><button class="button" :disabled="edit.processing">{{ t('Save changes') }}</button></div></form></section></div>
        <div v-if="showNote && !shared" class="modal-backdrop" @click.self="showNote = false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="note-title"><div class="section-heading"><h2 id="note-title">{{ t('Your private note') }}</h2><button class="icon-button" :aria-label="t('Close')" @click="showNote = false">×</button></div><p>{{ noteWord?.term }}</p><form @submit.prevent="saveNote"><Errors :errors="note.errors" /><label>{{ t('Note') }}<textarea v-model="note.note" rows="4" maxlength="5000" /></label><p class="muted small">{{ t('Only you can see this note. It does not change the original definition.') }}</p><div class="form-actions"><button type="button" class="button secondary" @click="showNote = false">{{ t('Cancel') }}</button><button class="button" :disabled="note.processing">{{ t('Save note') }}</button></div></form></section></div>
        <div v-if="showVoice" class="modal-backdrop" @click.self="showVoice=false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="voice-title"><div class="section-heading"><h2 id="voice-title">{{t('Voice settings')}}</h2><button type="button" class="icon-button" :aria-label="t('Close')" @click="showVoice=false">×</button></div><form @submit.prevent="saveVoice"><LocalVoicePicker v-model="voiceDraft"/><div class="form-actions"><button type="button" class="button secondary" @click="showVoice=false">{{t('Cancel')}}</button><button class="button">{{t('Save voice')}}</button></div></form></section></div>
    </AppLayout>
</template>

<style scoped>
.mode-card{display:flex;flex-direction:row;align-items:center;justify-content:space-between;gap:16px}.guest-mode-copy{min-width:0}.guest-mode-copy h3{display:flex;align-items:center;gap:8px;margin:0 0 10px}.guest-mode-copy p{margin:0}.guest-mode-copy :deep(.mode-icon){flex-shrink:0}.mode-card .guest-start{align-self:center;flex-shrink:0;justify-content:center;gap:8px;min-width:112px;min-height:44px;padding:12px 20px;font-size:14px;margin-top:0}
.guest-mode-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr))}.mode-card .guest-start{min-width:100px}@media(max-width:760px){.guest-mode-grid{grid-template-columns:1fr}}@media(max-width:360px){.mode-card{padding:18px;gap:10px}.mode-card .guest-start{min-width:84px;padding:10px 12px}}
</style>
