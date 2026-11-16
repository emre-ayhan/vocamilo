export function memberQuery(filters={},extra={}){return {tab:'members',member_q:String(filters.q??'').trim(),members_page:Math.max(1,Number(filters.page)||1),...extra};}
export function memberActionData(action,scope,collectionId){return {action,scope,...(scope==='selected'?{collection_id:collectionId}:{})};}
export async function previewMemberAction(userId,data,{signal,fetcher=fetch,cookie=document.cookie}={}){
 const token=cookie.split('; ').find(value=>value.startsWith('XSRF-TOKEN='))?.slice(11);
 const response=await fetcher(`/collection-members/${userId}/preview`,{method:'POST',credentials:'same-origin',signal,headers:{'Content-Type':'application/json',Accept:'application/json',...(token?{'X-XSRF-TOKEN':decodeURIComponent(token)}:{})},body:JSON.stringify(data)});
 if(!response.ok)throw new Error(response.status===419?'Your sign-in session expired. Keep this page open and sign in in another tab, then retry.':'Member access changed or the request failed. Refresh the member list and try again.');
 const preview=await response.json();
 if(!preview.token||!Number.isInteger(preview.count)||preview.count<1||preview.action!==data.action||preview.scope!==data.scope)throw new Error('No eligible collections are available for this action.');
 return preview;
}
