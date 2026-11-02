<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ParentDocumentsList from '@/components/ParentDocumentsList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { StaffSite } from '@/types/collections';
import type { ParentDocument } from '@/types/parents';
import { store } from '@/wayfinder/App/Http/Controllers/SiteDocumentController';
import { document as documentUrl } from '@/wayfinder/App/Http/Controllers/ParentMediaController';

defineOptions({
    layout: (props: { site: StaffSite }) => [
        StaffLayout,
        { site: props.site, title: 'Site documents', section: 'children' },
    ],
});
const props = defineProps<{
    site: StaffSite;
    documents: ParentDocument[];
    recipientCount: number;
    status: string | null;
    history: {
        id: number;
        title: string;
        number: number;
        has_pdf: boolean;
        markdown: string | null;
        acceptances: {
            actor_name: string;
            actor_email: string;
            accepted_at: string;
        }[];
    }[];
}>();
const form = useForm({
    action: 'publish',
    document_id: null as number | null,
    title: '',
    markdown: '',
    pdf: null as File | null,
    remove_pdf: false,
    requires_acceptance: true,
});
const selectedTitle = ref('New document');
function edit(doc: ParentDocument): void {
    form.reset();
    form.document_id = doc.document_id;
    form.title = doc.title;
    form.markdown = doc.markdown ?? '';
    form.requires_acceptance = doc.required;
    selectedTitle.value = 'New version of ' + doc.title;
}
function publish(): void {
    form.action = 'publish';
    form.post(store.url(props.site.slug), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            selectedTitle.value = 'New document';
        },
    });
}
const send = useForm({ action: 'send' });
</script>

<template>
    <div class="space-y-6">
        <Head :title="site.name + ' Documents'" />
        <p v-if="status" role="status" class="rounded-lg border p-4">
            {{ status }}
        </p>
        <form
            class="space-y-4 rounded-xl border bg-card p-5"
            @submit.prevent="publish"
        >
            <h2 class="text-xl font-semibold">{{ selectedTitle }}</h2>
            <label class="grid gap-2"
                >Title <Input v-model="form.title" required maxlength="255"
            /></label>
            <label class="grid gap-2"
                >Markdown content
                <textarea
                    v-model="form.markdown"
                    rows="10"
                    maxlength="200000"
                    class="rounded-md border bg-background p-3 font-mono text-sm"
                />
            </label>
            <label class="grid gap-2"
                >PDF (optional, up to 10 MB)
                <input
                    type="file"
                    accept="application/pdf"
                    @change="
                        form.pdf =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
            /></label>
            <p class="text-sm text-muted-foreground">
                Provide Markdown, a PDF, or both. When editing, the current PDF
                is retained unless replaced or removed. Publishing creates a
                retained version; changing either format requires fresh
                acceptance.
            </p>
            <label
                v-if="form.document_id"
                class="flex min-h-11 items-center gap-3"
                ><input
                    v-model="form.remove_pdf"
                    type="checkbox"
                    class="size-5"
                />Remove the existing PDF from the new version</label
            >
            <label class="flex min-h-11 items-center gap-3"
                ><input
                    v-model="form.requires_acceptance"
                    type="checkbox"
                    class="size-5"
                />Required acceptance</label
            >
            <div
                v-if="form.hasErrors"
                role="alert"
                class="text-sm text-red-700 dark:text-red-400"
            >
                <p v-for="error in form.errors" :key="error">{{ error }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <Button :disabled="form.processing">Publish version</Button
                ><Button
                    type="button"
                    variant="outline"
                    @click="
                        form.reset();
                        selectedTitle = 'New document';
                    "
                    >Start new document</Button
                >
            </div>
        </form>
        <section class="space-y-4 rounded-xl border bg-card p-5">
            <h2 class="text-xl font-semibold">
                Request updated document acceptance
            </h2>
            <p>
                {{ recipientCount }} nominated parents/guardians have an active
                child at this nursery. Send only to those with outstanding
                required documents. Adults with only inactive children are
                excluded.
            </p>
            <Button
                :disabled="send.processing"
                @click="
                    send.post(store.url(site.slug), { preserveScroll: true })
                "
                >Send outstanding document requests</Button
            >
        </section>
        <section class="space-y-4">
            <ParentDocumentsList :documents="documents" readonly />
            <div
                v-for="doc in documents"
                :key="doc.id"
                class="flex flex-wrap items-center gap-3 rounded-lg border p-3"
            >
                <span class="font-medium">{{ doc.title }}</span>
                <Button variant="outline" @click="edit(doc)"
                    >Publish a replacement version</Button
                >
                <Button
                    variant="outline"
                    @click="
                        useForm({
                            action: 'archive',
                            document_id: doc.document_id,
                        }).post(store.url(site.slug), { preserveScroll: true })
                    "
                    >Archive document</Button
                >
            </div>
        </section>
        <section class="space-y-4">
            <h2 class="text-2xl font-semibold">
                Versions and acceptance history
            </h2>
            <details
                v-for="version in history"
                :key="version.id"
                class="space-y-3 rounded-xl border bg-card p-4"
            >
                <summary class="cursor-pointer font-semibold">
                    {{ version.title }} — version {{ version.number }} ({{
                        version.acceptances.length
                    }}
                    acceptances)
                </summary>
                <a
                    v-if="version.has_pdf"
                    :href="documentUrl.url(version.id)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-block py-3 underline"
                    >Open retained PDF</a
                >
                <pre
                    v-if="version.markdown"
                    class="overflow-auto text-sm whitespace-pre-wrap"
                    >{{ version.markdown }}</pre>
                <p
                    v-for="(acceptance, index) in version.acceptances"
                    :key="index"
                >
                    {{ acceptance.actor_name }} ({{ acceptance.actor_email }}) —
                    {{
                        new Date(acceptance.accepted_at).toLocaleString('en-GB')
                    }}
                </p>
                <p v-if="!version.acceptances.length">
                    No acceptances recorded.
                </p>
            </details>
        </section>
    </div>
</template>
