import {selectableLocalVoices} from './speech.js';
function tag(value){return String(value??'').trim().replaceAll('_','-').toLowerCase();}
export function voiceLanguageScore(voice,language){
 const desired=tag(language),actual=tag(voice.lang);
 if(!desired||!actual)return 0;
 return actual===desired?2:actual.split('-')[0]===desired.split('-')[0]?1:0;
}
// Suggestions only: never replace the user's saved selection or enable speech.
export function suggestedLocalVoices(voices,language){
 return selectableLocalVoices(voices).map((voice,index)=>({voice,index,score:voiceLanguageScore(voice,language)}))
  .sort((a,b)=>b.score-a.score||a.index-b.index).map(item=>item.voice);
}
