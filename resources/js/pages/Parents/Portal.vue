<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import ParentDocumentsList from '@/components/ParentDocumentsList.vue';
import ParentPersonFields from '@/components/ParentPersonFields.vue';
import { Button } from '@/components/ui/button';
import type { ParentDocument, ParentPerson } from '@/types/parents';
import {
    store,
    destroy,
} from '@/wayfinder/App/Http/Controllers/AdultPortalController';
import { store as acceptDocuments } from '@/wayfinder/App/Http/Controllers/DocumentAcceptanceController';
import { photo } from '@/wayfinder/App/Http/Controllers/ParentMediaController';

interface PortalAdult extends ParentPerson {
    id: number;
    photo_id?: number | null;
    authorised_to_collect?: boolean;
}
interface PortalChild extends ParentPerson {
    id: number;
    photo_id: number | null;
    is_active: boolean;
    can_manage: boolean;
    adults?: PortalAdult[];
    authorised_to_collect: boolean;
    relationship: string;
}
interface PortalSite {
    id: number;
    name: string;
    slug: string;
    canAcceptDocuments: boolean;
    documents: ParentDocument[];
    children: PortalChild[];
}
const props = defineProps<{
    adult: ParentPerson;
    sites: PortalSite[];
    returnUrl: string;
    sessionExpiresAt: number;
    status: string | null;
    registrations: {
        uuid: string;
        child_name: string;
        site_name: string;
        status: string;
        correction_message: string | null;
        submitted_details: {
            name: string;
            date_of_birth: string;
            adults: ParentPerson[];
        } | null;
    }[];
    updates: {
        id: number;
        kind: string;
        status: string;
        message: string | null;
        payload: Record<string, unknown>;
    }[];
}>();
const now = ref(Date.now() / 1000);
const timer = setInterval(() => {
    now.value = Date.now() / 1000;
}, 1000);
onBeforeUnmount(() => clearInterval(timer));
const expired = computed(() => now.value >= props.sessionExpiresAt);
const expiring = computed(() => props.sessionExpiresAt - now.value <= 300);
const editing = ref<{
    site: PortalSite;
    kind: string;
    child: PortalChild | null;
    otherAdult: boolean;
} | null>(null);
const form = useForm({
    kind: 'adult',
    adult_id: null as number | null,
    child_id: null as number | null,
    name: '',
    date_of_birth: '',
    email: '' as string | null,
    phone: '' as string | null,
    also_known_as: [] as string[],
    new_photo: null as File | null,
    relationship: '',
    authorised_to_collect: true,
});
function edit(
    site: PortalSite,
    kind: string,
    child: PortalChild | null,
    adult?: PortalAdult,
): void {
    const person: ParentPerson =
        kind === 'child'
            ? child!
            : kind === 'add_adult'
              ? { name: '', also_known_as: [] }
              : (adult ?? props.adult);
    form.clearErrors();
    form.reset();
    Object.assign(form, {
        kind,
        child_id: child?.id ?? null,
        adult_id: adult?.id ?? (kind === 'adult' ? props.adult.id : null),
        name: person.name,
        date_of_birth: person.date_of_birth ?? '',
        email: person.email ?? '',
        phone: person.phone ?? '',
        also_known_as: [...(person.also_known_as ?? [])],
        new_photo: null,
        relationship: adult?.relationship ?? '',
        authorised_to_collect: adult?.authorised_to_collect ?? true,
    });
    editing.value = {
        site,
        kind,
        child,
        otherAdult: !!adult && adult.id !== props.adult.id,
    };
}
function submit(): void {
    if (!editing.value) return;
    form.post(store.url(editing.value.site.slug), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
}
function accept(site: PortalSite): void {
    router.post(acceptDocuments.url(site.slug), {}, { preserveScroll: true });
}
function displayProposalValue(value: unknown): string {
    if (Array.isArray(value)) return value.length ? value.join(', ') : 'None';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    return value === null ? 'Not provided' : String(value);
}
</script>

<template>
    <main class="min-h-screen bg-background px-4 py-8 text-foreground">
        <Head title="Your adult page" />
        <div
            v-if="expired"
            class="mx-auto max-w-xl space-y-4 rounded-xl border p-6"
            role="status"
        >
            <h1 class="text-2xl font-semibold">Your session has expired</h1>
            <a :href="returnUrl" class="underline"
                >Request a fresh access email</a
            >
        </div>
        <div v-else class="mx-auto max-w-4xl space-y-6">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <h1 class="text-3xl font-semibold">Your adult page</h1>
                <Button variant="outline" @click="router.delete(destroy.url())"
                    >Sign out</Button
                >
            </header>
            <p
                v-if="expiring"
                role="alert"
                class="rounded-lg border border-amber-500 p-4"
            >
                Your session will expire shortly. Save your proposed changes or
                use your permanent return link to request a fresh access email.
            </p>
            <p v-if="status" role="status" class="rounded-lg border p-4">
                {{ status }}
            </p>
            <section class="space-y-2 rounded-xl border p-5">
                <h2 class="text-xl font-semibold">{{ adult.name }}</h2>
                <p>{{ adult.email }}</p>
                <p v-if="adult.phone">{{ adult.phone }}</p>
                <p v-if="adult.date_of_birth">
                    Date of birth: {{ adult.date_of_birth }}
                </p>
                <p class="text-sm text-muted-foreground">
                    Bookmark your permanent return link from the registration
                    email. Changes submitted here require staff approval;
                    document acceptance is recorded immediately.
                </p>
            </section>
            <section v-if="registrations.length" class="space-y-3">
                <h2 class="text-xl font-semibold">Registration status</h2>
                <article
                    v-for="registration in registrations"
                    :key="registration.uuid"
                    class="space-y-2 rounded-xl border p-5"
                >
                    <h3 class="font-semibold">
                        {{ registration.child_name }} —
                        {{ registration.site_name }}
                    </h3>
                    <p class="capitalize">
                        {{
                            registration.status === 'submitted'
                                ? 'Awaiting approval'
                                : registration.status
                        }}
                    </p>
                    <p v-if="registration.correction_message">
                        Corrections requested:
                        {{ registration.correction_message }}. Use the fresh
                        registration link in your email.
                    </p>
                    <details v-if="registration.submitted_details">
                        <summary class="cursor-pointer py-2">
                            View submitted details
                        </summary>
                        <p>
                            {{ registration.submitted_details.name }} —
                            {{ registration.submitted_details.date_of_birth }}
                        </p>
                        <p
                            v-for="(person, index) in registration
                                .submitted_details.adults"
                            :key="index"
                        >
                            {{ person.name }} — {{ person.relationship }} —
                            {{ person.phone }}
                        </p>
                    </details>
                </article>
            </section>
            <section
                v-for="site in sites"
                :key="site.id"
                class="space-y-4 rounded-xl border p-5"
            >
                <h2 class="text-2xl font-semibold">{{ site.name }}</h2>
                <Button variant="outline" @click="edit(site, 'adult', null)"
                    >Propose changes to my details</Button
                >
                <article
                    v-for="child in site.children"
                    :key="child.id"
                    class="space-y-3 rounded-xl border p-4"
                >
                    <div class="flex items-center gap-4">
                        <img
                            v-if="child.photo_id"
                            :src="photo.url(child.photo_id)"
                            :alt="child.name"
                            class="size-20 rounded-lg object-cover"
                        />
                        <div>
                            <h3 class="text-xl font-semibold">
                                {{ child.name }}
                            </h3>
                            <p>{{ child.is_active ? 'Active' : 'Inactive' }}</p>
                        </div>
                    </div>
                    <p>
                        {{ child.relationship }} ·
                        {{
                            child.authorised_to_collect
                                ? 'Authorised to collect'
                                : 'Not authorised to collect'
                        }}
                    </p>
                    <template v-if="child.can_manage">
                        <p>Date of birth: {{ child.date_of_birth }}</p>
                        <p v-if="child.also_known_as.length">
                            Also known as: {{ child.also_known_as.join(', ') }}
                        </p>
                        <Button
                            variant="outline"
                            @click="edit(site, 'child', child)"
                            >Propose child details update</Button
                        >
                        <h4 class="font-semibold">Linked adults</h4>
                        <article
                            v-for="person in child.adults"
                            :key="person.id"
                            class="space-y-2 rounded-lg border p-3"
                        >
                            <div class="flex items-center gap-3">
                                <img
                                    v-if="person.photo_id"
                                    :src="photo.url(person.photo_id)"
                                    :alt="person.name"
                                    class="size-16 rounded-lg object-cover"
                                />
                                <div>
                                    <p class="font-medium">{{ person.name }}</p>
                                    <p>
                                        {{ person.relationship }} ·
                                        {{
                                            person.authorised_to_collect
                                                ? 'Authorised to collect'
                                                : 'Not authorised to collect'
                                        }}
                                    </p>
                                    <p>{{ person.email }} {{ person.phone }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    @click="edit(site, 'adult', child, person)"
                                    >Propose details update</Button
                                >
                                <Button
                                    variant="outline"
                                    @click="
                                        edit(
                                            site,
                                            'relationship',
                                            child,
                                            person,
                                        )
                                    "
                                    >Propose relationship update</Button
                                >
                                <Button
                                    variant="outline"
                                    @click="
                                        edit(
                                            site,
                                            'remove_adult',
                                            child,
                                            person,
                                        )
                                    "
                                    >Request adult removal</Button
                                >
                            </div>
                        </article>
                        <Button
                            variant="outline"
                            @click="edit(site, 'add_adult', child)"
                            >Propose another adult</Button
                        >
                    </template>
                </article>
                <template v-if="site.canAcceptDocuments">
                    <ParentDocumentsList :documents="site.documents" readonly />
                    <Button @click="accept(site)"
                        >Email me a document acceptance link</Button
                    >
                </template>
            </section>
            <section
                v-if="editing"
                class="space-y-4 rounded-xl border-2 border-primary p-5"
            >
                <h2 class="text-xl font-semibold">
                    {{
                        editing.kind === 'remove_adult'
                            ? 'Request removal of ' + form.name
                            : 'Propose an update'
                    }}
                </h2>
                <p>
                    Staff must approve your proposal before it takes effect.
                    Shared person details and relationships can affect other
                    nurseries too.
                </p>
                <form class="space-y-4" @submit.prevent="submit">
                    <template v-if="editing.kind === 'relationship'">
                        <label class="grid gap-2"
                            >Relationship
                            <input
                                v-model="form.relationship"
                                class="rounded-md border bg-background p-3"
                                required
                        /></label>
                        <label class="flex min-h-11 items-center gap-3"
                            ><input
                                v-model="form.authorised_to_collect"
                                type="checkbox"
                                class="size-5"
                            />Authorised to collect</label
                        >
                    </template>
                    <ParentPersonFields
                        v-else-if="editing.kind !== 'remove_adult'"
                        :model-value="form"
                        :dob="
                            !editing.otherAdult || editing.kind === 'add_adult'
                        "
                        :contact="editing.kind !== 'child'"
                        :relationship="editing.kind === 'add_adult'"
                        @update:model-value="
                            (person) => Object.assign(form, person)
                        "
                    />
                    <div
                        v-if="form.hasErrors"
                        role="alert"
                        class="text-sm text-red-700 dark:text-red-400"
                    >
                        <p v-for="error in form.errors" :key="error">
                            {{ error }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <Button :disabled="form.processing"
                            >Submit for staff review</Button
                        ><Button
                            type="button"
                            variant="outline"
                            @click="editing = null"
                            >Cancel</Button
                        >
                    </div>
                </form>
            </section>
            <section v-if="updates.length" class="space-y-3">
                <h2 class="text-xl font-semibold">Your proposed updates</h2>
                <article
                    v-for="update in updates"
                    :key="update.id"
                    class="space-y-2 rounded-lg border p-4"
                >
                    <p class="capitalize">
                        {{ update.kind.replaceAll('_', ' ') }} —
                        {{ update.status }}
                    </p>
                    <p v-if="update.message">{{ update.message }}</p>
                    <details v-if="Object.keys(update.payload).length">
                        <summary class="cursor-pointer py-2">
                            View proposal
                        </summary>
                        <dl class="space-y-2">
                            <div
                                v-for="(value, field) in update.payload"
                                :key="field"
                            >
                                <dt
                                    class="text-sm text-muted-foreground capitalize"
                                >
                                    {{ String(field).replaceAll('_', ' ') }}
                                </dt>
                                <dd>{{ displayProposalValue(value) }}</dd>
                            </div>
                        </dl>
                    </details>
                </article>
            </section>
        </div>
    </main>
</template>
