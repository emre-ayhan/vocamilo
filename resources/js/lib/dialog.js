import { onMounted,onUnmounted } from 'vue';
export function useDialogEscape(close) {const handler=e=>{if(e.key==='Escape')close();};onMounted(()=>window.addEventListener('keydown',handler));onUnmounted(()=>window.removeEventListener('keydown',handler));}
