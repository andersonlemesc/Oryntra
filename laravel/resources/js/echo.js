import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Reverb speaks the Pusher protocol. Connection details are injected at runtime
// via window.__ORYNTRA_REVERB__ (rendered server-side from config) so the built
// bundle is domain-agnostic; the VITE_REVERB_* values remain as a dev fallback
// for `npm run dev`, where import.meta.env is populated from .env.
const reverb = window.__ORYNTRA_REVERB__ ?? {};

const key = reverb.key ?? import.meta.env.VITE_REVERB_APP_KEY;
const host = reverb.host ?? import.meta.env.VITE_REVERB_HOST;
const port = reverb.port ?? import.meta.env.VITE_REVERB_PORT;
const scheme = reverb.scheme ?? import.meta.env.VITE_REVERB_SCHEME ?? 'https';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: port ?? 80,
    wssPort: port ?? 443,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});
