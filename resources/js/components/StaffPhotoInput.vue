<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

const photo = defineModel<File | null>({ required: true });
const props = defineProps<{
    name: string;
    currentPhoto?: string | null;
    error?: string;
}>();
const upload = ref<HTMLInputElement | null>(null);
const camera = ref<HTMLInputElement | null>(null);
const preview = ref<string | null>(null);
const clearPreview = (): void => {
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = null;
};
watch(
    photo,
    (file) => {
        clearPreview();
        if (file) preview.value = URL.createObjectURL(file);
    },
    { immediate: true },
);
onBeforeUnmount(clearPreview);
const choose = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    if (input.files?.[0]) photo.value = input.files[0];
    input.value = '';
};
</script>

<template>
    <div class="grid gap-2">
        <p class="text-sm font-medium">Photo (optional)</p>
        <img
            v-if="preview || props.currentPhoto"
            :src="preview || props.currentPhoto || undefined"
            :alt="preview ? `New photo of ${name || 'this person'}` : name"
            class="size-24 rounded-lg object-cover"
        />
        <input
            ref="upload"
            class="sr-only"
            tabindex="-1"
            aria-label="Upload image"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            @change="choose"
        />
        <input
            ref="camera"
            class="sr-only"
            tabindex="-1"
            aria-label="Take photo"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            capture="environment"
            @change="choose"
        />
        <div class="flex flex-wrap gap-2">
            <Button
                type="button"
                variant="outline"
                class="min-h-11"
                @click="upload?.click()"
                >Upload image</Button
            >
            <Button
                type="button"
                variant="outline"
                class="min-h-11"
                @click="camera?.click()"
                >Take photo</Button
            >
            <Button
                v-if="photo"
                type="button"
                variant="ghost"
                class="min-h-11"
                @click="photo = null"
                >Remove new photo</Button
            >
        </div>
        <p v-if="photo" role="status" class="text-sm text-muted-foreground">
            New photo selected. Save changes to keep it.
        </p>
        <p class="text-sm text-muted-foreground">
            JPEG, PNG or WebP, up to 2 MB.
            {{
                currentPhoto
                    ? 'Leave empty to keep the current photo. Older photos remain stored.'
                    : 'You can save without a photo.'
            }}
        </p>
        <p
            v-if="error"
            role="alert"
            class="text-sm text-red-600 dark:text-red-500"
        >
            {{ error }}
        </p>
    </div>
</template>
