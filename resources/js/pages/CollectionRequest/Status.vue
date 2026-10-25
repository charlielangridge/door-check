<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import { useCollectionChannel } from '@/composables/useCollectionChannel';
import { Check, Clock, LoaderCircle, Plus, X } from '@lucide/vue';
import { store } from '@/actions/App/Http/Controllers/CollectionStatusController';
import { create } from '@/actions/App/Http/Controllers/CollectionRequestController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { onMounted, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    collection: {
        uuid: string;
        adult_name: string;
        site: { name: string; uuid: string };
        children: {
            uuid: string;
            name: string;
            state: string;
            label: string;
        }[];
        can_add_child: boolean;
        finished: boolean;
    };
}>();

const form = useForm({ requested_child_text: '' });
const adding = ref(false);
const connected = ref(true);
const refreshOptions = {
    only: ['collection'],
    onSuccess: () => {
        connected.value = true;
    },
    onNetworkError: () => {
        connected.value = false;
        return false;
    },
};
function refresh(): void {
    router.reload(refreshOptions);
}
function offline(): void {
    connected.value = false;
}
useCollectionChannel(`collection.${props.collection.uuid}`, refresh);
usePoll(15000, refreshOptions);
onMounted(() => {
    connected.value = navigator.onLine;
    window.addEventListener('offline', offline);
    window.addEventListener('online', refresh);
});
onBeforeUnmount(() => {
    window.removeEventListener('offline', offline);
    window.removeEventListener('online', refresh);
});
</script>

<template>
    <main class="mx-auto flex min-h-screen max-w-lg flex-col gap-8 px-5 py-10">
        <Head title="Your collection request" />
        <header class="space-y-2">
            <p class="text-sm font-medium text-muted-foreground">
                {{ collection.site.name }}
            </p>
            <h1 class="text-3xl font-semibold tracking-tight">
                Your collection request
            </h1>
            <p class="text-muted-foreground">
                {{ collection.adult_name }}, your request is with our staff.
                Keep this screen open for updates.
            </p>
        </header>
        <p
            v-if="!connected"
            role="alert"
            class="rounded-xl bg-amber-100 p-4 text-amber-950"
        >
            Reconnecting. Your last received status is shown below.
        </p>
        <section
            aria-label="Collection progress"
            aria-live="polite"
            class="grid gap-4"
        >
            <article
                v-for="child in collection.children"
                :key="child.uuid"
                class="flex gap-4 rounded-2xl border bg-card p-5 shadow-sm"
            >
                <div
                    class="flex size-12 shrink-0 items-center justify-center rounded-full"
                    :class="
                        child.state === 'collected'
                            ? 'bg-emerald-100 text-emerald-800'
                            : child.state === 'cancelled'
                              ? 'bg-red-100 text-red-800'
                              : 'bg-sky-100 text-sky-800'
                    "
                >
                    <Check v-if="child.state === 'collected'" class="size-6" />
                    <X v-else-if="child.state === 'cancelled'" class="size-6" />
                    <LoaderCircle
                        v-else-if="child.state === 'preparing'"
                        class="size-6 motion-safe:animate-spin"
                    />
                    <Clock v-else class="size-6" />
                </div>
                <div class="space-y-1">
                    <h2 class="text-xl font-semibold">{{ child.name }}</h2>
                    <p class="text-base">{{ child.label }}</p>
                </div>
            </article>
        </section>
        <section v-if="collection.can_add_child" class="rounded-2xl border p-5">
            <Button
                v-if="!adding"
                variant="outline"
                class="min-h-14 w-full text-base"
                @click="adding = true"
                ><Plus class="size-5" /> Add another child</Button
            >
            <form
                v-else
                class="grid gap-4"
                @submit.prevent="
                    form.submit(store(collection.uuid), {
                        onSuccess: () => {
                            adding = false;
                            form.reset();
                        },
                    })
                "
            >
                <Label for="another-child">Child you are also collecting</Label>
                <Input
                    id="another-child"
                    v-model="form.requested_child_text"
                    class="min-h-14 text-lg"
                    required
                    maxlength="255"
                    autocomplete="off"
                />
                <InputError :message="form.errors.requested_child_text" />
                <Button class="min-h-14" :disabled="form.processing">{{
                    form.processing ? 'Adding…' : 'Add child to this request'
                }}</Button>
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="form.processing"
                    @click="adding = false"
                    >Keep existing request</Button
                >
            </form>
        </section>
        <p v-if="collection.finished" class="text-muted-foreground">
            This request is finished. Please speak to staff if you need help.
        </p>
        <Link
            v-if="collection.finished"
            :href="create(collection.site.uuid)"
            class="text-center underline underline-offset-4"
            >Start a new request</Link
        >
    </main>
</template>
