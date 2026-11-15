import {makeLetterBoxes,graphemes} from './letter-boxes.js';
import {normalizeAnswer} from './study.js';
export function createFirstEntryTracker(){
 const entries=new Map(),pending=new Map();let previous=new Map();
 return {
  record(boxes,{strict=false,finalize=false}={}){
   const changed=boxes.flatMap((box,index)=>!box.fixed&&box.value&&previous.get(index)!==box.value?[index]:[]);
   for(const [index,value] of pending){const next=boxes[index]?.value??'';const continuation=next&&normalizeAnswer(next).normalize('NFD').startsWith(normalizeAnswer(value).normalize('NFD'));if(!continuation||(finalize&&!changed.includes(index))||changed.some(other=>other!==index)){entries.set(index,value);pending.delete(index);}}
   for(const index of changed){if(entries.has(index))continue;const box=boxes[index],value=box.value;const expected=normalizeAnswer(box.text),actual=normalizeAnswer(value);
    const prefix=!strict&&actual!==expected&&expected.normalize('NFD').startsWith(actual.normalize('NFD'));
    if(prefix&&!finalize)pending.set(index,value);else{entries.set(index,value);pending.delete(index);}
   }
   for(const [index,value] of pending){if(finalize||changed.some(other=>other!==index)){entries.set(index,value);pending.delete(index);}}
   previous=new Map(boxes.map((box,index)=>[index,box.value]));
   return [...entries].map(([index,value])=>({index,value})).sort((a,b)=>a.index-b.index);
  },
  entries(){return [...entries].map(([index,value])=>({index,value})).sort((a,b)=>a.index-b.index);}
 };
}
export function firstEntryAccuracy(entries,layout){
 if(!layout?.length)return null;
 const values=new Map(entries.map(entry=>[entry.index,entry.value]));
 if(layout.some(slot=>!values.has(slot.index)))return null;
 const correct=layout.filter(slot=>normalizeAnswer(values.get(slot.index))===normalizeAnswer(slot.expected)).length;
 return {correct,total:layout.length,percent:correct/layout.length*100,passed:correct*5>=layout.length*4};
}

export function localLetterLayout(expected){const boxes=makeLetterBoxes(expected);return {supported:!!boxes&&boxes.length<=240,parts:boxes?.map(box=>box.text)??[],written:boxes?.flatMap((box,index)=>box.fixed?[]:[{index,expected:box.text}])??[],rearrange:boxes?.flatMap((box,index)=>/[\p{L}\p{N}]/u.test(box.text)?[{index,expected:box.text}]:[])??[]};}

export function matchingLetterLayout(expected,layout){const parts=graphemes(String(expected).normalize('NFKC'));return !!layout?.supported&&!!parts&&Array.isArray(layout.parts)&&parts.length===layout.parts.length&&parts.every((part,index)=>part===layout.parts[index]);}
