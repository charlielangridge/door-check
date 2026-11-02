<script setup lang="ts">
import { computed } from 'vue';
import StaffPhotoInput from '@/components/StaffPhotoInput.vue';
import { Input } from '@/components/ui/input';
import type { ParentPerson } from '@/types/parents';

const person = defineModel<ParentPerson>({ required: true });
withDefaults(
    defineProps<{
        dob?: boolean;
        contact?: boolean;
        relationship?: boolean;
        lockedEmail?: boolean;
        fixedDob?: boolean;
    }>(),
    { dob: true },
);
const aliases = computed({
    get: () => (person.value.also_known_as ?? []).join('\n'),
    set: (value: string) => {
        person.value.also_known_as = value
            .split('\n')
            .map((name) => name.trim())
            .filter(Boolean);
    },
});
const email = computed({
    get: () => person.value.email ?? '',
    set: (value: string | number) => {
        person.value.email = String(value);
    },
});
const phone = computed({
    get: () => person.value.phone ?? '',
    set: (value: string | number) => {
        person.value.phone = String(value);
    },
});
const photo = computed({
    get: () => person.value.new_photo ?? null,
    set: (file: File | null) => {
        person.value.new_photo = file;
    },
});
</script>

<template>
    <div class="space-y-4">
        <label class="grid gap-2 text-sm font-medium"
            >Full name
            <Input v-model="person.name" autocomplete="name" maxlength="255" />
        </label>
        <label v-if="dob" class="grid gap-2 text-sm font-medium"
            >Date of birth
            <Input
                v-model="person.date_of_birth"
                type="date"
                :readonly="fixedDob"
                :aria-readonly="fixedDob"
            />
        </label>
        <p v-if="fixedDob" class="text-sm text-muted-foreground">
            If the date of birth is incorrect, ask the nursery for a corrected
            invitation.
        </p>
        <template v-if="contact">
            <label class="grid gap-2 text-sm font-medium"
                >Email
                {{
                    lockedEmail || person.document_responsible
                        ? '(required)'
                        : '(optional)'
                }}
                <Input
                    v-model="email"
                    type="email"
                    :readonly="lockedEmail"
                    autocomplete="email"
                    maxlength="255"
                />
            </label>
            <label class="grid gap-2 text-sm font-medium"
                >Phone number (required)
                <Input
                    v-model="phone"
                    type="tel"
                    autocomplete="tel"
                    maxlength="50"
                />
            </label>
        </template>
        <label v-if="relationship" class="grid gap-2 text-sm font-medium"
            >Relationship to the child (required)
            <Input v-model="person.relationship" maxlength="255" />
        </label>
        <label class="grid gap-2 text-sm font-medium"
            >Alternative full names (optional, one per line)
            <textarea
                v-model="aliases"
                rows="2"
                class="rounded-md border bg-background p-3 font-normal"
            />
        </label>
        <StaffPhotoInput v-model="photo" :name="person.name" />
    </div>
</template>
