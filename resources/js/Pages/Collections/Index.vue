<script setup>
import { useDialogEscape } from '../../lib/dialog.js';
import { useI18n } from '../../lib/i18n.js';
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Errors from '../../Components/Errors.vue';
const { t } = useI18n();
const props = defineProps({ collections: { type: Array, default: () => [] } });
const filter = ref('all');
const filtered = computed(() => props.collections.filter(c => filter.value === 'all' || (filter.value === 'owned' ? c.isOwner : !c.isOwner)));
const open = ref(false); const form = useForm({ title: '', description: '' });
function save() { form.post('/collections', { onSuccess: () => { open.value = false; form.reset(); } }); }
useDialogEscape(() => { open.value = false; });
</script>
<template>
    <Head :title="t('Collections')" />
    <AppLayout :title="t('A place for every idea.')" :subtitle="t('Gather your words into something worth remembering.')">
        <template #actions><button class="button" @click="open = true">{{ t('+ New collection') }}</button></template>
        <div class="collection-filters" :aria-label="t('Filter collections')"><button v-for="tab in [{ value: 'all', label: 'All collections' }, { value: 'owned', label: 'Created by me' }, { value: 'shared', label: 'Shared with me' }]" :key="tab.value" :class="['button secondary', { selected: filter === tab.value }]" :aria-pressed="filter === tab.value" @click="filter = tab.value">{{ t(tab.label) }}</button></div>
        <div class="collection-grid">
            <Link class="collection-card" v-for="(c, i) in filtered" :key="c.id" :href="`/collections/${c.id}`"><div class="collection-icon" :class="`tone-${i % 3}`">▤</div><span class="tiny-label">{{ t(!c.isOwner ? 'SHARED WITH ME' : c.share_uuid ? 'LINK SHARING ON' : 'PRIVATE COLLECTION') }}</span><h3>{{ c.title }}</h3><p>{{ c.description || t('A new world of words.') }}</p><div class="card-bottom"><span>{{ t('{count} words', { count: c.words_count ?? c.words?.length ?? 0 }) }}</span><span>{{ t('Open →') }}</span></div></Link>
            <button v-if="filter !== 'shared'" class="collection-card add-card" @click="open = true"><span>＋</span><h3>{{ t('Start something new.') }}</h3><p>{{ t('A topic, a lesson, a little curiosity.') }}</p></button>
        </div>
        <div v-if="filter === 'shared' && !filtered.length" class="empty"><span>▤</span><h3>{{ t('A little knowledge, shared.') }}</h3><p>{{ t('Open a collection link and join it to find it here.') }}</p></div>
        <div v-if="open" class="modal-backdrop" @click.self="open = false"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="collection-title"><div class="section-heading"><h2 id="collection-title">{{ t('New collection') }}</h2><button class="icon-button" :aria-label="t('Close')" @click="open = false">×</button></div><form @submit.prevent="save"><Errors :errors="form.errors" /><label>{{ t('Title') }}<input v-model="form.title" required maxlength="150" autofocus :placeholder="t('e.g. Words for a new adventure')" /></label><label>{{ t('Description') }}<textarea v-model="form.description" maxlength="2000" rows="3" :placeholder="t('What brings these words together?')" /></label><p class="muted small">{{ t('Private by default. You decide when to share.') }}</p><div class="form-actions"><button type="button" class="button secondary" @click="open = false">{{ t('Cancel') }}</button><button class="button" :disabled="form.processing">{{ t('Create collection') }}</button></div></form></section></div>
    </AppLayout>
</template>
