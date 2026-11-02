<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    person: {
        name: string;
        date_of_birth: string | null;
        also_known_as: string[];
        photo: string | null;
    };
    action: string;
    relationship?: string | null;
    adult?: boolean;
}>();
const emit = defineEmits<{ saved: []; cancel: [] }>();
const uploadInput = ref<HTMLInputElement | null>(null);
const cameraInput = ref<HTMLInputElement | null>(null);
const photoPreview = ref<string | null>(null);
const form = useForm({
    _method: 'patch',
    name: props.person.name,
    date_of_birth: props.person.date_of_birth ?? '',
    also_known_as: [...props.person.also_known_as],
    relationship: props.relationship ?? '',
    new_photo: null as File | null,
});
const choosePhoto = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    const photo = input.files?.[0];
    if (!photo) {
        return;
    }
    if (photoPreview.value) {
        URL.revokeObjectURL(photoPreview.value);
    }
    form.new_photo = photo;
    photoPreview.value = URL.createObjectURL(photo);
    input.value = '';
};
onBeforeUnmount(() => {
    if (photoPreview.value) {
        URL.revokeObjectURL(photoPreview.value);
    }
});
const save = (): void => {
    form.post(props.action, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
};
</script>

<template>
    <form class="space-y-4" @submit.prevent="save">
        <h2 class="text-xl font-semibold">
            Edit {{ adult ? 'adult' : 'child' }}
        </h2>
        <p class="text-sm text-muted-foreground">
            These details are shared across all venues.
            {{
                adult
                    ? 'The relationship applies to this child at all venues.'
                    : ''
            }}
        </p>
        <fieldset :disabled="form.processing" class="space-y-4">
            <div class="grid gap-2">
                <Label for="person-name">Name</Label>
                <Input
                    id="person-name"
                    v-model="form.name"
                    required
                    maxlength="255"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="person-date-of-birth">Date of birth</Label>
                <Input
                    id="person-date-of-birth"
                    v-model="form.date_of_birth"
                    type="date"
                    required
                />
                <InputError :message="form.errors.date_of_birth" />
            </div>
            <div class="space-y-2">
                <p class="text-sm font-medium">Also known as</p>
                <div
                    v-for="(_, index) in form.also_known_as"
                    :key="index"
                    class="space-y-1"
                >
                    <div class="flex gap-2">
                        <Input
                            v-model="form.also_known_as[index]"
                            :aria-label="`Alternative full name ${index + 1}`"
                            required
                            maxlength="255"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            :aria-label="`Remove alternative name ${index + 1}`"
                            @click="form.also_known_as.splice(index, 1)"
                            >Remove</Button
                        >
                    </div>
                    <InputError
                        :message="form.errors[`also_known_as.${index}`]"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.also_known_as.length >= 50"
                    @click="form.also_known_as.push('')"
                    >Add alternative name</Button
                >
                <InputError :message="form.errors.also_known_as" />
            </div>
            <div v-if="adult" class="grid gap-2">
                <Label for="person-relationship"
                    >Relationship to this child</Label
                >
                <Input
                    id="person-relationship"
                    v-model="form.relationship"
                    required
                    maxlength="255"
                />
                <InputError :message="form.errors.relationship" />
            </div>
            <div class="grid gap-2">
                <p class="text-sm font-medium">Photo</p>
                <img
                    v-if="photoPreview || person.photo"
                    :src="photoPreview || person.photo || undefined"
                    :alt="
                        photoPreview
                            ? `New photo of ${person.name}`
                            : person.name
                    "
                    class="size-24 rounded-lg object-cover"
                />
                <input
                    ref="uploadInput"
                    class="sr-only"
                    tabindex="-1"
                    aria-label="Upload image"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    @change="choosePhoto"
                />
                <input
                    ref="cameraInput"
                    class="sr-only"
                    tabindex="-1"
                    aria-label="Take photo"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    capture="environment"
                    @change="choosePhoto"
                />
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11"
                        @click="uploadInput?.click()"
                        >Upload image</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11"
                        @click="cameraInput?.click()"
                        >Take photo</Button
                    >
                </div>
                <p
                    v-if="form.new_photo"
                    role="status"
                    class="text-sm text-muted-foreground"
                >
                    New photo selected. Save changes to keep it.
                </p>
                <p class="text-sm text-muted-foreground">
                    JPEG, PNG or WebP, up to 2 MB. Leave empty to keep the
                    current photo. Older photos remain stored.
                </p>
                <InputError :message="form.errors.new_photo" />
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
                <Button type="submit" :disabled="form.processing">{{
                    form.processing ? 'Saving…' : 'Save changes'
                }}</Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="emit('cancel')"
                    >Cancel</Button
                >
            </div>
        </fieldset>
    </form>
</template>
