<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { RegistrationReview } from '@/types/parent-review';
import { update } from '@/wayfinder/App/Http/Controllers/StaffParentRegistrationController';
import { registrationPhoto } from '@/wayfinder/App/Http/Controllers/ParentMediaController';

const props = defineProps<{
    registration: RegistrationReview;
    siteSlug: string;
    canApprove: boolean;
}>();
const flags = () =>
    (props.registration.payload?.adults ?? []).map((adult) => ({
        is_manager: adult.is_manager,
        document_responsible: adult.document_responsible,
        authorised_to_collect: adult.authorised_to_collect,
    }));
const form = useForm({
    action: '',
    revision: props.registration.revision,
    message: '',
    email: props.registration.email,
    date_of_birth: props.registration.child_date_of_birth,
    is_active: true,
    child_choice: '',
    adult_choices: {} as Record<number, string>,
    adults: flags(),
});
const needsSave = computed(
    () => JSON.stringify(form.adults) !== JSON.stringify(flags()),
);
watch(
    () => props.registration.revision,
    () => {
        form.revision = props.registration.revision;
        form.adults = flags();
    },
);
function act(action: string): void {
    form.action = action;
    form.post(
        update.url({
            site: props.siteSlug,
            registration: props.registration.uuid,
        }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <article class="space-y-4 rounded-xl border bg-card p-5">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-xl font-semibold">{{ registration.child_name }}</h3>
            <span class="rounded-full bg-muted px-3 py-1 text-sm capitalize">{{
                registration.status === 'submitted'
                    ? 'Awaiting approval'
                    : registration.status
            }}</span>
        </header>
        <p>
            {{ registration.email }} · DOB
            {{ registration.child_date_of_birth }}
        </p>
        <p class="text-sm text-muted-foreground">
            Invitation expires
            {{ new Date(registration.expires_at).toLocaleString('en-GB') }} ·
            {{ registration.failed_attempts }} failed DOB attempts
        </p>
        <div
            v-if="form.hasErrors"
            role="alert"
            class="space-y-1 text-sm text-red-700 dark:text-red-400"
        >
            <p v-for="error in form.errors" :key="error">{{ error }}</p>
        </div>
        <form
            v-if="
                ['invited', 'draft', 'corrections'].includes(
                    registration.status,
                )
            "
            class="space-y-3"
            @submit.prevent="act('resend')"
        >
            <p v-if="registration.correction_message">
                Correction request: {{ registration.correction_message }}
            </p>
            <p class="text-sm text-muted-foreground">
                Resending replaces the previous link. A draft is preserved only
                when the recipient email stays the same.
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-2"
                    >Recipient email
                    <Input v-model="form.email" type="email" required
                /></label>
                <label class="grid gap-2"
                    >Child’s DOB
                    <Input v-model="form.date_of_birth" type="date" required
                /></label>
            </div>
            <Button variant="outline" :disabled="form.processing"
                >Send fresh invitation</Button
            >
        </form>
        <template
            v-if="registration.status === 'submitted' && registration.payload"
        >
            <section class="space-y-2">
                <h4 class="font-semibold">Submitted child details</h4>
                <img
                    v-if="registration.payload.has_photo"
                    :src="
                        registrationPhoto.url({
                            site: siteSlug,
                            registration: registration.uuid,
                            slot: 'child',
                        })
                    "
                    :alt="registration.payload.name"
                    class="size-24 rounded-lg object-cover"
                />
                <p>
                    {{ registration.payload.name }} ·
                    {{ registration.child_date_of_birth }}
                </p>
                <p v-if="registration.payload.also_known_as?.length">
                    Also known as
                    {{ registration.payload.also_known_as.join(', ') }}
                </p>
            </section>
            <section
                v-for="(adult, index) in registration.payload.adults"
                :key="adult.key"
                class="space-y-3 rounded-lg border p-4"
            >
                <div class="flex items-start gap-3">
                    <img
                        v-if="adult.has_photo"
                        :src="
                            registrationPhoto.url({
                                site: siteSlug,
                                registration: registration.uuid,
                                slot: adult.key,
                            })
                        "
                        :alt="adult.name"
                        class="size-20 rounded-lg object-cover"
                    />
                    <div>
                        <h4 class="font-semibold">{{ adult.name }}</h4>
                        <p>
                            DOB {{ adult.date_of_birth }} ·
                            {{ adult.relationship }}
                        </p>
                        <p>{{ adult.email }} {{ adult.phone }}</p>
                    </div>
                </div>
                <div v-if="canApprove" class="flex flex-wrap gap-4">
                    <label class="flex min-h-11 items-center gap-2"
                        ><input
                            v-model="form.adults[index].authorised_to_collect"
                            type="checkbox"
                            class="size-5"
                        />Authorised to collect</label
                    >
                    <label class="flex min-h-11 items-center gap-2"
                        ><input
                            v-model="form.adults[index].is_manager"
                            type="checkbox"
                            class="size-5"
                        />Managing parent/guardian</label
                    >
                    <label class="flex min-h-11 items-center gap-2"
                        ><input
                            v-model="form.adults[index].document_responsible"
                            type="checkbox"
                            class="size-5"
                        />Responsible for documents</label
                    >
                </div>
                <label
                    v-if="
                        canApprove &&
                        registration.duplicates?.adults[index]?.length
                    "
                    class="grid gap-2"
                >
                    Possible duplicate — choose explicitly
                    <select
                        v-model="form.adult_choices[index]"
                        class="rounded-md border bg-background p-3"
                    >
                        <option :value="undefined">Choose a person</option>
                        <option value="new">Create a different person</option>
                        <option
                            v-for="match in registration.duplicates.adults[
                                index
                            ]"
                            :key="match.id"
                            :value="String(match.id)"
                        >
                            Link existing: {{ match.name }} (#{{ match.id }})
                        </option>
                    </select>
                </label>
            </section>
            <div
                v-if="registration.outstanding.length"
                class="space-y-2 rounded-lg border border-amber-500 p-4"
            >
                <h4 class="font-semibold">Outstanding document acceptance</h4>
                <p
                    v-for="(adult, index) in registration.outstanding"
                    :key="index"
                >
                    {{ adult.name }} ({{ adult.email }}):
                    {{ adult.documents.join(', ') }}
                </p>
                <Button
                    v-if="canApprove"
                    variant="outline"
                    :disabled="form.processing"
                    @click="act('send_documents')"
                    >Send fresh acceptance links</Button
                >
            </div>
            <template v-if="canApprove">
                <p class="text-sm text-muted-foreground">
                    Collection permission belongs to the shared adult–child
                    relationship. Management rights are specific to this
                    nursery.
                </p>
                <Button
                    v-if="needsSave"
                    variant="outline"
                    :disabled="form.processing"
                    @click="act('review')"
                    >Save review choices</Button
                >
                <label
                    v-if="registration.duplicates?.child.length"
                    class="grid gap-2"
                >
                    Possible duplicate child — choose explicitly
                    <select
                        v-model="form.child_choice"
                        class="rounded-md border bg-background p-3"
                    >
                        <option value="">Choose a child</option>
                        <option value="new">Create a different child</option>
                        <option
                            v-for="match in registration.duplicates.child"
                            :key="match.id"
                            :value="String(match.id)"
                        >
                            Link existing: {{ match.name }} (#{{ match.id }})
                        </option>
                    </select>
                </label>
                <label class="flex min-h-11 items-center gap-3"
                    ><input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-5"
                    />Make child active on approval</label
                >
                <Button
                    :disabled="
                        form.processing ||
                        registration.outstanding.length > 0 ||
                        needsSave
                    "
                    @click="act('approve')"
                    >{{
                        form.is_active
                            ? 'Approve and activate'
                            : 'Approve as inactive'
                    }}</Button
                >
                <form
                    class="space-y-3 border-t pt-4"
                    @submit.prevent="act('return')"
                >
                    <label class="grid gap-2"
                        >Corrections required
                        <textarea
                            v-model="form.message"
                            class="rounded-md border bg-background p-3"
                            rows="3"
                            required
                            maxlength="5000"
                        />
                    </label>
                    <Button variant="outline" :disabled="form.processing"
                        >Return for corrections</Button
                    >
                </form>
            </template>
        </template>
    </article>
</template>
