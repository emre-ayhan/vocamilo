import { ref, shallowRef } from 'vue';
export const installPrompt = shallowRef(null);
export const appInstalled = ref(false);
let initialized = false;
export function initializeInstall(target = window) {
    if (initialized) return;
    initialized = true;
    const standalone = target.matchMedia('(display-mode: standalone)');
    appInstalled.value = standalone.matches || target.navigator.standalone === true;
    standalone.addEventListener?.('change', event => { appInstalled.value = event.matches; });
    target.addEventListener('beforeinstallprompt', event => { event.preventDefault(); installPrompt.value = event; });
    target.addEventListener('appinstalled', () => { appInstalled.value = true; installPrompt.value = null; });
}
