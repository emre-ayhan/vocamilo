export function normalizeAnswer(value) {return String(value??'').normalize('NFKC').trim().replace(/\s+/gu,' ').toLowerCase();}
export function isCorrect(answer,expected) {return normalizeAnswer(answer)===normalizeAnswer(expected);}
export function shuffle(items,random=Math.random){const result=[...items];for(let i=result.length-1;i>0;i--){const j=Math.floor(random()*(i+1));[result[i],result[j]]=[result[j],result[i]];}return result;}
export function createQuestions(words,settings={},random=Math.random){
 const directions=settings.directions?.length?settings.directions:[settings.direction??'term'];
 const types=settings.types?.length?settings.types:[settings.type??'written'];
 const combinations=directions.flatMap(direction=>types.map(type=>({direction,type})));
 let cycle=[];

 const ordered=settings.order==='ordered'?[...words]:shuffle(words,random);
 const limit=Math.max(1,Math.min(words.length,Number(settings.count)||words.length));
 return ordered.slice(0,limit).map((word,index)=>{
   if(index%combinations.length===0)cycle=shuffle(combinations,random);
   const variant=cycle[index%combinations.length];
   const answerKey=variant.direction==='definition'?'definition':'term';
   const promptKey=answerKey==='term'?'definition':'term';
   const expected=word[answerKey];
   const others=[...new Map(words.filter(w=>normalizeAnswer(w[answerKey])!==normalizeAnswer(expected)).map(w=>[normalizeAnswer(w[answerKey]),w[answerKey]])).values()];
   const options=shuffle([expected,...shuffle(others,random).slice(0,3)],random);
   const directionOf=value=>value===expected?word.text_direction:words.find(w=>normalizeAnswer(w[answerKey])===normalizeAnswer(value))?.text_direction;
   return {id:word.id,text_direction:word.text_direction==='rtl'?'rtl':'ltr',prompt:word[promptKey],expected,note:word.note??null,answerKey,
    options,option_directions:options.map(value=>directionOf(value)==='rtl'?'rtl':'ltr'),
    type:variant.type==='choice'&&others.length?'choice':'written'};
 });
}
export function score(results){const correct=results.filter(r=>r.correct).length;return {correct,total:results.length,percent:results.length?Math.round(correct/results.length*100):0};}

// Choice answers are checked internally immediately; Test keeps feedback hidden.
export function shouldAutoCheck(mode, type) { return (mode === 'study' || mode === 'test') && type === 'choice'; }
