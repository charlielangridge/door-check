<script setup lang="ts">
import { LoaderCircle, UserRound } from '@lucide/vue';
import { toRef, watch } from 'vue';
import {
    useStaffPhoto,
    type StaffPhotoState,
} from '@/composables/useStaffPhoto';

const props = withDefaults(
    defineProps<{
        src?: string | null;
        alt: string;
        pending?: boolean;
        compact?: boolean;
        cover?: boolean;
    }>(),
    { pending: false, compact: false, cover: false },
);
const emit = defineEmits<{ state: [state: StaffPhotoState] }>();
const { imageSrc, state, loaded, failed } = useStaffPhoto(
    toRef(props, 'src'),
    toRef(props, 'pending'),
);
watch(state, (value) => emit('state', value), { immediate: true });
</script>

<template>
    <div
        class="relative flex shrink-0 items-center justify-center overflow-hidden bg-muted/30"
        :aria-busy="state === 'loading'"
    >
        <img
            v-if="imageSrc"
            :key="imageSrc"
            :src="imageSrc"
            :alt="alt"
            class="absolute inset-0 h-full w-full"
            :class="[
                cover ? 'object-cover' : 'object-contain',
                state === 'loaded' ? 'opacity-100' : 'opacity-0',
            ]"
            @load="loaded"
            @error="failed"
        />
        <div
            v-if="state !== 'loaded'"
            role="status"
            class="flex flex-col items-center justify-center gap-2 text-sm text-muted-foreground"
        >
            <LoaderCircle
                v-if="state === 'loading'"
                class="size-7 motion-safe:animate-spin"
                aria-hidden="true"
            />
            <UserRound v-else class="size-8" aria-hidden="true" />
            <span :class="compact ? 'sr-only' : ''">{{
                state === 'loading' ? 'Loading photo…' : 'Photo unavailable'
            }}</span>
        </div>
    </div>
</template>
