<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { ArrowDown, UsersRound } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import StaffRequestCard from '@/components/StaffRequestCard.vue';
import { Button } from '@/components/ui/button';
import { useCollectionChannel } from '@/composables/useCollectionChannel';
import { useStaffSound } from '@/composables/useStaffSound';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { BoardRequest, StaffSite } from '@/types/collections';

defineOptions({
    layout: (props: { site: StaffSite; history?: boolean }) => [
        StaffLayout,
        {
            site: props.site,
            title: props.history ? 'Today’s history' : 'Collection board',
            section: 'board',
            history: props.history,
        },
    ],
});

const props = defineProps<{
    site: StaffSite;
    requests: BoardRequest[];
    history: boolean;
}>();
const { chime } = useStaffSound();
const currentTime = ref(Date.now());
const highlighted = ref<string[]>([]);
const reachable = ref(true);
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
});
</script>

<template>
    <div class="space-y-6">
        <Head title="Staff collection board" />
        <div
            v-if="!reachable"
            role="alert"
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-amber-400 bg-amber-50 p-5 text-amber-950"
        >
            <span class="font-semibold"
                >Connection lost. These details may be out of date.</span
            >
            <Button variant="outline" @click="reconnect">Reconnect</Button>
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
            <p class="flex items-center gap-2 text-sm text-muted-foreground">
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
        >
            <ArrowDown class="size-5" />
            New collection request
        </Button>
        <section
            v-if="requests.length"
            class="grid gap-5 md:grid-cols-2 xl:grid-cols-3"
            aria-label="Collection requests"
        >
            <StaffRequestCard
                v-for="request in requests"
                :key="request.uuid"
                :request="request"
                :site-slug="site.slug"
                :history="history"
                :highlighted="highlighted.includes(request.uuid)"
                :waiting-since="waitingSince(request.requested_at)"
            />
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
</template>
