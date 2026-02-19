<script setup lang="ts">
import { ref, computed, watch } from 'vue';
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
    PlusIcon,
    TrashIcon,
    CurrencyDollarIcon,
    SparklesIcon,
    StarIcon,
    CheckBadgeIcon,
} from '@heroicons/vue/24/outline';

interface Offer {
    amount: string;
    tier: 'good' | 'better' | 'best';
    reasoning: string;
    images: number[];
    expires_at: string;
    admin_notes: string;
}

interface Image {
    id: number;
    url: string;
    thumbnail_url: string;
    item_title?: string;
}

const props = defineProps<{
    open: boolean;
    transactionId: number;
    availableImages: Image[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const tiers = [
    { value: 'good', label: 'Good', icon: StarIcon, color: 'text-gray-500', bg: 'bg-gray-100 dark:bg-gray-700' },
    { value: 'better', label: 'Better', icon: SparklesIcon, color: 'text-blue-500', bg: 'bg-blue-100 dark:bg-blue-900/30' },
    { value: 'best', label: 'Best', icon: CheckBadgeIcon, color: 'text-emerald-500', bg: 'bg-emerald-100 dark:bg-emerald-900/30' },
] as const;

const createEmptyOffer = (tier: 'good' | 'better' | 'best'): Offer => ({
    amount: '',
    tier,
    reasoning: '',
    images: [],
    expires_at: '',
    admin_notes: '',
});

const offers = ref<Offer[]>([createEmptyOffer('best')]);
const sendNotification = ref(false);
const submitting = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value),
});

// Reset offers when modal opens
watch(() => props.open, (open) => {
    if (open) {
        offers.value = [createEmptyOffer('best')];
        sendNotification.value = false;
    }
});

const availableTiers = computed(() => {
    const usedTiers = offers.value.map(o => o.tier);
    return tiers.filter(t => !usedTiers.includes(t.value));
});

const canAddOffer = computed(() => offers.value.length < 3 && availableTiers.value.length > 0);

const addOffer = () => {
    if (!canAddOffer.value) return;
    const nextTier = availableTiers.value[0].value;
    offers.value.push(createEmptyOffer(nextTier));
};

const removeOffer = (index: number) => {
    if (offers.value.length <= 1) return;
    offers.value.splice(index, 1);
};

const getTierInfo = (tier: string) => {
    return tiers.find(t => t.value === tier) || tiers[0];
};

const toggleImage = (offerIndex: number, imageId: number) => {
    const imageIds = offers.value[offerIndex].images;
    const idx = imageIds.indexOf(imageId);
    if (idx === -1) {
        imageIds.push(imageId);
    } else {
        imageIds.splice(idx, 1);
    }
};

const isImageSelected = (offerIndex: number, imageId: number) => {
    return offers.value[offerIndex].images.includes(imageId);
};

const canSubmit = computed(() => {
    return offers.value.every(o => {
        const amount = parseFloat(o.amount);
        return !isNaN(amount) && amount > 0;
    }) && !submitting.value;
});

