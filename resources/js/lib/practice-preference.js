// Only setup choices, scoped to this browser, account, collection and mode.
export function normalizePracticeSettings(value, wordCount) {
 const input=value&&typeof value==='object'&&!Array.isArray(value)?value:{};
 const max=Math.max(0,Math.min(2000,wordCount));
 const direction=['term','definition'].includes(input.direction)?input.direction:'term';
 const type=['choice','written','rearrange'].includes(input.type)?input.type:'choice';
 const subset=(items,allowed,fallback)=>{
  const clean=Array.isArray(items)?[...new Set(items.filter(item=>allowed.includes(item)))]:[];
  return clean.length?clean:[fallback];
 };
 return {direction,type,order:['ordered','random'].includes(input.order)?input.order:'random',
  count:Number.isInteger(input.count)&&input.count>0?Math.min(input.count,max):Math.min(10,max),
  directions:subset(input.directions,['term','definition'],direction),types:subset(input.types,['choice','written','rearrange'],type)};
}
export function practicePreference(storage,collection,user,mode,shared=false) {
 const enabled=['study','test'].includes(mode);
 const key=`vocamilo:practice:v1:${user??'guest'}:${shared?'share':'collection'}:${shared?collection.share_uuid:collection.id}:${mode}`;
 return {
  read(defaults,wordCount){
   let value=defaults;
   if(enabled)try{const raw=storage?.getItem(key);if(raw){const saved=JSON.parse(raw);if(saved?.version===1)value=saved.settings;}}catch{}
   return normalizePracticeSettings(value,wordCount);
  },
  write(settings,wordCount){if(!enabled)return false;try{if(!storage)return false;storage.setItem(key,JSON.stringify({version:1,settings:normalizePracticeSettings(settings,wordCount)}));return true;}catch{return false;}},
 };
}
