<script setup lang="ts">
import { Modal } from '@inertiaui/modal-vue';
import { router } from '@inertiajs/vue3';
import {
    Check,
    ChevronRight,
    CircleAlert,
    LoaderCircle,
    LockKeyhole,
    RefreshCw,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useStaffCollectionRoutes } from '@/composables/useStaffCollectionRoutes';
import { login } from '@/routes';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import PersonMatchPicker from '@/components/PersonMatchPicker.vue';
import { collectionHttp, CollectionHttpError } from '@/lib/collectionHttp';
import { useCollectionChannel } from '@/composables/useCollectionChannel';
import type {
    CollectionPerson,
    CollectionTimeline,
    StaffCollection,
} from '@/types/collections';

const props = defineProps<{
    collection: StaffCollection;
    cancellation_reasons: { value: string; label: string }[];
    photo_reasons: { value: string; label: string }[];
    admin_site?: string | null;
}>();
const {
    checks,
    data: loadData,
    timeline: loadTimeline,
    update,
} = useStaffCollectionRoutes(props.admin_site);
const record = ref(props.collection);
const activeUuid = ref(
    record.value.children.find(
        (child) => !['collected', 'cancelled'].includes(child.state),
    )?.uuid ?? record.value.children[0].uuid,
);
const entry = computed(
    () =>
        record.value.children.find(
            (child) => child.uuid === activeUuid.value,
        ) ?? record.value.children[0],
);
const modal = ref<{ close: () => void } | null>(null);
const adult = ref<CollectionPerson | null>(record.value.adult);
const child = ref<CollectionPerson | null>(entry.value.child);
const adultChanging = ref(!adult.value);
const childChanging = ref(!child.value);
const busy = ref(false);
const stale = ref(false);
const error = ref('');
const fieldErrors = ref<Record<string, string[]>>({});
const cancelling = ref(false);
const checking = ref(false);
const preview = ref<{
    relationship: string | null;
    unavailable_photos: string[];
    duplicate: boolean;
} | null>(null);
const photoFailures = ref<string[]>([]);
const timeline = ref(record.value.timeline);
const timelineBusy = ref(false);
const draft = reactive({
    relationship_override_reason: '',
    match_change_reason: '',
    photo_verification_reason: '',
    photo_verification_notes: '',
    cancellation_reason: '',
    cancellation_notes: '',
});
let previewGeneration = 0;
let fallbackTimer: ReturnType<typeof setInterval> | undefined;
let refreshBusy = false;

const dirty = computed(
    () =>
        adult.value?.id !== record.value.adult?.id ||
        child.value?.id !== entry.value.child?.id ||
        Object.values(draft).some(Boolean),
);
const missingPhotos = computed(() => [
    ...new Set([
        ...(preview.value?.unavailable_photos ?? []),
        ...photoFailures.value,
    ]),
]);
const changedMatch = computed(
    () =>
        (record.value.adult && adult.value?.id !== record.value.adult.id) ||
        (entry.value.child && child.value?.id !== entry.value.child.id),
);
const canReview = computed(
    () =>
        adult.value &&
        child.value &&
        preview.value &&
        !preview.value.duplicate &&
        !checking.value &&
        !stale.value &&
        !busy.value &&
        (preview.value.relationship ||
            draft.relationship_override_reason.trim()) &&
        (!changedMatch.value || draft.match_change_reason.trim()) &&
        (!missingPhotos.value.length ||
            (draft.photo_verification_reason &&
                (draft.photo_verification_reason !== 'other' ||
                    draft.photo_verification_notes.trim()))),
);

function resetDraft(): void {
    adult.value = record.value.adult;
    child.value = entry.value.child;
    adultChanging.value = !adult.value;
    childChanging.value = !child.value;
    Object.keys(draft).forEach((key) => {
        Reflect.set(draft, key, '');
    });
    cancelling.value = false;
    photoFailures.value = [];
    fieldErrors.value = {};
    preview.value = null;
    void refreshChecks();
}

