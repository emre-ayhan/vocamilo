<script setup>
import { Link, usePage } from '@inertiajs/vue3';
defineProps({title:String,subtitle:String,shared:Boolean});
const page=usePage();
const active=(path)=>page.url.split('?')[0]===path || (path!=='/dashboard' && page.url.startsWith(path+'/'));
</script>
<template>
<div class="app-shell">
<aside class="sidebar">
<Link :href="shared ? '/' : '/dashboard'" class="brand"><span class="brand-symbol">v.</span>vocamilo<span class="brand-dot">●</span></Link>
<div class="sidebar-caption">A LITTLE EVERY DAY</div>
<nav v-if="!shared && page.props.auth?.user" aria-label="Main navigation">
<Link href="/dashboard" :class="{active:active('/dashboard')}"><span>◫</span> Overview</Link>
<Link href="/words" :class="{active:active('/words')}"><span>≋</span> Word pool</Link>
<Link href="/collections" :class="{active:active('/collections')}"><span>▤</span> Collections</Link>
<Link href="/imports" :class="{active:active('/imports')}"><span>↥</span> Import CSV</Link>
<Link href="/logout" method="post" as="button" class="mobile-logout">Sign out</Link></nav>
<div v-else class="sidebar-public"><span class="pill">SHARED COLLECTION</span><p>A good word is worth sharing.</p><Link href="/register" class="button secondary">Create your own space</Link></div>
<div class="sidebar-bottom"><div class="small-label">MAKE ROOM FOR NEW WORDS.</div><p>Learn a little.<br>Remember a lot.</p><Link v-if="page.props.auth?.user" href="/logout" method="post" as="button" class="logout">Sign out · {{ page.props.auth.user.name }}</Link></div>
</aside>
<main class="main"><header class="page-heading"><div><div class="eyebrow">YOUR WORDS, YOUR WAY</div><h1>{{ title }}</h1><p v-if="subtitle" class="muted">{{ subtitle }}</p></div><slot name="actions" /></header>
<div v-if="page.props.flash?.success" role="status" class="notice success">{{ page.props.flash.success }}</div>
<div v-if="page.props.flash?.error" role="alert" class="notice error">{{ page.props.flash.error }}</div>
<slot />
<footer class="footer">Vocamilo <span>Small steps. Lasting knowledge.</span></footer>
</main></div>
</template>
