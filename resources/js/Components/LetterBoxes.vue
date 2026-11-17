<script setup>
import {hasArabicScript} from '../lib/vocabulary-typography.js';
import {isCorrect} from "../lib/study.js";
import {createFirstEntryTracker} from '../lib/first-letter-entries.js';
import {ref,computed,nextTick,onMounted,onBeforeUnmount,useId} from 'vue';
import {committedLetterWrong,letterState} from '../lib/arrange-letters.js';
import {useI18n} from '../lib/i18n.js';
import {makeLetterBoxes,editBoxes,eraseBox,boxAnswer,answerDirection,boxesComplete,graphemes,compositionBuffer} from '../lib/letter-boxes.js';
const props=defineProps({expected:{type:String,required:true},modelValue:{type:String,default:''},disabled:Boolean,direction:String,liveFeedback:Boolean,commitKey:String,letterLayout:Array,forceText:Boolean,previewCompletion:Boolean,dismissOnExact:Boolean,autofocus:Boolean,revealAnswer:Boolean});
const descriptionId=useId();
const emit=defineEmits(['update:modelValue','committed-error','committed-answer','answer-preview']);const {t}=useI18n();
const initialBoxes=makeLetterBoxes(props.expected);
if(initialBoxes&&props.letterLayout){const editable=new Set(props.letterLayout.map(slot=>slot.index));initialBoxes.forEach((box,index)=>{box.fixed=!editable.has(index);box.value=box.fixed?box.text:'';});}
const tracker=createFirstEntryTracker();
const boxes=ref(initialBoxes);const active=ref(boxes.value?.findIndex(box=>!box.fixed)??-1);
const draft=ref('');let stagedTracker=null,lastCandidate=null,acceptedPreview=false;
function previewDraft(text){if(acceptedPreview||props.revealAnswer)return;draft.value=text;if(!props.previewCompletion||!stagedTracker||acceptedPreview)return;const result=editBoxes(boxes.value,active.value,text);const exact=!!text&&boxesComplete(result.boxes)&&isCorrect(boxAnswer(result.boxes),props.expected);stagedTracker.record(result.boxes,{finalize:exact});lastCandidate=exact?{answer:boxAnswer(result.boxes),key:props.commitKey,input_mode:'slots',first_entries:stagedTracker.entries()}:null;emit('answer-preview',{key:props.commitKey,candidate:lastCandidate});if(lastCandidate&&props.dismissOnExact)acceptPreview();}
const displayedBoxes=computed(()=>props.revealAnswer?boxes.value?.map(box=>({...box,value:box.text})):(draft.value?editBoxes(boxes.value,active.value,draft.value).boxes:boxes.value));
function feedbackState(box){return !props.revealAnswer&&props.liveFeedback&&box?letterState(box):'';}
function feedbackLabel(box){const state=feedbackState(box);return state?': '+t(draft.value?(state==='incorrect'?'Preview: incorrect placement':'Preview: correct placement'):(state==='incorrect'?'Incorrect placement':'Correct placement')):'';}
const input=ref(null),fallbackInputEl=ref(null),area=ref(null),position=ref({});let observer, keyDeletion=false,disposed=false;
const textDirection=computed(()=>answerDirection(props.expected,props.direction));
const supported=computed(()=>!props.forceText&&boxes.value&&active.value>=0&&boxes.value.length<=240);
function locate(){const cell=area.value?.querySelector(`[data-cell="${active.value}"]`);if(cell){const a=area.value.getBoundingClientRect(),b=cell.getBoundingClientRect();position.value={left:`${b.left-a.left}px`,top:`${b.top-a.top}px`,width:`${b.width}px`,height:`${b.height}px`};}}
async function choose(index){if(disposed||props.revealAnswer||acceptedPreview||props.disabled||composition.active())return;active.value=index;await nextTick();if(disposed||props.revealAnswer||acceptedPreview||props.disabled)return;locate();input.value?.focus({preventScroll:true});}
function announceCommitted(value,inputMode='slots'){const dismiss=props.dismissOnExact&&isCorrect(value,props.expected);if(dismiss)acceptedPreview=true;emit('committed-answer',{answer:value,key:props.commitKey,first_entries:inputMode==='slots'?tracker.entries():[],input_mode:inputMode});if(dismiss)(inputMode==='text'?fallbackInputEl.value:input.value)?.blur();}
function apply(result){if(props.revealAnswer)return;boxes.value=result.boxes;tracker.record(boxes.value,{finalize:boxesComplete(boxes.value)&&boxAnswer(boxes.value).normalize('NFKC').toLowerCase()===props.expected.normalize('NFKC').toLowerCase()});if(props.liveFeedback&&boxes.value.some(committedLetterWrong))emit('committed-error',{key:props.commitKey});active.value=result.cursor;const value=boxesComplete(boxes.value)?boxAnswer(boxes.value):'';emit('update:modelValue',value);announceCommitted(value);nextTick(locate);}
function commit(text){if(acceptedPreview||props.revealAnswer)return;if(!props.disabled&&text){if(stagedTracker)tracker.restore(stagedTracker.snapshot());apply(editBoxes(boxes.value,active.value,text));}if(input.value)input.value.value='';}
const composition=compositionBuffer(commit,previewDraft);
function startComposition(){if(acceptedPreview||props.revealAnswer)return;stagedTracker=props.previewCompletion?tracker.fork():null;composition.start();if(boxesComplete(boxes.value))emit('update:modelValue','');}
function endComposition(event){if(acceptedPreview||props.revealAnswer)return;composition.end(event);stagedTracker=null;lastCandidate=null;emit('update:modelValue',boxesComplete(boxes.value)?boxAnswer(boxes.value):'');}
function acceptPreview(){if(!lastCandidate||acceptedPreview||props.disabled||!stagedTracker)return false;const value=lastCandidate.answer;acceptedPreview=true;tracker.restore(stagedTracker.snapshot());apply(editBoxes(boxes.value,active.value,draft.value));composition.dispose();draft.value='';stagedTracker=null;lastCandidate=null;emit('answer-preview',{key:props.commitKey,candidate:null});return value;}
function cancelInput(){acceptedPreview=true;composition.dispose();stagedTracker=null;lastCandidate=null;draft.value='';input.value?.blur();fallbackInputEl.value?.blur();}
async function focusFirst(){if(disposed||props.revealAnswer||!props.autofocus||props.disabled||acceptedPreview)return;await nextTick();if(disposed||props.revealAnswer||props.disabled||acceptedPreview)return;if(supported.value){active.value=boxes.value.findIndex(box=>!box.fixed&&!box.value);if(active.value<0)return;locate();input.value?.focus({preventScroll:true});}else fallbackInputEl.value?.focus({preventScroll:true});}
defineExpose({acceptPreview,cancelInput});
function key(event){if(acceptedPreview||props.revealAnswer)return;if(event.isComposing||composition.active()||event.keyCode===229){if(event.key==='Enter')event.preventDefault();return;}if(props.disabled)return;composition.key();if(event.key==='Backspace'){event.preventDefault();keyDeletion=true;apply(eraseBox(boxes.value,active.value));}else if(event.key==='ArrowRight'||event.key==='ArrowLeft'){event.preventDefault();const step=(event.key==='ArrowRight'?1:-1)*(textDirection.value==='rtl'?-1:1);let index=active.value+step;while(boxes.value[index]?.fixed)index+=step;if(boxes.value[index])choose(index);}}
function beforeInput(event){if(acceptedPreview||props.revealAnswer)return;if(event.inputType==='deleteContentBackward'&&!composition.active()&&!props.disabled){event.preventDefault();if(!keyDeletion)apply(eraseBox(boxes.value,active.value));keyDeletion=false;}}
function paste(event){event.preventDefault();if(acceptedPreview||composition.active())return;composition.key();const text=event.clipboardData.getData('text');if(graphemes(text.normalize('NFKC'))?.length===boxes.value.length)active.value=0;commit(text);}
const fallbackValue=ref(props.modelValue??'');let fallbackComposing=false;
function fallbackInput(event){if(acceptedPreview||props.revealAnswer)return;fallbackValue.value=event.target.value;if(fallbackComposing||event.isComposing)return;emit('update:modelValue',fallbackValue.value);announceCommitted(fallbackValue.value,'text');}
function fallbackStart(){if(acceptedPreview||props.revealAnswer)return;fallbackComposing=true;emit('update:modelValue','');}
function fallbackEnd(event){fallbackComposing=false;fallbackInput(event);}
onMounted(()=>{locate();focusFirst();if(globalThis.ResizeObserver&&area.value){observer=new ResizeObserver(locate);observer.observe(area.value);}});onBeforeUnmount(()=>{disposed=true;observer?.disconnect();composition.dispose();});
</script>
<template>
<div class="letter-answer" :class="{'revealed-answer':revealAnswer,'arabic-content':hasArabicScript(expected)}">
<span class="answer-label">{{t(revealAnswer?'Skipped answer':'Your answer')}}</span>
<template v-if="supported">
<p v-if="!revealAnswer" :id="descriptionId" class="muted small">{{t('Tap a blank box and type. Given letters stay fixed; spaces and punctuation are already included.')}}</p>
<div ref="area" class="letter-area" :dir="textDirection">
<template v-for="(box,index) in displayedBoxes" :key="index"><span v-if="box.fixed" :class="['letter-cell','fixed',{'separator':/\s/u.test(box.text)}]" :aria-label="t('Given: {letter}',{letter:box.text})">{{box.text}}</span><button v-else type="button" :data-cell="index" tabindex="-1" :disabled="disabled||revealAnswer" :class="['letter-cell',{'active':index===active},feedbackState(box)]" :aria-label="t('Letter {number}: {letter}',{number:index+1,letter:box.value||t('blank')})+feedbackLabel(box)" @click="choose(index)">{{box.value||' '}}<span v-if="feedbackState(box)" class="placement-mark" aria-hidden="true">{{letterState(box)==='incorrect'?'×':'✓'}}</span></button></template>
<input v-show="!revealAnswer" ref="input" class="letter-entry" :style="position" :disabled="disabled||revealAnswer" :aria-describedby="descriptionId" :aria-label="t('Letter {number}: {letter}',{number:active+1,letter:displayedBoxes[active]?.value||t('blank')})+feedbackLabel(displayedBoxes[active])" autocomplete="off" autocapitalize="off" spellcheck="false" :dir="textDirection" @keydown="key" @keyup="keyDeletion=false" @beforeinput="beforeInput" @input="composition.input" @compositionstart="startComposition" @compositionupdate="composition.update" @compositionend="endComposition" @paste="paste"/>
</div>
<p class="answer-preview sr-only" dir="auto" aria-live="polite">{{boxAnswer(displayedBoxes)}}</p>
</template>
<p v-if="!supported&&!revealAnswer" class="muted small">{{t('Use the text field for this answer. Letter boxes are unavailable for very long answers or this browser.')}}</p>
<p v-if="!supported&&!revealAnswer&&liveFeedback" class="muted small">{{t('Live letter feedback is unavailable in this text field; the answer is checked when submitted.')}}</p>
<p v-if="!supported&&!revealAnswer&&letterLayout!==undefined&&liveFeedback" class="muted small">{{t('First-letter accuracy scoring is unavailable in this text field. Your final answer must be exact.')}}</p>
<p v-if="!supported&&revealAnswer" class="fallback-reveal" dir="auto">{{expected}}</p>
<textarea v-if="!supported&&!revealAnswer" ref="fallbackInputEl" :value="fallbackValue" @input="fallbackInput" @compositionstart="fallbackStart" @compositionend="fallbackEnd" :disabled="disabled||revealAnswer" dir="auto" rows="3" :aria-label="t('Your answer')"/>
</div>
</template>
<style scoped>
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.answer-label{font-weight:600}.letter-area{position:relative;display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;unicode-bidi:isolate;max-width:100%;margin:1rem 0}.letter-cell{display:inline-flex;align-items:center;justify-content:center;min-width:2.5rem;min-height:2.75rem;padding:.3rem;border:1px solid var(--practice-border,#bacbc8);border-radius:.5rem;background:white;color:var(--practice-ink,var(--ink,#173a39));font-size:1.2rem;white-space:pre-wrap;overflow-wrap:anywhere}.letter-cell.fixed{background:var(--practice-soft,#e7efed);border-style:dashed}.letter-cell.separator{min-width:.7rem;border:0;background:transparent}.letter-cell.active{outline:2px solid var(--practice-focus,#247d70);outline-offset:1px}.letter-entry{position:absolute!important;min-width:0!important;padding:0!important;margin:0!important;opacity:0;z-index:2;font-size:18px;caret-color:transparent}.letter-area:focus-within .letter-cell.active{outline-width:3px}.letter-cell:disabled{opacity:.7}.letter-answer textarea{unicode-bidi:plaintext;text-align:start}.letter-answer textarea:focus-visible{outline:3px solid var(--practice-focus,#247d70);outline-offset:2px}
.letter-cell,.letter-cell.separator{flex:0 0 2.75rem;width:2.75rem;max-width:100%;min-width:0;min-height:44px;box-sizing:border-box}.letter-answer{min-width:0;max-width:100%}
.letter-cell{position:relative}.letter-cell.correct{border:2px solid var(--practice-accent,#17644d);background:var(--practice-soft,#e9f7ef)}.letter-cell.incorrect{border:2px solid #a42f36;background:#fff0f1}.placement-mark{position:absolute;right:1px;bottom:0;font-size:.7rem;font-weight:bold}
.revealed-answer .letter-cell,.fallback-reveal{background:#fff4df;border:1px solid #a97d31;color:#684812;opacity:1}.revealed-answer .letter-cell.active{outline:none}.fallback-reveal{padding:1rem;border-radius:.5rem;white-space:pre-wrap;overflow-wrap:anywhere;unicode-bidi:plaintext}.revealed-answer .answer-label{color:#684812}
.arabic-content .letter-cell{font-size:1.5rem;line-height:1.7;min-height:3.25rem}.arabic-content textarea,.arabic-content .fallback-reveal{font-size:1.4rem;line-height:1.9}@media(min-width:900px){.arabic-content .letter-cell{font-size:1.85rem;min-height:3.75rem}.arabic-content textarea,.arabic-content .fallback-reveal{font-size:1.65rem}}
</style>
