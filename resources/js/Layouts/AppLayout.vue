<script setup>
import { useI18n } from "../lib/i18n.js";
import AppIcon from "../Components/AppIcon.vue";
import ProfileMenu from "../Components/ProfileMenu.vue";
import LanguageSwitch from "../Components/LanguageSwitch.vue";
const {t,locale}=useI18n();
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { createDrawerFocus } from '../lib/drawer-focus.js';
import { Link, usePage } from '@inertiajs/vue3';
defineProps({title:String,subtitle:String,shared:Boolean});
const page=usePage();
const drawerOpen=ref(false),mobile=ref(false),drawer=ref(null),menuTrigger=ref(null);
let media,focusManager;
function closeDrawer(){drawerOpen.value=false;}
function mediaChanged(){mobile.value=!!media?.matches;closeDrawer();}
onMounted(()=>{
 media=window.matchMedia('(max-width:760px)');mediaChanged();
 if(media.addEventListener)media.addEventListener('change',mediaChanged);else media.addListener(mediaChanged);
 focusManager=createDrawerFocus(document,closeDrawer);
});
watch(drawerOpen,async open=>{await nextTick();if(open&&drawerOpen.value&&mobile.value)focusManager?.activate(drawer.value,menuTrigger.value);else focusManager?.deactivate();});
watch(()=>page.url,closeDrawer);
onBeforeUnmount(()=>{focusManager?.deactivate(false);if(media?.removeEventListener)media.removeEventListener('change',mediaChanged);else media?.removeListener(mediaChanged);});
const active=(path)=>page.url.split('?')[0]===path || (path!=='/dashboard' && page.url.startsWith(path+'/'));
</script>
<template>
<div class="app-shell">
<div v-if="drawerOpen && mobile" class="drawer-backdrop" aria-hidden="true" @click="closeDrawer"></div>
<aside id="mobile-navigation" ref="drawer" class="sidebar" :class="{'drawer-open':drawerOpen}" :role="mobile&&drawerOpen?'dialog':undefined" :aria-modal="mobile&&drawerOpen?'true':undefined" :aria-label="mobile?t('Menu'):undefined" :inert="mobile&&!drawerOpen?true:undefined" tabindex="-1">
<button type="button" class="drawer-close" :aria-label="t('Close menu')" @click="closeDrawer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
<div class="sidebar-brand"><Link :href="shared ? '/' : '/dashboard'" class="brand" @click="closeDrawer"><span class="brand-symbol">v.</span>vocamilo<span class="brand-dot">●</span></Link></div>
<div class="sidebar-caption">{{t("A LITTLE EVERY DAY")}}</div>
<nav v-if="!shared && page.props.auth?.user" :aria-label="t('Main navigation')" @click="closeDrawer">
<Link href="/dashboard" :class="{active:active('/dashboard')}"><AppIcon name="overview"/> {{t("Overview")}}</Link>
<Link href="/words" :class="{active:active('/words')||active('/word-pools')}"><AppIcon name="words"/> {{t("Word pools")}}</Link>
<Link href="/collections" :class="{active:active('/collections')}"><AppIcon/> {{t("Collections")}}</Link>
<Link v-if="page.props.auth?.user?.is_admin" href="/admin/users" :class="{active:active('/admin')}"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3 4 6v6c0 4 8 9 8 9s8-5 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/></svg>{{t("Administration")}}</Link></nav>
<div v-else class="sidebar-public"><span class="pill">{{t("SHARED COLLECTION")}}</span><p>{{t("A good word is worth sharing.")}}</p><Link href="/register" class="button secondary">{{t("Create your own space")}}</Link></div>
<div class="sidebar-bottom"><div class="small-label">{{t("MAKE ROOM FOR NEW WORDS.")}}</div><p>{{t("Learn a little.")}}<br>{{t("Remember a lot.")}}</p><ProfileMenu v-if="page.props.auth?.user" :key="drawerOpen"/><div v-else class="sidebar-language"><LanguageSwitch/></div></div>
</aside>
<main class="main" :inert="mobile&&drawerOpen?true:undefined">
<div class="mobile-menu-bar"><Link :href="shared?'/' : '/dashboard'" class="brand"><span class="brand-symbol">v.</span>vocamilo</Link><div class="mobile-toolbar"><button type="button" class="menu-trigger" ref="menuTrigger" :aria-label="t('Open menu')" aria-controls="mobile-navigation" :aria-expanded="drawerOpen" @click="drawerOpen=true"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button></div></div><header class="page-heading"><div><div class="eyebrow">{{t("YOUR WORDS, YOUR WAY")}}</div><h1>{{ title }}</h1><p v-if="subtitle" class="muted">{{ subtitle }}</p></div><div class="header-actions"><slot name="actions" /></div></header>
<div v-if="page.props.flash?.success" role="status" class="notice success">{{ page.props.flash.success }}</div>
<div v-if="page.props.flash?.error" role="alert" class="notice error">{{ page.props.flash.error }}</div>
<slot />
<footer class="footer app-footer"><div class="footer-brand"><strong>Vocamilo</strong><span>{{t("Small steps. Lasting knowledge.")}}</span></div><Link v-if="page.props.auth?.user" href="/account/disclosure" class="footer-privacy">{{t('Privacy choices')}}</Link></footer>
</main></div>
</template>

