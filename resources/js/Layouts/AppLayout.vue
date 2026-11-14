<script setup>
import { useI18n } from "../lib/i18n.js";
import InstallApp from "../Components/InstallApp.vue";
import LanguageSwitch from "../Components/LanguageSwitch.vue";
const {t,locale}=useI18n();
import { Link, usePage } from '@inertiajs/vue3';
defineProps({title:String,subtitle:String,shared:Boolean});
const page=usePage();
const active=(path)=>page.url.split('?')[0]===path || (path!=='/dashboard' && page.url.startsWith(path+'/'));
</script>
<template>
<div class="app-shell">
<aside class="sidebar">
<div class="sidebar-brand"><Link :href="shared ? '/' : '/dashboard'" class="brand"><span class="brand-symbol">v.</span>vocamilo<span class="brand-dot">●</span></Link></div>
<div class="sidebar-caption">{{t("A LITTLE EVERY DAY")}}</div>
<nav v-if="!shared && page.props.auth?.user" :aria-label="t('Main navigation')">
<Link href="/dashboard" :class="{active:active('/dashboard')}"><span>◫</span> {{t("Overview")}}</Link>
<Link href="/words" :class="{active:active('/words')||active('/word-pools')}"><span>≋</span> {{t("Word pools")}}</Link>
<Link href="/collections" :class="{active:active('/collections')}"><span>▤</span> {{t("Collections")}}</Link>
<Link href="/account/disclosure">{{t("Privacy choices")}}</Link><Link v-if="page.props.auth?.user?.is_admin" href="/admin/users" :class="{active:active('/admin')}">{{t("Administration")}}</Link><Link href="/logout" method="post" as="button" class="mobile-logout">{{t("Sign out")}}</Link></nav>
<div v-else class="sidebar-public"><span class="pill">{{t("SHARED COLLECTION")}}</span><p>{{t("A good word is worth sharing.")}}</p><Link href="/register" class="button secondary">{{t("Create your own space")}}</Link></div>
<div class="sidebar-bottom"><InstallApp/><div class="small-label">{{t("MAKE ROOM FOR NEW WORDS.")}}</div><p>{{t("Learn a little.")}}<br>{{t("Remember a lot.")}}</p><div class="sidebar-language"><LanguageSwitch/></div><Link v-if="page.props.auth?.user" href="/logout" method="post" as="button" class="logout">{{t('Sign out')}} · {{ page.props.auth.user.name }}</Link></div>
</aside>
<main class="main"><header class="page-heading"><div><div class="eyebrow">{{t("YOUR WORDS, YOUR WAY")}}</div><h1>{{ title }}</h1><p v-if="subtitle" class="muted">{{ subtitle }}</p></div><div class="header-actions"><slot name="actions" /></div></header>
<div v-if="page.props.flash?.success" role="status" class="notice success">{{ page.props.flash.success }}</div>
<div v-if="page.props.flash?.error" role="alert" class="notice error">{{ page.props.flash.error }}</div>
<slot />
<footer class="footer">Vocamilo <span>{{t("Small steps. Lasting knowledge.")}}</span></footer>
</main></div>
</template>

<style scoped>
.header-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.auth-language{display:flex;justify-content:flex-end;margin-bottom:1rem}@media(max-width:760px){.header-actions{width:100%;align-items:stretch;flex-direction:column}.header-actions :deep(.language-switch){align-self:flex-end}.header-actions :deep(.actions){flex-direction:column;align-items:stretch;width:100%}.header-actions :deep(.button){width:100%}}

.sidebar-brand{display:flex;align-items:center}
.sidebar-language{margin-bottom:16px}
@media(max-width:760px){.sidebar-brand{min-height:38px;padding-right:100px;width:100%}.sidebar-brand .brand{font-size:22px;letter-spacing:-1px}.sidebar-bottom{display:contents}.sidebar-bottom>.small-label,.sidebar-bottom>p,.sidebar-bottom>.logout{display:none}.sidebar-language{position:absolute;top:18px;right:20px;margin:0}}
@media(max-width:360px){.sidebar-brand .brand{font-size:20px;gap:6px}.sidebar-brand .brand-symbol{width:30px;height:34px;font-size:25px}.sidebar-brand .brand-dot{display:none}}
</style>
