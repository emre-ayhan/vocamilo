// Device-local identifiers only; never vocabulary or enabled state.
export function voicePreference(storage, collection, user = 'guest') {
 const key=`vocamilo:voice:${user}:${collection}`;
 return {
  read(){try{return storage?.getItem(key)||'';}catch{return '';}},
  write(uri){try{if(!storage)return false;storage.setItem(key,uri);return true;}catch{return false;}},
 };
}
export function collectionVoicePreference(storage,collection,user='guest',shared=false){
 const preference=voicePreference(storage,shared?collection.share_uuid:collection.id,user);
 return {...preference,read(){
  // A stored empty string explicitly clears a voice; do not revive the legacy value.
  try{const key=`vocamilo:voice:${user}:${shared?collection.share_uuid:collection.id}`;
   if(storage?.getItem(key)!=null)return preference.read();
  }catch{}
  return shared&&collection.id!=null?voicePreference(storage,collection.id,user).read():preference.read();
 }};
}
