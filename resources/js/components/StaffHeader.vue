<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { History, LogOut, Volume2, VolumeX } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useStaffSound } from '@/composables/useStaffSound';
import type { StaffSite } from '@/types/collections';
import { index as childIndex } from '@/wayfinder/App/Http/Controllers/ChildController';
import { board } from '@/wayfinder/App/Http/Controllers/StaffCollectionController';
import { destroy as logout } from '@/wayfinder/Laravel/Fortify/Http/Controllers/AuthenticatedSessionController';

defineProps<{
    site: StaffSite;
    title: string;
    section: 'board' | 'children';
    history?: boolean;
}>();

const { enabled: sound, toggle: toggleSound } = useStaffSound();
</script>

<template>
    <header
        class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-5 border-b bg-background/95 px-6 py-5 backdrop-blur lg:px-10"
    >
        <div class="flex items-center gap-4">
            <img
                v-if="site.logo"
                class="max-h-18"
                :src="site.logo"
                :alt="site.name"
            />
            <div>
                <p class="text-sm font-medium text-muted-foreground">
                    {{ site.name }}
                </p>
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                    {{ title }}
                </h1>
            </div>
        </div>
        <nav
            class="flex flex-wrap items-center gap-3"
            aria-label="Staff controls"
        >
            <Button as-child variant="outline" class="min-h-12">
                <Link
                    v-if="section === 'board'"
                    :href="childIndex({ site: site.slug })"
                    prefetch
                    cache-for="5s"
                    >View Children</Link
                >
                <Link v-else :href="board(site.slug)" prefetch cache-for="5s"
                    >Collection requests</Link
                >
            </Button>
            <Button
                v-if="section === 'board'"
                as-child
                variant="outline"
                class="min-h-12"
            >
                <Link
                    :href="
                        board(site.slug, {
                            query: { history: history ? 0 : 1 },
                        })
                    "
                    prefetch
                    cache-for="5s"
                >
                    <History class="size-5" />
                    {{ history ? 'Active board' : 'Today’s history' }}
                </Link>
            </Button>
            <Button
                variant="outline"
                class="min-h-12"
                :aria-pressed="sound"
                @click="toggleSound"
            >
                <Volume2 v-if="sound" class="size-5" />
                <VolumeX v-else class="size-5" />
                {{ sound ? 'Mute sound' : 'Enable sound' }}
            </Button>
            <Form v-bind="logout.form()">
                <Button type="submit" variant="ghost" class="min-h-12">
                    <LogOut class="size-5" />
                    Log out
                </Button>
            </Form>
        </nav>
    </header>
</template>
