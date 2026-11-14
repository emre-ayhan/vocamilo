// Never select a network-backed voice or let the browser choose a fallback voice.
export function localVoices(synthesis) {
  return (synthesis?.getVoices?.() ?? []).filter(voice => voice.localService === true);
}
export function createLocalSpeech(synthesis, Utterance) {
  let disposed = false;
  return {
    supported: Boolean(synthesis && Utterance),
    cancel() { synthesis?.cancel(); },
    speak(text, voiceURI) {
      synthesis?.cancel();
      if (disposed || !synthesis || !Utterance || !String(text).trim()) return false;
      const voice = localVoices(synthesis).find(item => item.voiceURI === voiceURI);
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
