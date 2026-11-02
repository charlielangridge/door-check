<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import ParentDocumentsList from '@/components/ParentDocumentsList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ParentDocument } from '@/types/parents';
import {
    store,
    consume,
} from '@/wayfinder/App/Http/Controllers/AdultAccessController';

const props = defineProps<{
    mode: string;
    uuid: string;
    signedQuery: Record<string, string | number>;
    sent?: boolean;
    verified?: boolean;
    documents?: ParentDocument[];
}>();
const form = useForm({ date_of_birth: '', documents: [] as number[] });
function submit(): void {
    const action = props.mode === 'request' ? store : consume;
    form.post(action.url(props.uuid, { query: props.signedQuery }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <main class="min-h-screen bg-background px-4 py-12 text-foreground">
        <Head title="Your nursery page" />
        <form
            class="mx-auto max-w-2xl space-y-6 rounded-xl border p-6"
            @submit.prevent="submit"
        >
            <h1 class="text-2xl font-semibold">
                {{
                    mode === 'documents'
                        ? 'Nursery documents'
                        : mode === 'email_change'
                          ? 'Verify your email address'
                          : 'Your adult page'
                }}
            </h1>
            <p v-if="mode === 'request'">
                Bookmark this page. Request a fresh email to open a secure
                session. The email link lasts 15 minutes.
            </p>
            <p v-if="sent" role="status">
                An access link has been queued for your email address.
            </p>
            <template v-if="mode === 'documents'">
                <label v-if="!verified" class="grid gap-2"
                    >Your date of birth
                    <Input v-model="form.date_of_birth" type="date" required />
                    <span class="text-sm text-muted-foreground"
                        >Use your own date of birth. Five incorrect attempts
                        lock this link.</span
                    >
                </label>
                <ParentDocumentsList
                    v-else
                    v-model="form.documents"
                    :documents="documents ?? []"
                />
            </template>
            <p v-else-if="mode !== 'request'">
                Continue to
                {{
                    mode === 'email_change'
                        ? 'verify this new email address'
                        : 'open your adult page'
                }}. This link can only be used once.
            </p>
            <div
                v-if="form.hasErrors"
                role="alert"
                class="space-y-1 text-sm text-red-700 dark:text-red-400"
            >
                <p v-for="error in form.errors" :key="error">{{ error }}</p>
            </div>
            <Button :disabled="form.processing">{{
                mode === 'request'
                    ? 'Email me an access link'
                    : mode === 'documents' && verified
                      ? 'Record my acceptance'
                      : 'Continue'
            }}</Button>
        </form>
    </main>
</template>
