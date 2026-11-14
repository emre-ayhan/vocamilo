// Device-specific voice identifiers only. Never store vocabulary or speech-enabled state.
export function voicePreference(storage, collection, user = 'guest') {
 const key = `vocamilo:voice:${user}:${collection}`;
 return {
  read() { try { return storage?.getItem(key) || ''; } catch { return ''; } },
  write(uri) { if (!uri) return; try { storage?.setItem(key, uri); } catch { /* Storage may be disabled. */ } },
 };
}
