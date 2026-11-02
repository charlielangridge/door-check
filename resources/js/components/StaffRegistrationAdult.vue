<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import StaffRegistrationPersonFields from '@/components/StaffRegistrationPersonFields.vue';
import { adults as searchAdults } from '@/wayfinder/App/Http/Controllers/StaffChildRegistrationController';
import { collectionHttp } from '@/lib/collectionHttp';
import type {
    RegistrationAdult,
    RegistrationDuplicateGroup,
    RegistrationMatch,
} from '@/types/registration';

const adult = defineModel<RegistrationAdult>({ required: true });
const props = defineProps<{
    index: number;
    siteSlug: string;
    errors: Record<string, string>;
    duplicates?: RegistrationDuplicateGroup;
}>();
const emit = defineEmits<{ remove: [] }>();
const query = ref('');
const results = ref<RegistrationMatch[]>([]);
const searching = ref(false);
const searched = ref(false);
const searchError = ref('');
let generation = 0;
onBeforeUnmount(() => {
    generation++;
});
const duplicates = computed(() =>
    props.duplicates?.name.trim().toLowerCase() ===
        adult.value.name.trim().toLowerCase() &&
    props.duplicates.date_of_birth === adult.value.date_of_birth
        ? props.duplicates.people
        : [],
);
const select = (person: RegistrationMatch): void => {
    Object.assign(adult.value, {
        mode: 'existing',
        adult_id: person.id,
        name: person.name,
        date_of_birth: person.date_of_birth,
        photo: person.photo,
        new_photo: null,
        also_known_as: [],
        create_different: false,
    });
};
const changeMode = (mode: 'existing' | 'new'): void => {
    if (adult.value.mode === mode) return;
    Object.assign(adult.value, {
        mode,
        adult_id: null,
        name: '',
        date_of_birth: '',
        photo: null,
        new_photo: null,
        also_known_as: [],
        create_different: false,
    });
};
const search = async (): Promise<void> => {
    const current = ++generation;
    searching.value = true;
    searchError.value = '';
    results.value = [];
    try {
        const result = await collectionHttp<{ adults: RegistrationMatch[] }>(
            searchAdults.url(props.siteSlug, {
                query: { q: query.value.trim() },
            }),
        );
        if (current === generation) {
            results.value = result.adults;
            searched.value = true;
        }
    } catch {
        if (current === generation)
            searchError.value =
                'Search unavailable. Check the connection and try again.';
    } finally {
        if (current === generation) searching.value = false;
    }
};
</script>

<template>
    <div class="space-y-4 rounded-xl border p-3">
        <div class="flex items-center justify-between gap-2">
            <h4 class="font-semibold">Adult {{ index + 1 }}</h4>
            <Button
                type="button"
                variant="ghost"
                :aria-label="`Remove adult ${index + 1}`"
                @click="emit('remove')"
                >Remove</Button
            >
        </div>
        <div class="flex flex-wrap gap-2">
            <Button
                type="button"
                :variant="adult.mode === 'existing' ? 'secondary' : 'outline'"
                :aria-pressed="adult.mode === 'existing'"
                @click="changeMode('existing')"
                >Link existing adult</Button
            >
            <Button
                type="button"
                :variant="adult.mode === 'new' ? 'secondary' : 'outline'"
                :aria-pressed="adult.mode === 'new'"
                @click="changeMode('new')"
                >Add new adult</Button
            >
        </div>
        <div v-if="adult.mode === 'existing'" class="space-y-3">
            <div
                v-if="adult.adult_id"
                class="space-y-2 rounded-lg bg-muted p-3"
            >
                <img
                    v-if="adult.photo"
                    :src="adult.photo"
                    :alt="adult.name"
                    class="size-20 rounded-lg object-cover"
                />
                <p class="font-medium">{{ adult.name }}</p>
                <p class="text-sm">Date of birth: {{ adult.date_of_birth }}</p>
                <Button
                    type="button"
                    variant="outline"
                    @click="adult.adult_id = null"
                    >Choose another adult</Button
                >
            </div>
            <div v-else class="space-y-2">
                <Label :for="`adult-search-${adult.key}`"
                    >Search adults at this venue</Label
                >
                <div class="flex gap-2">
                    <Input
                        :id="`adult-search-${adult.key}`"
                        v-model="query"
                        placeholder="Adult name"
                        maxlength="255"
                        @keydown.enter.prevent="
                            query.trim().length >= 2 && search()
                        "
                    />
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="searching || query.trim().length < 2"
                        @click="search"
                        >{{ searching ? 'Searching…' : 'Search' }}</Button
                    >
                </div>
                <p v-if="searchError" role="alert" class="text-sm text-red-600">
                    {{ searchError }}
                </p>
                <p
                    v-else-if="searched && !results.length"
                    class="text-sm text-muted-foreground"
                >
                    No adults found. Try another name or add a new adult.
                </p>
                <ul class="space-y-2">
                    <li v-for="person in results" :key="person.id">
                        <button
                            type="button"
                            class="flex min-h-11 w-full items-center gap-3 rounded-lg border p-3 text-left hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                            @click="select(person)"
                        >
                            <img
                                v-if="person.photo"
                                :src="person.photo"
                                :alt="person.name"
                                class="size-16 shrink-0 rounded-lg object-cover"
                            />
                            <span v-else class="text-xs text-muted-foreground"
                                >No photo</span
                            >
                            <span
                                ><span class="block font-medium">{{
                                    person.name
                                }}</span
                                ><span class="text-sm text-muted-foreground"
                                    >Date of birth:
                                    {{ person.date_of_birth }}</span
                                ></span
                            >
                        </button>
                    </li>
                </ul>
            </div>
            <InputError :message="errors[`adults.${index}.adult_id`]" />
        </div>
        <template v-else>
            <StaffRegistrationPersonFields
                :model-value="adult"
                :prefix="`adults.${index}`"
                :errors="errors"
            />
            <div
                v-if="duplicates.length && !adult.create_different"
                role="alert"
                class="space-y-3 rounded-lg border border-amber-400 p-3"
            >
                <p class="font-medium">
                    An adult with these details already exists.
                </p>
                <div
                    v-for="person in duplicates"
                    :key="person.id"
                    class="space-y-2"
                >
                    <img
                        v-if="person.photo"
                        :src="person.photo"
                        :alt="person.name"
                        class="size-20 rounded-lg object-cover"
                    />
                    <p>{{ person.name }} · {{ person.date_of_birth }}</p>
                    <Button
                        type="button"
                        variant="outline"
                        @click="select(person)"
                        >Link this adult</Button
                    >
                </div>
                <Button
                    type="button"
                    variant="outline"
                    @click="adult.create_different = true"
                    >Create a different person</Button
                >
            </div>
            <p
                v-if="adult.create_different"
                role="status"
                class="text-sm text-muted-foreground"
            >
                You have chosen to create a different person with these details.
            </p>
            <InputError
                v-else
                :message="errors[`adults.${index}.create_different`]"
            />
        </template>
        <div class="grid gap-2">
            <Label :for="`relationship-${adult.key}`"
                >Relationship to this child</Label
            >
            <Input
                :id="`relationship-${adult.key}`"
                v-model="adult.relationship"
                required
                maxlength="255"
            />
            <InputError :message="errors[`adults.${index}.relationship`]" />
        </div>
    </div>
</template>
