<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';

defineProps<{ disabled?: boolean }>();
const emit = defineEmits<{
    'update:photo': [photo: File | null];
    capturing: [capturing: boolean];
    available: [available: boolean];
}>();

const video = ref<HTMLVideoElement | null>(null);
const available = ref(false);
const ready = ref(false);
const capturing = ref(false);
const preview = ref<string | null>(null);
let stream: MediaStream | null = null;
let disposed = false;

function stopCamera(): void {
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
    ready.value = false;
}

function clearPreview(): void {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
        preview.value = null;
    }
}

function hideCamera(): void {
    stopCamera();
    available.value = false;
    emit('available', false);
}

async function startCamera(): Promise<void> {
    if (!navigator.mediaDevices?.getUserMedia) {
        return;
    }

    try {
        const camera = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user' },
            audio: false,
        });

        if (disposed) {
            camera.getTracks().forEach((track) => track.stop());
            return;
        }

        stream = camera;
        available.value = true;
        camera.getVideoTracks().forEach((track) => {
            track.addEventListener('ended', () => {
                if (!preview.value) {
                    hideCamera();
                }
            });
        });
        await nextTick();

        if (video.value && !disposed) {
            video.value.srcObject = camera;
            await video.value.play();
        }
    } catch {
        hideCamera();
    }
}

function takePhoto(): void {
    const element = video.value;

    if (!element || !ready.value || capturing.value) {
        return;
    }

    const side = Math.min(element.videoWidth, element.videoHeight);
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = Math.min(side, 1000);
    const context = canvas.getContext('2d');

    if (!context || side === 0) {
        hideCamera();
        return;
    }

    context.translate(canvas.width, 0);
    context.scale(-1, 1);
    context.drawImage(
        element,
        (element.videoWidth - side) / 2,
        (element.videoHeight - side) / 2,
        side,
        side,
        0,
        0,
        canvas.width,
        canvas.height,
    );

    capturing.value = true;
    emit('capturing', true);
    canvas.toBlob(
        (blob) => {
            capturing.value = false;
            emit('capturing', false);

            if (disposed) {
                return;
            }

            if (!blob) {
                hideCamera();
                return;
            }

            clearPreview();
            preview.value = URL.createObjectURL(blob);
            emit(
                'update:photo',
                new File([blob], 'requester.jpg', { type: 'image/jpeg' }),
            );
            stopCamera();
        },
        'image/jpeg',
        0.85,
    );
}

function retakePhoto(): void {
    clearPreview();
    emit('update:photo', null);
    void startCamera();
}

function cameraReady(): void {
    ready.value = true;
    emit('available', true);
}

onMounted(() => void startCamera());
onBeforeUnmount(() => {
    disposed = true;
    stopCamera();
    clearPreview();
});
</script>

<template>
    <section
        v-if="available"
        class="grid justify-items-center gap-3"
        aria-label="Your photo"
    >
        <p class="text-sm font-medium">Your photo</p>
        <div class="size-56 overflow-hidden rounded-full border bg-muted">
            <img
                v-if="preview"
                :src="preview"
                alt="Your captured photo"
                class="size-full object-cover"
            />
            <video
                v-else
                ref="video"
                autoplay
                muted
                playsinline
                aria-label="Live camera preview"
                class="size-full -scale-x-100 object-cover"
                @loadeddata="cameraReady"
                @error="hideCamera"
            />
        </div>
        <Button
            v-if="preview"
            type="button"
            variant="outline"
            :disabled="disabled"
            @click="retakePhoto"
        >
            Retake photo
        </Button>
        <Button
            v-else
            type="button"
            variant="outline"
            :disabled="disabled || !ready || capturing"
            @click="takePhoto"
        >
            Take photo
        </Button>
    </section>
</template>
