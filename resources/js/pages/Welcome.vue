<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    ClipboardCheck,
    DoorOpen,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { dashboard } from '@/wayfinder/routes/filament/admin/pages';

const steps = [
    {
        number: '01',
        icon: Users,
        title: 'Request a collection',
        description:
            'Adults let your team know who they have arrived to collect.',
    },
    {
        number: '02',
        icon: ShieldCheck,
        title: 'Check with confidence',
        description:
            'Staff review people, photos and relationships before handover.',
    },
    {
        number: '03',
        icon: ClipboardCheck,
        title: 'Keep everyone in the loop',
        description:
            'Follow each child’s progress from preparation to collection.',
    },
];
</script>

<template>
    <div
        class="min-h-svh bg-[#f7f8f2] text-[#183d32] selection:bg-[#d5edaa] selection:text-[#183d32] dark:bg-[#102820] dark:text-[#f7f8f2]"
    >
        <Head title="Door Check" />

        <div
            class="mx-auto flex min-h-svh max-w-7xl flex-col px-6 sm:px-10 lg:px-16"
        >
            <header
                class="flex items-center justify-between gap-4 border-b border-[#183d32]/15 py-6 dark:border-white/15"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-[#183d32] text-[#d5edaa] dark:bg-[#d5edaa] dark:text-[#183d32]"
                    >
                        <DoorOpen
                            class="size-6"
                            :stroke-width="1.8"
                            aria-hidden="true"
                        />
                    </span>
                    <span class="text-xl font-semibold tracking-tight"
                        >Door Check<span
                            class="text-[#648445] dark:text-[#d5edaa]"
                            >.</span
                        ></span
                    >
                </div>
                <a
                    :href="dashboard.url()"
                    class="group inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-sm font-semibold transition-colors hover:bg-[#183d32]/5 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-current dark:hover:bg-white/10"
                >
                    Admin
                    <ArrowRight
                        class="size-4 transition-transform group-hover:translate-x-1 motion-reduce:transform-none"
                        aria-hidden="true"
                    />
                </a>
            </header>

            <main class="flex flex-1 flex-col justify-center">
                <section
                    class="grid items-center gap-14 py-16 sm:py-20 lg:grid-cols-[1.1fr_1fr] lg:gap-16 lg:py-24"
                    aria-labelledby="welcome-heading"
                >
                    <div class="flex flex-col items-start gap-7">
                        <p
                            class="flex items-center gap-2 text-xs font-semibold tracking-[0.18em] uppercase"
                        >
                            <span
                                class="size-2 rounded-full bg-[#648445] dark:bg-[#d5edaa]"
                                aria-hidden="true"
                            ></span>
                            A calmer collection time
                        </p>
                        <h1
                            id="welcome-heading"
                            class="max-w-xl text-5xl leading-[1.05] font-semibold tracking-[-0.055em] sm:text-6xl lg:text-7xl"
                        >
                            Small checks.<br />
                            <span class="text-[#648445] dark:text-[#d5edaa]"
                                >Big peace<br class="hidden lg:block" />
                                of mind.</span
                            >
                        </h1>
                        <p
                            class="max-w-md text-lg leading-relaxed text-[#183d32]/75 dark:text-white/70"
                        >
                            Bring clarity to every child collection. Connect
                            requests, people and staff checks in one place, from
                            arrival to handover.
                        </p>
                        <div class="flex flex-col items-start gap-3 pt-1">
                            <a
                                :href="dashboard.url()"
                                class="group inline-flex min-h-13 items-center justify-center gap-6 rounded-full bg-[#183d32] px-7 py-3.5 text-sm font-semibold text-white transition-colors hover:bg-[#2c5747] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#183d32] dark:bg-[#d5edaa] dark:text-[#183d32] dark:hover:bg-[#e2f4c3] dark:focus-visible:outline-[#d5edaa]"
                            >
                                Open admin
                                <ArrowRight
                                    class="size-5 transition-transform group-hover:translate-x-1 motion-reduce:transform-none"
                                    aria-hidden="true"
                                />
                            </a>
                            <span
                                class="pl-2 text-xs text-[#183d32]/60 dark:text-white/60"
                                >For authorised team members.</span
                            >
                        </div>
                    </div>

                    <div class="relative isolate mx-auto w-full max-w-md">
                        <div
                            class="absolute -inset-5 -z-10 rounded-full bg-[#e3edcf]/70 blur-3xl dark:bg-[#6f8c53]/10"
                            aria-hidden="true"
                        ></div>
                        <div
                            class="overflow-hidden rounded-t-[10rem] rounded-b-3xl border border-[#183d32]/10 bg-[#e9eedf] px-6 pt-12 pb-7 sm:px-9 dark:border-white/10 dark:bg-[#1b382d]"
                        >
                            <div
                                class="relative mx-auto flex h-48 w-36 items-end justify-center rounded-t-[5rem] border-8 border-[#183d32] border-b-transparent bg-[#d5edaa] dark:border-[#aec78d] dark:border-b-transparent"
                                aria-hidden="true"
                            >
                                <div
                                    class="absolute inset-x-3 top-4 bottom-0 rounded-t-[4rem] border border-[#183d32]/20"
                                ></div>
                                <div
                                    class="absolute right-5 bottom-16 size-2.5 rounded-full bg-[#183d32]"
                                ></div>
                                <span
                                    class="absolute -right-8 bottom-8 flex size-20 rotate-[-8deg] items-center justify-center rounded-3xl border-4 border-[#e9eedf] bg-[#183d32] text-[#d5edaa] shadow-lg dark:border-[#1b382d]"
                                >
                                    <Check
                                        class="size-10"
                                        :stroke-width="2.5"
                                    />
                                </span>
                            </div>
                            <div
                                class="relative flex flex-col gap-5 rounded-2xl border border-[#183d32]/10 bg-[#fdfef9] p-6 shadow-[0_12px_32px_-20px_rgba(24,61,50,0.3)] dark:border-white/10 dark:bg-[#102820]"
                            >
                                <div
                                    class="flex items-center justify-between gap-3 border-b border-[#183d32]/10 pb-4 dark:border-white/10"
                                >
                                    <span class="text-sm font-semibold"
                                        >Every handover matters.</span
                                    >
                                    <ShieldCheck
                                        class="size-5 shrink-0 text-[#648445] dark:text-[#d5edaa]"
                                        aria-hidden="true"
                                    />
                                </div>
                                <div
                                    v-for="item in [
                                        'Know who’s collecting',
                                        'Review the right details',
                                        'Record the handover',
                                    ]"
                                    :key="item"
                                    class="flex items-center gap-3 text-sm text-[#183d32]/80 dark:text-white/80"
                                >
                                    <span
                                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#e9eedf] text-[#183d32] dark:bg-[#d5edaa]/15 dark:text-[#d5edaa]"
                                    >
                                        <Check
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    {{ item }}
                                </div>
                            </div>
                        </div>
                        <p
                            class="pt-4 text-center text-[11px] font-medium tracking-[0.16em] text-[#183d32]/55 uppercase dark:text-white/50"
                        >
                            People first. Checks in place.
                        </p>
                    </div>
                </section>

                <section
                    aria-label="How Door Check works"
                    class="grid gap-8 border-t border-[#183d32]/15 py-10 md:grid-cols-3 md:gap-10 dark:border-white/15"
                >
                    <article
                        v-for="step in steps"
                        :key="step.number"
                        class="flex flex-col items-start gap-3"
                    >
                        <div class="flex w-full items-center justify-between">
                            <component
                                :is="step.icon"
                                class="size-5 text-[#648445] dark:text-[#d5edaa]"
                                :stroke-width="1.7"
                                aria-hidden="true"
                            />
                            <span
                                class="font-mono text-xs text-[#183d32]/45 dark:text-white/40"
                                >{{ step.number }}</span
                            >
                        </div>
                        <h2 class="text-base font-semibold tracking-tight">
                            {{ step.title }}
                        </h2>
                        <p
                            class="max-w-xs text-sm leading-relaxed text-[#183d32]/70 dark:text-white/65"
                        >
                            {{ step.description }}
                        </p>
                    </article>
                </section>
            </main>

            <footer
                class="flex flex-wrap items-center justify-between gap-3 border-t border-[#183d32]/15 py-5 text-xs text-[#183d32]/60 dark:border-white/15 dark:text-white/50"
            >
                <span>Door Check</span>
                <span>A little more confidence at the door.</span>
            </footer>
        </div>
    </div>
</template>
