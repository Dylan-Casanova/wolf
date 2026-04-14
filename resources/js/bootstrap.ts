import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

window.Pusher = Pusher;

if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
