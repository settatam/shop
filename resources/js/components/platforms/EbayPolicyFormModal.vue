<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type PolicyType = 'return' | 'fulfillment' | 'payment';

interface Props {
    open: boolean;
    policyType: PolicyType;
    marketplaceId: string;
    editData?: Record<string, unknown> | null;
}

const props = withDefaults(defineProps<Props>(), {
    editData: null,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    save: [data: Record<string, unknown>];
}>();

const saving = ref(false);
const errors = ref<Record<string, string>>({});

const selectClass = 'mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

// Return policy form
const returnForm = ref({
    name: '',
    marketplaceId: '',
    returnsAccepted: true,
    returnPeriod: { value: 30, unit: 'DAY' },
    refundMethod: 'MONEY_BACK',
    returnShippingCostPayer: 'BUYER',
    description: '',
});

// Fulfillment policy form
const fulfillmentForm = ref({
    name: '',
    marketplaceId: '',
    handlingTime: { value: 1, unit: 'DAY' },
    description: '',
});

// Payment policy form
const paymentForm = ref({
    name: '',
    marketplaceId: '',
    immediatePay: true,
    description: '',
});

const title = computed(() => {
    const isEdit = !!props.editData;
    const prefix = isEdit ? 'Edit' : 'Create';
    const typeLabel = {
        return: 'Return Policy',
        fulfillment: 'Fulfillment Policy',
        payment: 'Payment Policy',
    }[props.policyType];
    return `${prefix} ${typeLabel}`;
});

function resetForms() {
    returnForm.value = {
        name: '',
        marketplaceId: props.marketplaceId,
        returnsAccepted: true,
        returnPeriod: { value: 30, unit: 'DAY' },
        refundMethod: 'MONEY_BACK',
        returnShippingCostPayer: 'BUYER',
        description: '',
    };
    fulfillmentForm.value = {
        name: '',
        marketplaceId: props.marketplaceId,
        handlingTime: { value: 1, unit: 'DAY' },
        description: '',
    };
    paymentForm.value = {
        name: '',
        marketplaceId: props.marketplaceId,
        immediatePay: true,
        description: '',
    };
    errors.value = {};
}

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        resetForms();
        if (props.editData) {
            populateFromEditData();
        }
    }
});

function populateFromEditData() {
    const data = props.editData;
    if (!data) return;

    if (props.policyType === 'return') {
        returnForm.value.name = (data.name as string) ?? '';
        returnForm.value.marketplaceId = (data.marketplaceId as string) ?? props.marketplaceId;
        returnForm.value.returnsAccepted = (data.returnsAccepted as boolean) ?? true;
        returnForm.value.returnPeriod = (data.returnPeriod as { value: number; unit: string }) ?? { value: 30, unit: 'DAY' };
        returnForm.value.refundMethod = (data.refundMethod as string) ?? 'MONEY_BACK';
        returnForm.value.returnShippingCostPayer = (data.returnShippingCostPayer as string) ?? 'BUYER';
        returnForm.value.description = (data.description as string) ?? '';
    } else if (props.policyType === 'fulfillment') {
        fulfillmentForm.value.name = (data.name as string) ?? '';
        fulfillmentForm.value.marketplaceId = (data.marketplaceId as string) ?? props.marketplaceId;
        fulfillmentForm.value.handlingTime = (data.handlingTime as { value: number; unit: string }) ?? { value: 1, unit: 'DAY' };
        fulfillmentForm.value.description = (data.description as string) ?? '';
    } else if (props.policyType === 'payment') {
        paymentForm.value.name = (data.name as string) ?? '';
        paymentForm.value.marketplaceId = (data.marketplaceId as string) ?? props.marketplaceId;
        paymentForm.value.immediatePay = (data.immediatePay as boolean) ?? true;
        paymentForm.value.description = (data.description as string) ?? '';
    }
}

