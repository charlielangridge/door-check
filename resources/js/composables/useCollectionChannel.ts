import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

let echo: Echo<'reverb'> | null = null;
export interface CollectionChange {
    request_uuid: string;
    new_work: boolean;
}
type ChangeListener = (event?: CollectionChange) => void;
interface Subscription {
    connected: boolean;
    listeners: Map<ChangeListener, Ref<boolean>>;
    cleanup: () => void;
}
const subscriptions = new Map<string, Subscription>();

export function useCollectionChannel(name: string, changed: ChangeListener) {
    const live = ref(false);
    const configured = Boolean(import.meta.env.VITE_REVERB_APP_KEY);
    onMounted(() => {
        if (!configured) return;
        echo ??= new Echo({
            broadcaster: 'reverb',
            Pusher,
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost:
                import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
            forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
            enabledTransports: ['ws', 'wss'],
        });
        const existing = subscriptions.get(name);
        if (existing) {
            existing.listeners.set(changed, live);
            live.value = existing.connected;
            return;
        }
        const subscription: Subscription = {
            connected: false,
            listeners: new Map([[changed, live]]),
            cleanup: () => {},
        };
        const setConnected = (connected: boolean) => {
            subscription.connected = connected;
            subscription.listeners.forEach((status) => {
                status.value = connected;
            });
        };
        const stateChanged = ({ current }: { current: string }) => {
            if (current !== 'connected') setConnected(false);
        };
        subscriptions.set(name, subscription);
        echo.private(name)
            .listen('.collection.changed', (event: CollectionChange) => {
                subscription.listeners.forEach((_, listener) =>
                    listener(event),
                );
            })
            .subscribed(() => {
                setConnected(true);
                subscription.listeners.forEach((_, listener) => listener());
            })
            .error(() => setConnected(false));
        echo.connector.pusher.connection.bind('state_change', stateChanged);
        subscription.cleanup = () => {
            echo?.leave(name);
            echo?.connector.pusher.connection.unbind(
                'state_change',
                stateChanged,
            );
        };
    });
    onBeforeUnmount(() => {
        const subscription = subscriptions.get(name);
        subscription?.listeners.delete(changed);
        if (subscription && subscription.listeners.size === 0) {
            subscription.cleanup();
            subscriptions.delete(name);
        }
    });
    return { live, configured };
}
