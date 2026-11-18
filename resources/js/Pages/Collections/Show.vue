<script setup>
import ChevronText from '../../Components/ChevronText.vue';
import {collectionWordQuery} from '../../lib/collection-word-pagination.js';
import VocabularyText from "../../Components/VocabularyText.vue";
import LocalVoicePicker from '../../Components/LocalVoicePicker.vue';
import {collectionVoicePreference} from '../../lib/speech-preference.js';
import ModeIcon from "../../Components/ModeIcon.vue";
import StartIcon from "../../Components/StartIcon.vue";
import StudySummary from '../../Components/StudySummary.vue';
import WordPicker from '../../Components/WordPicker.vue';
import { useDialogEscape } from '../../lib/dialog.js';
import { useI18n } from '../../lib/i18n.js';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Errors from '../../Components/Errors.vue';
const { t } = useI18n();
const page = usePage();
const actionsMenu = ref(null), actionsTrigger = ref(null);
function closeActions() { if (actionsMenu.value) actionsMenu.value.open = false; }
function dismissActions(event) {
    if (!actionsMenu.value?.contains(event.target)) closeActions();
}
function escapeActions(event) {
    if (event.key === 'Escape' && actionsMenu.value?.open) {
        event.stopPropagation();
        closeActions();
        actionsTrigger.value?.focus();
    }
}
onMounted(() => {
    document.addEventListener('pointerdown', dismissActions);
    document.addEventListener('focusin', dismissActions);
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', dismissActions);
    document.removeEventListener('focusin', dismissActions);
});
watch(() => page.url, closeActions);

