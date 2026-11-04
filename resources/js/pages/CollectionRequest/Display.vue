<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { start } from '@/wayfinder/App/Http/Controllers/CollectionKioskController';
import { Button } from '@/components/ui/button';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import {
    show,
    update,
} from '@/wayfinder/App/Http/Controllers/CollectionEntryDisplayController';
import { useCollectionEntryChannel } from '@/composables/useCollectionEntryChannel';
import { collectionHttp, CollectionHttpError } from '@/lib/collectionHttp';

const props = defineProps<{
    site: { name: string; uuid: string; slug: string; logo: string | null };
    display: { uuid: string };
    requestReceived: boolean;
}>();

type Heartbeat = { entry: string; qr: string | null; remaining_ms: number };
const qr = ref<string | null>(null);
const message = ref('Preparing your QR code…');
const signInRequired = ref(false);
const startingKiosk = ref(false);
const requestReceived = ref(props.requestReceived);
let notificationTimer: ReturnType<typeof setTimeout> | undefined;

function startKiosk(): void {
    if (startingKiosk.value || signInRequired.value) return;
    startingKiosk.value = true;
    router.post(
        start.url({ site: props.site.slug, display: props.display.uuid }),
        {},
        {
            onFinish: () => {
                startingKiosk.value = false;
            },
        },
    );
}
let entry: string | null = null;
let deadline = 0;
let lastSuccess = 0;
let revision = 0;
let replaceNext = true;
let disposed = false;
let pending: AbortController | null = null;
let nextCheck: ReturnType<typeof setTimeout> | undefined;
let watchdog: ReturnType<typeof setInterval> | undefined;

function disconnected(): void {
    qr.value = null;
    replaceNext = true;
    message.value = 'Connection lost. Please speak to a member of staff';
}

async function refresh(): Promise<void> {
    if (pending || disposed || signInRequired.value) return;
    clearTimeout(nextCheck);
    if (!navigator.onLine) {
        disconnected();
        nextCheck = setTimeout(() => void refresh(), 1000);
        return;
    }
    const version = revision;
    const started = performance.now();
    const replace = replaceNext;
    replaceNext = false;
    pending = new AbortController();
    const timeout = setTimeout(() => pending?.abort(), 5000);
    try {
        const result = await collectionHttp<Heartbeat>(
            update.url({ site: props.site.slug, display: props.display.uuid }),
            { replace, entry: replace ? null : entry },
            pending.signal,
        );
        if (disposed || version !== revision) return;
        lastSuccess = performance.now();
        deadline = started + result.remaining_ms;
        entry = result.entry;
        qr.value =
            performance.now() < deadline ? (result.qr ?? qr.value) : null;
        if (!qr.value) entry = null;
        message.value = '';
    } catch (error) {
        if (disposed) return;
        disconnected();
        if (
            error instanceof CollectionHttpError &&
            [401, 403, 404, 419].includes(error.status)
        ) {
            signInRequired.value = true;
            message.value = 'Please sign in again to continue.';
        }
    } finally {
        clearTimeout(timeout);
        pending = null;
        if (!disposed && !signInRequired.value) {
            nextCheck = setTimeout(
                () => void refresh(),
                version !== revision ? 0 : 1000,
            );
        }
    }
}

function claimed(): void {
    qr.value = null;
    entry = null;
    ++revision;
    void refresh();
}

function offline(): void {
    ++revision;
    disconnected();
    pending?.abort();
}

function online(): void {
    replaceNext = true;
    void refresh();
}

useCollectionEntryChannel(props.display.uuid, claimed);
onMounted(() => {
    if (requestReceived.value) {
        notificationTimer = setTimeout(() => {
            requestReceived.value = false;
        }, 5000);
    }
    window.addEventListener('offline', offline);
    window.addEventListener('online', online);
    void refresh();
    watchdog = setInterval(() => {
        if (qr.value && performance.now() - lastSuccess > 3500) {
            offline();
        } else if (qr.value && performance.now() >= deadline) {
            qr.value = null;
            entry = null;
            message.value = 'Preparing your QR code…';
            void refresh();
        }
    }, 250);
});
onBeforeUnmount(() => {
    disposed = true;
    pending?.abort();
    clearTimeout(nextCheck);
    clearInterval(watchdog);
    clearTimeout(notificationTimer);
    window.removeEventListener('offline', offline);
    window.removeEventListener('online', online);
});
</script>

<template>
    <main
        class="flex min-h-dvh flex-col items-center justify-center gap-5 bg-background p-6 text-center text-foreground landscape:flex-row landscape:gap-12 landscape:px-12"
    >
        <Head :title="site.name">
            <meta name="robots" content="noindex, nofollow" />
        </Head>
        <p
            v-if="requestReceived"
            role="status"
            class="fixed inset-x-6 top-6 z-10 mx-auto max-w-lg rounded-xl bg-emerald-100 p-4 text-xl font-semibold text-emerald-950 shadow-lg"
        >
            Request received
        </p>
        <div class="flex max-w-lg flex-col items-center gap-4">
            <img
                v-if="site.logo"
                :src="site.logo"
                :alt="site.name"
                class="max-h-28 max-w-64 object-contain"
            />
            <p class="text-xl font-semibold">{{ site.name }}</p>
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                Scan to collect your child
            </h1>
            <p class="text-lg text-muted-foreground">
                Open your phone’s camera and scan the QR code
            </p>
            <Button
                type="button"
                variant="outline"
                size="lg"
                :disabled="startingKiosk || signInRequired"
                @click="startKiosk"
            >
                {{
                    startingKiosk
                        ? 'Opening collection form…'
                        : 'Collect without a phone'
                }}
            </Button>
        </div>
        <div
            class="flex aspect-square w-[min(80vw,48dvh)] items-center justify-center rounded-2xl bg-white p-4 landscape:w-[min(45vw,80dvh)]"
        >
            <img
                v-if="qr"
                :src="qr"
                alt="Scan to open the collection request form"
                class="h-full w-full"
            />
            <div
                v-else
                role="status"
                class="flex flex-col gap-4 p-4 text-lg text-slate-900"
            >
                <p>{{ message || 'Preparing your QR code…' }}</p>
                <Link
                    v-if="signInRequired"
                    :href="show(site.slug)"
                    class="font-semibold underline"
                    >Sign in</Link
                >
            </div>
        </div>
    </main>
</template>
