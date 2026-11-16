// One visible-time countdown; cancellation invalidates already queued callbacks.
export function createSkipRevealTimer({onTick,onDone,now=()=>performance.now(),setTimer=setTimeout,clearTimer=clearTimeout}){
 let generation=0,timer=null,remaining=0,last=0,paused=false,active=false,key;
 const clear=()=>{if(timer!==null)clearTimer(timer);timer=null;};
 const consume=()=>{const current=now();remaining=Math.max(0,remaining-(current-last));last=current;};
 function schedule(token){timer=setTimer(()=>{if(token!==generation||!active||paused)return;consume();onTick(Math.ceil(remaining/1000));if(remaining===0){active=false;timer=null;onDone(key);return;}schedule(token);},Math.min(250,remaining));}
 return {
  start(id,hidden=false){clear();generation++;key=id;remaining=5000;last=now();paused=hidden;active=true;onTick(5);if(!paused)schedule(generation);},
  visibility(hidden){if(!active||paused===hidden)return;generation++;if(hidden){consume();paused=true;clear();onTick(Math.ceil(remaining/1000));}else{paused=false;last=now();schedule(generation);}},
  cancel(){clear();generation++;active=false;remaining=0;}
 };
}
