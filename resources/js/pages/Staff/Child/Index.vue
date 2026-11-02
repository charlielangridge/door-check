<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { index as childrenPage } from '@/wayfinder/App/Http/Controllers/ChildController';
import { computed, ref } from 'vue';
import { formatLongDate } from '@/lib/dates';
import StaffPersonEditForm from '@/components/StaffPersonEditForm.vue';
import StaffChildRegistrationForm from '@/components/StaffChildRegistrationForm.vue';
import type { RegistrationDuplicates } from '@/types/registration';
import PencilIcon from '@/components/PencilIcon.vue';
import { Button } from '@/components/ui/button';
import {
    updateChild,
    updateAdult,
} from '@/wayfinder/App/Http/Controllers/StaffPersonController';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { StaffSite } from '@/types/collections';

defineOptions({
    layout: (props: { site: StaffSite }) => [
        StaffLayout,
        { site: props.site, title: 'Children', section: 'children' },
    ],
});

interface ChildDetails {
    name: string;
    photo: string | null;
    uuid: string;
    date_of_birth: string | null;
    age: number | null;
    also_known_as: string[];
    adults: {
        id: number;
        name: string;
        photo: string | null;
        relationship: string | null;
        date_of_birth: string | null;
        also_known_as: string[];
    }[];
}

const props = defineProps<{
    site: StaffSite;
    children: ChildDetails[];
    registrationDuplicates: RegistrationDuplicates;
    registeredChildUuid: string | null;
    inactive: boolean;
}>();
const registering = ref(false);
const registrationForm = ref<InstanceType<
    typeof StaffChildRegistrationForm
> | null>(null);

const selectedChildUuid = ref<string | null>(null);
const selectedChild = computed(
    () =>
        props.children.find(
            (child) => child.uuid === selectedChildUuid.value,
        ) ?? null,
);
const editing = ref<'child' | number | null>(null);
const editingAdult = computed(() =>
    selectedChild.value?.adults.find((adult) => adult.id === editing.value),
);
const saved = ref(false);
const selectChild = (uuid: string): void => {
    if (registering.value) {
        registrationForm.value?.requestLeave(uuid);
        return;
    }
    selectedChildUuid.value = uuid;
    saved.value = false;
};
const leaveRegistration = (uuid: string | null): void => {
    registering.value = false;
    if (uuid) selectedChildUuid.value = uuid;
};
const finishRegistration = (): void => {
    registering.value = false;
    selectedChildUuid.value = props.registeredChildUuid;
    saved.value = true;
};
const finishEditing = (): void => {
    editing.value = null;
    saved.value = true;
};
</script>

