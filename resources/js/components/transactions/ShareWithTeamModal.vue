<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { XMarkIcon, PaperAirplaneIcon, UserGroupIcon } from '@heroicons/vue/24/outline';

interface TeamMember {
    id: number;
    name: string;
    email?: string;
}

const props = defineProps<{
    open: boolean;
    teamMembers: TeamMember[];
    shareUrl: string;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const selectedMemberIds = ref<number[]>([]);
const message = ref('');
const sending = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value),
});

const canSend = computed(() => selectedMemberIds.value.length > 0 && !sending.value);

const toggleMember = (memberId: number) => {
    const index = selectedMemberIds.value.indexOf(memberId);
    if (index === -1) {
        selectedMemberIds.value.push(memberId);
    } else {
        selectedMemberIds.value.splice(index, 1);
    }
};

const isMemberSelected = (memberId: number) => {
    return selectedMemberIds.value.includes(memberId);
};

const share = () => {
    if (!canSend.value) return;

    sending.value = true;
    router.post(props.shareUrl, {
        team_member_ids: selectedMemberIds.value,
        message: message.value || null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            isOpen.value = false;
            selectedMemberIds.value = [];
            message.value = '';
        },
        onFinish: () => {
            sending.value = false;
        },
    });
};

const close = () => {
    isOpen.value = false;
    selectedMemberIds.value = [];
    message.value = '';
};
</script>

<template>
    <TransitionRoot as="template" :show="isOpen">
        <Dialog as="div" class="relative z-50" @close="close">
            <TransitionChild
                as="template"
                enter="ease-out duration-300"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-200"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/75" />
            </TransitionChild>

            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    >
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md sm:p-6 dark:bg-gray-800">
                            <div class="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    class="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none dark:bg-gray-800"
                                    @click="close"
                                >
                                    <span class="sr-only">Close</span>
                                    <XMarkIcon class="size-6" />
                                </button>
                            </div>

                            <div class="flex items-center gap-3 mb-6">
                                <div class="flex size-10 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/30">
                                    <UserGroupIcon class="size-5 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white">
                                    Share with Team
                                </DialogTitle>
                            </div>

                            <!-- Team members selection -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Select Team Members
                                </label>
                                <div class="max-h-48 overflow-y-auto border border-gray-200 rounded-md dark:border-gray-700">
                                    <div v-if="teamMembers.length === 0" class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                        No team members available
                                    </div>
                                    <div
                                        v-for="member in teamMembers"
                                        :key="member.id"
                                        class="flex items-center gap-3 p-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer border-b border-gray-100 dark:border-gray-700 last:border-b-0"
                                        @click="toggleMember(member.id)"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="isMemberSelected(member.id)"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                            @click.stop
                                            @change="toggleMember(member.id)"
                                        />
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                                {{ member.name }}
                                            </p>
                                            <p v-if="member.email" class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                                {{ member.email }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <p v-if="selectedMemberIds.length > 0" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ selectedMemberIds.length }} team member{{ selectedMemberIds.length === 1 ? '' : 's' }} selected
                                </p>
                            </div>

                            <!-- Message -->
                            <div class="mb-6">
                                <label for="share-message" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Message (optional)
                                </label>
                                <textarea
                                    id="share-message"
                                    v-model="message"
                                    rows="3"
                                    maxlength="500"
                                    class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400"
                                    placeholder="Add a note for your team..."
                                />
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 text-right">
                                    {{ message.length }}/500
                                </p>
                            </div>

                            <!-- Actions -->
                            <div class="flex justify-end gap-3">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="close"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="!canSend"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                    @click="share"
                                >
                                    <PaperAirplaneIcon class="size-4" />
                                    {{ sending ? 'Sending...' : 'Send' }}
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
