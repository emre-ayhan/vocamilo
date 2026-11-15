export function graphemes(text, Segmenter = globalThis.Intl?.Segmenter) {
  return Segmenter ? [...new Segmenter(undefined, { granularity: 'grapheme' }).segment(String(text))].map(part => part.segment) : null;
}
export function makeLetterBoxes(expected, Segmenter = globalThis.Intl?.Segmenter) {
  const parts = graphemes(String(expected).normalize('NFKC'), Segmenter);
  if (!parts) return null;
  const letters = parts.map((text, index) => /[\p{L}\p{N}]/u.test(text) ? index : -1).filter(index => index >= 0);
  const count = Math.min((letters.length >= 3 ? Math.ceil(letters.length * 0.4) : 0), Math.max(0, letters.length - 1));
  const hints = new Set(Array.from({length:count}, (_, i) => letters[Math.floor((i + 1) * letters.length / (count + 1))]));
  return parts.map((text, index) => ({ text, fixed: !letters.includes(index) || hints.has(index), value: !letters.includes(index) || hints.has(index) ? text : '' }));
}
export function editBoxes(boxes, start, text) {
  const next = boxes.map(box => ({...box}));
  const parts = graphemes(String(text).normalize('NFKC')) ?? [];
  let cursor = start;
  for (const part of parts) {
    // Mobile keyboards may deliver marks/conjunct continuations separately.
    let previous = cursor - 1;
    while (previous >= 0 && next[previous].fixed) previous--;
    if (cursor === next.findLastIndex(box => !box.fixed) && next[cursor]?.value) previous = cursor;
    const prior = next[previous];
    if (prior?.value && graphemes(prior.value + part)?.length === 1) {
      prior.value = (prior.value + part).normalize('NFKC');
      continue;
    }
    // Separators are supplied by the template, including after auto-advance.
    if (!/[\p{L}\p{N}\p{M}]/u.test(part)) {
      if (next[cursor]?.fixed && next[cursor].text === part) cursor++;
      continue;
    }
    if (/^\p{M}/u.test(part)) continue;
    let matchedFixed = false;
    while (cursor < next.length && next[cursor].fixed) {
      const matches = next[cursor++].text.normalize('NFKC').toLowerCase() === part.normalize('NFKC').toLowerCase();
      if (matches) { matchedFixed = true; break; }
    }
    if (matchedFixed) continue;
    if (cursor >= next.length) break;
    next[cursor++].value = part;
  }
  while (cursor < next.length && next[cursor].fixed) cursor++;
  if (cursor >= next.length) cursor = next.findLastIndex(box => !box.fixed);
  return {boxes:next, cursor};
}
export function eraseBox(boxes, cursor) {
  const next = boxes.map(box => ({...box}));
  if (!next[cursor]?.value) { do { cursor--; } while(cursor >= 0 && next[cursor].fixed); }
  if (cursor < 0) return {boxes:next,cursor:boxes.findIndex(box=>!box.fixed)};
  next[cursor].value='';
  return {boxes:next,cursor};
}
export function boxAnswer(boxes) { return boxes.map(box => box.value || '□').join(''); }
export function compositionBuffer(commit, preview = () => {}) {
  let composing=false, pendingFinal=null, sameTurn=false, timer;
  return {
    active(){return composing;},
    start(){clearTimeout(timer);composing=true;pendingFinal=null;sameTurn=false;preview('');},
    update(event){if(composing)preview(event.data??event.target?.value??'');},
    key(){if(!composing)pendingFinal=null;},
    input(event){
      if(composing||event.isComposing){preview(event.target.value);return;}
      const value=event.target.value;
      if(pendingFinal!==null&&value===pendingFinal&&(sameTurn||event.inputType==='insertFromComposition')){pendingFinal=null;event.target.value='';return;}
      pendingFinal=null;if(value)commit(value);
    },
    end(event){composing=false;pendingFinal=event.target.value;sameTurn=true;commit(pendingFinal);preview('');event.target.value='';clearTimeout(timer);timer=setTimeout(()=>{sameTurn=false;},0);},
    dispose(){clearTimeout(timer);pendingFinal=null;preview('');}
  };
}
export function answerDirection(text,fallback='ltr') {
  const letter=graphemes(text)?.find(part=>/\p{L}/u.test(part));
  return letter ? (/[\p{Script=Arabic}\p{Script=Hebrew}\p{Script=Syriac}\p{Script=Thaana}]/u.test(letter)?'rtl':'ltr') : (fallback==='rtl'?'rtl':'ltr');
}

export function boxesComplete(boxes) {return boxes.every(box=>box.fixed||box.value!=='');}
