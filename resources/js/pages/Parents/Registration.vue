<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import ParentDocumentsList from '@/components/ParentDocumentsList.vue';
import ParentPersonFields from '@/components/ParentPersonFields.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ParentDocument, ParentPerson } from '@/types/parents';
import { store } from '@/wayfinder/App/Http/Controllers/ParentRegistrationController';

const props = defineProps<{
    uuid: string;
    signedQuery: Record<string, string | number>;
    verified: boolean;
    closed: boolean;
    submitted: boolean;
    payload: (ParentPerson & { adults: ParentPerson[] }) | null;
    siteName: string | null;
    correctionMessage: string | null;
    documents: ParentDocument[];
    saved: boolean;
    adultPageUrl: string | null;
}>();
const verification = useForm({ action: 'verify', date_of_birth: '' });
const form = useForm({
    action: 'draft' as 'draft' | 'submit',
    name: props.payload?.name ?? '',
    date_of_birth: props.payload?.date_of_birth ?? '',
    also_known_as: props.payload?.also_known_as ?? [],
    new_photo: null as File | null,
    adults: (props.payload?.adults ?? []).map((adult) => ({
        ...adult,
        new_photo: null as File | null,
    })),
    documents: [] as number[],
});
function verify(): void {
    verification.post(store.url(props.uuid, { query: props.signedQuery }), {
        preserveState: false,
    });
}
function save(action: 'draft' | 'submit'): void {
    form.action = action;
    form.post(store.url(props.uuid, { query: props.signedQuery }), {
        preserveScroll: true,
        forceFormData: true,
    });
}
function addAdult(): void {
    form.adults.push({
        key: crypto.randomUUID(),
        name: '',
        date_of_birth: '',
        email: '',
        phone: '',
        relationship: '',
        also_known_as: [],
        document_responsible: false,
        new_photo: null,
    });
}
</script>

<template>
    <main class="min-h-screen bg-background px-4 py-10 text-foreground">
        <Head title="Child registration" />
        <div class="mx-auto max-w-2xl space-y-6">
            <h1 class="text-3xl font-semibold">Child registration</h1>
            <section
                v-if="closed"
                class="space-y-4 rounded-xl border p-6"
                role="status"
            >
                <h2 class="text-xl font-semibold">
                    {{
                        submitted
                            ? 'Registration already submitted'
                            : 'This invitation is no longer valid'
                    }}
                </h2>
                <p>
                    {{
                        submitted
                            ? 'Your registration has been submitted for staff review. This registration link is now invalid and cannot be reused.'
                            : 'The invitation has expired or has been locked. Contact the nursery for a fresh link.'
                    }}
                </p>
                <a
                    v-if="adultPageUrl"
                    :href="adultPageUrl"
                    class="font-medium underline"
                    >Open your adult page</a
                >
            </section>
            <form
                v-else-if="!verified"
                class="space-y-4 rounded-xl border p-6"
                @submit.prevent="verify"
            >
                <p>
                    Enter your child’s date of birth to continue. Five incorrect
                    attempts will lock this invitation.
                </p>
                <label class="grid gap-2"
                    >Child’s date of birth
                    <Input
                        v-model="verification.date_of_birth"
                        type="date"
                        required
                /></label>
                <p
                    v-for="error in verification.errors"
                    :key="error"
                    role="alert"
                    class="text-sm text-red-700 dark:text-red-400"
                >
                    {{ error }}
                </p>
                <Button :disabled="verification.processing">Continue</Button>
            </form>
            <form v-else class="space-y-6" @submit.prevent="save('submit')">
                <p class="text-lg">{{ siteName }}</p>
                <p
                    v-if="correctionMessage"
                    class="rounded-lg border border-amber-500 bg-amber-50 p-4 text-amber-950 dark:bg-amber-950 dark:text-amber-100"
                >
                    Please correct: {{ correctionMessage }}
                </p>
                <p v-if="saved" role="status" class="rounded-lg border p-4">
                    Draft saved. You can return using this invitation while it
                    remains valid. Previously saved photos are retained.
                </p>
                <div
                    v-if="form.hasErrors"
                    role="alert"
                    class="space-y-1 rounded-lg border border-red-500 p-4 text-red-700 dark:text-red-400"
                >
                    <p v-for="(error, field) in form.errors" :key="field">
                        {{ error }}
                    </p>
                </div>
                <fieldset :disabled="form.processing" class="space-y-6">
                    <section class="space-y-4 rounded-xl border p-5">
                        <h2 class="text-xl font-semibold">Child details</h2>
                        <ParentPersonFields
                            :model-value="form"
                            fixed-dob
                            @update:model-value="
                                (person) => Object.assign(form, person)
                            "
                        />
                    </section>
                    <section
                        v-for="(adult, index) in form.adults"
                        :key="adult.key"
                        class="space-y-4 rounded-xl border p-5"
                    >
                        <h2 class="text-xl font-semibold">
                            {{
                                index === 0
                                    ? 'Your details'
                                    : 'Additional adult ' + index
                            }}
                        </h2>
                        <ParentPersonFields
                            v-model="form.adults[index]"
                            contact
                            relationship
                            :locked-email="index === 0"
                        />
                        <label class="flex min-h-11 items-center gap-3">
                            <input
                                v-model="adult.document_responsible"
                                type="checkbox"
                                :disabled="index === 0"
                                class="size-5"
                            />
                            <span
                                >This parent/guardian is responsible for
                                accepting nursery documents</span
                            >
                        </label>
                        <p class="text-sm text-muted-foreground">
                            Date of birth, phone number and relationship are
                            required. Collection permission defaults to on and
                            is checked by staff.
                        </p>
                        <Button
                            v-if="index > 0"
                            type="button"
                            variant="outline"
                            @click="form.adults.splice(index, 1)"
                            >Remove adult</Button
                        >
                    </section>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.adults.length >= 20"
                        @click="addAdult"
                        >Add another adult</Button
                    >
                    <ParentDocumentsList
                        v-model="form.documents"
                        :documents="documents"
                    />
                    <p class="text-sm text-muted-foreground">
                        Each nominated parent/guardian must accept personally.
                        Other nominated adults will receive their own email
                        link. Staff approval is required before the registration
                        becomes available for collection.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button type="submit">Submit for approval</Button>
                        <Button
                            type="button"
                            variant="outline"
                            @click="save('draft')"
                            >Save draft</Button
                        >
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Submitting permanently closes this registration link.
                        You will receive a separate permanent return link for
                        your adult page.
                    </p>
                </fieldset>
            </form>
        </div>
    </main>
</template>