const submit = () => {
    if (!canSubmit.value) return;

    submitting.value = true;
    router.post(`/transactions/${props.transactionId}/multiple-offers`, {
        offers: offers.value.map(o => ({
            amount: parseFloat(o.amount),
            tier: o.tier,
            reasoning: o.reasoning || null,
            images: o.images.length > 0 ? o.images : null,
            expires_at: o.expires_at || null,
            admin_notes: o.admin_notes || null,
        })),
        send_notification: sendNotification.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            isOpen.value = false;
        },
        onFinish: () => {
            submitting.value = false;
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
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl sm:p-6 dark:bg-gray-800">
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
                                <div class="flex size-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                    <CurrencyDollarIcon class="size-5 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white">
                                    Create Multiple Offers
                                </DialogTitle>
                            </div>

                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                Create up to 3 offer tiers (good, better, best) for the customer to choose from.
                            </p>

                            <!-- Offers List -->
                            <div class="space-y-6 max-h-[60vh] overflow-y-auto pr-2">
                                <div
                                    v-for="(offer, index) in offers"
                                    :key="index"
                                    class="border border-gray-200 dark:border-gray-700 rounded-lg p-4"
                                >
                                    <div class="flex items-center justify-between mb-4">
                                        <div class="flex items-center gap-2">
                                            <div :class="[getTierInfo(offer.tier).bg, 'p-1.5 rounded-md']">
                                                <component
                                                    :is="getTierInfo(offer.tier).icon"
                                                    class="size-5"
                                                    :class="getTierInfo(offer.tier).color"
                                                />
                                            </div>
                                            <select
                                                v-model="offer.tier"
                                                class="block rounded-md border-0 py-1.5 pl-3 pr-8 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-emerald-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option
                                                    v-for="tier in tiers"
                                                    :key="tier.value"
                                                    :value="tier.value"
                                                    :disabled="offers.some((o, i) => i !== index && o.tier === tier.value)"
                                                >
                                                    {{ tier.label }}
                                                </option>
                                            </select>
                                        </div>
                                        <button
                                            v-if="offers.length > 1"
                                            type="button"
                                            class="text-gray-400 hover:text-red-500"
                                            @click="removeOffer(index)"
                                        >
                                            <TrashIcon class="size-5" />
                                        </button>
                                    </div>

                                    <div class="grid gap-4">
                                        <!-- Amount -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Offer Amount *
                                            </label>
                                            <div class="relative">
                                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                    <span class="text-gray-500 dark:text-gray-400">$</span>
                                                </div>
                                                <input
                                                    v-model="offer.amount"
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    required
                                                    class="block w-full rounded-md border-0 py-1.5 pl-7 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-emerald-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                    placeholder="0.00"
                                                />
                                            </div>
                                        </div>

                                        <!-- Reasoning -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Reasoning (visible to customer)
                                            </label>
                                            <textarea
                                                v-model="offer.reasoning"
                                                rows="2"
                                                maxlength="2000"
                                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-emerald-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="Explain why this offer..."
                                            />
                                        </div>

                                        <!-- Expiration -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Expires At (optional)
                                            </label>
                                            <input
                                                v-model="offer.expires_at"
                                                type="datetime-local"
                                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-emerald-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>

                                        <!-- Images Selection -->
                                        <div v-if="availableImages.length > 0">
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                                Include Images
                                            </label>
                                            <div class="flex flex-wrap gap-2">
                                                <button
                                                    v-for="image in availableImages"
                                                    :key="image.id"
                                                    type="button"
                                                    class="relative size-16 rounded-md overflow-hidden ring-2 transition-all"
                                                    :class="isImageSelected(index, image.id) ? 'ring-emerald-500' : 'ring-transparent hover:ring-gray-300'"
                                                    @click="toggleImage(index, image.id)"
                                                >
                                                    <img
                                                        :src="image.thumbnail_url"
                                                        :alt="image.item_title || 'Item'"
                                                        class="w-full h-full object-cover"
                                                    />
                                                    <div
                                                        v-if="isImageSelected(index, image.id)"
                                                        class="absolute inset-0 bg-emerald-500/20 flex items-center justify-center"
                                                    >
                                                        <div class="bg-emerald-500 rounded-full p-0.5">
                                                            <svg class="size-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                            </svg>
                                                        </div>
                                                    </div>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Admin Notes -->
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                Admin Notes (internal only)
                                            </label>
                                            <input
                                                v-model="offer.admin_notes"
                                                type="text"
                                                maxlength="1000"
                                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-emerald-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                placeholder="Internal notes..."
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Add Offer Button -->
                            <button
                                v-if="canAddOffer"
                                type="button"
                                class="mt-4 w-full flex items-center justify-center gap-2 rounded-md border-2 border-dashed border-gray-300 dark:border-gray-600 py-3 text-sm text-gray-600 dark:text-gray-400 hover:border-emerald-500 hover:text-emerald-600 transition-colors"
                                @click="addOffer"
                            >
                                <PlusIcon class="size-5" />
                                Add Another Tier
                            </button>

                            <!-- Send Notification Checkbox -->
                            <div class="mt-6 flex items-center gap-2">
                                <input
                                    id="send-notification"
                                    v-model="sendNotification"
                                    type="checkbox"
                                    class="size-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-600 dark:border-gray-600 dark:bg-gray-700"
                                />
                                <label for="send-notification" class="text-sm text-gray-700 dark:text-gray-300">
                                    Send notification to customer
                                </label>
                            </div>

                            <!-- Actions -->
                            <div class="mt-6 flex justify-end gap-3">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="close"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="!canSubmit"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                    @click="submit"
                                >
                                    {{ submitting ? 'Submitting...' : 'Submit Offers' }}
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