<style scoped>
.header-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.sidebar-brand{display:flex;align-items:center}.sidebar-language{margin-bottom:16px}.nav-icon{width:20px;height:20px;flex-shrink:0}
.mobile-menu-bar,.drawer-close,.drawer-backdrop{display:none}.sidebar{overflow-y:auto}.app-footer{display:flex;align-items:center;justify-content:space-between;gap:20px}.footer-brand{display:flex;flex-direction:column;gap:6px}.footer-brand strong{font-size:14px}.footer-brand span{font-size:11px;line-height:1.5}.footer-privacy{font-size:12px;padding:12px 0;min-height:44px;display:flex;align-items:center;color:#42635e}
@media(max-width:760px){
.header-actions{width:100%;align-items:stretch;flex-direction:column}.header-actions :deep(.actions){flex-direction:column;align-items:stretch;width:100%}.header-actions :deep(.button){width:100%}
.mobile-menu-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:-28px -20px 28px;padding:16px 20px;background:var(--navy);color:#eaf4ed}.mobile-menu-bar .brand{font-size:22px;color:#eaf4ed}.mobile-toolbar{display:flex;align-items:center;gap:8px}.sidebar-language :deep(.language-switch button){padding:8px;min-height:44px}.menu-trigger,.drawer-close{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:44px;min-height:44px;padding:10px;border:1px solid #526f77;border-radius:9px;background:#25424c;color:#eaf4ed}.menu-trigger svg,.drawer-close svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.drawer-backdrop{display:block;position:fixed;inset:0;background:#07192399;z-index:99}.sidebar{display:flex;position:fixed;inset:0 auto 0 0;width:min(320px,90vw);max-height:100dvh;height:100vh;height:100dvh;padding:24px;z-index:100;transform:translateX(-100%);visibility:hidden;transition:transform .2s ease,visibility .2s;overscroll-behavior:contain}.sidebar.drawer-open{transform:translateX(0);visibility:visible}.drawer-close{align-self:flex-end;margin-bottom:16px;background:#25424c;color:#fff;border-color:#526f77;flex-shrink:0}.sidebar-brand{width:100%;padding:0;min-height:38px}.sidebar-brand .brand{font-size:23px}.sidebar-caption{display:block;margin:20px 0 24px}.sidebar nav{display:grid;overflow:visible;gap:8px;margin-top:0}.sidebar nav a{min-height:44px;padding:12px 14px;font-size:14px;gap:12px;white-space:normal}.sidebar nav a span{font-size:20px;width:20px}.sidebar .mobile-logout{display:none}.sidebar-bottom{display:block;margin-top:auto;padding-top:28px}.sidebar-bottom>.logout{display:block;min-height:44px;max-width:100%;overflow-wrap:anywhere;line-height:1.5;padding:12px 0}.sidebar-language{position:static;margin:16px 0}.sidebar-public{display:block;padding-top:0}.sidebar-public .button{white-space:normal;min-height:44px}.app-footer{align-items:flex-start;flex-wrap:wrap}.footer-privacy{margin-left:auto}.footer-brand{max-width:100%}
}
@media(prefers-reduced-motion:reduce){.sidebar{transition:none}}
</style>
