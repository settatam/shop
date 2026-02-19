<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    XMarkIcon,
    PaperAirplaneIcon,
    ChatBubbleLeftRightIcon,
    DocumentTextIcon,
    InformationCircleIcon,
} from '@heroicons/vue/24/outline';

interface SmsTemplate {
    id: string;
    name: string;
    content: string;
    preview: string;
    character_count: number;
    segment_count: number;
    encoding: string;
}

const props = defineProps<{
    open: boolean;
    transactionId: number;
    customerPhone: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const message = ref('');
const selectedTemplateId = ref<string | null>(null);
const templates = ref<SmsTemplate[]>([]);
const loadingTemplates = ref(false);
const sending = ref(false);

const SMS_CHAR_LIMIT = 160;
const SMS_EXTENDED_LIMIT = 1600;

const isOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value),
});

const characterCount = computed(() => message.value.length);
const segmentCount = computed(() => Math.ceil(message.value.length / SMS_CHAR_LIMIT) || 0);

const canSend = computed(() => {
    return (
        props.customerPhone &&
        message.value.trim().length > 0 &&
        message.value.length <= SMS_EXTENDED_LIMIT &&
        !sending.value
    );
});

// Load templates when modal opens
watch(() => props.open, async (open) => {
    if (open) {
        message.value = '';
        selectedTemplateId.value = null;
        await loadTemplates();
    }
});

const loadTemplates = async () => {
    loadingTemplates.value = true;
    try {
        const response = await fetch(`/transactions/${props.transactionId}/sms-templates`);
        const data = await response.json();
        templates.value = data.templates || [];
    } catch (error) {
        console.error('Failed to load SMS templates:', error);
        templates.value = [];
    } finally {
        loadingTemplates.value = false;
    }
};

const selectTemplate = (template: SmsTemplate) => {
    selectedTemplateId.value = template.id;
    message.value = template.preview;
};

const clearTemplate = () => {
    selectedTemplateId.value = null;
};

const send = () => {
    if (!canSend.value) return;

    sending.value = true;
    router.post(`/transactions/${props.transactionId}/custom-sms`, {
        message: message.value,
        template_id: selectedTemplateId.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            isOpen.value = false;
        },
        onFinish: () => {
            sending.value = false;
        },
    });
};

const close = () => {
    isOpen.value = false;
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
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
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
                                <div class="flex size-10 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                                    <ChatBubbleLeftRightIcon class="size-5 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div>
                                    <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white">
                                        Send SMS
                                    </DialogTitle>
                                    <p v-if="customerPhone" class="text-sm text-gray-500 dark:text-gray-400">
                                        To: {{ customerPhone }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="!customerPhone" class="text-center py-8">
                                <InformationCircleIcon class="mx-auto size-12 text-gray-400" />
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    No phone number on file for this customer.
                                </p>
                            </div>

                            <template v-else>
                                <!-- Templates -->
                                <div v-if="loadingTemplates" class="mb-4">
                                    <div class="animate-pulse flex gap-2">
                                        <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded w-20"></div>
                                        <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded w-24"></div>
                                        <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded w-16"></div>
                                    </div>
                                </div>

                                <div v-else-if="templates.length > 0" class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Quick Templates
                                    </label>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="template in templates"
                                            :key="template.id"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition-colors"
                                            :class="selectedTemplateId === template.id
                                                ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'"
                                            @click="selectTemplate(template)"
                                        >
                                            <DocumentTextIcon class="size-3.5" />
                                            {{ template.name }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Message Compose -->
                                <div class="mb-4">
                                    <label for="sms-message" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Message
                                    </label>
                                    <textarea
                                        id="sms-message"
                                        v-model="message"
                                        rows="4"
                                        maxlength="1600"
                                        class="block w-full rounded-md border-0 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-blue-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        placeholder="Type your message..."
                                        @input="clearTemplate"
                                    />
                                    <div class="mt-2 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                        <span>
                                            {{ characterCount }}/{{ SMS_EXTENDED_LIMIT }}
                                            <span v-if="segmentCount > 1" class="text-amber-600 dark:text-amber-400">
                                                ({{ segmentCount }} segments)
                                            </span>
                                        </span>
                                        <span v-if="segmentCount > 1" class="flex items-center gap-1">
                                            <InformationCircleIcon class="size-3.5" />
                                            Multi-segment message
                                        </span>
                                    </div>
                                </div>

                                <!-- Info Box -->
                                <div class="mb-6 rounded-md bg-blue-50 dark:bg-blue-900/20 p-3">
                                    <div class="flex">
                                        <div class="shrink-0">
                                            <InformationCircleIcon class="size-5 text-blue-400" />
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-xs text-blue-700 dark:text-blue-300">
                                                Messages over 160 characters will be sent as multiple segments.
                                                Standard messaging rates may apply to the customer.
                                            </p>
                                        </div>
                                    </div>
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
                                        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                        @click="send"
                                    >
                                        <PaperAirplaneIcon class="size-4" />
                                        {{ sending ? 'Sending...' : 'Send SMS' }}
                                    </button>
                                </div>
                            </template>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
