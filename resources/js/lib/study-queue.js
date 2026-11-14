// Each occurrence is unique, even when a one-word collection repeats immediately.
export function createStudyQueue(questions) {
    return questions.map(question => ({ ...question, attempt_no: 1, occurrence_id: `${question.id}:1` }));
}
export function appendStudyRepeat(queue, occurrence, correct, firstWrong, rulesVersion = 3) {
    const repeat = rulesVersion === 2
        ? (occurrence.attempt_no === 1 ? !correct : firstWrong && (occurrence.attempt_no < 3 || !correct))
        : !correct && occurrence.attempt_no < 3;
    if (!repeat) return queue;
    const attempt = occurrence.attempt_no + 1;
    // Append after all remaining questions to space repeats whenever possible.
    return [...queue, { ...occurrence, attempt_no: attempt, occurrence_id: `${occurrence.id}:${attempt}` }];
}
export function studyAttemptPayload(attempts) {
    return attempts.map(({id,answer,self_assessed,attempt_no}) => ({word_id:id,answer,self_assessed:!!self_assessed,attempt_no}));
}
export function prioritizeGuestWords(words, learned) {
    return [...words].sort((a,b)=>Number(learned.has(a.id))-Number(learned.has(b.id)));
}
