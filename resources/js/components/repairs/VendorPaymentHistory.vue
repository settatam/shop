<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    PencilIcon,
    TrashIcon,
    ArrowDownTrayIcon,
    PaperClipIcon,
} from '@heroicons/vue/24/outline';
import VendorPaymentForm from './VendorPaymentForm.vue';

interface User {
    id: number;
    name: string;
}

interface Vendor {
    id: number;
    name: string;
    display_name?: string;
}

interface VendorPayment {
    id: number;
    check_number?: string;
    amount: number;
    vendor_invoice_amount?: number;
    reason?: string;
    payment_date?: string;
    has_attachment: boolean;
    attachment_name?: string;
    created_at: string;
    vendor?: Vendor;
    user?: User;
}

interface Props {
    repairId: number;
    payments: VendorPayment[];
}

const props = defineProps<Props>();

const showEditModal = ref(false);
const editingPayment = ref<VendorPayment | null>(null);
const isDeleting = ref<number | null>(null);

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
}

function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function editPayment(payment: VendorPayment) {
    editingPayment.value = payment;
    showEditModal.value = true;
}

function closeEditModal() {
    showEditModal.value = false;
    editingPayment.value = null;
}

function onPaymentSaved() {
    closeEditModal();
    router.reload({ only: ['repair'] });
}

function deletePayment(payment: VendorPayment) {
    if (!confirm('Are you sure you want to delete this vendor payment?')) return;

    isDeleting.value = payment.id;
    router.delete(`/repair-vendor-payments/${payment.id}`, {
        preserveScroll: true,
        onFinish: () => {
            isDeleting.value = null;
        },
    });
}

function downloadAttachment(payment: VendorPayment) {
    window.location.href = `/repair-vendor-payments/${payment.id}/attachment`;
}

const totalPaid = props.payments.reduce((sum, p) => sum + Number(p.amount), 0);
</script>

<template>
    <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">
                Vendor Payments ({{ payments.length }})
            </h2>
            <div v-if="payments.length > 0" class="text-sm text-gray-500 dark:text-gray-400">
                Total: <span class="font-medium text-gray-900 dark:text-white">{{ formatCurrency(totalPaid) }}</span>
            </div>
        </div>

        <div v-if="payments.length > 0" class="space-y-3">
            <div
                v-for="payment in payments"
                :key="payment.id"
                class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-700/50"
            >
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-center gap-3">
                            <span class="text-lg font-semibold text-gray-900 dark:text-white">
                                {{ formatCurrency(payment.amount) }}
                            </span>
                            <span v-if="payment.check_number" class="text-sm text-gray-500 dark:text-gray-400">
                                Check #{{ payment.check_number }}
                            </span>
                        </div>

                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            <span v-if="payment.payment_date">
                                {{ formatDate(payment.payment_date) }}
                            </span>
                            <span v-if="payment.vendor">
                                &bull; {{ payment.vendor.display_name || payment.vendor.name }}
                            </span>
                        </div>

                        <div v-if="payment.vendor_invoice_amount" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Invoice Amount: {{ formatCurrency(payment.vendor_invoice_amount) }}
                        </div>

                        <p v-if="payment.reason" class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            {{ payment.reason }}
                        </p>

                        <div v-if="payment.has_attachment" class="mt-2 flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                @click="downloadAttachment(payment)"
                            >
                                <PaperClipIcon class="size-4" />
                                {{ payment.attachment_name }}
                            </button>
                        </div>

                        <div v-if="payment.user" class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                            Recorded by {{ payment.user.name }}
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded p-1 text-gray-400 hover:bg-gray-200 hover:text-gray-600 dark:hover:bg-gray-600 dark:hover:text-gray-300"
                            title="Edit payment"
                            @click="editPayment(payment)"
                        >
                            <PencilIcon class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded p-1 text-gray-400 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                            title="Delete payment"
                            :disabled="isDeleting === payment.id"
                            @click="deletePayment(payment)"
                        >
                            <TrashIcon class="size-4" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="text-center py-6 text-gray-500 dark:text-gray-400">
            <p>No vendor payments recorded yet.</p>
        </div>

        <!-- Edit Modal -->
        <VendorPaymentForm
            :show="showEditModal"
            :repair-id="repairId"
            :payment="editingPayment"
            @close="closeEditModal"
            @saved="onPaymentSaved"
        />
    </div>
</template>
