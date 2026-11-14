// Never select a network-backed voice or let the browser choose a fallback voice.
export function localVoices(synthesis) {
  return (synthesis?.getVoices?.() ?? []).filter(voice => voice.localService === true);
}

const identityPrefix='vocamilo-voice-v2:';
export function voiceIdentity(voice){return identityPrefix+JSON.stringify([voice.voiceURI,voice.name??'',voice.lang??'']);}
export function resolveLocalVoice(voices,selection){
 if(!selection)return null;
 const matches=voices.filter(v=>v.localService===true&&(selection.startsWith(identityPrefix)?voiceIdentity(v)===selection:v.voiceURI===selection));
 return matches.length===1?matches[0]:null;
}
export function selectableLocalVoices(voices){return voices.filter(v=>resolveLocalVoice(voices,voiceIdentity(v)));}
// Refresh asynchronous inventories and settings changed while the page was away.
export function observeVoiceChanges(synthesis,win,doc,refresh){
 const visible=()=>{if(doc?.visibilityState!=='hidden')refresh();};
 synthesis?.addEventListener('voiceschanged',refresh);
 for(const event of ['focus','pageshow','storage'])win?.addEventListener(event,refresh);
 doc?.addEventListener('visibilitychange',visible);
 refresh();
 return ()=>{synthesis?.removeEventListener('voiceschanged',refresh);for(const event of ['focus','pageshow','storage'])win?.removeEventListener(event,refresh);doc?.removeEventListener('visibilitychange',visible);};
}

export function createLocalSpeech(synthesis, Utterance) {
  let disposed = false;
  return {
    supported: Boolean(synthesis && Utterance),
    cancel() { synthesis?.cancel(); },
    speak(text, voiceURI) {
      synthesis?.cancel();
      if (disposed || !synthesis || !Utterance || !String(text).trim()) return false;
      const voice = resolveLocalVoice(localVoices(synthesis),voiceURI);
      if (!voice) return false;
      const utterance = new Utterance(String(text));
      utterance.voice = voice;
      utterance.lang = voice.lang;
      synthesis.speak(utterance);
      return true;
    },
    dispose() { disposed = true; synthesis?.cancel(); },
  };
}