async function refreshChecks(): Promise<void> {
    const generation = ++previewGeneration;
    preview.value = null;
    if (
        !adult.value ||
        !child.value ||
        entry.value.state !== 'needs_checking'
    ) {
        checking.value = false;
        return;
    }
    checking.value = true;
    try {
        const result = await collectionHttp<{
            relationship: string | null;
            unavailable_photos: string[];
            duplicate: boolean;
        }>(
            checks.url(entry.value.uuid, {
                query: { adult_id: adult.value.id, child_id: child.value.id },
            }),
        );
        if (generation === previewGeneration) preview.value = result;
    } catch (exception) {
        if (generation === previewGeneration)
            error.value =
                exception instanceof Error
                    ? exception.message
                    : 'Unable to check these identities.';
    } finally {
        if (generation === previewGeneration) checking.value = false;
    }
}

watch(
    [() => adult.value?.id, () => child.value?.id, activeUuid],
    refreshChecks,
    { immediate: true },
);

async function refresh(force = false): Promise<void> {
    if (refreshBusy || busy.value) return;
    refreshBusy = true;
    try {
        const latest = await collectionHttp<StaffCollection>(
            loadData.url(record.value.uuid),
        );
        if (latest.revision !== record.value.revision || force) {
            if (dirty.value && !force) stale.value = true;
            else {
                record.value = latest;
                timeline.value = latest.timeline;
                stale.value = false;
                resetDraft();
            }
        }
    } catch (exception) {
        if (
            exception instanceof CollectionHttpError &&
            exception.status === 401
        ) {
            router.visit(login());
            return;
        }
        stale.value = true;
        error.value = 'Connection unavailable. Refresh before making changes.';
    } finally {
        refreshBusy = false;
    }
}

useCollectionChannel(`staff.${record.value.site_id}`, () => {
    void refresh();
});
fallbackTimer = setInterval(() => {
    void refresh();
}, 15000);
onBeforeUnmount(() => {
    clearInterval(fallbackTimer);
    ++previewGeneration;
});

async function discard(): Promise<boolean> {
    if (!dirty.value) return true;
    if (!window.confirm('Discard these unsaved selections and explanations?'))
        return false;
    try {
        await collectionHttp(update.url(entry.value.uuid), {
            action: 'discard',
            revision: record.value.revision,
        });
        return true;
    } catch {
        error.value =
            'Could not record the discarded edits. Reconnect and try again.';
        return false;
    }
}

async function closeModal(): Promise<void> {
    if (!busy.value && (await discard())) modal.value?.close();
}
async function selectChild(uuid: string): Promise<void> {
    if (uuid === activeUuid.value || busy.value || !(await discard())) return;
    activeUuid.value = uuid;
    error.value = '';
    resetDraft();
}

async function refreshLatest(): Promise<void> {
    if (!(await discard())) return;
    error.value = '';
    await refresh(true);
}

async function save(
    action: 'review' | 'prepare' | 'collect' | 'cancel' | 'flag_duplicate',
): Promise<void> {
    if (busy.value || stale.value) return;
    busy.value = true;
    error.value = '';
    fieldErrors.value = {};
    try {
        const payload: Record<string, unknown> = {
            action,
            revision: record.value.revision,
        };
        if (action === 'flag_duplicate') payload.child_id = child.value?.id;
        if (action === 'review')
            Object.assign(payload, {
                adult_id: adult.value?.id,
                child_id: child.value?.id,
                identity_confirmed: true,
                relationship_override_reason:
                    draft.relationship_override_reason,
                match_change_reason: draft.match_change_reason,
                photo_verification_reason: draft.photo_verification_reason,
                photo_verification_notes: draft.photo_verification_notes,
                unavailable_photos: photoFailures.value,
            });
        if (action === 'cancel')
            Object.assign(payload, {
                cancellation_reason: draft.cancellation_reason,
                cancellation_notes: draft.cancellation_notes,
            });
        const result = await collectionHttp<{
            saved: boolean;
            collection: StaffCollection;
        }>(update.url(entry.value.uuid), payload);
        record.value = result.collection;
        timeline.value = result.collection.timeline;
        resetDraft();
        router.reload({ only: ['requests'] });
    } catch (exception) {
        if (exception instanceof CollectionHttpError) {
            if (exception.status === 401) {
                router.visit(login());
                return;
            }
            fieldErrors.value = exception.errors;
            error.value =
                exception.status === 409
                    ? 'This request has changed—review the latest details.'
                    : exception.message;
            stale.value = exception.status === 409;
        } else {
            stale.value = true;
            error.value =
                'The server has not confirmed this action. Refresh to check its saved status before retrying.';
        }
    } finally {
        busy.value = false;
    }
}

