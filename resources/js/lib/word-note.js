export async function wordNote(url, {note, signal} = {}) {
 const writing=note!==undefined;
 const token=document.cookie.split('; ').find(value=>value.startsWith('XSRF-TOKEN='))?.slice(11);
 const response=await fetch(url,{method:writing?'PUT':'GET',credentials:'same-origin',signal,headers:{Accept:'application/json',...(writing?{'Content-Type':'application/json',...(token?{'X-XSRF-TOKEN':decodeURIComponent(token)}:{})}:{})},...(writing?{body:JSON.stringify({note})}:{})});
 if(!response.ok)throw Error(response.status===419?'Your sign-in session expired. Keep this page open and sign in in another tab, then retry.':'Your note could not be loaded or saved. Check your access and connection, then try again.');
 return response.json();
}
