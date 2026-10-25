<script setup lang="ts">
import { Form, Head, Link, router, usePoll } from '@inertiajs/vue3';
import { ModalLink } from '@inertiaui/modal-vue';
import {
    ArrowDown,
    Clock,
    History,
    LogOut,
    UsersRound,
    Volume2,
    VolumeX,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    index,
    show,
} from '@/actions/App/Http/Controllers/StaffCollectionController';
import { logout } from '@/routes';
import { Button } from '@/components/ui/button';
import { useCollectionChannel } from '@/composables/useCollectionChannel';
import type { CollectionState } from '@/types/collections';

interface BoardRequest {
    uuid: string;
    adult_name: string;
    requested_at: string;
    previous_day: boolean;
    children: {
        uuid: string;
        name: string;
        state: CollectionState;
        label: string;
        needs_matching: boolean;
    }[];
}
const props = defineProps<{
    site: { id: number; name: string };
    requests: BoardRequest[];
    history: boolean;
}>();
const currentTime = ref(Date.now());
const sound = ref(false);
const highlighted = ref<string[]>([]);
const reachable = ref(true);
let audio: AudioContext | null = null;
let clockTimer: ReturnType<typeof setInterval> | undefined;
let highlightTimer: ReturnType<typeof setTimeout> | undefined;
const childCount = computed(() =>
    props.requests.reduce(
        (total, request) =>
            total +
            request.children.filter(
                (child) => !['collected', 'cancelled'].includes(child.state),
            ).length,
        0,
    ),
);
const { live, configured } = useCollectionChannel(
    `staff.${props.site.id}`,
    () => reconnect(),
);
usePoll(
    15000,
    {
        only: ['requests', 'site'],
        onSuccess: () => {
            reachable.value = true;
        },
        onNetworkError: () => {
            reachable.value = false;
            return false;
        },
    },
    { keepAlive: true },
);

function waitingSince(value: string): string {
    const minutes = Math.max(
        0,
        Math.floor((currentTime.value - new Date(value).getTime()) / 60000),
    );
    return minutes < 1
        ? 'Just arrived'
        : minutes < 60
          ? `${minutes} min waiting`
          : `${Math.floor(minutes / 60)}h ${minutes % 60}m waiting`;
}

async function toggleSound(): Promise<void> {
    if (sound.value) {
        sound.value = false;
        return;
    }
    audio ??= new AudioContext();
    await audio.resume();
    sound.value = audio.state === 'running';
}

function chime(): void {
    if (!sound.value || !audio || audio.state !== 'running') return;
    const oscillator = audio.createOscillator();
    const gain = audio.createGain();
    oscillator.connect(gain);
    gain.connect(audio.destination);
    oscillator.frequency.value = 660;
    gain.gain.setValueAtTime(0.1, audio.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.45);
    oscillator.start();
    oscillator.stop(audio.currentTime + 0.45);
}

watch(
    () => props.requests,
    (requests, previous) => {
        if (props.history) return;
        const oldChildren = new Set(
            previous.flatMap((request) =>
                request.children.map((child) => child.uuid),
            ),
        );
        const changed = requests
            .filter((request) =>
                request.children.some((child) => !oldChildren.has(child.uuid)),
            )
            .map((request) => request.uuid);
        if (changed.length) {
            highlighted.value = changed;
            chime();
            clearTimeout(highlightTimer);
            highlightTimer = setTimeout(() => {
                highlighted.value = [];
            }, 12000);
        }
    },
);