async function earlierActivity(): Promise<void> {
    if (!timeline.value.next_cursor || timelineBusy.value) return;
    timelineBusy.value = true;
    try {
        const result = await collectionHttp<CollectionTimeline>(
            loadTimeline.url(record.value.uuid, {
                query: { before: timeline.value.next_cursor },
            }),
        );
        timeline.value = {
            events: [...timeline.value.events, ...result.events],
            next_cursor: result.next_cursor,
        };
    } catch {
        error.value = 'Unable to load earlier activity. Please try again.';
    } finally {
        timelineBusy.value = false;
    }
}

function photoFailed(kind: string): void {
    if (!photoFailures.value.includes(kind))
        photoFailures.value = [...photoFailures.value, kind];
}
</script>

<template>
    <Modal
        ref="modal"
        max-width="7xl"
        :close-button="false"
        :close-explicitly="true"
        padding-classes="p-0"
    >
        <div class="bg-background text-foreground">
            <header
                class="flex items-start justify-between gap-5 border-b p-6 lg:px-8"
            >
                <div>
                    <p class="text-sm text-muted-foreground">
                        {{ record.site_name }} · Collection request
                    </p>
                    <h1 class="mt-1 text-2xl font-semibold sm:text-3xl">
                        {{ record.adult?.name ?? record.submitted_adult_name }}
                    </h1>
                </div>
                <Button
                    variant="outline"
                    class="min-h-12 gap-2"
                    :disabled="busy"
                    @click="closeModal"
                    ><X class="size-5" />Close</Button
                >
            </header>
            <div class="space-y-6 p-5 lg:p-8">
                <div
                    v-if="error || stale"
                    role="alert"
                    class="space-y-3 rounded-2xl border border-amber-400 bg-amber-50 p-5 text-amber-950"
                >
                    <p class="font-semibold">
                        {{
                            error ||
                            'This request has changed—review the latest details.'
                        }}
                    </p>
                    <ul
                        v-if="Object.keys(fieldErrors).length"
                        class="list-inside list-disc space-y-1"
                    >
                        <li
                            v-for="(messages, field) in fieldErrors"
                            :key="field"
                        >
                            {{ messages.join(' ') }}
                        </li>
                    </ul>
                    <Button
                        v-if="stale"
                        variant="outline"
                        :disabled="busy"
                        @click="refreshLatest"
                        ><RefreshCw class="size-5" />Refresh latest
                        details</Button
                    >
                </div>
                <nav
                    v-if="record.children.length > 1"
                    class="flex flex-wrap gap-3"
                    aria-label="Children in this request"
                >
                    <button
                        v-for="item in record.children"
                        :key="item.uuid"
                        type="button"
                        class="min-h-16 rounded-xl border-2 px-5 py-3 text-left"
                        :class="
                            item.uuid === activeUuid
                                ? 'border-sky-600 bg-sky-50 text-sky-950 dark:bg-sky-950 dark:text-sky-50'
                                : 'border-border'
                        "
                        :aria-pressed="item.uuid === activeUuid"
                        :disabled="busy"
                        @click="selectChild(item.uuid)"
                    >
                        <span class="block text-lg font-semibold">{{
                            item.child?.name ?? item.submitted_name
                        }}</span
                        ><span class="text-sm">{{ item.label }}</span>
                    </button>
                </nav>
                <div
                    v-if="
                        entry.state === 'needs_checking' &&
                        (record.adult_match_status === 'matching_failed' ||
                            entry.match_status === 'matching_failed')
                    "
                    class="flex gap-3 rounded-xl bg-amber-100 p-5 text-amber-950"
                >
                    <CircleAlert class="size-6 shrink-0" />
                    <p>
                        Automatic matching unavailable. Select and check the
                        adult and child manually.
                    </p>
                </div>
                <section class="space-y-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <h2 class="text-xl font-semibold">Collecting adult</h2>
                        <span
                            v-if="record.adult_locked"
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                            ><LockKeyhole class="size-4" />Adult confirmed for
                            this request</span
                        ><Button
                            v-else-if="
                                adult && entry.state === 'needs_checking'
                            "
                            variant="outline"
                            class="min-h-12"
                            @click="adultChanging = !adultChanging"
                            >{{
                                adultChanging
                                    ? 'Keep selection'
                                    : 'Change match'
                            }}</Button
                        >
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Submitted name: {{ record.submitted_adult_name
                        }}<span v-if="record.adult_match_confidence != null">
                            · Automatic name confidence
                            {{
                                Math.round(record.adult_match_confidence)
                            }}%</span
                        ><span v-if="record.threshold != null">
                            · Threshold {{ record.threshold }}%</span
                        >
                    </p>
                    <PersonMatchPicker
                        v-if="
                            entry.state === 'needs_checking' &&
                            adultChanging &&
                            !record.adult_locked
                        "
                        v-model="adult"
                        :candidates="record.adult_candidates"
                        person="adult"
                        :entry-uuid="entry.uuid"
                        :admin-site="admin_site"
                    />
                    <div class="grid grid-cols-2 gap-4">
                        <figure class="rounded-2xl border bg-muted/30 p-4">
                            <figcaption class="mb-3 font-medium">
                                Stored adult photo<span
                                    v-if="adult"
                                    class="block text-sm text-muted-foreground"
                                    >{{ adult.name }}</span
                                >
                            </figcaption>
                            <img
                                v-if="
                                    adult?.photo &&
                                    !photoFailures.includes('stored_adult')
                                "
                                :src="adult.photo"
                                :alt="`Stored photo of ${adult.name}`"
                                class="mx-auto aspect-square max-h-64 rounded-xl object-contain"
                                @error="photoFailed('stored_adult')"
                            />
                            <div
                                v-else
                                class="flex min-h-40 flex-col items-center justify-center gap-3 text-muted-foreground"
                            >
                                <UserRound class="size-14" /><span
                                    >Photo unavailable</span
                                >
                            </div>
                        </figure>
                        <figure class="rounded-2xl border bg-muted/30 p-4">
                            <figcaption class="mb-3 font-medium">
                                Current adult photo<span
                                    class="block text-sm text-muted-foreground"
                                    >Taken with this request</span
                                >
                            </figcaption>
                            <img
                                v-if="
                                    record.requester_photo &&
                                    !photoFailures.includes('current_adult')
                                "
                                :src="record.requester_photo"
                                alt="Adult photo submitted with this request"
                                class="mx-auto aspect-square max-h-64 rounded-xl object-contain"
                                @error="photoFailed('current_adult')"
                            />
                            <div
                                v-else
                                class="flex min-h-40 flex-col items-center justify-center gap-3 text-muted-foreground"
                            >
                                <UserRound class="size-14" /><span
                                    >Photo unavailable</span
                                >
                            </div>
                        </figure>
                    </div>
                </section>
                <section class="space-y-4 border-t pt-6">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div>
                            <p class="text-sm text-muted-foreground">
                                {{ entry.label }}
                            </p>
                            <h2 class="text-2xl font-semibold">
                                {{ child?.name ?? entry.submitted_name }}
                            </h2>
                        </div>
                        <Button
                            v-if="child && entry.state === 'needs_checking'"
                            variant="outline"
                            class="min-h-12"
                            @click="childChanging = !childChanging"
                            >{{
                                childChanging
                                    ? 'Keep selection'
                                    : 'Change match'
                            }}</Button
                        >
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Submitted child name: {{ entry.submitted_name
                        }}<span v-if="entry.match_confidence != null">
                            · Automatic name confidence
                            {{ Math.round(entry.match_confidence) }}%</span
                        >
                    </p>
                    <div
                        v-if="entry.duplicate || preview?.duplicate"
                        role="alert"
                        class="space-y-3 rounded-xl bg-red-100 p-4 font-medium text-red-950"
                    >
                        <p>
                            This child appears twice in this request. Cancel the
                            duplicate entry or select the correct child.
                        </p>
                        <template v-if="preview?.duplicate && !entry.duplicate">
                            <p class="text-sm">
                                Flag this finding to stop both entries
                                progressing while staff resolve it. This does
                                not accept the selected identity.
                            </p>
                            <Button
                                variant="destructive"
                                class="min-h-14"
                                :disabled="busy || stale"
                                @click="save('flag_duplicate')"
                                >Flag duplicate for staff</Button
                            >
                        </template>
                        <p v-else class="text-sm">
                            Both unfinished entries are blocked until this
                            duplicate is resolved.
                        </p>
                    </div>
                    <PersonMatchPicker
                        v-if="entry.state === 'needs_checking' && childChanging"
                        v-model="child"
                        :candidates="entry.candidates"
                        person="child"
                        :entry-uuid="entry.uuid"
                        :admin-site="admin_site"
                    />
                    <div class="grid gap-5 sm:grid-cols-[14rem_1fr]">
                        <figure class="rounded-2xl border bg-muted/30 p-4">
                            <figcaption class="mb-3 font-medium">
                                Child photo
                            </figcaption>
                            <img
                                v-if="
                                    child?.photo &&
                                    !photoFailures.includes('child')
                                "
                                :src="child.photo"
                                :alt="`Photo of ${child.name}`"
                                class="mx-auto aspect-square max-h-64 rounded-xl object-contain"
                                @error="photoFailed('child')"
                            />
                            <div
                                v-else
                                class="flex min-h-40 flex-col items-center justify-center gap-3 text-muted-foreground"
                            >
                                <UserRound class="size-14" /><span
                                    >Photo unavailable</span
                                >
                            </div>
                        </figure>
                        <div class="space-y-4">
                            <p
                                v-if="adult && child"
                                class="rounded-2xl bg-sky-50 p-5 text-xl leading-relaxed text-sky-950 dark:bg-sky-950 dark:text-sky-50"
                            >
                                <strong>{{ adult.name }}</strong> is here to
                                collect <strong>{{ child.name }}</strong
                                >.
                            </p>
                            <p
                                v-if="
                                    entry.state !== 'needs_checking' &&
                                    entry.relationship
                                "
                                class="text-lg"
                            >
                                Recorded relationship:
                                <strong>{{ entry.relationship }}</strong>
                            </p>
                            <p
                                v-if="
                                    entry.state !== 'needs_checking' &&
                                    entry.relationship_override_reason
                                "
                                class="rounded-xl bg-amber-50 p-4 text-amber-950"
                            >
                                Relationship exception:
                                {{ entry.relationship_override_reason }}
                            </p>
                            <div
                                v-if="entry.state === 'needs_checking'"
                                class="space-y-4"
                            >
                                <p
                                    v-if="checking"
                                    class="flex items-center gap-2 text-muted-foreground"
                                >
                                    <LoaderCircle
                                        class="size-5 motion-safe:animate-spin"
                                    />Checking relationship and photos…
                                </p>
                                <p
                                    v-if="preview?.relationship"
                                    class="rounded-xl bg-emerald-50 p-4 font-medium text-emerald-950"
                                >
                                    Recorded relationship:
                                    {{ preview.relationship }}
                                </p>
                                <div
                                    v-if="preview && !preview.relationship"
                                    class="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950"
                                >
                                    <Label for="relationship-override"
                                        >No recorded relationship. Explain why
                                        this adult may collect this
                                        child.</Label
                                    ><textarea
                                        id="relationship-override"
                                        v-model="
                                            draft.relationship_override_reason
                                        "
                                        class="min-h-28 w-full rounded-lg border bg-background p-3 text-base text-foreground"
                                        maxlength="2000"
                                    />
                                    <p class="text-sm">
                                        This exception applies only to this
                                        child in this request.
                                    </p>
                                </div>
                                <div v-if="changedMatch" class="grid gap-2">
                                    <Label for="match-change"
                                        >Why are you changing the automatic
                                        match?</Label
                                    ><textarea
                                        id="match-change"
                                        v-model="draft.match_change_reason"
                                        class="min-h-24 w-full rounded-lg border bg-background p-3"
                                        maxlength="2000"
                                    />
                                </div>
                                <div
                                    v-if="preview && missingPhotos.length"
                                    class="grid gap-3 rounded-xl border border-amber-300 p-4"
                                >
                                    <Label for="photo-verification"
                                        >Photo unavailable. How did you check
                                        identity in person?</Label
                                    ><select
                                        id="photo-verification"
                                        v-model="
                                            draft.photo_verification_reason
                                        "
                                        class="min-h-14 w-full rounded-lg border bg-background px-3 text-base"
                                    >
                                        <option value="">
                                            Choose a verification reason
                                        </option>
                                        <option
                                            v-for="reason in photo_reasons"
                                            :key="reason.value"
                                            :value="reason.value"
                                        >
                                            {{ reason.label }}
                                        </option></select
                                    ><Label for="photo-notes">{{
                                        draft.photo_verification_reason ===
                                        'other'
                                            ? 'Explanation (required)'
                                            : 'Notes (optional)'
                                    }}</Label
                                    ><textarea
                                        id="photo-notes"
                                        v-model="draft.photo_verification_notes"
                                        class="min-h-24 w-full rounded-lg border bg-background p-3"
                                        maxlength="2000"
                                    />
                                </div>
                                <p class="text-sm text-muted-foreground">
                                    Confirm only after checking the identities,
                                    photos and relationship above.
                                </p>
                                <Button
                                    class="min-h-16 w-full bg-sky-700 text-lg text-white hover:bg-sky-800"
                                    :disabled="!canReview"
                                    @click="save('review')"
                                    ><Check class="size-6" />{{
                                        busy
                                            ? 'Saving checks…'
                                            : 'Confirm checks'
                                    }}</Button
                                >
                            </div>
                            <Button
                                v-if="entry.state === 'reviewed'"
                                class="min-h-16 w-full bg-sky-700 text-lg text-white hover:bg-sky-800"
                                :disabled="busy || stale"
                                @click="save('prepare')"
                                ><ChevronRight class="size-6" />{{
                                    busy ? 'Saving…' : 'Prepare child'
                                }}</Button
                            >
                            <div
                                v-if="entry.state === 'preparing'"
                                class="space-y-3"
                            >
                                <p class="text-muted-foreground">
                                    Mark collected only after physically handing
                                    the child over to this adult.
                                </p>
                                <Button
                                    class="min-h-16 w-full bg-emerald-700 text-lg text-white hover:bg-emerald-800"
                                    :disabled="busy || stale"
                                    @click="save('collect')"
                                    ><Check class="size-6" />{{
                                        busy ? 'Saving…' : 'Child collected'
                                    }}</Button
                                >
                            </div>
                            <p
                                v-if="entry.state === 'collected'"
                                class="rounded-xl bg-emerald-50 p-5 text-lg font-semibold text-emerald-950"
                            >
                                Child collected. This record is read-only.
                            </p>
                            <div
                                v-if="entry.state === 'cancelled'"
                                class="space-y-2 rounded-xl bg-muted p-5"
                            >
                                <p class="text-lg font-semibold">
                                    Collection cancelled
                                </p>
                                <p>{{ entry.cancellation_reason }}</p>
                                <p v-if="entry.cancellation_notes">
                                    {{ entry.cancellation_notes }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    This record is read-only. Corrections are
                                    made in Filament.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
                <section
                    v-if="!['collected', 'cancelled'].includes(entry.state)"
                    class="border-t pt-5"
                >
                    <Button
                        v-if="!cancelling"
                        variant="outline"
                        class="min-h-12 border-red-200 text-red-700 dark:text-red-300"
                        :disabled="busy || stale"
                        @click="cancelling = true"
                        >Cancel this child’s collection</Button
                    >
                    <div
                        v-else
                        class="grid gap-3 rounded-2xl border border-red-200 p-5"
                    >
                        <Label for="cancel-reason"
                            >Why is this collection being cancelled?</Label
                        ><select
                            id="cancel-reason"
                            v-model="draft.cancellation_reason"
                            class="min-h-14 rounded-lg border bg-background px-3"
                        >
                            <option value="">
                                Choose a cancellation reason
                            </option>
                            <option
                                v-for="reason in cancellation_reasons"
                                :key="reason.value"
                                :value="reason.value"
                            >
                                {{ reason.label }}
                            </option></select
                        ><Label for="cancel-notes">{{
                            draft.cancellation_reason === 'other'
                                ? 'Explanation (required)'
                                : 'Notes (optional)'
                        }}</Label
                        ><textarea
                            id="cancel-notes"
                            v-model="draft.cancellation_notes"
                            class="min-h-24 rounded-lg border bg-background p-3"
                            maxlength="2000"
                        />
                        <div class="flex flex-wrap gap-3">
                            <Button
                                variant="destructive"
                                class="min-h-14"
                                :disabled="
                                    busy ||
                                    stale ||
                                    !draft.cancellation_reason ||
                                    (draft.cancellation_reason === 'other' &&
                                        !draft.cancellation_notes.trim())
                                "
                                @click="save('cancel')"
                                >{{
                                    busy
                                        ? 'Saving cancellation…'
                                        : 'Confirm cancellation'
                                }}</Button
                            ><Button
                                variant="outline"
                                class="min-h-14"
                                :disabled="busy"
                                @click="
                                    cancelling = false;
                                    draft.cancellation_reason = '';
                                    draft.cancellation_notes = '';
                                "
                                >Keep collection</Button
                            >
                        </div>
                    </div>
                </section>
                <details class="rounded-2xl border p-5">
                    <summary
                        class="min-h-10 cursor-pointer text-lg font-semibold"
                    >
                        Request timeline
                    </summary>
                    <ol class="mt-5 grid gap-4">
                        <li
                            v-for="activity in timeline.events"
                            :key="activity.id"
                            class="border-l-2 border-sky-200 pl-4"
                        >
                            <p class="font-medium capitalize">
                                {{ activity.event.replaceAll('_', ' ') }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ activity.actor }} ·
                                {{
                                    activity.at
                                        ? new Date(activity.at).toLocaleString(
                                              'en-GB',
                                              { timeZone: 'Europe/London' },
                                          )
                                        : ''
                                }}
                            </p>
                            <details
                                v-if="Object.keys(activity.new_values).length"
                                class="mt-1"
                            >
                                <summary
                                    class="cursor-pointer text-sm text-muted-foreground"
                                >
                                    View recorded details
                                </summary>
                                <pre
                                    class="mt-2 max-h-60 overflow-auto rounded-lg bg-muted p-3 text-xs whitespace-pre-wrap"
                                    >{{
                                        JSON.stringify(
                                            {
                                                before: activity.old_values,
                                                after: activity.new_values,
                                            },
                                            null,
                                            2,
                                        )
                                    }}</pre>
                            </details>
                        </li>
                    </ol>
                    <Button
                        v-if="timeline.next_cursor"
                        variant="outline"
                        class="mt-5 min-h-12"
                        :disabled="timelineBusy"
                        @click="earlierActivity"
                        >{{
                            timelineBusy ? 'Loading…' : 'Load earlier activity'
                        }}</Button
                    >
                </details>
            </div>
        </div>
    </Modal>
</template>
