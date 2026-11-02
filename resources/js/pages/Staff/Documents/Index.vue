<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import StaffWizardProgress from '@/components/StaffWizardProgress.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
const editing = ref(false);
const step = ref(0);
const search = ref('');
const tab = ref('current');
const selectedId = ref(props.documents[0]?.id ?? null);
const selected = computed(() =>
    props.documents.find((doc) => doc.id === selectedId.value),
);
const visibleDocuments = computed(() =>
    props.documents.filter((doc) =>
        doc.title.toLowerCase().includes(search.value.toLowerCase()),
    ),
);
const confirmAction = ref<'send' | 'archive' | 'discard' | null>(null);
const archive = useForm({
    action: 'archive',
    document_id: null as number | null,
});
function start(): void {
    form.reset();
    form.clearErrors();
    step.value = 0;
    selectedTitle.value = 'New document';
    editing.value = true;
}
function next(): void {
    form.clearErrors();
    if (step.value === 0 && !form.title.trim()) {
        form.setError('title', 'Enter a document title.');
        return;
    }
    if (
        step.value === 1 &&
        !form.markdown.trim() &&
        !form.pdf &&
        !(selected.value?.has_pdf && form.document_id && !form.remove_pdf)
    ) {
        form.setError('markdown', 'Provide Markdown content or a PDF.');
        return;
    }
    step.value++;
}
function closeEditor(): void {
    if (form.isDirty) {
        confirmAction.value = 'discard';
    } else {
        editing.value = false;
    }
}
function confirm(): void {
    if (confirmAction.value === 'discard') {
        editing.value = false;
        form.reset();
        confirmAction.value = null;
        return;
    }
    if (confirmAction.value === 'send') {
        send.post(store.url(props.site.slug), {
            preserveScroll: true,
            onSuccess: () => {
                confirmAction.value = null;
            },
        });
    } else if (selected.value) {
        archive.document_id = selected.value.document_id;
        archive.post(store.url(props.site.slug), {
            preserveScroll: true,
            onSuccess: () => {
                selectedId.value = props.documents[0]?.id ?? null;
                confirmAction.value = null;
            },
        });
    }
}
function edit(doc: ParentDocument): void {
    form.reset();
    form.clearErrors();
    step.value = 0;
    editing.value = true;
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
        onError: () => {
            step.value = form.errors.title ? 0 : 1;
        },
        preserveScroll: true,
        onSuccess: () => {
            const publishedTitle = form.title;
            form.reset();
            selectedTitle.value = 'New document';
            editing.value = false;
            selectedId.value =
                props.documents.find((doc) => doc.title === publishedTitle)
                    ?.id ??
                props.documents[0]?.id ??
                null;
        },
    });
}
const send = useForm({ action: 'send' });
</script>
<template>
    <div class="space-y-5">
        <Head :title="site.name + ' Documents'" />
        <p v-if="status" role="status" class="rounded-xl border bg-card p-4">
            {{ status }}
        </p>
        <template v-if="!editing">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    {{ documents.length }} current documents
                </p>
                <div class="flex gap-2">
                    <Button variant="outline" @click="confirmAction = 'send'"
                        >Send requests</Button
                    >
                    <Button @click="start">New document</Button>
                </div>
            </div>
            <nav aria-label="Document views" class="flex gap-2 border-b pb-3">
                <Button
                    :variant="tab === 'current' ? 'default' : 'ghost'"
                    :aria-pressed="tab === 'current'"
                    @click="tab = 'current'"
                    >Current documents</Button
                >
                <Button
                    :variant="tab === 'history' ? 'default' : 'ghost'"
                    :aria-pressed="tab === 'history'"
                    @click="tab = 'history'"
                    >Version history</Button
                >
            </nav>
            <div
                v-if="tab === 'current'"
                class="grid items-start gap-5 lg:grid-cols-3"
            >
                <div
                    class="space-y-2 rounded-3xl border border-dashed bg-background p-3"
                >
                    <Input
                        v-model="search"
                        aria-label="Search documents"
                        placeholder="Search documents…"
                        class="mb-3"
                    />
                    <button
                        v-for="doc in visibleDocuments"
                        :key="doc.id"
                        type="button"
                        class="w-full rounded-xl border p-3 text-left transition hover:bg-muted"
                        :class="
                            selectedId === doc.id
                                ? 'border-primary bg-muted'
                                : 'border-transparent'
                        "
                        :aria-pressed="selectedId === doc.id"
                        @click="selectedId = doc.id"
                    >
                        <span class="block font-semibold">{{ doc.title }}</span>
                        <span class="mt-1 block text-sm text-muted-foreground"
                            >Version {{ doc.number }} ·
                            {{
                                doc.required
                                    ? 'Acceptance required'
                                    : 'For information'
                            }}</span
                        >
                    </button>
                    <p
                        v-if="!visibleDocuments.length"
                        class="p-3 text-sm text-muted-foreground"
                    >
                        {{
                            documents.length
                                ? 'No matching documents.'
                                : 'Add your first nursery document.'
                        }}
                    </p>
                </div>
                <section
                    class="space-y-4 rounded-3xl border border-dashed bg-background p-5 lg:col-span-2"
                >
                    <template v-if="selected">
                        <ParentDocumentsList :documents="[selected]" readonly />
                        <div class="flex flex-wrap gap-2 border-t pt-4">
                            <Button variant="outline" @click="edit(selected)"
                                >New version</Button
                            >
                            <Button
                                variant="ghost"
                                @click="confirmAction = 'archive'"
                                >Archive</Button
                            >
                        </div>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        Select a document to view it.
                    </p>
                </section>
            </div>
            <section v-else class="space-y-3">
                <p class="text-sm text-muted-foreground">
                    Published versions and recorded acceptances are retained,
                    including archived documents.
                </p>
                <details
                    v-for="version in history"
                    :key="version.id"
                    class="rounded-xl border bg-background p-4"
                >
                    <summary class="cursor-pointer font-medium">
                        {{ version.title }}
                        <span class="text-sm font-normal text-muted-foreground"
                            >· Version {{ version.number }} ·
                            {{ version.acceptances.length }} acceptances</span
                        >
                    </summary>
                    <div class="mt-4 space-y-3">
                        <a
                            v-if="version.has_pdf"
                            :href="documentUrl.url(version.id)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-block py-2 underline"
                            >Open retained PDF</a
                        >
                        <details v-if="version.markdown">
                            <summary class="cursor-pointer text-sm font-medium">
                                Markdown content
                            </summary>
                            <pre
                                class="mt-3 overflow-auto text-sm whitespace-pre-wrap"
                                >{{ version.markdown }}</pre>
                        </details>
                        <p
                            v-for="(acceptance, index) in version.acceptances"
                            :key="index"
                            class="text-sm"
                        >
                            {{ acceptance.actor_name }} ({{
                                acceptance.actor_email
                            }}) ·
                            {{
                                new Date(acceptance.accepted_at).toLocaleString(
                                    'en-GB',
                                )
                            }}
                        </p>
                        <p
                            v-if="!version.acceptances.length"
                            class="text-sm text-muted-foreground"
                        >
                            No acceptances recorded.
                        </p>
                    </div>
                </details>
                <p
                    v-if="!history.length"
                    class="rounded-3xl border border-dashed p-6 text-muted-foreground"
                >
                    No published versions yet.
                </p>
            </section>
        </template>
        <form
            v-else
            class="space-y-5 rounded-3xl border border-dashed bg-background p-5"
            @submit.prevent="step < 2 ? next() : publish()"
        >
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-semibold">{{ selectedTitle }}</h2>
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="form.processing"
                    @click="closeEditor"
                    >Cancel</Button
                >
            </div>
            <StaffWizardProgress
                :steps="['Details', 'Content', 'Review']"
                :current="step"
                :disabled="form.processing"
                @select="step = $event"
            />
            <div v-show="step === 0" class="max-w-xl space-y-4">
                <label class="grid gap-2 text-sm font-medium"
                    >Title<Input v-model="form.title" maxlength="255"
                /></label>
                <label class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        v-model="form.requires_acceptance"
                        type="checkbox"
                        class="size-5"
                    />Require adults to accept this document</label
                >
            </div>
            <div v-show="step === 1" class="space-y-4">
                <label class="grid gap-2 text-sm font-medium"
                    >Markdown content<textarea
                        v-model="form.markdown"
                        rows="10"
                        maxlength="200000"
                        class="w-full rounded-md border bg-background p-3 font-mono text-sm"
                    />
                </label>
                <label class="grid gap-2 text-sm font-medium"
                    >PDF (optional, up to 10 MB)<Input
                        type="file"
                        accept=".pdf,application/pdf"
                        @change="
                            form.pdf =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                /></label>
                <p class="text-sm text-muted-foreground">
                    Provide Markdown, a PDF, or both. The current PDF is kept
                    unless replaced or removed.
                </p>
                <label
                    v-if="form.document_id && selected?.has_pdf"
                    class="flex min-h-11 items-center gap-3 text-sm"
                    ><input
                        v-model="form.remove_pdf"
                        type="checkbox"
                        class="size-5"
                    />Remove the current PDF</label
                >
            </div>
            <section v-show="step === 2" class="space-y-4">
                <h3 class="text-lg font-semibold">{{ form.title }}</h3>
                <p class="text-sm">
                    {{
                        form.requires_acceptance
                            ? 'Acceptance required'
                            : 'For information only'
                    }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        form.pdf
                            ? 'PDF: ' + form.pdf.name
                            : form.document_id &&
                                selected?.has_pdf &&
                                !form.remove_pdf
                              ? 'Current PDF retained'
                              : 'No PDF attached'
                    }}
                </p>
                <details v-if="form.markdown">
                    <summary class="cursor-pointer text-sm font-medium">
                        Review Markdown content
                    </summary>
                    <pre
                        class="mt-3 overflow-auto rounded-lg bg-muted p-4 text-sm whitespace-pre-wrap"
                        >{{ form.markdown }}</pre>
                </details>
                <p class="rounded-xl bg-muted p-4 text-sm">
                    Publishing retains a new version. Required documents need
                    fresh acceptance. You can send requests from the documents
                    page after publishing.
                </p>
            </section>
            <div
                v-if="form.hasErrors"
                role="alert"
                class="text-sm text-red-700 dark:text-red-400"
            >
                <p v-for="error in form.errors" :key="error">{{ error }}</p>
            </div>
            <div class="flex justify-between gap-3 border-t pt-4">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="step === 0 || form.processing"
                    @click="step--"
                    >Back</Button
                >
                <Button :disabled="form.processing">{{
                    step < 2 ? 'Continue' : 'Publish version'
                }}</Button>
            </div>
        </form>
        <Dialog
            :open="confirmAction !== null"
            @update:open="
                (value) => {
                    if (!value && !send.processing && !archive.processing)
                        confirmAction = null;
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        confirmAction === 'send'
                            ? 'Send document requests?'
                            : confirmAction === 'archive'
                              ? 'Archive this document?'
                              : 'Discard changes?'
                    }}</DialogTitle>
                    <DialogDescription v-if="confirmAction === 'send'"
                        >{{ recipientCount }} nominated adults have active
                        children at this nursery. Only those with outstanding
                        required documents will receive a request. Adults with
                        only inactive children are excluded.</DialogDescription
                    >
                    <DialogDescription v-else-if="confirmAction === 'archive'"
                        >Remove {{ selected?.title }} from current documents.
                        Previous versions and acceptance records will be
                        kept.</DialogDescription
                    >
                    <DialogDescription v-else
                        >Your unpublished changes will be
                        discarded.</DialogDescription
                    >
                </DialogHeader>
                <p
                    v-for="error in { ...send.errors, ...archive.errors }"
                    :key="error"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <DialogFooter
                    ><Button
                        variant="outline"
                        :disabled="send.processing || archive.processing"
                        @click="confirmAction = null"
                        >Cancel</Button
                    ><Button
                        :disabled="send.processing || archive.processing"
                        @click="confirm"
                        >{{
                            confirmAction === 'send'
                                ? 'Send requests'
                                : confirmAction === 'archive'
                                  ? 'Archive document'
                                  : 'Discard changes'
                        }}</Button
                    ></DialogFooter
                >
            </DialogContent>
        </Dialog>
    </div>
</template>
