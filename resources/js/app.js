import './passkeys.js';

// PWA install prompt — captured here, triggered from anywhere via window.pwaInstall()
let deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredInstallPrompt = e;
    window.dispatchEvent(new CustomEvent('pwa-installable'));
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    window.dispatchEvent(new CustomEvent('pwa-installed'));
});

window.pwaInstall = () => {
    if (!deferredInstallPrompt) return;
    deferredInstallPrompt.prompt();
    deferredInstallPrompt.userChoice.then(() => { deferredInstallPrompt = null; });
};

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Capture whether a SW was already controlling this page before registering.
        // If yes, any new SW that activates is an update — reload to get fresh assets.
        const hadController = !!navigator.serviceWorker.controller;

        navigator.serviceWorker.register('/sw.js').then(reg => {
            reg.addEventListener('updatefound', () => {
                const incoming = reg.installing;
                incoming.addEventListener('statechange', () => {
                    if (incoming.state === 'activated' && hadController) {
                        window.location.reload();
                    }
                });
            });
        });
    });
}

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