function submit() {
    errors.value = {};
    saving.value = true;

    let formData: Record<string, unknown>;

    if (props.policyType === 'return') {
        if (!returnForm.value.name) {
            errors.value.name = 'Name is required.';
            saving.value = false;
            return;
        }
        formData = {
            ...returnForm.value,
            categoryTypes: [{ name: 'ALL_EXCLUDING_MOTORS_VEHICLES' }],
        };
    } else if (props.policyType === 'fulfillment') {
        if (!fulfillmentForm.value.name) {
            errors.value.name = 'Name is required.';
            saving.value = false;
            return;
        }
        formData = {
            ...fulfillmentForm.value,
            categoryTypes: [{ name: 'ALL_EXCLUDING_MOTORS_VEHICLES' }],
        };
    } else {
        if (!paymentForm.value.name) {
            errors.value.name = 'Name is required.';
            saving.value = false;
            return;
        }
        formData = {
            ...paymentForm.value,
            categoryTypes: [{ name: 'ALL_EXCLUDING_MOTORS_VEHICLES' }],
        };
    }

    emit('save', formData);
    saving.value = false;
}

function close() {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="close">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    Configure the policy details below. This will be created directly on your eBay account.
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="submit" class="space-y-4">
                <!-- Return Policy Form -->
                <template v-if="policyType === 'return'">
                    <div>
                        <Label for="rp-name">Policy Name</Label>
                        <Input id="rp-name" v-model="returnForm.name" class="mt-1" placeholder="e.g. 30-Day Returns" />
                        <InputError :message="errors.name" />
                    </div>

                    <div>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="returnForm.returnsAccepted"
                                class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-800"
                            />
                            <span class="text-sm font-medium text-gray-900 dark:text-white">Returns Accepted</span>
                        </label>
                    </div>

                    <div v-if="returnForm.returnsAccepted" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label for="rp-period">Return Window (days)</Label>
                            <Input id="rp-period" v-model.number="returnForm.returnPeriod.value" type="number" min="1" class="mt-1" />
                        </div>

                        <div>
                            <Label for="rp-refund">Refund Method</Label>
                            <select id="rp-refund" v-model="returnForm.refundMethod" :class="selectClass">
                                <option value="MONEY_BACK">Money Back</option>
                                <option value="MERCHANDISE_CREDIT">Merchandise Credit</option>
                            </select>
                        </div>

                        <div>
                            <Label for="rp-shipping">Return Shipping Cost Payer</Label>
                            <select id="rp-shipping" v-model="returnForm.returnShippingCostPayer" :class="selectClass">
                                <option value="BUYER">Buyer</option>
                                <option value="SELLER">Seller</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <Label for="rp-desc">Description</Label>
                        <textarea
                            id="rp-desc"
                            v-model="returnForm.description"
                            rows="2"
                            class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            placeholder="Optional description..."
                        />
                    </div>
                </template>

                <!-- Fulfillment Policy Form -->
                <template v-if="policyType === 'fulfillment'">
                    <div>
                        <Label for="fp-name">Policy Name</Label>
                        <Input id="fp-name" v-model="fulfillmentForm.name" class="mt-1" placeholder="e.g. Standard Shipping" />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label for="fp-handling">Handling Time (days)</Label>
                            <Input id="fp-handling" v-model.number="fulfillmentForm.handlingTime.value" type="number" min="0" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <Label for="fp-desc">Description</Label>
                        <textarea
                            id="fp-desc"
                            v-model="fulfillmentForm.description"
                            rows="2"
                            class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            placeholder="Optional description..."
                        />
                    </div>
                </template>

                <!-- Payment Policy Form -->
                <template v-if="policyType === 'payment'">
                    <div>
                        <Label for="pp-name">Policy Name</Label>
                        <Input id="pp-name" v-model="paymentForm.name" class="mt-1" placeholder="e.g. Immediate Payment" />
                        <InputError :message="errors.name" />
                    </div>

                    <div>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                v-model="paymentForm.immediatePay"
                                class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-800"
                            />
                            <span class="text-sm font-medium text-gray-900 dark:text-white">Require Immediate Payment</span>
                        </label>
                    </div>

                    <div>
                        <Label for="pp-desc">Description</Label>
                        <textarea
                            id="pp-desc"
                            v-model="paymentForm.description"
                            rows="2"
                            class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            placeholder="Optional description..."
                        />
                    </div>
                </template>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="close">Cancel</Button>
                    <Button type="submit" :disabled="saving">
                        {{ saving ? 'Saving...' : (editData ? 'Update' : 'Create') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
