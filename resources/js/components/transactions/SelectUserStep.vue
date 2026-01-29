<script setup lang="ts">
import { RadioGroup, RadioGroupLabel, RadioGroupOption } from '@headlessui/vue';
import { CheckCircleIcon } from '@heroicons/vue/20/solid';
import { UserIcon, InformationCircleIcon } from '@heroicons/vue/24/outline';

interface StoreUser {
    id: number;
    name: string;
}

interface Props {
    storeUsers: StoreUser[];
    selectedId: number | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    select: [id: number];
}>();

function selectUser(id: number) {
    emit('select', id);
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                    Select Employee
                </h2>
                <div class="group relative">
                    <InformationCircleIcon class="size-5 text-gray-400 hover:text-gray-500 cursor-help" />
                    <div class="pointer-events-none absolute left-1/2 top-full z-10 mt-2 w-64 -translate-x-1/2 rounded-lg bg-gray-900 px-3 py-2 text-sm text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100 dark:bg-gray-700">
                        Click on an employee to change the selection
                        <div class="absolute -top-1 left-1/2 -translate-x-1/2 border-4 border-transparent border-b-gray-900 dark:border-b-gray-700"></div>
                    </div>
                </div>
            </div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Who is processing this buy transaction?
            </p>
        </div>

        <RadioGroup :model-value="selectedId" @update:model-value="selectUser">
            <RadioGroupLabel class="sr-only">Select employee</RadioGroupLabel>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <RadioGroupOption
                    v-for="user in storeUsers"
                    :key="user.id"
                    :value="user.id"
                    v-slot="{ checked, active }"
                    as="template"
                >
                    <div
                        :class="[
                            'relative flex cursor-pointer rounded-lg border p-4 shadow-sm focus:outline-none',
                            checked
                                ? 'border-indigo-600 ring-2 ring-indigo-600'
                                : 'border-gray-300 dark:border-gray-600',
                            active ? 'border-indigo-600 ring-2 ring-indigo-600' : '',
                        ]"
                    >
                        <div class="flex w-full items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    :class="[
                                        'flex size-10 shrink-0 items-center justify-center rounded-full',
                                        checked ? 'bg-indigo-100 dark:bg-indigo-900' : 'bg-gray-100 dark:bg-gray-700',
                                    ]"
                                >
                                    <UserIcon
                                        :class="[
                                            'size-5',
                                            checked ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-500 dark:text-gray-400',
                                        ]"
                                    />
                                </div>
                                <RadioGroupLabel
                                    as="span"
                                    :class="[
                                        'text-sm font-medium',
                                        checked ? 'text-indigo-900 dark:text-indigo-100' : 'text-gray-900 dark:text-white',
                                    ]"
                                >
                                    {{ user.name }}
                                </RadioGroupLabel>
                            </div>
                            <CheckCircleIcon
                                v-if="checked"
                                class="size-5 text-indigo-600 dark:text-indigo-400"
                            />
                        </div>
                    </div>
                </RadioGroupOption>
            </div>
        </RadioGroup>
    </div>
</template>
