<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
import { board } from '@/routes/staff';

defineProps<{ sites: { name: string; uuid: string }[] }>();
</script>

<template>
    <main
        class="min-h-screen bg-slate-50 p-6 text-slate-950 lg:p-10 dark:bg-slate-950 dark:text-slate-50"
    >
        <Head title="Choose a staff venue" />
        <div class="mx-auto max-w-4xl space-y-8">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold">Choose a venue</h1>
                    <p class="mt-2 text-muted-foreground">
                        Open the collection board for the venue you’re working
                        at.
                    </p>
                </div>
                <Form v-bind="logout.form()"
                    ><Button class="min-h-12" variant="outline" type="submit"
                        >Log out</Button
                    ></Form
                >
            </header>
            <nav class="grid gap-4 sm:grid-cols-2" aria-label="Staff venues">
                <Link
                    v-for="site in sites"
                    :key="site.uuid"
                    :href="board(site.uuid)"
                    class="flex min-h-32 items-center justify-between rounded-2xl border-2 bg-card p-6 text-xl font-semibold shadow-sm hover:border-sky-500 focus-visible:outline-4 focus-visible:outline-sky-600"
                    ><span>{{ site.name }}</span
                    ><span aria-hidden="true">→</span></Link
                >
            </nav>
        </div>
    </main>
</template>
