<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StaffRegistrationReview from '@/components/StaffRegistrationReview.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { StaffSite } from '@/types/collections';
import type { RegistrationReview } from '@/types/parent-review';
import {
    store,
    reviewUpdate,
    membership,
} from '@/wayfinder/App/Http/Controllers/StaffParentRegistrationController';
import { updatePhoto } from '@/wayfinder/App/Http/Controllers/ParentMediaController';

defineOptions({
    layout: (props: { site: StaffSite }) => [
        StaffLayout,
        { site: props.site, title: 'Registrations', section: 'children' },
    ],
});
interface MemberAdult {
    is_linked: boolean;
    id: number;
    name: string;
    email: string | null;
    relationship: string;
    authorised_to_collect: boolean;
    is_manager: boolean;
    document_responsible: boolean;
    access_suspended: boolean;
    access_id: number | null;
    outstanding_documents: { title: string }[];
}
interface MemberChild {
    id: number;
    uuid: string;
    name: string;
    is_active: boolean;
    adults: MemberAdult[];
}
const props = defineProps<{
    site: StaffSite;
    canApprove: boolean;
    status: string | null;
    registrations: RegistrationReview[];
    children: MemberChild[];
    updates: {
        id: number;
        kind: string;
        submitted_by: string;
        payload: Record<string, unknown>;
        original: Record<string, unknown> | null;
        has_photo: boolean;
        adult_matches: { id: number; name: string }[];
        email_verified_at: string | null;
    }[];
}>();
const invite = useForm({ name: '', date_of_birth: '', email: '' });
const review = useForm({
    action: 'approve_update',
    message: '',
    adult_choice: '',
});
const showApproved = ref(false);
const membershipErrors = ref<string[]>([]);
function sendInvite(): void {
    invite.post(store.url(props.site.slug), {
        preserveScroll: true,
        onSuccess: () => invite.reset(),
    });
}
function reviewProposal(id: number, action: string): void {
    review.action = action;
    review.post(reviewUpdate.url({ site: props.site.slug, update: id }), {
        preserveScroll: true,
    });
}
function change(
    child: MemberChild,
    data: Record<string, string | number | boolean>,
): void {
    membershipErrors.value = [];
    router.post(
        membership.url({ site: props.site.slug, child: child.uuid }),
        data,
        {
            preserveScroll: true,
            onError: (errors) => {
                membershipErrors.value = Object.values(errors);
            },
        },
    );
}
function checked(event: Event): boolean {
    return (event.target as HTMLInputElement).checked;
}
function display(value: unknown): string {
    return Array.isArray(value)
        ? value.join(', ')
        : value === null
          ? 'Not provided'
          : String(value);
}
</script>

