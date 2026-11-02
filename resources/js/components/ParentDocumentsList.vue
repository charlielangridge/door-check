<script setup lang="ts">
import type { ParentDocument } from '@/types/parents';
import { watch } from 'vue';
import { document as documentUrl } from '@/wayfinder/App/Http/Controllers/ParentMediaController';

const props = defineProps<{
    documents: ParentDocument[];
    readonly?: boolean;
}>();
const selected = defineModel<number[]>({ default: () => [] });
watch(
    () => props.documents,
    (documents) => {
        const current = new Set(
            documents
                .filter((document) => document.required)
                .map((document) => document.id),
        );
        selected.value = selected.value.filter((id) => current.has(id));
    },
);
</script>

<template>
    <section class="space-y-4" aria-label="Site documents">
        <h2 class="text-xl font-semibold">Nursery documents</h2>
        <p v-if="!documents.length" class="text-sm text-muted-foreground">
            There are no documents to accept.
        </p>
        <article
            v-for="doc in documents"
            :key="doc.id"
            class="space-y-3 rounded-xl border p-4"
        >
            <h3 class="font-semibold">
                {{ doc.title }}
                <span class="text-sm font-normal text-muted-foreground"
                    >Version {{ doc.number }}</span
                >
            </h3>
            <div
                v-if="doc.html"
                class="space-y-3 break-words [&_a]:underline [&_h1]:text-xl [&_h2]:text-lg [&_li]:ml-5 [&_li]:list-disc [&_p]:my-2"
                v-html="doc.html"
            />
            <a
                v-if="doc.has_pdf"
                :href="documentUrl.url(doc.id)"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-block py-2 font-medium underline"
                >Open PDF (new tab)</a
            >
            <p
                v-if="doc.accepted"
                class="text-sm font-medium text-emerald-700 dark:text-emerald-400"
            >
                You have accepted this version.
            </p>
            <label
                v-else-if="doc.required && !readonly"
                class="flex min-h-11 items-start gap-3"
            >
                <input
                    v-model="selected"
                    type="checkbox"
                    :value="doc.id"
                    class="mt-1 size-5 shrink-0"
                />
                <span
                    >I have read and accept {{ doc.title }} (version
                    {{ doc.number }}), including its PDF where provided.</span
                >
            </label>
            <p v-else class="text-sm text-muted-foreground">
                {{ doc.required ? 'Acceptance required' : 'For information' }}
            </p>
        </article>
    </section>
</template>