const props = defineProps({
    sharing: { type: Object, default: () => ({access:'guests',participants_count:0,blocked_count:0}) },
    collection: Object, shared: { type: Boolean, default: false }, isOwner: { type: Boolean, default: false },
    pools:{type:Array,default:()=>[]},filters:{type:Object,default:()=>({pool_ids:[]})},
    availableWords: { type: Array, default: () => [] },
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
const visibleWords=computed(()=>props.collection.words??[]);
const wordPagination=computed(()=>props.collection.word_pagination??{current_page:1,last_page:1,total:visibleWords.value.length,from:visibleWords.value.length?1:null,to:visibleWords.value.length||null});
const loading=ref(false),removing=ref(false),wordErrors=ref({});
let wordVisit=0,cancelWordVisit;
function wordVisitOptions(kind){
 const token=++wordVisit;wordErrors.value={};kind==='remove'?removing.value=true:loading.value=true;
 return {preserveState:true,preserveScroll:true,onCancelToken:cancel=>{if(token===wordVisit)cancelWordVisit=cancel;},onError:errors=>{if(token===wordVisit)wordErrors.value=errors;},onFinish:()=>{if(token===wordVisit){loading.value=false;removing.value=false;cancelWordVisit=null;}}};
}
onBeforeUnmount(()=>{wordVisit++;cancelWordVisit?.cancel();});
const wordBusy=computed(()=>loading.value||removing.value||applyingPools.value||add.processing||note.processing||edit.processing);
function goToWordsPage(target){
 if(wordBusy.value||target===wordPagination.value.current_page||target<1||target>wordPagination.value.last_page)return;
 router.get(base.value,collectionWordQuery(target,props.filters.pool_ids,props.shared),wordVisitOptions('page'));
}
const poolIds=ref(props.filters.pool_ids?.length?[...props.filters.pool_ids]:props.pools.map(pool=>pool.id));
const applyingPools=ref(false);
const add = useForm({ word_ids: [],pool_ids:[...poolIds.value] });
const poolsDirty=computed(()=>JSON.stringify(poolIds.value.map(String).sort())!==JSON.stringify(add.pool_ids.map(String).sort()));
function applyPools(){if(!poolIds.value.length||applyingPools.value)return;applyingPools.value=true;add.word_ids=[];const selected=[...poolIds.value];router.get(base.value,{pool_ids:selected,words_page:wordPagination.value.current_page},{preserveState:true,preserveScroll:true,onSuccess:()=>{add.pool_ids=selected;add.defaults('pool_ids',selected);},onError:errors=>add.setError(errors),onFinish:()=>{applyingPools.value=false;}});}
const edit = useForm({ title: props.collection.title, description: props.collection.description ?? '',voice_language:props.collection.voice_language??'' });
const note = useForm({ note: '' }); const noteWord = ref(null);
const base = computed(() => props.shared ? `/s/${props.collection.share_uuid}` : `/collections/${props.collection.id}`);
const candidates = computed(() => props.availableWords.filter(w => !visibleWords.value.some(x => x.id === w.id) && `${w.term} ${w.definition}`.toLowerCase().includes(query.value.toLowerCase())));
const shareUrl = computed(() => typeof window === 'undefined' ? '' : `${window.location.origin}/s/${props.collection.share_uuid}`);
const totalWords = computed(() => props.collection.words_count ?? props.collection.words.length);
async function copy() {
    try { await navigator.clipboard.writeText(shareUrl.value); copyStatus.value = t('Link copied'); }
    catch { copyStatus.value = t('Select and copy the link below.'); }
}
const sharingBusy=ref(false),sharingErrors=ref({}),access=ref(props.sharing.access??'guests');
watch(()=>props.sharing.access,value=>{access.value=value??'guests';});
watch(()=>props.collection.share_uuid,()=>{copyStatus.value='';});
function sharingAction(method,url,data={}){
 if(sharingBusy.value)return;
 sharingBusy.value=true;sharingErrors.value={};
 router.visit(url,{method,data,preserveScroll:true,onError:errors=>{sharingErrors.value=errors;},onFinish:()=>{sharingBusy.value=false;}});
}
function toggleSharing(event){
 event.target.checked=Boolean(props.collection.share_uuid);
 if(props.collection.share_uuid){
  if(!confirm(t('Turn sharing off? The link will stop working and all joined accounts will lose access. They must join again after sharing is enabled.')))return;
  sharingAction('delete',`${base.value}/sharing`);
 }else sharingAction('post',`${base.value}/sharing`);
}
function rotateLink(){
 if(confirm(t('Refresh the sharing link? The old link will stop working immediately. Existing members keep access and blocked accounts stay blocked.')))sharingAction('post',`${base.value}/sharing/rotate`);
}
function remove(word) {
 if(!owner.value||wordBusy.value)return;
 if(confirm(t('Remove “{term}” from this collection? It stays in your word pool.',{term:word.term})))router.delete(`${base.value}/words/${word.id}`,{...wordVisitOptions('remove'),data:collectionWordQuery(wordPagination.value.current_page,props.filters.pool_ids,props.shared)});
}
function destroy() { if (confirm(t('Delete this collection? Your words will stay in your pool.'))) router.delete(base.value); }
function editNote(word) { noteWord.value = word; note.note = word.note ?? ''; note.clearErrors(); showNote.value = true; }
function saveNote() { note.put(`/collections/${props.collection.id}/words/${noteWord.value.id}/note`, { preserveScroll: true, onSuccess: () => { showNote.value = false; } }); }
const leave=useForm({});
function leaveCollection(){if(leave.processing)return;if(confirm(t('Leave this collection? Your personal notes and history are retained. If you join again and consent, the owner may see your past results.')))leave.delete(`/collections/${props.collection.id}/membership`);}
function join() { router.get(`/s/${props.collection.share_uuid}/join`); }
useDialogEscape(() => { showAdd.value = false; showEdit.value = false; showNote.value = false;showVoice.value=false; });
const modes = [{ mode: 'flashcards', icon: '▱', name: 'Flashcards', text: 'Flip. Recall. Repeat.' }, { mode: 'study', icon: '✦', name: 'Study', text: 'Practice at your own pace.' }, { mode: 'test', icon: '✓', name: 'Test', text: 'Find out what sticks.' }];
</script>
<template>
    <Head :title="collection.title" />
    <AppLayout :title="collection.title" :subtitle="collection.description" :shared="shared">
        <template #title-badge><span v-if="!owner" class="pill dark">{{ t(shared ? 'SHARED COLLECTION' : 'SHARED WITH ME') }}</span></template>
        <template #actions><div class="actions">
            <details ref="actionsMenu" class="collection-actions" @keydown="escapeActions"><summary ref="actionsTrigger" class="button secondary" :aria-label="t('More actions')" :title="t('More actions')"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></summary><div class="collection-actions-menu" @click="closeActions"><button class="button secondary" @click="openVoice">{{t('Voice settings')}}</button><Link v-if="!shared&&!owner" :href="`/collections/${collection.id}/consent`" class="button secondary">{{t('Progress sharing choices')}}</Link><button v-if="!shared&&!owner" type="button" class="button secondary danger" :disabled="leave.processing" @click="leaveCollection">{{t('Leave collection')}}</button><a v-if="owner" :href="`/collections/${collection.id}/export`" class="button secondary">{{ t('Export CSV') }}</a>
            <button v-if="owner" class="button secondary" @click="voiceDraft=voiceMemory.read();showEdit = true">{{ t('Edit collection') }}</button></div></details>
        </div></template>
        <section v-if="shared" class="panel join-collection">
            <div><h2>{{ t('Make this part of your learning.') }}</h2><p class="muted">{{ t('Join this collection to save your progress and add private notes. The original words stay connected to the owner.') }}</p></div>
            <button class="button" @click="join">{{ t(authenticated ? 'Join collection' : 'Sign in to join') }}</button>
        </section>
        <StudySummary v-if="!shared" :summary="summary" :base="base" />
        <div v-if="shared" class="mode-grid guest-mode-grid"><Link v-for="item in modes" :key="item.mode" :href="`${base}/study?mode=${item.mode}`" class="mode-card" :class="`mode-card--${item.mode}`"><div class="guest-mode-copy"><h3><ModeIcon :mode="item.mode"/>{{ t(item.name) }}</h3><p>{{ t(item.text) }}</p></div><b class="guest-start">{{ t('Start') }}<StartIcon/></b></Link></div>
        <section v-if="owner" class="sharing panel sharing-management" :aria-busy="sharingBusy">
            <div class="sharing-heading"><div><span class="eyebrow">{{ t('KNOWLEDGE IS BETTER SHARED') }}</span><h3>{{ t(collection.share_uuid ? 'Your link is ready.' : 'Keep it private. Or pass it on.') }}</h3></div><label class="speech-toggle sharing-switch"><span>{{t('Link sharing')}}</span><input type="checkbox" role="switch" :checked="Boolean(collection.share_uuid)" :aria-checked="Boolean(collection.share_uuid)" :disabled="sharingBusy" @change="toggleSharing"/><span class="speech-toggle-track" aria-hidden="true"></span></label></div>
            <Errors :errors="sharingErrors"/>
            <form class="sharing-access" @submit.prevent="sharingAction('put',`${base}/sharing/access`,{access})"><label>{{t('Who can open the link?')}}<select v-model="access" :disabled="sharingBusy"><option value="guests">{{t('Everyone, including guests')}}</option><option value="signed_in">{{t('Signed-in accounts only')}}</option></select></label><button class="button secondary" :disabled="sharingBusy||access===sharing.access">{{t('Save access settings')}}</button></form>
            <p class="muted small">{{t('Your private notes and learning history are never shared.')}}</p>
            <p v-if="sharing.access==='guests'" class="notice">{{t('Guest access cannot identify signed-out visitors. A blocked account can still view the collection while signed out. Choose signed-in access to enforce account blocking.')}}</p>
            <div v-if="collection.share_uuid" class="share-link"><input readonly :value="shareUrl" :aria-label="t('Collection share link')" @focus="$event.target.select()"/><div class="share-link-actions"><button type="button" class="button secondary" :disabled="sharingBusy" @click="rotateLink">{{t('Refresh link')}}</button><button type="button" class="button" :disabled="sharingBusy" @click="copy">{{t('Copy link')}}</button></div><small role="status">{{copyStatus}}</small></div>
            <p v-else class="muted small">{{t('Link sharing is off. Enable it to create a new sharing link.')}}</p>
        </section>
        <div class="section-heading"><div><h2>{{ t('{count} words to discover', { count: totalWords }) }}</h2><p class="muted small word-order">{{ t('Newest words first') }}</p></div><button v-if="owner" class="button secondary" @click="showAdd = true">{{ t('+ Add from pool') }}</button></div>
        <div class="word-list">
            <article v-for="word in visibleWords" :key="word.id" class="word-row editable"><div><VocabularyText as="strong" :text="word.term" :direction="word.text_direction"/><VocabularyText as="p" :text="word.definition" :direction="word.text_direction"/><div v-if="!shared && word.note" class="personal-note"><span>{{ t('YOUR NOTE') }}</span>{{ word.note }}</div></div><div v-if="!shared" class="actions"><button class="text-button" :disabled="wordBusy" @click="editNote(word)">{{ t(word.note ? 'Edit note' : 'Add note') }}</button><button v-if="owner" class="text-button" :disabled="wordBusy" @click="remove(word)">{{ t('Remove') }}</button></div></article>
            <div v-if="!visibleWords.length" class="empty"><span>▤</span><h3>{{ t('This collection is waiting for words.') }}</h3><p v-if="owner">{{ t('Add words from your pool to start practicing.') }}</p></div>
        </div>
        <Errors :errors="wordErrors"/><nav class="word-pagination" :aria-label="t('Word pages')" :aria-busy="wordBusy"><p class="muted small" aria-live="polite">{{t('Showing {from}–{to} of {total}',{from:wordPagination.from??0,to:wordPagination.to??0,total:wordPagination.total})}} · {{t('Page {page} of {total}',{page:wordPagination.current_page,total:wordPagination.last_page})}}</p><div class="word-page-buttons"><button type="button" class="button secondary" :disabled="wordBusy||wordPagination.current_page<=1" @click="goToWordsPage(1)">{{t('First page')}}</button><button type="button" class="button secondary" :disabled="wordBusy||wordPagination.current_page<=1" @click="goToWordsPage(wordPagination.current_page-1)"><ChevronText :text="t('← Previous')"/></button><button type="button" class="button secondary" :disabled="wordBusy||wordPagination.current_page>=wordPagination.last_page" @click="goToWordsPage(wordPagination.current_page+1)"><ChevronText :text="t('Next →')"/></button><button type="button" class="button secondary" :disabled="wordBusy||wordPagination.current_page>=wordPagination.last_page" @click="goToWordsPage(wordPagination.last_page)">{{t('Last page')}}</button></div><span v-if="wordBusy" class="muted small" role="status">{{t('Loading…')}}</span></nav>
        <div v-if="owner" class="form-actions"><button class="text-button danger" @click="destroy">{{ t('Delete collection') }}</button></div>
        <div v-if="showAdd && owner" class="modal-backdrop" @click.self="showAdd = false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="add-title"><div class="section-heading"><h2 id="add-title">{{ t('Add from your pool') }}</h2><button class="icon-button" :aria-label="t('Close')" @click="showAdd = false">×</button></div><form @submit.prevent="add.post(`${base}/words`, { onSuccess: () => { showAdd = false; add.reset(); } })"><Errors :errors="add.errors" /><fieldset class="pool-selection"><legend>{{t('Choose source pools')}}</legend><label v-for="pool in pools" :key="pool.id"><input type="checkbox" v-model="poolIds" :value="pool.id"/><span>{{pool.name}} ({{pool.language}})</span></label><button type="button" class="button secondary" :disabled="!poolIds.length||applyingPools" @click="applyPools">{{t('Apply pool selection')}}</button></fieldset><input type="search" v-model="query" :placeholder="t('Find a word…')" :aria-label="t('Find a word')" /><WordPicker :words="candidates" :filter="query" :disabled="add.processing||applyingPools||poolsDirty" v-model="add.word_ids" /><div class="form-actions"><button type="button" class="button secondary" @click="showAdd = false">{{ t('Cancel') }}</button><button class="button" :disabled="!add.word_ids.length || add.processing||applyingPools||poolsDirty">{{ t('Add {count} words', { count: add.word_ids.length }) }}</button></div></form></section></div>
        <div v-if="showEdit && owner" class="modal-backdrop" @click.self="showEdit = false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="edit-title"><h2 id="edit-title">{{ t('Edit collection') }}</h2><form @submit.prevent="edit.put(base, { onSuccess: () => {persistVoice(voiceDraft);showEdit = false;} })"><Errors :errors="edit.errors" /><label>{{t('Default voice language')}}<input v-model="edit.voice_language" list="collection-languages" maxlength="35" placeholder="en-US, pt-PT"/></label><datalist id="collection-languages"><option v-for="language in [...new Set(pools.map(pool=>pool.language))]" :key="language" :value="language"/></datalist><p class="muted small">{{t('This language suggests device voices but never starts speech or replaces your chosen voice.')}}</p><LocalVoicePicker v-model="voiceDraft" :preferred-language="edit.voice_language"/><label>{{ t('Title') }}<input v-model="edit.title" required maxlength="150" /></label><label>{{ t('Description') }}<textarea v-model="edit.description" maxlength="2000" rows="3" /></label><div class="form-actions"><button type="button" class="button secondary" @click="showEdit = false">{{ t('Cancel') }}</button><button class="button" :disabled="edit.processing">{{ t('Save changes') }}</button></div></form></section></div>
        <div v-if="showNote && !shared" class="modal-backdrop" @click.self="showNote = false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="note-title"><div class="section-heading"><h2 id="note-title">{{ t('Your private note') }}</h2><button class="icon-button" :aria-label="t('Close')" @click="showNote = false">×</button></div><VocabularyText as="p" :text="noteWord?.term" :direction="noteWord?.text_direction"/><form @submit.prevent="saveNote"><Errors :errors="note.errors" /><label>{{ t('Note') }}<textarea dir="auto" v-model="note.note" rows="4" maxlength="5000" /></label><p class="muted small">{{ t('Only you can see this note. It does not change the original definition.') }}</p><div class="form-actions"><button type="button" class="button secondary" @click="showNote = false">{{ t('Cancel') }}</button><button class="button" :disabled="note.processing">{{ t('Save note') }}</button></div></form></section></div>
        <div v-if="showVoice" class="modal-backdrop" @click.self="showVoice=false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="voice-title"><div class="section-heading"><h2 id="voice-title">{{t('Voice settings')}}</h2><button type="button" class="icon-button" :aria-label="t('Close')" @click="showVoice=false">×</button></div><form @submit.prevent="saveVoice"><LocalVoicePicker v-model="voiceDraft" :preferred-language="collection.voice_language??''"/><div class="form-actions"><button type="button" class="button secondary" @click="showVoice=false">{{t('Cancel')}}</button><button class="button">{{t('Save voice')}}</button></div></form></section></div>
    </AppLayout>
</template>

<style scoped>
.mode-card{display:flex;flex-direction:row;align-items:center;justify-content:space-between;gap:16px}.guest-mode-copy{min-width:0}.guest-mode-copy h3{display:flex;align-items:center;gap:8px;margin:0 0 10px}.guest-mode-copy p{margin:0}.guest-mode-copy :deep(.mode-icon){flex-shrink:0}.mode-card .guest-start{align-self:center;flex-shrink:0;justify-content:center;gap:8px;min-width:112px;min-height:44px;padding:12px 20px;font-size:14px;margin-top:0}
.guest-mode-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr))}.mode-card .guest-start{min-width:100px}@media(max-width:760px){.guest-mode-grid{grid-template-columns:1fr}}@media(max-width:360px){.mode-card{padding:18px;gap:10px}.mode-card .guest-start{min-width:84px;padding:10px 12px}}
.sharing-management{display:block}.sharing-management .manage-members-button{display:flex;width:100%;box-sizing:border-box;justify-content:center;margin-top:12px}.sharing-management .sharing-heading{max-width:none;width:100%;display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.sharing-switch{flex-shrink:0;margin:0}.sharing-access{display:flex;align-items:flex-end;gap:12px;margin:16px 0}.sharing-access label{flex:1;margin:0}.sharing-access button{flex-shrink:0}.sharing-management .share-link{display:block;width:100%}.share-link-actions{display:flex;justify-content:space-between;gap:12px;margin:12px 0}.sharing-people{border-top:1px solid var(--line,#deded8);margin-top:20px;padding-top:12px}.sharing-person{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0}.sharing-person strong{overflow-wrap:anywhere;min-width:0}.sharing-person .actions{flex-shrink:0}@media(max-width:600px){.sharing-heading{gap:8px}.sharing-heading h3{font-size:1.05rem}.sharing-access,.sharing-person{align-items:stretch;flex-direction:column}.sharing-switch{font-size:.8rem}.share-link-actions .button{flex:1;padding:10px}.sharing-person .actions{justify-content:flex-start}}
.sharing-switch{position:relative;display:flex;flex-direction:row;align-items:center;gap:10px;cursor:pointer}.sharing-switch input{position:absolute;opacity:0;width:1px;height:1px}.sharing-switch .speech-toggle-track{position:relative;display:inline-block;flex:0 0 42px;width:42px;height:24px;border-radius:20px;background:#a5afad;transition:background .15s}.sharing-switch .speech-toggle-track:after{content:"";position:absolute;left:3px;top:3px;width:18px;height:18px;border-radius:50%;background:white;transition:transform .15s}.sharing-switch input:checked+.speech-toggle-track{background:#157e73}.sharing-switch input:checked+.speech-toggle-track:after{transform:translateX(18px)}.sharing-switch input:focus-visible+.speech-toggle-track{outline:3px solid #163c55;outline-offset:3px}.sharing-switch input:disabled+.speech-toggle-track{opacity:.5}@media(prefers-reduced-motion:reduce){.sharing-switch .speech-toggle-track,.sharing-switch .speech-toggle-track:after{transition:none}}
.pool-selection{border:1px solid #c6e5d2;padding:14px;border-radius:10px;margin-bottom:16px}.pool-selection label{display:flex;flex-direction:row;align-items:center;gap:10px}.pool-selection input{width:18px;height:18px;flex:0 0 18px;accent-color:var(--teal)}.word-pagination{margin:24px 0}.word-page-buttons{display:flex;flex-wrap:wrap;gap:10px}.word-page-buttons .button{min-height:44px}.word-page-buttons .button:disabled{opacity:.5;cursor:not-allowed}@media(max-width:600px){.word-page-buttons{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.word-page-buttons .button{width:100%;padding:12px 8px;white-space:normal}}</style>

<style scoped>
.collection-actions{position:relative;flex-shrink:0}.collection-actions summary{display:flex;align-items:center;justify-content:center;list-style:none;cursor:pointer;width:52px;height:52px;min-height:52px;padding:0;border-radius:50%;background:var(--surface);border:1px solid var(--line);color:var(--navy);transition:background .15s,border-color .15s}.collection-actions summary::-webkit-details-marker{display:none}.collection-actions summary svg{display:block;width:28px;height:28px;fill:currentColor}.collection-actions summary:hover,.collection-actions[open] summary{background:#e5f1e9;border-color:var(--teal);color:var(--teal);transform:none}.collection-actions summary:focus-visible{outline:3px solid #45baa8;outline-offset:3px}
.collection-actions-menu{position:absolute;right:0;top:calc(100% + 8px);z-index:10;display:grid;gap:6px;min-width:220px;padding:8px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-md);box-shadow:var(--shadow-card)}.collection-actions-menu .button{width:100%;justify-content:flex-start;text-align:left;border-color:transparent}
.collection-actions-menu .button.danger{color:#b42318}.collection-actions-menu .button.danger:hover,.collection-actions-menu .button.danger:focus-visible{background:#fef3f2;color:#912018}
@media(max-width:760px){.collection-actions{align-self:flex-end;width:auto}.collection-actions summary{width:52px}.collection-actions-menu{min-width:220px;max-width:calc(100vw - 40px)}}
</style>
