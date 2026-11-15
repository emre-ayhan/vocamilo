<script setup>
import {ref,computed,nextTick,onMounted,onBeforeUnmount,useId} from 'vue';
import {useI18n} from '../lib/i18n.js';
import {makeLetterBoxes,editBoxes,eraseBox,boxAnswer,answerDirection,boxesComplete,graphemes,compositionBuffer} from '../lib/letter-boxes.js';
const props=defineProps({expected:{type:String,required:true},modelValue:{type:String,default:''},disabled:Boolean,direction:String});
const descriptionId=useId();
const emit=defineEmits(['update:modelValue']);const {t}=useI18n();
const boxes=ref(makeLetterBoxes(props.expected));const active=ref(boxes.value?.findIndex(box=>!box.fixed)??-1);
const input=ref(null),area=ref(null),position=ref({});let observer, keyDeletion=false;
const textDirection=computed(()=>answerDirection(props.expected,props.direction));
const supported=computed(()=>boxes.value&&active.value>=0&&boxes.value.length<=240);
function locate(){const cell=area.value?.querySelector(`[data-cell="${active.value}"]`);if(cell){const a=area.value.getBoundingClientRect(),b=cell.getBoundingClientRect();position.value={left:`${b.left-a.left}px`,top:`${b.top-a.top}px`,width:`${b.width}px`,height:`${b.height}px`};}}
async function choose(index){if(props.disabled)return;active.value=index;await nextTick();locate();input.value?.focus();}
function apply(result){boxes.value=result.boxes;active.value=result.cursor;emit('update:modelValue',boxesComplete(boxes.value)?boxAnswer(boxes.value):'');nextTick(locate);}
function commit(text){if(!props.disabled&&text)apply(editBoxes(boxes.value,active.value,text));if(input.value)input.value.value='';}
const composition=compositionBuffer(commit);
function key(event){if(event.isComposing||composition.active()||event.keyCode===229){if(event.key==='Enter')event.preventDefault();return;}if(props.disabled)return;composition.key();if(event.key==='Backspace'){event.preventDefault();keyDeletion=true;apply(eraseBox(boxes.value,active.value));}else if(event.key==='ArrowRight'||event.key==='ArrowLeft'){event.preventDefault();const step=(event.key==='ArrowRight'?1:-1)*(textDirection.value==='rtl'?-1:1);let index=active.value+step;while(boxes.value[index]?.fixed)index+=step;if(boxes.value[index])choose(index);}}
function beforeInput(event){if(event.inputType==='deleteContentBackward'&&!composition.active()&&!props.disabled){event.preventDefault();if(!keyDeletion)apply(eraseBox(boxes.value,active.value));keyDeletion=false;}}
function paste(event){event.preventDefault();composition.key();const text=event.clipboardData.getData('text');if(graphemes(text.normalize('NFKC'))?.length===boxes.value.length)active.value=0;commit(text);}
onMounted(()=>{locate();if(globalThis.ResizeObserver&&area.value){observer=new ResizeObserver(locate);observer.observe(area.value);}});onBeforeUnmount(()=>{observer?.disconnect();composition.dispose();});
</script>
<template>
<div class="letter-answer">
<span class="answer-label">{{t('Your answer')}}</span>
<template v-if="supported">
<p :id="descriptionId" class="muted small">{{t('Tap a blank box and type. Given letters stay fixed; spaces and punctuation are already included.')}}</p>
<div ref="area" class="letter-area" :dir="textDirection">
<template v-for="(box,index) in boxes" :key="index"><span v-if="box.fixed" :class="['letter-cell','fixed',{'separator':/\s/u.test(box.text)}]" :aria-label="t('Given: {letter}',{letter:box.text})">{{box.text}}</span><button v-else type="button" :data-cell="index" tabindex="-1" :disabled="disabled" :class="['letter-cell',{'active':index===active}]" :aria-label="t('Letter {number}: {letter}',{number:index+1,letter:box.value||t('blank')})" @click="choose(index)">{{box.value||' '}}</button></template>
<input ref="input" class="letter-entry" :style="position" :disabled="disabled" :aria-describedby="descriptionId" :aria-label="t('Letter {number}: {letter}',{number:active+1,letter:boxes[active]?.value||t('blank')})" autocomplete="off" autocapitalize="off" spellcheck="false" :dir="textDirection" @keydown="key" @keyup="keyDeletion=false" @beforeinput="beforeInput" @input="composition.input" @compositionstart="composition.start" @compositionend="composition.end" @paste="paste"/>
</div>
<p class="answer-preview" dir="auto" aria-live="polite">{{boxAnswer(boxes)}}</p>
</template>
<p v-if="!supported" class="muted small">{{t('Use the text field for this answer. Letter boxes are unavailable for very long answers or this browser.')}}</p>
<textarea v-if="!supported" :value="modelValue" @input="emit('update:modelValue',$event.target.value)" :disabled="disabled" dir="auto" rows="3" :aria-label="t('Your answer')"/>
</div>
</template>
<style scoped>
.answer-label{font-weight:600}.letter-area{position:relative;display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;unicode-bidi:isolate;max-width:100%;margin:1rem 0}.letter-cell{display:inline-flex;align-items:center;justify-content:center;min-width:2.5rem;min-height:2.75rem;padding:.3rem;border:1px solid #bacbc8;border-radius:.5rem;background:white;color:var(--ink,#173a39);font-size:1.2rem;white-space:pre-wrap;overflow-wrap:anywhere}.letter-cell.fixed{background:#e7efed;border-style:dashed}.letter-cell.separator{min-width:.7rem;border:0;background:transparent}.letter-cell.active{outline:2px solid #247d70;outline-offset:1px}.letter-entry{position:absolute!important;min-width:0!important;padding:0!important;margin:0!important;opacity:.025;z-index:2;font-size:18px;caret-color:transparent}.letter-area:focus-within .letter-cell.active{outline-width:3px}.letter-cell:disabled{opacity:.7}.letter-answer textarea{unicode-bidi:plaintext;text-align:start}
</style>
