export function safeDirection(value){return value==='rtl'?'rtl':'ltr';}
export function suggestedDirection(language){
 const parts=String(language??'').toLowerCase().split('-');
 if(parts.includes('latn'))return 'ltr';
 return parts.includes('arab')||parts.includes('hebr')||['ar','he','fa','ur'].includes(parts[0])?'rtl':'ltr';
}
export function languageDirection(language,current,manuallyChosen){return manuallyChosen?safeDirection(current):suggestedDirection(language);}
