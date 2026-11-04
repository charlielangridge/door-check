<script setup lang="ts">
import { ModalLink } from '@inertiaui/modal-vue';
import { Clock } from '@lucide/vue';
import StaffPhoto from '@/components/StaffPhoto.vue';
import { useStaffCollectionRoutes } from '@/composables/useStaffCollectionRoutes';
import type { BoardRequest } from '@/types/collections';

const props = defineProps<{
    request: BoardRequest;
    siteSlug: string;
    history: boolean;
    highlighted: boolean;
    waitingSince: string;
}>();
const { show } = useStaffCollectionRoutes(props.siteSlug);
</script>

<template>
    <ModalLink
        :id="`request-${request.uuid}`"
        prefetch
        :cache-for="5000"
        :href="show.url(request.uuid)"
        class="flex min-h-56 flex-col gap-5 rounded-2xl border-2 bg-card p-6 text-left shadow-sm transition-colors hover:border-sky-500 focus-visible:outline-4 focus-visible:outline-sky-600"
        :class="
            highlighted
                ? 'border-sky-500 ring-4 ring-sky-500/15'
                : 'border-transparent'
        "
    >
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="mb-1 text-sm text-muted-foreground">
                    Collecting adult
                </p>
                <h2 class="text-2xl font-semibold">
                    {{ request.adult_name }}
                </h2>
                <p
                    class="mt-2 flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Clock class="size-4" />
                    {{ waitingSince }}
                </p>
            </div>
            <StaffPhoto
                :src="request.requester_photo"
                :alt="`Photo submitted with ${request.adult_name}'s collection request`"
                compact
                cover
                class="size-20 rounded-full"
            />
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
                <span class="text-lg font-medium">{{ child.name }}</span
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
                        child.needs_matching && child.state === 'needs_checking'
                            ? 'Check identity'
                            : child.label
                    }}</span
                >
            </li>
        </ul>
        <p class="mt-auto text-sm font-medium text-sky-700 dark:text-sky-300">
            {{ history ? 'View collection timeline' : 'Tap to review request' }}
            →
        </p>
    </ModalLink>
</template>
