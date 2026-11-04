<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    site: { name: string; logo: string | null };
}>();
</script>

<template>
    <main
        class="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-6 px-5 py-10 text-center"
    >
        <Head title="Scan to collect your child" />
        <img
            v-if="site.logo"
            :src="site.logo"
            :alt="site.name"
            class="max-h-24"
        />
        <p class="text-lg font-semibold">{{ site.name }}</p>
        <h1 class="text-2xl font-semibold">
            Please scan the QR code on the screen at your site
        </h1>
        <p class="text-muted-foreground">
            If your code has expired or has already been used, please scan the
            current code. If you need help, please speak to a member of staff.
        </p>
    </main>
</template>
