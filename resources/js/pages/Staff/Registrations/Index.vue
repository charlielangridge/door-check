<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { formatLongDate } from '@/lib/dates';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
const activeTab = ref('registrations');
const inviteOpen = ref(false);
const search = ref('');
const selectedRegistration = ref(
    props.registrations.find((item) => item.status !== 'approved')?.uuid ?? '',
);
const selectedUpdate = ref(props.updates[0]?.id ?? null);
const updateDrafts = new Map<
    number,
    { message: string; adult_choice: string }
>();
watch(selectedUpdate, (id, previous) => {
    if (previous !== null) {
        updateDrafts.set(previous, {
            message: review.message,
            adult_choice: review.adult_choice,
        });
    }
    review.reset();
    review.clearErrors();
    if (id !== null) {
        Object.assign(review, updateDrafts.get(id) ?? {});
    }
});
watch(
    () => props.updates,
    (updates) => {
        if (!updates.some((item) => item.id === selectedUpdate.value)) {
            selectedUpdate.value = updates[0]?.id ?? null;
        }
    },
);
const selectedChild = ref(props.children[0]?.id ?? null);
const filteredRegistrations = computed(() =>
    props.registrations.filter(
        (item) =>
            (showApproved.value || item.status !== 'approved') &&
            (item.child_name + ' ' + item.email)
                .toLowerCase()
                .includes(search.value.toLowerCase()),
    ),
);
const filteredChildren = computed(() =>
    props.children.filter((item) =>
        item.name.toLowerCase().includes(search.value.toLowerCase()),
    ),
);
const membershipErrors = ref<string[]>([]);
function sendInvite(): void {
    invite.post(store.url(props.site.slug), {
        preserveScroll: true,
        onSuccess: () => {
            invite.reset();
            inviteOpen.value = false;
        },
    });
}
function reviewProposal(id: number, action: string): void {
    review.action = action;
    review.post(reviewUpdate.url({ site: props.site.slug, update: id }), {
        preserveScroll: true,
        onSuccess: () => {
            updateDrafts.delete(id);
            review.reset();
        },
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
function display(value: unknown, field: string | number): string {
    if (field === 'date_of_birth' && typeof value === 'string') {
        return formatLongDate(value);
    }

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
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-muted-foreground">
                {{
                    registrations.filter((item) => item.status === 'submitted')
                        .length
                }}
                awaiting approval
            </p>
            <Button @click="inviteOpen = true">Invite parent</Button>
        </div>
        <nav
            aria-label="Registration views"
            class="flex flex-wrap gap-2 border-b pb-3"
        >
            <Button
                v-for="tab in [
                    { id: 'registrations', label: 'Registrations' },
                    ...(canApprove
                        ? [
                              {
                                  id: 'updates',
                                  label:
                                      'Proposed updates (' +
                                      updates.length +
                                      ')',
                              },
                          ]
                        : []),
                    { id: 'access', label: 'Child access' },
                ]"
                :key="tab.id"
                :variant="activeTab === tab.id ? 'default' : 'ghost'"
                :aria-pressed="activeTab === tab.id"
                @click="
                    activeTab = tab.id;
                    search = '';
                "
                >{{ tab.label }}</Button
            >
        </nav>
        <Dialog v-model:open="inviteOpen">
            <DialogContent>
                <DialogHeader
                    ><DialogTitle>Invite a parent</DialogTitle
                    ><DialogDescription
                        >Send a secure link to register their
                        child.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="sendInvite">
                    <div class="grid gap-4">
                        <label class="grid gap-2"
                            >Child’s full name
                            <Input
                                v-model="invite.name"
                                required
                                maxlength="255"
                        /></label>
                        <label class="grid gap-2"
                            >Child’s date of birth
                            <Input
                                v-model="invite.date_of_birth"
                                type="date"
                                required
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
                    <Button :disabled="invite.processing"
                        >Send invitation</Button
                    >
                </form>
            </DialogContent>
        </Dialog>
        <section v-show="activeTab === 'registrations'" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Input
                    v-model="search"
                    aria-label="Search registrations"
                    placeholder="Search child or email…"
                    class="max-w-sm"
                />
                <label class="flex min-h-11 items-center gap-2 text-sm"
                    ><input
                        v-model="showApproved"
                        type="checkbox"
                        class="size-4"
                    />Include approved</label
                >
            </div>
            <div class="grid items-start gap-5 lg:grid-cols-3">
                <div
                    class="space-y-2 rounded-3xl border border-dashed bg-background p-3"
                >
                    <button
                        v-for="item in filteredRegistrations"
                        :key="item.uuid"
                        type="button"
                        class="w-full rounded-xl border p-3 text-left transition hover:bg-muted"
                        :class="
                            selectedRegistration === item.uuid
                                ? 'border-primary bg-muted'
                                : 'border-transparent'
                        "
                        :aria-pressed="selectedRegistration === item.uuid"
                        @click="selectedRegistration = item.uuid"
                    >
                        <span class="block font-semibold">{{
                            item.child_name
                        }}</span>
                        <span
                            class="block truncate text-sm text-muted-foreground"
                            >{{ item.email }}</span
                        >
                        <span
                            class="mt-2 inline-block rounded-full bg-muted px-2 py-1 text-xs capitalize"
                            >{{
                                item.status === 'submitted'
                                    ? 'Awaiting approval'
                                    : item.status
                            }}</span
                        >
                    </button>
                    <p
                        v-if="!filteredRegistrations.length"
                        class="p-3 text-sm text-muted-foreground"
                    >
                        No matching registrations.
                    </p>
                </div>
                <div class="lg:col-span-2">
                    <StaffRegistrationReview
                        v-for="registration in registrations"
                        v-show="selectedRegistration === registration.uuid"
                        :key="registration.id"
                        :registration="registration"
                        :site-slug="site.slug"
                        :can-approve="canApprove"
                    />
                    <p
                        v-if="!selectedRegistration"
                        class="rounded-3xl border border-dashed p-6 text-muted-foreground"
                    >
                        Select a registration to review.
                    </p>
                </div>
            </div>
        </section>
        <section
            v-if="canApprove"
            v-show="activeTab === 'updates'"
            class="space-y-4"
        >
            <label
                v-if="updates.length"
                class="grid max-w-md gap-2 text-sm font-medium"
                >Choose an update<select
                    v-model="selectedUpdate"
                    :disabled="review.processing"
                    class="min-h-11 rounded-md border bg-background px-3"
                >
                    <option
                        v-for="item in updates"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.submitted_by }} —
                        {{ item.kind.replaceAll('_', ' ') }}
                    </option>
                </select></label
            >
            <p
                v-else
                class="rounded-3xl border border-dashed p-6 text-muted-foreground"
            >
                No proposed updates awaiting review.
            </p>
            <p
                v-for="error in review.errors"
                :key="error"
                role="alert"
                class="text-sm text-red-700 dark:text-red-400"
            >
                {{ error }}
            </p>
            <article
                v-for="update in updates.filter(
                    (item) => item.id === selectedUpdate,
                )"
                :key="update.id"
                class="space-y-4 rounded-xl border bg-card p-5"
            >
                <h3 class="font-semibold">
                    {{ update.submitted_by }} —
                    {{ update.kind.replaceAll('_', ' ') }}
                </h3>
                <div class="space-y-4">
                    <details class="rounded-lg border p-3">
                        <summary class="cursor-pointer text-sm font-medium">
                            View previously approved details
                        </summary>
                        <dl>
                            <template
                                v-for="(value, key) in update.original ?? {}"
                                :key="key"
                                ><dt
                                    class="mt-2 text-sm text-muted-foreground capitalize"
                                >
                                    {{ String(key).replaceAll('_', ' ') }}
                                </dt>
                                <dd>{{ display(value, key) }}</dd></template
                            >
                        </dl>
                    </details>
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
                                <dd>{{ display(value, key) }}</dd></template
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
        <section v-show="activeTab === 'access'" class="space-y-4">
            <label class="grid max-w-md gap-2 text-sm font-medium"
                >Choose a child<select
                    v-model="selectedChild"
                    class="min-h-11 rounded-md border bg-background px-3"
                >
                    <option
                        v-for="item in children"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}{{ item.is_active ? '' : ' (inactive)' }}
                    </option>
                </select></label
            >
            <p
                v-if="!children.length"
                class="rounded-3xl border border-dashed p-6 text-muted-foreground"
            >
                No children at this nursery.
            </p>
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
                v-for="child in filteredChildren.filter(
                    (item) => item.id === selectedChild,
                )"
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
                <details
                    v-for="adult in child.adults"
                    :key="adult.id"
                    class="space-y-2 rounded-lg border p-4"
                >
                    <summary class="min-h-11 cursor-pointer py-2 font-semibold">
                        {{ adult.name }} — {{ adult.relationship }}
                    </summary>
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
                </details>
            </article>
        </section>
    </div>
</template>
