<script setup lang="ts">
import { watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';
import StaffPhotoInput from '@/components/StaffPhotoInput.vue';
import type { RegistrationPerson } from '@/types/registration';

const person = defineModel<RegistrationPerson>({ required: true });
const props = defineProps<{ prefix: string; errors: Record<string, string> }>();
const error = (field: string): string | undefined =>
    props.errors[props.prefix ? `${props.prefix}.${field}` : field];
watch(
    () => [person.value.name, person.value.date_of_birth],
    () => {
        person.value.create_different = false;
    },
);
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label :for="`${prefix}-name`">Name</Label>
            <Input
                :id="`${prefix}-name`"
                v-model="person.name"
                required
                maxlength="255"
            />
            <InputError :message="error('name')" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${prefix}-date-of-birth`">Date of birth</Label>
            <Input
                :id="`${prefix}-date-of-birth`"
                v-model="person.date_of_birth"
                type="date"
                required
            />
            <InputError :message="error('date_of_birth')" />
        </div>
        <div class="space-y-2">
            <p class="text-sm font-medium">Also known as (optional)</p>
            <div
                v-for="(_, index) in person.also_known_as"
                :key="index"
                class="space-y-1"
            >
                <div class="flex gap-2">
                    <Input
                        v-model="person.also_known_as[index]"
                        :aria-label="`Alternative full name ${index + 1}`"
                        required
                        maxlength="255"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        :aria-label="`Remove alternative name ${index + 1}`"
                        @click="person.also_known_as.splice(index, 1)"
                        >Remove</Button
                    >
                </div>
                <InputError :message="error(`also_known_as.${index}`)" />
            </div>
            <Button
                type="button"
                variant="outline"
                :disabled="person.also_known_as.length >= 50"
                @click="person.also_known_as.push('')"
                >Add alternative name</Button
            >
            <InputError :message="error('also_known_as')" />
        </div>
        <StaffPhotoInput
            v-model="person.new_photo"
            :name="person.name"
            :error="error('new_photo')"
        />
    </div>
</template>