<template>
    <div class="space-y-6">
        <Head :title="site.name + ' Registrations'" />
        <p v-if="status" role="status" class="rounded-lg border bg-card p-4">
            {{ status }}
        </p>
        <form
            class="space-y-4 rounded-xl border bg-card p-5"
            @submit.prevent="sendInvite"
        >
            <h2 class="text-xl font-semibold">
                Invite a parent to register a child
            </h2>
            <div class="grid gap-4 md:grid-cols-3">
                <label class="grid gap-2"
                    >Child’s full name
                    <Input v-model="invite.name" required maxlength="255"
                /></label>
                <label class="grid gap-2"
                    >Child’s date of birth
                    <Input v-model="invite.date_of_birth" type="date" required
                /></label>
                <label class="grid gap-2"
                    >Adult’s email
                    <Input
                        v-model="invite.email"
                        type="email"
                        required
                        maxlength="255"
                /></label>
            </div>
            <p
                v-for="error in invite.errors"
                :key="error"
                role="alert"
                class="text-sm text-red-700 dark:text-red-400"
            >
                {{ error }}
            </p>
            <Button :disabled="invite.processing">Send invitation</Button>
        </form>
        <section class="space-y-4">
            <h2 class="text-2xl font-semibold">
                Invitations and registrations
            </h2>
            <label class="flex min-h-11 items-center gap-3"
                ><input
                    v-model="showApproved"
                    type="checkbox"
                    class="size-5"
                />Include approved registrations</label
            >
            <StaffRegistrationReview
                v-for="registration in registrations.filter(
                    (item) => showApproved || item.status !== 'approved',
                )"
                :key="registration.id"
                :registration="registration"
                :site-slug="site.slug"
                :can-approve="canApprove"
            />
            <p v-if="!registrations.length" class="text-muted-foreground">
                No parent invitations yet.
            </p>
        </section>
        <section v-if="canApprove && updates.length" class="space-y-4">
            <h2 class="text-2xl font-semibold">Proposed details updates</h2>
            <p
                v-for="error in review.errors"
                :key="error"
                role="alert"
                class="text-sm text-red-700 dark:text-red-400"
            >
                {{ error }}
            </p>
            <article
                v-for="update in updates"
                :key="update.id"
                class="space-y-4 rounded-xl border bg-card p-5"
            >
                <h3 class="font-semibold">
                    {{ update.submitted_by }} —
                    {{ update.kind.replaceAll('_', ' ') }}
                </h3>
                <div class="grid gap-4 md:grid-cols-2">
                    <section>
                        <h4 class="font-medium">
                            Approved details when proposed
                        </h4>
                        <dl>
                            <template
                                v-for="(value, key) in update.original ?? {}"
                                :key="key"
                                ><dt
                                    class="mt-2 text-sm text-muted-foreground capitalize"
                                >
                                    {{ String(key).replaceAll('_', ' ') }}
                                </dt>
                                <dd>{{ display(value) }}</dd></template
                            >
                        </dl>
                    </section>
                    <section>
                        <h4 class="font-medium">Proposed details</h4>
                        <dl>
                            <template
                                v-for="(value, key) in update.payload"
                                :key="key"
                                ><dt
                                    class="mt-2 text-sm text-muted-foreground capitalize"
                                >
                                    {{ String(key).replaceAll('_', ' ') }}
                                </dt>
                                <dd>{{ display(value) }}</dd></template
                            >
                        </dl>
                    </section>
                </div>
                <img
                    v-if="update.has_photo"
                    :src="
                        updatePhoto.url({ site: site.slug, update: update.id })
                    "
                    alt="Proposed photo"
                    class="size-24 rounded-lg object-cover"
                />
                <p v-if="update.payload.email" class="text-sm">
                    New-address verification:
                    {{
                        update.email_verified_at
                            ? 'Verified'
                            : 'Required if the email is changing'
                    }}
                </p>
                <label v-if="update.adult_matches.length" class="grid gap-2"
                    >Possible duplicate adult
                    <select
                        v-model="review.adult_choice"
                        class="rounded-md border bg-background p-3"
                    >
                        <option value="">Choose explicitly</option>
                        <option value="new">Create a different person</option>
                        <option
                            v-for="adult in update.adult_matches"
                            :key="adult.id"
                            :value="String(adult.id)"
                        >
                            Link {{ adult.name }} (#{{ adult.id }})
                        </option>
                    </select>
                </label>
                <label class="grid gap-2"
                    >Review message
                    <Input v-model="review.message" maxlength="5000"
                /></label>
                <div class="flex flex-wrap gap-3">
                    <Button
                        :disabled="review.processing"
                        @click="reviewProposal(update.id, 'approve_update')"
                        >Approve update</Button
                    ><Button
                        variant="outline"
                        :disabled="review.processing"
                        @click="reviewProposal(update.id, 'reject_update')"
                        >Reject update</Button
                    >
                </div>
            </article>
        </section>
        <section class="space-y-4">
            <h2 class="text-2xl font-semibold">Child and adult access</h2>
            <div
                v-if="membershipErrors.length"
                role="alert"
                class="text-sm text-red-700 dark:text-red-400"
            >
                <p v-for="error in membershipErrors" :key="error">
                    {{ error }}
                </p>
            </div>
            <p class="text-sm text-muted-foreground">
                Active status and parent-page rights apply at this nursery.
                Collection permission applies to the adult–child relationship
                across all nurseries. Turning it off blocks collection
                overrides.
            </p>
            <article
                v-for="child in children"
                :key="child.id"
                class="space-y-4 rounded-xl border bg-card p-5"
            >
                <h3 class="text-xl font-semibold">{{ child.name }}</h3>
                <label class="flex min-h-11 items-center gap-3"
                    ><input
                        :checked="child.is_active"
                        type="checkbox"
                        class="size-5"
                        @change="
                            change(child, {
                                action: 'active',
                                value: checked($event),
                            })
                        "
                    />Active at this nursery</label
                >
                <section
                    v-for="adult in child.adults"
                    :key="adult.id"
                    class="space-y-2 rounded-lg border p-4"
                >
                    <h4 class="font-semibold">
                        {{ adult.name }} — {{ adult.relationship }}
                    </h4>
                    <label class="flex min-h-11 items-center gap-3"
                        ><input
                            :checked="adult.authorised_to_collect"
                            type="checkbox"
                            class="size-5"
                            @change="
                                change(child, {
                                    action: 'collection',
                                    adult_id: adult.id,
                                    value: checked($event),
                                })
                            "
                        />Authorised to collect</label
                    >
                    <template v-if="canApprove">
                        <label class="flex min-h-11 items-center gap-3"
                            ><input
                                :checked="adult.is_linked"
                                type="checkbox"
                                class="size-5"
                                @change="
                                    change(child, {
                                        action: 'association',
                                        adult_id: adult.id,
                                        value: checked($event),
                                    })
                                "
                            />Linked to this child at this nursery</label
                        >
                        <label class="flex min-h-11 items-center gap-3"
                            ><input
                                :checked="adult.is_manager"
                                type="checkbox"
                                class="size-5"
                                @change="
                                    change(child, {
                                        action: 'rights',
                                        adult_id: adult.id,
                                        is_manager: checked($event),
                                        document_responsible:
                                            adult.document_responsible,
                                    })
                                "
                            />Managing parent/guardian at this nursery</label
                        >
                        <label class="flex min-h-11 items-center gap-3"
                            ><input
                                :checked="adult.document_responsible"
                                type="checkbox"
                                class="size-5"
                                @change="
                                    change(child, {
                                        action: 'rights',
                                        adult_id: adult.id,
                                        is_manager: adult.is_manager,
                                        document_responsible: checked($event),
                                    })
                                "
                            />Responsible for documents here</label
                        >
                        <label class="flex min-h-11 items-center gap-3"
                            ><input
                                :checked="adult.access_suspended"
                                type="checkbox"
                                class="size-5"
                                @change="
                                    change(child, {
                                        action: 'suspension',
                                        adult_id: adult.id,
                                        value: checked($event),
                                    })
                                "
                            />Suspend this adult’s child-record access at this
                            nursery</label
                        >
                        <Button
                            v-if="adult.email"
                            variant="outline"
                            @click="
                                change(child, {
                                    action: 'enable_access',
                                    adult_id: adult.id,
                                })
                            "
                            >{{
                                adult.access_id
                                    ? 'Send adult-page access email'
                                    : 'Enable online access and email link'
                            }}</Button
                        >
                        <p v-else class="text-sm text-muted-foreground">
                            Record an email address to enable online access.
                        </p>
                    </template>
                    <p
                        v-if="adult.outstanding_documents.length"
                        class="font-medium text-amber-800 dark:text-amber-300"
                    >
                        Outstanding documents:
                        {{
                            adult.outstanding_documents
                                .map((doc) => doc.title)
                                .join(', ')
                        }}
                    </p>
                </section>
            </article>
        </section>
    </div>
</template>