function jumpToNew(): void {
    document
        .getElementById(`request-${highlighted.value[0]}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function reconnect(): void {
    router.reload({
        only: ['requests', 'site'],
        onSuccess: () => {
            reachable.value = true;
        },
        onNetworkError: () => {
            reachable.value = false;
            return false;
        },
    });
}
function visibilityChanged(): void {
    if (document.visibilityState === 'visible') reconnect();
}
function offline(): void {
    reachable.value = false;
}
watch(
    () => props.site.id,
    () => window.location.reload(),
);
onMounted(() => {
    reachable.value = navigator.onLine;
    window.addEventListener('offline', offline);
    window.addEventListener('online', reconnect);
    clockTimer = setInterval(() => {
        currentTime.value = Date.now();
    }, 30000);
    document.addEventListener('visibilitychange', visibilityChanged);
});
onBeforeUnmount(() => {
    window.removeEventListener('offline', offline);
    window.removeEventListener('online', reconnect);
    clearInterval(clockTimer);
    clearTimeout(highlightTimer);
    document.removeEventListener('visibilitychange', visibilityChanged);
    void audio?.close();
});
</script>

<template>
    <main
        class="min-h-screen bg-slate-50 text-slate-950 dark:bg-slate-950 dark:text-slate-50"
    >
        <Head title="Staff collection board" />
        <header
            class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-5 border-b bg-background/95 px-6 py-5 backdrop-blur lg:px-10"
        >
            <div class="flex items-center gap-4">
                <div
                    class="flex size-14 items-center justify-center rounded-2xl bg-sky-700 text-white"
                >
                    <UsersRound class="size-8" />
                </div>
                <div>
                    <p class="text-sm font-medium text-muted-foreground">
                        {{ site.name }}
                    </p>
                    <h1
                        class="text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        {{ history ? 'Today’s history' : 'Collection board' }}
                    </h1>
                </div>
            </div>
            <nav
                class="flex flex-wrap items-center gap-3"
                aria-label="Staff controls"
            >
                <Button variant="outline" class="min-h-12" @click="toggleSound"
                    ><Volume2 v-if="sound" class="size-5" /><VolumeX
                        v-else
                        class="size-5"
                    />{{ sound ? 'Mute sound' : 'Enable sound' }}</Button
                >
                <Button as-child variant="outline" class="min-h-12"
                    ><Link
                        :href="index({ query: { history: history ? 0 : 1 } })"
                        ><History class="size-5" />{{
                            history ? 'Active board' : 'Today’s history'
                        }}</Link
                    ></Button
                >
                <Form v-bind="logout.form()"
                    ><Button type="submit" variant="ghost" class="min-h-12"
                        ><LogOut class="size-5" />Log out</Button
                    ></Form
                >
            </nav>
        </header>
        <div class="mx-auto max-w-screen-2xl space-y-6 p-6 lg:p-10">
            <div
                v-if="!reachable"
                role="alert"
                class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-amber-400 bg-amber-50 p-5 text-amber-950"
            >
                <span class="font-semibold"
                    >Connection lost. These details may be out of date.</span
                ><Button variant="outline" @click="reconnect">Reconnect</Button>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="text-lg font-medium">
                    {{ requests.length }} {{ history ? 'finished' : 'active' }}
                    {{ requests.length === 1 ? 'request' : 'requests'
                    }}<span
                        v-if="!history"
                        class="font-normal text-muted-foreground"
                    >
                        · {{ childCount }}
                        {{ childCount === 1 ? 'child' : 'children' }}
                        waiting</span
                    >
                </p>
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :class="live ? 'bg-emerald-500' : 'bg-amber-500'"
                    />{{
                        live
                            ? 'Live updates connected'
                            : configured
                              ? 'Reconnecting live updates · checking regularly'
                              : 'Checking for updates regularly'
                    }}
                </p>
            </div>
            <Button
                v-if="highlighted.length"
                class="min-h-12 bg-sky-700 text-white hover:bg-sky-800"
                @click="jumpToNew"
                ><ArrowDown class="size-5" />New collection request</Button
            >
            <section
                v-if="requests.length"
                class="grid gap-5 md:grid-cols-2 xl:grid-cols-3"
                aria-label="Collection requests"
            >
                <ModalLink
                    v-for="request in requests"
                    :id="`request-${request.uuid}`"
                    :key="request.uuid"
                    :href="show.url(request.uuid)"
                    class="flex min-h-56 flex-col gap-5 rounded-2xl border-2 bg-card p-6 text-left shadow-sm transition-colors hover:border-sky-500 focus-visible:outline-4 focus-visible:outline-sky-600"
                    :class="
                        highlighted.includes(request.uuid)
                            ? 'border-sky-500 ring-4 ring-sky-500/15'
                            : 'border-transparent'
                    "
                >
                    <div>
                        <p class="mb-1 text-sm text-muted-foreground">
                            Collecting adult
                        </p>
                        <h2 class="text-2xl font-semibold">
                            {{ request.adult_name }}
                        </h2>
                        <p
                            class="mt-2 flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Clock class="size-4" />{{
                                waitingSince(request.requested_at)
                            }}
                        </p>
                    </div>
                    <p
                        v-if="request.previous_day && !history"
                        class="rounded-lg bg-amber-100 px-3 py-2 text-sm font-semibold text-amber-950"
                    >
                        Previous day — needs attention
                    </p>
                    <ul class="grid gap-3">
                        <li
                            v-for="child in request.children"
                            :key="child.uuid"
                            class="flex flex-wrap items-center justify-between gap-2 border-t pt-3"
                        >
                            <span class="text-lg font-medium">{{
                                child.name
                            }}</span
                            ><span
                                class="rounded-full px-3 py-1.5 text-sm font-semibold"
                                :class="
                                    child.state === 'collected'
                                        ? 'bg-emerald-100 text-emerald-900'
                                        : child.state === 'preparing'
                                          ? 'bg-sky-100 text-sky-900'
                                          : child.state === 'cancelled'
                                            ? 'bg-slate-100 text-slate-700'
                                            : child.state === 'reviewed'
                                              ? 'bg-violet-100 text-violet-900'
                                              : 'bg-amber-100 text-amber-950'
                                "
                                >{{
                                    child.needs_matching &&
                                    child.state === 'needs_checking'
                                        ? 'Check identity'
                                        : child.label
                                }}</span
                            >
                        </li>
                    </ul>
                    <p
                        class="mt-auto text-sm font-medium text-sky-700 dark:text-sky-300"
                    >
                        {{
                            history
                                ? 'View collection timeline'
                                : 'Tap to review request'
                        }}
                        →
                    </p>
                </ModalLink>
            </section>
            <section
                v-else
                class="grid min-h-96 place-content-center justify-items-center gap-4 rounded-3xl border border-dashed bg-background text-center"
            >
                <UsersRound class="size-14 text-slate-400" />
                <h2 class="text-2xl font-semibold">
                    {{
                        history
                            ? 'No finished requests today'
                            : 'No waiting collection requests'
                    }}
                </h2>
                <p class="text-muted-foreground">
                    {{
                        history
                            ? 'Collected and cancelled requests will appear here.'
                            : 'New requests will appear here automatically.'
                    }}
                </p>
            </section>
        </div>
    </main>
</template>
