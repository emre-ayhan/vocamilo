<script setup>
import {ref,onMounted,onBeforeUnmount} from 'vue';
import {localVoices} from '../lib/speech.js';
import {useI18n} from '../lib/i18n.js';
defineProps({modelValue:{type:String,default:''}});
const emit=defineEmits(['update:modelValue']);
const {t}=useI18n(),voices=ref([]);
const synthesis=typeof window!=='undefined'?window.speechSynthesis:null;
const supported=Boolean(synthesis&&window.SpeechSynthesisUtterance);
function refresh(){voices.value=localVoices(synthesis);}
onMounted(()=>{refresh();synthesis?.addEventListener('voiceschanged',refresh);});
onBeforeUnmount(()=>synthesis?.removeEventListener('voiceschanged',refresh));
</script>
<template><fieldset class="voice-settings"><legend>{{t('Voice and vocabulary language')}}</legend><p class="muted small">{{t('Voice preferences are personal to this collection and browser. Browser storage must be available to remember them. Reading aloud stays off until you turn it on.')}}</p><label>{{t('Choose a local voice')}}<select :value="modelValue" :disabled="!supported||!voices.length" @change="emit('update:modelValue',$event.target.value)"><option value="">{{t('No voice selected')}}</option><option v-if="modelValue&&!voices.some(v=>v.voiceURI===modelValue)" :value="modelValue" disabled>{{t('Saved voice unavailable on this device')}}</option><option v-for="voice in voices" :key="voice.voiceURI" :value="voice.voiceURI">{{voice.name}} ({{voice.lang}})</option></select></label><p v-if="!supported" class="muted small">{{t('Speech is not supported in this browser.')}}</p><p v-else-if="!voices.length" class="muted small" role="status">{{t('No local voices are available on this device. Install a device voice to use read aloud.')}}</p></fieldset></template>

<style scoped>.voice-settings{min-width:0;border:1px solid #c6e5d2;border-radius:10px;padding:1rem;margin:1.5rem 0}.voice-settings legend{font-size:.85rem;font-weight:650;padding:0 .4rem}.voice-settings label{gap:16px;margin-top:1.25rem}.voice-settings select{width:100%;min-width:0}</style>
