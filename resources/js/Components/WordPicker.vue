<script setup>
import VocabularyText from "../Components/VocabularyText.vue";
import { useI18n } from "../lib/i18n.js";
const {t,locale}=useI18n();
import { computed, ref, watch } from 'vue';
import { MAX_SELECTION, inclusiveRange, selectIds, deselectIds } from '../lib/selection.js';
const props = defineProps({ words: { type: Array, required: true }, modelValue: { type: Array, required: true }, filter: String, disabled: Boolean });
const emit = defineEmits(['update:modelValue']);
const anchor = ref(null), rangeMode = ref(false), message = ref('');
const ids = computed(() => props.words.map(word => word.id));
const selected = computed(() => new Set(props.modelValue));
const visibleCount = computed(() => ids.value.filter(id => selected.value.has(id)).length);
const anchorWord = computed(() => props.words.find(word => word.id === anchor.value));
function resetRange() { anchor.value = null; rangeMode.value = false; message.value = ''; }
watch(() => props.filter, resetRange);
watch(ids, () => { if (!ids.value.includes(anchor.value)) anchor.value = null; });
function select(additions) {
    const result = selectIds(props.modelValue, additions);
    message.value = result.overflow ? t('You can add up to {max} words at once. Nothing was changed. Select a smaller range, add it, then repeat.',{max:MAX_SELECTION}) : '';
    if (!result.overflow) emit('update:modelValue', result.ids);
}
function toggle(id, event) {
    if (props.disabled) return;
    if (rangeMode.value) {
        if (anchor.value === null) { anchor.value = id; message.value = ''; }
        else { select(inclusiveRange(ids.value, anchor.value, id)); rangeMode.value = false; }
    } else if (event.shiftKey && anchor.value !== null) {
        select(inclusiveRange(ids.value, anchor.value, id));
    } else {
        message.value = '';
        if (selected.value.has(id)) emit('update:modelValue', deselectIds(props.modelValue, [id]));
        else select([id]);
        anchor.value = id;
    }
    // Undo the browser's tentative checkbox toggle; Vue owns selection state.
    event.target.checked = selected.value.has(id);
}
function startRange() { anchor.value = null; rangeMode.value = !rangeMode.value; message.value = ''; }
function clearVisible() { emit('update:modelValue', deselectIds(props.modelValue, ids.value)); resetRange(); }
function clearAll() { emit('update:modelValue', []); resetRange(); }
</script>
<template>
    <div class="selection-toolbar">
        <div class="actions">
            <button type="button" class="button secondary" :disabled="disabled || !words.length || visibleCount === words.length" @click="select(ids)">{{t('Select all results ({count})',{count:words.length})}}</button>
            <button type="button" class="button secondary" :disabled="disabled || !visibleCount" @click="clearVisible">{{t("Clear results")}}</button>
            <button type="button" class="button secondary" :disabled="disabled || !words.length" :aria-pressed="rangeMode" @click="startRange">{{ rangeMode ? t('Cancel range') : t('Select a range') }}</button>
            <button type="button" class="text-button" :disabled="disabled || !modelValue.length" @click="clearAll">{{t("Clear all selections")}}</button>
        </div>
        <p class="muted small" role="status">{{t('{selected} / {max} selected · {visible} in these results. Search changes keep existing selections.',{selected:modelValue.length,max:MAX_SELECTION,visible:visibleCount})}}</p>
        <p class="small" aria-live="polite">{{ rangeMode ? (anchorWord ? t('Start: {term}. Tap the last word; both ends are included.',{term:anchorWord.term}) : t('Tap the first word, then the last word to select everything between them.')) : t('Select a word, then Shift-click another to select a range. On touch screens, use Select a range.') }}</p>
        <p v-if="message" class="notice error" role="alert">{{ message }}</p>
    </div>
    <div class="pick-list">
        <label v-for="word in words" :key="word.id" class="check" :class="{'range-start': rangeMode && anchor === word.id}">
            <input type="checkbox" :checked="selected.has(word.id)" :disabled="disabled" @click="toggle(word.id, $event)"/>
            <span><VocabularyText as="strong" :text="word.term" :direction="word.text_direction"/><VocabularyText as="small" :text="word.definition" :direction="word.text_direction"/><small v-if="rangeMode && anchor === word.id">{{t("Range starts here")}}</small></span>
        </label>
        <p v-if="!words.length" class="muted">{{t("No more matching words. Try another search or add words to your pool.")}}</p>
    </div>
</template>
<style scoped>
.selection-toolbar { margin: 1rem 0; }
.selection-toolbar .actions { flex-wrap: wrap; gap: .5rem; }
.selection-toolbar .button { min-height: 44px; }
.range-start { outline: 2px solid currentColor; outline-offset: -2px; }
</style>
