<script setup lang="ts">
import { Check } from '@lucide/vue';
defineProps<{ steps: string[]; current: number; disabled?: boolean }>();
defineEmits<{ select: [index: number] }>();
</script>
<template>
    <nav aria-label="Progress">
        <ol class="divide-y rounded-xl border md:flex md:divide-y-0">
            <li
                v-for="(step, index) in steps"
                :key="step"
                class="relative min-w-0 flex-1"
            >
                <button
                    type="button"
                    :disabled="disabled || index > current"
                    :aria-current="index === current ? 'step' : undefined"
                    class="flex min-h-16 w-full items-center gap-3 rounded-lg px-4 py-3 text-left text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-default md:pr-6"
                    @click="$emit('select', index)"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-full border-2"
                        :class="
                            index < current
                                ? 'border-primary bg-primary text-primary-foreground'
                                : index === current
                                  ? 'border-primary text-primary'
                                  : 'border-muted-foreground/30 text-muted-foreground'
                        "
                    >
                        <Check
                            v-if="index < current"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <span v-else>{{
                            String(index + 1).padStart(2, '0')
                        }}</span>
                    </span>
                    <span
                        :class="
                            index === current
                                ? 'text-primary'
                                : index > current
                                  ? 'text-muted-foreground'
                                  : ''
                        "
                        >{{ step }}</span
                    >
                </button>
                <svg
                    v-if="index < steps.length - 1"
                    class="absolute top-0 right-0 hidden h-full w-4 text-border md:block"
                    viewBox="0 0 22 80"
                    fill="none"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                >
                    <path
                        d="M0 -2L20 40L0 82"
                        vector-effect="non-scaling-stroke"
                        stroke="currentColor"
                        stroke-linejoin="round"
                    />
                </svg>
            </li>
        </ol>
    </nav>
</template>
