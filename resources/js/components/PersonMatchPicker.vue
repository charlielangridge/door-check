<script setup lang="ts">
import { Check, Search, UserRound } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { candidates as searchCandidates } from '@/actions/App/Http/Controllers/StaffCollectionController';
import { collectionHttp } from '@/lib/collectionHttp';
import type { CollectionPerson } from '@/types/collections';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    modelValue: CollectionPerson | null;
    candidates: CollectionPerson[];
    person: 'adult' | 'child';
    entryUuid: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [person: CollectionPerson] }>();
const query = ref('');
const choices = ref(props.candidates);
const searching = ref(false);
const error = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let generation = 0;
watch(
    () => props.candidates,
    (values) => {
        if (!query.value) choices.value = values;
    },
);
watch(query, (value) => {
    clearTimeout(timer);
    const current = ++generation;
    if (value.trim().length < 2) {
        choices.value = props.candidates;
        searching.value = false;
        return;
    }
    timer = setTimeout(async () => {
        searching.value = true;
        error.value = '';
        try {
            const result = await collectionHttp<{
                candidates: CollectionPerson[];
            }>(
                searchCandidates.url(props.entryUuid, {
                    query: { person: props.person, q: value },
                }),
            );
            if (current === generation) choices.value = result.candidates;
        } catch {
            if (current === generation)
                error.value =
                    'Search unavailable. Check the connection and try again.';
        } finally {
            if (current === generation) searching.value = false;
        }
    }, 350);
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    ++generation;
});
</script>

<template>
    <section class="space-y-4" :aria-label="`Choose ${person}`">
        <label
            class="flex items-center gap-3 rounded-xl border bg-background px-4"
        >
            <Search class="size-6 shrink-0 text-muted-foreground" />
            <Input
                v-model="query"
                :aria-label="`Search for another ${person}`"
                :placeholder="`Search for another ${person}…`"
                class="min-h-14 border-0 bg-transparent text-lg shadow-none focus-visible:ring-0"
                autocomplete="off"
            />
        </label>
        <p v-if="searching" role="status" class="text-sm text-muted-foreground">
            Searching this venue…
        </p>
        <p v-if="error" role="alert" class="text-red-700 dark:text-red-300">
            {{ error }}
        </p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <button
                v-for="personOption in choices"
                :key="personOption.id"
                type="button"
                class="relative flex min-h-44 flex-col items-center gap-3 rounded-2xl border-2 bg-background p-4 text-center transition-colors"
                :class="
                    modelValue?.id === personOption.id
                        ? 'border-sky-600 bg-sky-50 ring-2 ring-sky-600/20 dark:bg-sky-950'
                        : 'border-border hover:border-sky-400'
                "
                :aria-pressed="modelValue?.id === personOption.id"
                @click="emit('update:modelValue', personOption)"
            >
                <Check
                    v-if="modelValue?.id === personOption.id"
                    class="absolute top-2 right-2 size-6 rounded-full bg-sky-600 p-1 text-white"
                />
                <img
                    v-if="personOption.photo"
                    :src="personOption.photo"
                    :alt="personOption.name"
                    class="size-24 rounded-xl object-cover"
                />
                <div
                    v-else
                    class="flex size-24 items-center justify-center rounded-xl bg-muted text-muted-foreground"
                >
                    <UserRound class="size-12" />
                </div>
                <span class="text-base font-semibold">{{
                    personOption.name
                }}</span>
                <span
                    v-if="personOption.confidence != null"
                    class="text-sm text-muted-foreground"
                    >{{ Math.round(personOption.confidence) }}% name match</span
                >
            </button>
        </div>
        <p
            v-if="!choices.length && !searching"
            class="rounded-xl bg-muted p-4 text-muted-foreground"
        >
            No suggestions found. Search using another name, or cancel if
            identity cannot be confirmed.
        </p>
    </section>
</template>
