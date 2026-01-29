<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    TruckIcon,
    ExclamationTriangleIcon,
    CheckCircleIcon,
} from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';

interface TransactionData {
    id: number;
    transaction_number: string;
    type: string;
    customer: {
        full_name: string;
        address: string | null;
        city: string | null;
        state: string | null;
        zip: string | null;
    } | null;
    has_outbound_label: boolean;
    has_return_label: boolean;
    outbound_tracking: string | null;
    return_tracking: string | null;
}

interface Props {
    transactions: TransactionData[];
    selectedTransactionIds: number[];
    labelType: string;
    isConfigured: boolean;
    serviceTypes: Record<string, string>;
    packagingTypes: Record<string, string>;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Labels', href: '/labels' },
    { title: 'Print shipping labels', href: '#' },
];

const selectedServiceType = ref<string>('');
const selectedPackagingType = ref<string>('');
const isCreating = ref(false);

const transactionsMissingAddress = props.transactions.filter(
    t => !t.customer?.address || !t.customer?.city || !t.customer?.state || !t.customer?.zip
);

const transactionsWithAddress = props.transactions.filter(
    t => t.customer?.address && t.customer?.city && t.customer?.state && t.customer?.zip
);

function hasExistingLabel(t: TransactionData): boolean {
    return props.labelType === 'return' ? t.has_return_label : t.has_outbound_label;
}

function getExistingTracking(t: TransactionData): string | null {
    return props.labelType === 'return' ? t.return_tracking : t.outbound_tracking;
}

function createLabels() {
    if (transactionsWithAddress.length === 0) return;

    isCreating.value = true;

    router.post('/print-labels/shipping', {
        transaction_ids: transactionsWithAddress.map(t => t.id),
        type: props.labelType === 'return' ? 'return' : 'outbound',
        service_type: selectedServiceType.value || undefined,
        packaging_type: selectedPackagingType.value || undefined,
    }, {
        preserveScroll: true,
        onFinish: () => {
            isCreating.value = false;
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Print shipping labels" />

        <div class="mx-auto max-w-4xl space-y-6">
            <HeadingSmall
                :title="`Create ${labelType === 'return' ? 'return' : 'outbound'} shipping labels`"
                :description="`Create shipping labels for ${transactions.length} selected transaction${transactions.length === 1 ? '' : 's'}`"
            />

            <!-- Not configured warning -->
            <div v-if="!isConfigured" class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-500/20 dark:bg-yellow-500/10">
                <div class="flex items-center gap-3">
                    <ExclamationTriangleIcon class="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                    <div>
                        <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">FedEx not configured</p>
                        <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">
                            Please add FedEx credentials in your store settings to create shipping labels.
                        </p>
                    </div>
                </div>
            </div>

            <template v-else>
                <!-- Missing address warning -->
                <div v-if="transactionsMissingAddress.length > 0" class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-500/20 dark:bg-yellow-500/10">
                    <div class="flex items-center gap-3">
                        <ExclamationTriangleIcon class="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                        <div>
                            <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                {{ transactionsMissingAddress.length }} transaction{{ transactionsMissingAddress.length === 1 ? '' : 's' }} missing customer address
                            </p>
                            <ul class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">
                                <li v-for="t in transactionsMissingAddress" :key="t.id">
                                    {{ t.transaction_number }} - {{ t.customer?.full_name || 'No customer' }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Shipping options -->
                <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">Shipping Options</h3>

                    <div class="grid gap-6 md:grid-cols-2">
                        <div>
                            <Label for="service_type">Service Type</Label>
                            <select
                                id="service_type"
                                v-model="selectedServiceType"
                                class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-white/10"
                            >
                                <option value="">Default</option>
                                <option v-for="(label, value) in serviceTypes" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <Label for="packaging_type">Packaging Type</Label>
                            <select
                                id="packaging_type"
                                v-model="selectedPackagingType"
                                class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-white/10"
                            >
                                <option value="">Default</option>
                                <option v-for="(label, value) in packagingTypes" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Transactions list -->
                <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
                    <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">Transactions</h3>

                    <div class="max-h-80 overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Transaction #</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Customer</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Address</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Label Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                <tr v-for="t in transactions" :key="t.id">
                                    <td class="px-3 py-2 text-sm font-medium text-gray-900 dark:text-white">
                                        {{ t.transaction_number }}
                                    </td>
                                    <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                                        {{ t.customer?.full_name || 'No customer' }}
                                    </td>
                                    <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                                        <template v-if="t.customer?.address">
                                            {{ t.customer.address }}, {{ t.customer.city }}, {{ t.customer.state }} {{ t.customer.zip }}
                                        </template>
                                        <span v-else class="text-red-500">Missing</span>
                                    </td>
                                    <td class="px-3 py-2 text-sm">
                                        <div v-if="hasExistingLabel(t)" class="flex items-center gap-1 text-green-600 dark:text-green-400">
                                            <CheckCircleIcon class="h-4 w-4" />
                                            {{ getExistingTracking(t) }}
                                        </div>
                                        <span v-else class="text-gray-400">No label</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end gap-4">
                    <Button variant="outline" as="a" href="/transactions">
                        Cancel
                    </Button>

                    <Button
                        @click="createLabels"
                        :disabled="isCreating || transactionsWithAddress.length === 0"
                    >
                        <TruckIcon class="mr-2 h-4 w-4" />
                        {{ isCreating ? 'Creating...' : `Create ${transactionsWithAddress.length} Label${transactionsWithAddress.length === 1 ? '' : 's'}` }}
                    </Button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
