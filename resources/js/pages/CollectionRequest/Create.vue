<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { store as kioskStore } from '@/wayfinder/App/Http/Controllers/CollectionKioskController';
import { resume } from '@/wayfinder/App/Http/Controllers/CollectionEntryDisplayController';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { store } from '@/wayfinder/App/Http/Controllers/CollectionRequestController';
import InputError from '@/components/InputError.vue';
import RequesterPhoto from '@/components/RequesterPhoto.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    forgetCollectionNames,
    readCollectionNames,
    rememberCollectionNames,
    readCollectionDraft,
    rememberCollectionDraft,
    forgetCollectionDraft,
} from '@/lib/collectionNames';

const props = withDefaults(
    defineProps<{
        site: { name: string; uuid: string; slug: string; logo: string | null };
        entry: { uuid: string; remaining_ms: number };
        kiosk?: { display: string } | null;
    }>(),
    { kiosk: null },
);

const endpoint = props.kiosk
    ? kioskStore({
          site: props.site.slug,
          display: props.kiosk.display,
          entry: props.entry.uuid,
      })
    : store({ site: props.site.slug, entry: props.entry.uuid });

const form = useForm({
    requester_name_text: '',
    requested_child_text: '',
    photo: null as File | null,
    camera_available: false,
}).withPrecognition(endpoint);

const remaining = ref(props.entry.remaining_ms);
const expired = computed(() => remaining.value <= 0);
let expiryTimer: ReturnType<typeof setInterval> | undefined;
let deadline = 0;
let submitted = false;

watch(
    () => [form.requester_name_text, form.requested_child_text],
    () => {
        if (!props.kiosk && !submitted) {
            rememberCollectionDraft(
                props.site.uuid,
                form.requester_name_text,
                form.requested_child_text,
            );
        }
    },
);

const capturingPhoto = ref(false);
function submit(): void {
    if (
        expired.value ||
        form.processing ||
        capturingPhoto.value ||
        (form.camera_available && !form.photo)
    ) {
        return;
    }

    form.submit({
        onSuccess: (page) => {
            if (props.kiosk) {
                submitted = true;
                form.reset();
                return;
            }
            const collection = page.props.collection as
                | {
                      uuid: string;
                      forget_saved_names: boolean;
                  }
                | undefined;
            if (!collection) return;
            submitted = true;
            forgetCollectionDraft(props.site.uuid);
            rememberCollectionNames(props.site.uuid, {
                requestUuid: collection.uuid,
                adultName: form.requester_name_text,
                childName: form.requested_child_text,
            });
            if (collection.forget_saved_names) {
                forgetCollectionNames(props.site.uuid, collection.uuid);
            }
        },
    });
}

function updatePhoto(photo: File | null): void {
    form.photo = photo;
    form.clearErrors('photo');
}

onMounted(() => {
    deadline = performance.now() + props.entry.remaining_ms;
    expiryTimer = setInterval(() => {
        remaining.value = Math.max(0, deadline - performance.now());
    }, 1000);
    const savedNames = props.kiosk
        ? null
        : (readCollectionDraft(props.site.uuid) ??
          readCollectionNames(props.site.uuid));
    if (savedNames) {
        form.requester_name_text = savedNames.adultName;
        form.requested_child_text = savedNames.childName;
    }
    void import('./Status.vue');
});
onBeforeUnmount(() => {
    clearInterval(expiryTimer);
    if (props.kiosk) form.reset();
});
</script>

<template>
    <main class="mx-auto flex min-h-screen max-w-md flex-col gap-6 px-5 py-10">
        <Head title="Collection request" />

        <div class="flex flex-col items-center justify-center gap-y-2">
            <img
                v-if="site.logo"
                :src="site.logo"
                :alt="site.name"
                class="max-h-24"
            />

            <p v-else class="text-sm text-muted-foreground">{{ site.name }}</p>
            <h1 class="text-2xl font-semibold">Collection request</h1>
        </div>

        <p
            v-if="expired"
            role="alert"
            class="rounded-lg bg-muted p-4 text-center"
        >
            <template v-if="kiosk"
                >Your form has expired. Please return to the QR screen to start
                again.</template
            >
            <template v-else
                >Your form has expired. Please scan the QR code on the screen at
                your site again. Your entered names will be kept when you scan
                again in this browser.</template
            >
        </p>
        <p
            v-else-if="remaining <= 120000"
            role="alert"
            class="rounded-lg bg-muted p-4 text-center"
        >
            Your form expires in {{ Math.ceil(remaining / 60000) }}
            {{ Math.ceil(remaining / 60000) === 1 ? 'minute' : 'minutes' }}.
            Please send your request before it expires.
        </p>

        <form
            v-if="!expired"
            class="grid gap-6"
            novalidate
            :autocomplete="kiosk ? 'off' : undefined"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="requester_name_text">Your name</Label>
                <Input
                    id="requester_name_text"
                    v-model="form.requester_name_text"
                    name="requester_name_text"
                    :autocomplete="kiosk ? 'off' : 'name'"
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
                    class="mt-2 text-center text-sm text-muted-foreground"
                >
                    Please take a photo before sending your request.
                </p>
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
        <Link
            v-if="kiosk"
            :href="resume({ site: site.slug, display: kiosk.display })"
            class="text-center underline underline-offset-4"
        >
            Back to QR screen
        </Link>
    </main>
</template>
