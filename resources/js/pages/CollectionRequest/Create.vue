<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/CollectionRequestController';
import InputError from '@/components/InputError.vue';
import RequesterPhoto from '@/components/RequesterPhoto.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    site: { name: string; uuid: string };
}>();

const form = useForm({
    requester_name_text: '',
    requested_child_text: '',
    requester_latitude: null as number | null,
    requester_longitude: null as number | null,
    photo: null as File | null,
    camera_available: false,
}).withPrecognition(store(props.site.uuid));

const submitted = ref(false);
const capturingPhoto = ref(false);
const locationStatus = ref('');
let locationAttempt = 0;
let resetTimer: ReturnType<typeof setTimeout> | undefined;

function requestLocation(): void {
    const attempt = ++locationAttempt;

    if (!navigator.geolocation) {
        locationStatus.value =
            'Location unavailable. You can still submit your request.';
        return;
    }

    locationStatus.value = 'Finding your location (optional)…';
    navigator.geolocation.getCurrentPosition(
        ({ coords }) => {
            if (attempt !== locationAttempt) {
                return;
            }

            form.requester_latitude = coords.latitude;
            form.requester_longitude = coords.longitude;
            locationStatus.value = 'Location recorded.';
        },
        () => {
            if (attempt === locationAttempt) {
                locationStatus.value =
                    'Location unavailable. You can still submit your request.';
            }
        },
        { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 },
    );
}

function submit(): void {
    if (
        form.processing ||
        capturingPhoto.value ||
        (form.camera_available && !form.photo)
    ) {
        return;
    }

    form.submit({
        onSuccess: () => {
            ++locationAttempt;
            submitted.value = true;
            form.resetAndClearErrors();
            resetTimer = setTimeout(() => {
                submitted.value = false;
                requestLocation();
            }, 10000);
        },
    });
}

function updatePhoto(photo: File | null): void {
    form.photo = photo;
    form.clearErrors('photo');
}

onMounted(requestLocation);
onBeforeUnmount(() => {
    ++locationAttempt;
    clearTimeout(resetTimer);
});
</script>

<template>
    <main class="mx-auto flex min-h-screen max-w-md flex-col gap-6 px-5 py-10">
        <Head title="Collection request" />

        <div class="grid gap-2">
            <p class="text-sm text-muted-foreground">{{ site.name }}</p>
            <h1 class="text-2xl font-semibold">Collection request</h1>
        </div>

        <div
            v-if="submitted"
            role="status"
            class="grid gap-3 rounded-lg border p-6"
        >
            <h2 class="text-xl font-medium">Thank you</h2>
            <p>
                Thanks, your collection request has been received. Please wait
                for a member of the team.
            </p>
            <p class="text-sm text-muted-foreground">
                A new form will appear in 10 seconds.
            </p>
        </div>

        <form v-else class="grid gap-6" novalidate @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="requester_name_text">Your name</Label>
                <Input
                    id="requester_name_text"
                    v-model="form.requester_name_text"
                    name="requester_name_text"
                    autocomplete="name"
                    required
                    maxlength="255"
                    :disabled="form.processing"
                    :aria-invalid="!!form.errors.requester_name_text"
                    aria-describedby="requester-name-error"
                    @blur="form.validate('requester_name_text')"
                />
                <InputError
                    id="requester-name-error"
                    :message="form.errors.requester_name_text"
                />
            </div>

            <div class="grid gap-2">
                <Label for="requested_child_text"
                    >Child you are collecting</Label
                >
                <Input
                    id="requested_child_text"
                    v-model="form.requested_child_text"
                    name="requested_child_text"
                    autocomplete="off"
                    required
                    maxlength="255"
                    :disabled="form.processing"
                    :aria-invalid="!!form.errors.requested_child_text"
                    aria-describedby="child-name-error"
                    @blur="form.validate('requested_child_text')"
                />
                <InputError
                    id="child-name-error"
                    :message="form.errors.requested_child_text"
                />
            </div>

            <div>
                <RequesterPhoto
                    :disabled="form.processing"
                    @update:photo="updatePhoto"
                    @capturing="capturingPhoto = $event"
                    @available="form.camera_available = $event"
                />
                <InputError :message="form.errors.photo" />
                <p
                    v-if="form.camera_available && !form.photo"
                    class="text-sm text-muted-foreground"
                >
                    Please take a photo before sending your request.
                </p>
            </div>

            <div class="grid gap-2">
                <p role="status" class="text-sm text-muted-foreground">
                    {{ locationStatus }}
                </p>
                <InputError :message="form.errors.requester_latitude" />
                <InputError :message="form.errors.requester_longitude" />
            </div>

            <Button
                type="submit"
                :disabled="
                    form.processing ||
                    capturingPhoto ||
                    (form.camera_available && !form.photo)
                "
            >
                {{
                    form.processing
                        ? 'Sending request…'
                        : 'Send collection request'
                }}
            </Button>
        </form>
    </main>
</template>