<template>
    <div class="space-y-4">
        <Head :title="site.name + ' Children'" />
        <nav class="flex gap-3" aria-label="Child status filter">
            <Button as-child :variant="inactive ? 'outline' : 'default'"
                ><Link :href="childrenPage(site.slug)">Active</Link></Button
            ><Button as-child :variant="inactive ? 'default' : 'outline'"
                ><Link
                    :href="childrenPage(site.slug, { query: { inactive: 1 } })"
                    >Inactive</Link
                ></Button
            >
        </nav>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-lg font-medium">
                {{ children.length }}
                {{ children.length === 1 ? 'Child' : 'Children' }}
            </p>
            <Button
                type="button"
                class="min-h-11"
                :disabled="editing !== null || registering"
                @click="
                    registering = true;
                    saved = false;
                "
                >Add Child</Button
            >
        </div>
        <div class="grid items-start gap-4 lg:grid-cols-3">
            <section
                aria-label="Children"
                class="grid grid-cols-2 gap-4 rounded-3xl border border-dashed bg-background p-4 text-center sm:grid-cols-3 lg:col-span-2 xl:grid-cols-4"
            >
                <button
                    v-for="child in children"
                    :key="child.uuid"
                    type="button"
                    :aria-pressed="selectedChild?.uuid === child.uuid"
                    aria-controls="child-details"
                    class="flex min-h-40 w-full flex-col items-center justify-center gap-3 rounded-xl border-2 bg-card p-3 transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="
                        selectedChild?.uuid === child.uuid
                            ? 'border-primary bg-accent'
                            : 'border-transparent'
                    "
                    :disabled="editing !== null"
                    @click="selectChild(child.uuid)"
                >
                    <img
                        v-if="child.photo"
                        :src="child.photo"
                        :alt="child.name"
                        class="size-28 rounded-lg object-cover"
                    />
                    <span
                        v-else
                        class="flex size-28 items-center justify-center rounded-lg bg-muted text-sm text-muted-foreground"
                        >No photo</span
                    >
                    <span class="text-lg font-semibold break-words">{{
                        child.name
                    }}</span>
                </button>
                <p
                    v-if="children.length === 0"
                    class="col-span-full py-8 text-muted-foreground"
                >
                    No children are registered at this venue.
                </p>
            </section>
            <section
                id="child-details"
                aria-label="Child details"
                class="relative rounded-3xl border border-dashed bg-background p-5"
            >
                <StaffChildRegistrationForm
                    v-if="registering"
                    ref="registrationForm"
                    :site-slug="site.slug"
                    :duplicates="registrationDuplicates"
                    @leave="leaveRegistration"
                    @saved="finishRegistration"
                />
                <p
                    v-else-if="!selectedChild"
                    class="py-12 text-center font-medium text-muted-foreground"
                >
                    Select a child
                </p>
                <div v-else :key="selectedChild.uuid" class="space-y-6">
                    <p
                        v-if="saved"
                        role="status"
                        class="text-sm font-medium text-green-700 dark:text-green-400"
                    >
                        Changes saved.
                    </p>
                    <StaffPersonEditForm
                        v-if="editing === 'child'"
                        :person="selectedChild"
                        :action="
                            updateChild.url({
                                site: site.slug,
                                child: selectedChild.uuid,
                            })
                        "
                        @cancel="editing = null"
                        @saved="finishEditing"
                    />
                    <StaffPersonEditForm
                        v-else-if="editingAdult"
                        :key="editingAdult.id"
                        :person="editingAdult"
                        :relationship="editingAdult.relationship"
                        adult
                        :action="
                            updateAdult.url({
                                site: site.slug,
                                child: selectedChild.uuid,
                                adult: editingAdult.id,
                            })
                        "
                        @cancel="editing = null"
                        @saved="finishEditing"
                    />
                    <template v-else>
                        <div class="flex items-center gap-4 pr-10">
                            <img
                                v-if="selectedChild.photo"
                                :src="selectedChild.photo"
                                :alt="selectedChild.name"
                                class="size-28 shrink-0 rounded-xl object-cover"
                            />
                            <span
                                v-else
                                class="flex size-28 shrink-0 items-center justify-center rounded-xl bg-muted text-sm text-muted-foreground"
                                >No photo</span
                            >
                            <h2
                                class="min-w-0 text-xl font-semibold break-words"
                            >
                                {{ selectedChild.name }}
                            </h2>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="absolute top-3 right-3 size-11"
                            :aria-label="`Edit ${selectedChild.name}`"
                            title="Edit child"
                            @click="
                                editing = 'child';
                                saved = false;
                            "
                        >
                            <PencilIcon class="size-5" />
                        </Button>
                        <dl class="space-y-3 text-sm">
                            <div>
                                <dt class="font-medium text-muted-foreground">
                                    Date of birth
                                </dt>
                                <dd>
                                    {{
                                        selectedChild.date_of_birth
                                            ? formatLongDate(
                                                  selectedChild.date_of_birth,
                                              )
                                            : 'Not recorded'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-medium text-muted-foreground">
                                    Age
                                </dt>
                                <dd>
                                    {{
                                        selectedChild.age === null
                                            ? 'Not recorded'
                                            : `${selectedChild.age} ${selectedChild.age === 1 ? 'year' : 'years'} old`
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-medium text-muted-foreground">
                                    Also known as
                                </dt>
                                <dd v-if="selectedChild.also_known_as.length">
                                    <ul class="space-y-1">
                                        <li
                                            v-for="(
                                                name, index
                                            ) in selectedChild.also_known_as"
                                            :key="index"
                                        >
                                            {{ name }}
                                        </li>
                                    </ul>
                                </dd>
                                <dd v-else>None recorded</dd>
                            </div>
                        </dl>
                        <div class="space-y-3">
                            <h3 class="text-lg font-semibold">
                                Related adults
                                <span class="text-sm text-muted-foreground"
                                    >({{ selectedChild.adults.length }})</span
                                >
                            </h3>
                            <ul
                                v-if="selectedChild.adults.length"
                                class="space-y-3"
                            >
                                <li
                                    v-for="adult in selectedChild.adults"
                                    :key="adult.id"
                                    class="relative flex items-center gap-3 rounded-xl border bg-card p-3 pr-14"
                                >
                                    <img
                                        v-if="adult.photo"
                                        :src="adult.photo"
                                        :alt="adult.name"
                                        loading="lazy"
                                        class="size-20 shrink-0 rounded-lg object-cover"
                                    />
                                    <span
                                        v-else
                                        class="flex size-20 shrink-0 items-center justify-center rounded-lg bg-muted text-center text-sm text-muted-foreground"
                                        >No photo</span
                                    >
                                    <div class="min-w-0">
                                        <p class="font-semibold break-words">
                                            {{ adult.name }}
                                        </p>
                                        <p
                                            class="text-sm break-words text-muted-foreground"
                                        >
                                            {{
                                                adult.relationship ||
                                                'Relationship not recorded'
                                            }}
                                        </p>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="absolute top-1 right-1 size-11"
                                            :aria-label="`Edit ${adult.name}`"
                                            title="Edit adult"
                                            @click="
                                                editing = adult.id;
                                                saved = false;
                                            "
                                        >
                                            <PencilIcon class="size-5" />
                                        </Button>
                                    </div>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">
                                No related adults recorded.
                            </p>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </div>
</template>
