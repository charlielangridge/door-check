<script setup lang="ts">
import StaffHeader from '@/components/StaffHeader.vue';
import { provideStaffSound } from '@/composables/useStaffSound';
import type { StaffSite } from '@/types/collections';

defineProps<{
    site: StaffSite;
    title: string;
    section: 'board' | 'children';
    history?: boolean;
}>();

provideStaffSound();
</script>

<template>
    <main
        class="min-h-screen bg-slate-50 text-slate-950 dark:bg-slate-950 dark:text-slate-50"
    >
        <StaffHeader
            :site="site"
            :title="title"
            :section="section"
            :history="history"
        />
        <div class="mx-auto max-w-screen-2xl space-y-6 p-6 lg:p-10">
            <slot />
        </div>
    </main>
</template>
