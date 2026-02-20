<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Dialog, DialogPanel, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { XMarkIcon, PaperClipIcon, TrashIcon } from '@heroicons/vue/24/outline';

interface VendorPayment {
    id: number;
    check_number?: string;
    amount: number;
    vendor_invoice_amount?: number;
    reason?: string;
    payment_date?: string;
    has_attachment: boolean;
    attachment_name?: string;
}

interface Props {
    show: boolean;
    repairId: number;
    payment?: VendorPayment | null;
}

const props = withDefaults(defineProps<Props>(), {
    show: false,
    payment: null,
});

const emit = defineEmits<{
    close: [];
    saved: [];
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const dragOver = ref(false);

const form = useForm<{
    check_number: string;
    amount: string;
    vendor_invoice_amount: string;
    reason: string;
    payment_date: string;
    attachment: File | null;
    remove_attachment: boolean;
    _method?: string;
}>({
    check_number: '',
    amount: '',
    vendor_invoice_amount: '',
    reason: '',
    payment_date: new Date().toISOString().split('T')[0],
    attachment: null,
    remove_attachment: false,
});

const isEditing = ref(false);

watch(
    () => [props.show, props.payment],
    () => {
        if (props.show) {
            if (props.payment) {
                isEditing.value = true;
                form.check_number = props.payment.check_number || '';
                form.amount = props.payment.amount?.toString() || '';
                form.vendor_invoice_amount = props.payment.vendor_invoice_amount?.toString() || '';
                form.reason = props.payment.reason || '';
                form.payment_date = props.payment.payment_date || new Date().toISOString().split('T')[0];
                form.attachment = null;
                form.remove_attachment = false;
            } else {
                isEditing.value = false;
                form.reset();
                form.payment_date = new Date().toISOString().split('T')[0];
            }
        }
    },
    { immediate: true }
);

const close = () => {
    form.reset();
    form.clearErrors();
    emit('close');
};

const submit = () => {
    if (isEditing.value) {
        form.transform((data) => ({
            ...data,
            _method: 'put',
        })).post(`/repair-vendor-payments/${props.payment?.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                emit('saved');
                close();
            },
        });
    } else {
        form.post(`/repairs/${props.repairId}/vendor-payments`, {
            preserveScroll: true,
            onSuccess: () => {
                emit('saved');
                close();
            },
        });
    }
};

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        form.attachment = target.files[0];
        form.remove_attachment = false;
    }
};

const handleDrop = (event: DragEvent) => {
    event.preventDefault();
    dragOver.value = false;
    if (event.dataTransfer?.files && event.dataTransfer.files.length > 0) {
        form.attachment = event.dataTransfer.files[0];
        form.remove_attachment = false;
    }
};

const handleDragOver = (event: DragEvent) => {
    event.preventDefault();
    dragOver.value = true;
};

const handleDragLeave = () => {
    dragOver.value = false;
};

const removeFile = () => {
    form.attachment = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const removeExistingAttachment = () => {
    form.remove_attachment = true;
};
</script>

<template>
    <TransitionRoot as="template" :show="show">
        <Dialog class="relative z-50" @close="close">
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

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
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
                        <DialogPanel
                            class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800"
                        >
                            <div class="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    class="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:bg-gray-800 dark:hover:text-gray-300"
                                    @click="close"
                                >
                                    <span class="sr-only">Close</span>
                                    <XMarkIcon class="size-6" />
                                </button>
                            </div>

                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                    {{ isEditing ? 'Edit Vendor Payment' : 'Add Vendor Payment' }}
                                </h3>

                                <form @submit.prevent="submit" class="space-y-4">
                                    <!-- Amount (Required) -->
                                    <div>
                                        <label
                                            for="vendor_payment_amount"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            Amount <span class="text-red-500">*</span>
                                        </label>
                                        <div class="relative mt-1">
                                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                            <input
                                                id="vendor_payment_amount"
                                                v-model="form.amount"
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                required
                                                class="block w-full rounded-md border-0 py-1.5 pl-7 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="0.00"
                                            />
                                        </div>
                                        <p v-if="form.errors.amount" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.amount }}
                                        </p>
                                    </div>

                                    <!-- Check Number -->
                                    <div>
                                        <label
                                            for="vendor_payment_check_number"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            Check / Reference Number
                                        </label>
                                        <input
                                            id="vendor_payment_check_number"
                                            v-model="form.check_number"
                                            type="text"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            placeholder="CHK-1234"
                                        />
                                        <p v-if="form.errors.check_number" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.check_number }}
                                        </p>
                                    </div>

                                    <!-- Vendor Invoice Amount -->
                                    <div>
                                        <label
                                            for="vendor_payment_invoice_amount"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            Vendor Invoice Amount
                                        </label>
                                        <div class="relative mt-1">
                                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                            <input
                                                id="vendor_payment_invoice_amount"
                                                v-model="form.vendor_invoice_amount"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="block w-full rounded-md border-0 py-1.5 pl-7 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="0.00"
                                            />
                                        </div>
                                        <p v-if="form.errors.vendor_invoice_amount" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.vendor_invoice_amount }}
                                        </p>
                                    </div>

                                    <!-- Payment Date -->
                                    <div>
                                        <label
                                            for="vendor_payment_date"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            Payment Date
                                        </label>
                                        <input
                                            id="vendor_payment_date"
                                            v-model="form.payment_date"
                                            type="date"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        />
                                        <p v-if="form.errors.payment_date" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.payment_date }}
                                        </p>
                                    </div>

                                    <!-- Reason -->
                                    <div>
                                        <label
                                            for="vendor_payment_reason"
                                            class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                        >
                                            Reason / Description
                                        </label>
                                        <textarea
                                            id="vendor_payment_reason"
                                            v-model="form.reason"
                                            rows="2"
                                            class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            placeholder="Describe the payment..."
                                        />
                                        <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.reason }}
                                        </p>
                                    </div>

                                    <!-- File Attachment -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            Attachment (Invoice/Receipt)
                                        </label>

                                        <!-- Existing attachment -->
                                        <div
                                            v-if="isEditing && payment?.has_attachment && !form.remove_attachment && !form.attachment"
                                            class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-600 dark:bg-gray-700"
                                        >
                                            <div class="flex items-center gap-2">
                                                <PaperClipIcon class="size-5 text-gray-400" />
                                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ payment.attachment_name }}</span>
                                            </div>
                                            <button
                                                type="button"
                                                class="text-red-500 hover:text-red-700"
                                                @click="removeExistingAttachment"
                                            >
                                                <TrashIcon class="size-4" />
                                            </button>
                                        </div>

                                        <!-- New file selected -->
                                        <div
                                            v-else-if="form.attachment"
                                            class="flex items-center justify-between rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-600 dark:bg-green-900/30"
                                        >
                                            <div class="flex items-center gap-2">
                                                <PaperClipIcon class="size-5 text-green-500" />
                                                <span class="text-sm text-green-700 dark:text-green-300">{{ form.attachment.name }}</span>
                                            </div>
                                            <button
                                                type="button"
                                                class="text-red-500 hover:text-red-700"
                                                @click="removeFile"
                                            >
                                                <TrashIcon class="size-4" />
                                            </button>
                                        </div>

                                        <!-- Drop zone -->
                                        <div
                                            v-else
                                            class="mt-1 flex justify-center rounded-lg border border-dashed px-6 py-6"
                                            :class="[
                                                dragOver
                                                    ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                                                    : 'border-gray-300 dark:border-gray-600'
                                            ]"
                                            @drop="handleDrop"
                                            @dragover="handleDragOver"
                                            @dragleave="handleDragLeave"
                                        >
                                            <div class="text-center">
                                                <PaperClipIcon class="mx-auto size-8 text-gray-400" />
                                                <div class="mt-2 flex text-sm text-gray-600 dark:text-gray-400">
                                                    <label
                                                        for="file_upload"
                                                        class="relative cursor-pointer rounded-md font-medium text-indigo-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-indigo-500 focus-within:ring-offset-2 hover:text-indigo-500 dark:text-indigo-400"
                                                    >
                                                        <span>Upload a file</span>
                                                        <input
                                                            id="file_upload"
                                                            ref="fileInput"
                                                            type="file"
                                                            class="sr-only"
                                                            accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx"
                                                            @change="handleFileSelect"
                                                        />
                                                    </label>
                                                    <p class="pl-1">or drag and drop</p>
                                                </div>
                                                <p class="text-xs text-gray-500 dark:text-gray-500">PDF, images, or documents up to 10MB</p>
                                            </div>
                                        </div>
                                        <p v-if="form.errors.attachment" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                            {{ form.errors.attachment }}
                                        </p>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex gap-3 justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
                                        <button
                                            type="button"
                                            class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            @click="close"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="form.processing"
                                            class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                        >
                                            {{ form.processing ? 'Saving...' : (isEditing ? 'Update Payment' : 'Add Payment') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
