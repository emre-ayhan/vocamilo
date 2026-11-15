<script setup>
import {ref,computed,nextTick,onMounted,onBeforeUnmount,useId} from 'vue';
import {committedLetterWrong,letterState} from '../lib/arrange-letters.js';
import {useI18n} from '../lib/i18n.js';
import {makeLetterBoxes,editBoxes,eraseBox,boxAnswer,answerDirection,boxesComplete,graphemes,compositionBuffer} from '../lib/letter-boxes.js';
const props=defineProps({expected:{type:String,required:true},modelValue:{type:String,default:''},disabled:Boolean,direction:String,liveFeedback:Boolean});
const descriptionId=useId();
const emit=defineEmits(['update:modelValue','committed-error']);const {t}=useI18n();
const boxes=ref(makeLetterBoxes(props.expected));const active=ref(boxes.value?.findIndex(box=>!box.fixed)??-1);
const draft=ref('');
const displayedBoxes=computed(()=>draft.value?editBoxes(boxes.value,active.value,draft.value).boxes:boxes.value);
const input=ref(null),area=ref(null),position=ref({});let observer, keyDeletion=false;
const textDirection=computed(()=>answerDirection(props.expected,props.direction));
const supported=computed(()=>boxes.value&&active.value>=0&&boxes.value.length<=240);
function locate(){const cell=area.value?.querySelector(`[data-cell="${active.value}"]`);if(cell){const a=area.value.getBoundingClientRect(),b=cell.getBoundingClientRect();position.value={left:`${b.left-a.left}px`,top:`${b.top-a.top}px`,width:`${b.width}px`,height:`${b.height}px`};}}
async function choose(index){if(props.disabled||composition.active())return;active.value=index;await nextTick();locate();input.value?.focus();}
function apply(result){boxes.value=result.boxes;if(props.liveFeedback&&boxes.value.some(committedLetterWrong))emit('committed-error');active.value=result.cursor;emit('update:modelValue',boxesComplete(boxes.value)?boxAnswer(boxes.value):'');nextTick(locate);}
function commit(text){if(!props.disabled&&text)apply(editBoxes(boxes.value,active.value,text));if(input.value)input.value.value='';}
const composition=compositionBuffer(commit,text=>{draft.value=text;});
function startComposition(){composition.start();if(boxesComplete(boxes.value))emit('update:modelValue','');}
function endComposition(event){composition.end(event);emit('update:modelValue',boxesComplete(boxes.value)?boxAnswer(boxes.value):'');}
function key(event){if(event.isComposing||composition.active()||event.keyCode===229){if(event.key==='Enter')event.preventDefault();return;}if(props.disabled)return;composition.key();if(event.key==='Backspace'){event.preventDefault();keyDeletion=true;apply(eraseBox(boxes.value,active.value));}else if(event.key==='ArrowRight'||event.key==='ArrowLeft'){event.preventDefault();const step=(event.key==='ArrowRight'?1:-1)*(textDirection.value==='rtl'?-1:1);let index=active.value+step;while(boxes.value[index]?.fixed)index+=step;if(boxes.value[index])choose(index);}}
function beforeInput(event){if(event.inputType==='deleteContentBackward'&&!composition.active()&&!props.disabled){event.preventDefault();if(!keyDeletion)apply(eraseBox(boxes.value,active.value));keyDeletion=false;}}
function paste(event){event.preventDefault();if(composition.active())return;composition.key();const text=event.clipboardData.getData('text');if(graphemes(text.normalize('NFKC'))?.length===boxes.value.length)active.value=0;commit(text);}
onMounted(()=>{locate();if(globalThis.ResizeObserver&&area.value){observer=new ResizeObserver(locate);observer.observe(area.value);}});onBeforeUnmount(()=>{observer?.disconnect();composition.dispose();});
</script>
<template>
<div class="letter-answer">
<span class="answer-label">{{t('Your answer')}}</span>
<template v-if="supported">
<p :id="descriptionId" class="muted small">{{t('Tap a blank box and type. Given letters stay fixed; spaces and punctuation are already included.')}}</p>
<div ref="area" class="letter-area" :dir="textDirection">
<template v-for="(box,index) in displayedBoxes" :key="index"><span v-if="box.fixed" :class="['letter-cell','fixed',{'separator':/\s/u.test(box.text)}]" :aria-label="t('Given: {letter}',{letter:box.text})">{{box.text}}</span><button v-else type="button" :data-cell="index" tabindex="-1" :disabled="disabled" :class="['letter-cell',{'active':index===active},liveFeedback&&!draft?letterState(box):'']" :aria-label="t('Letter {number}: {letter}',{number:index+1,letter:box.value||t('blank')})+(liveFeedback&&!draft&&letterState(box)?': '+t(letterState(box)==='incorrect'?'Incorrect placement':'Correct placement'):'')" @click="choose(index)">{{box.value||' '}}<span v-if="liveFeedback&&!draft&&letterState(box)" class="placement-mark" aria-hidden="true">{{letterState(box)==='incorrect'?'×':'✓'}}</span></button></template>
<input ref="input" class="letter-entry" :style="position" :disabled="disabled" :aria-describedby="descriptionId" :aria-label="t('Letter {number}: {letter}',{number:active+1,letter:boxes[active]?.value||t('blank')})+(liveFeedback&&!draft&&letterState(boxes[active])?': '+t(letterState(boxes[active])==='incorrect'?'Incorrect placement':'Correct placement'):'')" autocomplete="off" autocapitalize="off" spellcheck="false" :dir="textDirection" @keydown="key" @keyup="keyDeletion=false" @beforeinput="beforeInput" @input="composition.input" @compositionstart="startComposition" @compositionupdate="composition.update" @compositionend="endComposition" @paste="paste"/>
</div>
<p class="answer-preview sr-only" dir="auto" aria-live="polite">{{boxAnswer(displayedBoxes)}}</p>
</template>
<p v-if="!supported" class="muted small">{{t('Use the text field for this answer. Letter boxes are unavailable for very long answers or this browser.')}}</p>
<p v-if="!supported&&liveFeedback" class="muted small">{{t('Live letter feedback is unavailable in this text field; the answer is checked when submitted.')}}</p>
<textarea v-if="!supported" :value="modelValue" @input="emit('update:modelValue',$event.target.value)" :disabled="disabled" dir="auto" rows="3" :aria-label="t('Your answer')"/>
</div>
</template>
<style scoped>
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.answer-label{font-weight:600}.letter-area{position:relative;display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;unicode-bidi:isolate;max-width:100%;margin:1rem 0}.letter-cell{display:inline-flex;align-items:center;justify-content:center;min-width:2.5rem;min-height:2.75rem;padding:.3rem;border:1px solid var(--practice-border,#bacbc8);border-radius:.5rem;background:white;color:var(--practice-ink,var(--ink,#173a39));font-size:1.2rem;white-space:pre-wrap;overflow-wrap:anywhere}.letter-cell.fixed{background:var(--practice-soft,#e7efed);border-style:dashed}.letter-cell.separator{min-width:.7rem;border:0;background:transparent}.letter-cell.active{outline:2px solid var(--practice-focus,#247d70);outline-offset:1px}.letter-entry{position:absolute!important;min-width:0!important;padding:0!important;margin:0!important;opacity:0;z-index:2;font-size:18px;caret-color:transparent}.letter-area:focus-within .letter-cell.active{outline-width:3px}.letter-cell:disabled{opacity:.7}.letter-answer textarea{unicode-bidi:plaintext;text-align:start}.letter-answer textarea:focus-visible{outline:3px solid var(--practice-focus,#247d70);outline-offset:2px}
@media(max-width:600px){.letter-area{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:.25rem;width:100%}.letter-cell,.letter-cell.separator{min-width:0;width:100%;box-sizing:border-box;padding:.15rem;min-height:44px;font-size:1.05rem}.letter-cell{overflow-wrap:anywhere}.letter-answer{min-width:0;max-width:100%}}
.letter-cell{position:relative}.letter-cell.correct{border:2px solid var(--practice-accent,#17644d);background:var(--practice-soft,#e9f7ef)}.letter-cell.incorrect{border:2px solid #a42f36;background:#fff0f1}.placement-mark{position:absolute;right:1px;bottom:0;font-size:.7rem;font-weight:bold}
</style>
