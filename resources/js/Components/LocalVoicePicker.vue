<script setup>
import {ref,computed,onMounted,onBeforeUnmount} from 'vue';
import {localVoices,voiceIdentity,resolveLocalVoice,observeVoiceChanges} from '../lib/speech.js';
import {suggestedLocalVoices,voiceLanguageScore} from '../lib/speech-language.js';
import {useI18n} from '../lib/i18n.js';
const props=defineProps({modelValue:{type:String,default:''},preferredLanguage:{type:String,default:''}});
const emit=defineEmits(['update:modelValue']);
const {t}=useI18n(),voices=ref([]);
const synthesis=typeof window!=='undefined'?window.speechSynthesis:null;
const supported=Boolean(synthesis&&window.SpeechSynthesisUtterance);
const choices=computed(()=>suggestedLocalVoices(voices.value,props.preferredLanguage));
const hasLanguageMatch=computed(()=>choices.value.some(voice=>voiceLanguageScore(voice,props.preferredLanguage)>0));
const selected=computed(()=>resolveLocalVoice(voices.value,props.modelValue));
function refresh(){voices.value=localVoices(synthesis);}
let stopObserving;
onMounted(()=>{stopObserving=observeVoiceChanges(synthesis,window,document,refresh);});
onBeforeUnmount(()=>stopObserving?.());
</script>
<template><fieldset class="voice-settings"><legend>{{t('Voice and vocabulary language')}}</legend><p class="muted small">{{t('Voice preferences are personal to this collection and browser. Browser storage must be available to remember them. Reading aloud stays off until you turn it on.')}}</p><label>{{t('Choose a local voice')}}<select :value="selected?voiceIdentity(selected):modelValue" :disabled="!supported||!choices.length" @change="emit('update:modelValue',$event.target.value)"><option value="">{{t('No voice selected')}}</option><option v-if="modelValue&&!selected" :value="modelValue" disabled>{{t('Saved voice unavailable or ambiguous. Choose a local voice again.')}}</option><option v-for="voice in choices" :key="voiceIdentity(voice)" :value="voiceIdentity(voice)">{{voice.name}} ({{voice.lang}}){{voiceLanguageScore(voice,preferredLanguage)>0?` · ${t('Recommended')}`:''}}</option></select></label><p v-if="!supported" class="muted small">{{t('Speech is not supported in this browser.')}}</p><p v-else-if="!voices.length" class="muted small" role="status">{{t('No local voices are available on this device. Install a device voice to use read aloud.')}}</p><p v-if="preferredLanguage&&choices.length&&!hasLanguageMatch" class="muted small" role="status">{{t('No local voice matches {language}. You can choose another available voice.',{language:preferredLanguage})}}</p><p class="muted small">{{t('Only voices exposed by this browser can be selected. Device voice settings may not be exposed or honored by the browser.')}}</p><p v-if="voices.length&&!choices.length" class="muted small" role="status">{{t('This browser reports indistinguishable local voices. Reading aloud is unavailable until it provides a unique voice.')}}</p></fieldset></template>

<style scoped>.voice-settings{min-width:0;border:1px solid #c6e5d2;border-radius:10px;padding:1rem;margin:1.5rem 0}.voice-settings legend{font-size:.85rem;font-weight:650;padding:0 .4rem}.voice-settings label{gap:16px;margin-top:1.25rem}.voice-settings select{width:100%;min-width:0}</style>
