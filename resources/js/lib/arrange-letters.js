import {graphemes} from './letter-boxes.js';
import {normalizeAnswer,shuffle} from './study.js';
export function makeArrangement(expected,random=Math.random){
 const parts=graphemes(String(expected).normalize('NFKC'));
 if(!parts)return null;
 const slots=parts.map(text=>({text,fixed:!/[\p{L}\p{N}]/u.test(text),token:null}));
 const original=slots.flatMap((slot,index)=>slot.fixed?[]:[{id:index,text:slot.text}]);
 let tokens=shuffle(original,random);
 if(new Set(tokens.map(token=>token.text)).size>1&&tokens.every((token,index)=>token.text===original[index].text))tokens=[...tokens.slice(1),tokens[0]];
 return {slots,tokens};
}
export function placeToken(state,id){if(state.slots.some(slot=>slot.token===id))return state;const index=state.slots.findIndex(slot=>!slot.fixed&&slot.token===null);if(index<0||!state.tokens.some(token=>token.id===id))return state;return {...state,slots:state.slots.map((slot,i)=>i===index?{...slot,token:id}:slot)};}
export function returnToken(state,index){return {...state,slots:state.slots.map((slot,i)=>i===index&&!slot.fixed?{...slot,token:null}:slot)};}
export function arrangedBoxes(state){return state.slots.map(slot=>({...slot,value:slot.fixed?slot.text:state.tokens.find(token=>token.id===slot.token)?.text??''}));}
export function committedLetterWrong(box){if(box.fixed||!box.value)return false;const expected=normalizeAnswer(box.text),value=normalizeAnswer(box.value);return expected!==value&&!expected.normalize('NFD').startsWith(value.normalize('NFD'));}
export function letterState(box){return !box.value||box.fixed?'':committedLetterWrong(box)?'incorrect':normalizeAnswer(box.text)===normalizeAnswer(box.value)?'correct':'';}

export function tileWrong(box){return !box.fixed&&!!box.value&&normalizeAnswer(box.text)!==normalizeAnswer(box.value);}
export function tileState(box){return !box.value||box.fixed?'':tileWrong(box)?'incorrect':'correct';}
