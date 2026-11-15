// Each occurrence is unique, even when a one-word collection repeats immediately.
export function createStudyQueue(questions) {
    return questions.map(question => ({ ...question, attempt_no: 1, occurrence_id: `${question.id}:1` }));
}
export function appendStudyRepeat(queue, occurrence, correct, firstWrong, rulesVersion = 3, mode = 'study', skipped = false) {
    if(skipped)return queue;
    const studyRules=rulesVersion>=4&&mode!=='test';
    const correctCount=(occurrence.correct_count??0)+(correct?1:0);
    const repeat = studyRules
        ? correctCount < 2 && occurrence.attempt_no < 3
        : rulesVersion === 2
          ? (occurrence.attempt_no === 1 ? !correct : firstWrong && (occurrence.attempt_no < 3 || !correct))
          : !correct && occurrence.attempt_no < 3;
    if (!repeat) return queue;
    const attempt = occurrence.attempt_no + 1;
    // Append after all remaining questions to space repeats whenever possible.
    return [...queue, { ...occurrence, ...(studyRules?{correct_count:correctCount,type:occurrence.retry_type??occurrence.type}:{}), attempt_no: attempt, occurrence_id: `${occurrence.id}:${attempt}` }];
}
export function studyAttemptPayload(attempts,rulesVersion=3) {
    return attempts.map(({id,answer,self_assessed,attempt_no,had_error,skipped}) => ({word_id:id,answer,self_assessed:!!self_assessed,attempt_no,...(rulesVersion>=5?{had_error:!!had_error,skipped:!!skipped}:{})}));
}
export function prioritizeGuestWords(words, learned) {
    return [...words].sort((a,b)=>Number(learned.has(a.id))-Number(learned.has(b.id)));
}

// Guest-only transient display. Signed-in mastery always comes from the server.
export function completedGuestMastery(attempts){
 const groups=new Map();
 for(const attempt of attempts){const state=groups.get(attempt.id)??{word_id:attempt.id,priorQualified:attempt.prior_qualified===true,correct:0,wrong:false};state.correct+=attempt.correct?1:0;state.wrong ||= !attempt.correct;groups.set(attempt.id,state);}
 return [...groups.values()].map(state=>({word_id:state.word_id,stage:state.correct>=2&&state.priorQualified&&!state.wrong?'reinforced':'learning',qualified:state.correct>=2,applied:true}));
}

// Uses only completed rounds from this open guest page. Input questions retain their chosen order.
export function selectGuestStudyQuestions(questions,stages,count,completedRounds=0){
 const limit=Math.max(0,Math.min(questions.length,Number(count)||0));
 if(!limit)return [];
 const fresh=questions.filter(q=>stages.get(q.id)?.stage!=='reinforced').sort((a,b)=>Number(stages.get(a.id)?.qualified===true)-Number(stages.get(b.id)?.qualified===true));
 const review=questions.filter(q=>stages.get(q.id)?.stage==='reinforced').sort((a,b)=>(stages.get(a.id)?.last_review??0)-(stages.get(b.id)?.last_review??0));
 const quota=limit===1?((completedRounds+1)%5===0?1:0):Math.max(1,Math.round(limit*.2));
 const reviewCount=Math.min(review.length,Math.max(quota,limit-fresh.length));
 return [...fresh.slice(0,limit-reviewCount),...review.slice(0,reviewCount)];
}
