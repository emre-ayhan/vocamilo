import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { initializeInstall, installPrompt, appInstalled } from './install-app.js';
test('installation is user-triggered, tracks browser events and never requests notification permission', () => {
    const events={};let prompted=0;
    const target={navigator:{},matchMedia:()=>({matches:false,addEventListener:(type,handler)=>events.display=handler}),addEventListener:(type,handler)=>events[type]=handler};
    initializeInstall(target);assert.equal(appInstalled.value,false);
    let prevented=false;const prompt={preventDefault:()=>prevented=true,prompt:()=>prompted++};
    events.beforeinstallprompt(prompt);assert.equal(prevented,true);assert.equal(installPrompt.value,prompt);assert.equal(prompted,0);
    events.appinstalled();assert.equal(appInstalled.value,true);assert.equal(installPrompt.value,null);
    const source=readFileSync(new URL('./install-app.js',import.meta.url),'utf8');
    assert.doesNotMatch(source,/serviceWorker|caches\.|indexedDB|Notification|PushManager/);
});
test('manifest has standalone same-origin identity and required icon dimensions',()=>{
    const manifest=JSON.parse(readFileSync(new URL('../../../public/manifest.webmanifest',import.meta.url),'utf8'));
    assert.equal(manifest.id,'/');assert.equal(manifest.start_url,'/');assert.equal(manifest.scope,'/');assert.equal(manifest.display,'standalone');
    for(const size of [192,512]){const icon=manifest.icons.find(i=>i.sizes===`${size}x${size}`&&i.purpose==='any');assert.ok(icon);const png=readFileSync(new URL('../../../public'+icon.src,import.meta.url));assert.equal(png.readUInt32BE(16),size);assert.equal(png.readUInt32BE(20),size);}
    assert.ok(manifest.icons.some(i=>i.purpose==='maskable'));
});

test('install button fills sidebar width immediately before footer heading',()=>{
    const layout=readFileSync(new URL('../Layouts/AppLayout.vue',import.meta.url),'utf8');
    const component=readFileSync(new URL('../Components/InstallApp.vue',import.meta.url),'utf8');
    assert.match(layout,/class="sidebar-bottom"><InstallApp\/><div class="small-label">/);
    assert.match(component,/\.install-app \.button\{width:100%/);
});
