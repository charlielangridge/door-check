<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import StaffRegistrationPersonFields from '@/components/StaffRegistrationPersonFields.vue';
import StaffRegistrationAdult from '@/components/StaffRegistrationAdult.vue';
import { store } from '@/wayfinder/App/Http/Controllers/StaffChildRegistrationController';
import type {
    RegistrationAdult,
    RegistrationDuplicates,
} from '@/types/registration';

const props = defineProps<{
    siteSlug: string;
    duplicates: RegistrationDuplicates;
}>();
const emit = defineEmits<{ saved: []; leave: [uuid: string | null] }>();
let nextKey = 1;
const newAdult = (): RegistrationAdult => ({
    key: nextKey++,
    mode: 'existing',
    adult_id: null,
    name: '',
    date_of_birth: '',
    also_known_as: [],
    new_photo: null,
    create_different: false,
    relationship: '',
    photo: null,
});
const form = useForm({
    name: '',
    date_of_birth: '',
    also_known_as: [] as string[],
    new_photo: null as File | null,
    create_different: false,
    adults: [newAdult()],
});
const duplicateChildren = computed(() =>
    props.duplicates.child?.name.trim().toLowerCase() ===
        form.name.trim().toLowerCase() &&
    props.duplicates.child.date_of_birth === form.date_of_birth
        ? props.duplicates.child.people
        : [],
);
const discardRequested = ref(false);
let leaveTarget: string | null = null;
const requestLeave = (uuid: string | null = null): void => {
    if (form.processing) return;
    if (!form.isDirty) {
        emit('leave', uuid);
        return;
    }
    leaveTarget = uuid;
    discardRequested.value = true;
};
defineExpose({ requestLeave, isProcessing: computed(() => form.processing) });
const beforeUnload = (event: BeforeUnloadEvent): void => {
    if (form.isDirty) {
        event.preventDefault();
        event.returnValue = '';
    }
};
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));
const save = (): void => {
    form.transform((data) => ({
        ...data,
        adults: data.adults.map(({ key, photo, ...adult }) => adult),
    })).post(store.url(props.siteSlug), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('saved'),
        onError: async () => {
            await nextTick();
            document.getElementById('registration-errors')?.focus();
        },
    });
};
</script>

<template>
    <form class="space-y-5" @submit.prevent="save">
        <h2 class="text-xl font-semibold">Add Child</h2>
        <div
            v-if="Object.keys(form.errors).length"
            id="registration-errors"
            tabindex="-1"
            role="alert"
            class="rounded-lg border border-red-400 p-3 text-sm"
        >
            Please check the details below. Your registration has not been
            saved.
        </div>
        <fieldset :disabled="form.processing" class="space-y-5">
            <StaffRegistrationPersonFields
                :model-value="form"
                prefix=""
                :errors="form.errors"
            />
            <div
                v-if="duplicateChildren.length && !form.create_different"
                role="alert"
                class="space-y-3 rounded-lg border border-amber-400 p-3"
            >
                <p class="font-medium">
                    A child with these details already exists.
                </p>
                <div
                    v-for="child in duplicateChildren"
                    :key="child.id"
                    class="space-y-2"
                >
                    <img
                        v-if="child.photo"
                        :src="child.photo"
                        :alt="child.name"
                        class="size-20 rounded-lg object-cover"
                    />
                    <p>{{ child.name }} · {{ child.date_of_birth }}</p>
                    <Button
                        type="button"
                        variant="outline"
                        @click="requestLeave(child.uuid)"
                        >Open existing child</Button
                    >
                </div>
                <Button
                    type="button"
                    variant="outline"
                    @click="form.create_different = true"
                    >Create a different child</Button
                >
            </div>
            <p
                v-if="form.create_different"
                role="status"
                class="text-sm text-muted-foreground"
            >
                You have chosen to create a different child with these details.
            </p>
            <InputError v-else :message="form.errors.create_different" />
            <div class="space-y-3">
                <h3 class="text-lg font-semibold">Related adults</h3>
                <p class="text-sm text-muted-foreground">
                    Link or add at least one adult. Nothing is saved until you
                    save the child.
                </p>
                <StaffRegistrationAdult
                    v-for="(adult, index) in form.adults"
                    :key="adult.key"
                    :model-value="adult"
                    :index="index"
                    :site-slug="siteSlug"
                    :errors="form.errors"
                    :duplicates="duplicates.adults[index]"
                    @remove="form.adults.splice(index, 1)"
                />
                <InputError :message="form.errors.adults" />
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11"
                    :disabled="form.adults.length >= 50"
                    @click="form.adults.push(newAdult())"
                    >Add another adult</Button
                >
            </div>
            <progress
                v-if="form.progress"
                :value="form.progress.percentage"
                max="100"
                class="w-full"
            >
                {{ form.progress.percentage }}%
            </progress>
            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    class="min-h-11"
                    :disabled="form.processing"
                    >{{ form.processing ? 'Saving…' : 'Save child' }}</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11"
                    @click="requestLeave()"
                    >Cancel</Button
                >
            </div>
        </fieldset>
        <Dialog v-model:open="discardRequested">
            <DialogContent class="sm:max-w-sm">
                <DialogTitle>Discard this registration?</DialogTitle>
                <DialogDescription
                    >The child and all adults entered in this form will not be
                    saved.</DialogDescription
                >
                <DialogFooter>
                    <Button
                        type="button"
                        class="min-h-11"
                        autofocus
                        @click="discardRequested = false"
                        >Keep editing</Button
                    >
                    <Button
                        type="button"
                        variant="destructive"
                        class="min-h-11"
                        @click="emit('leave', leaveTarget)"
                        >Discard</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </form>
</template>
