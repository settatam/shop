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
    PaperAirplaneIcon,
    EnvelopeIcon,
    PhotoIcon,
    EyeIcon,
    InformationCircleIcon,
} from '@heroicons/vue/24/outline';

interface Offer {
    id: number;
    amount: number;
    tier?: string;
    tier_label?: string;
    status: string;
}

interface Image {
    id: number;
    url: string;
    thumbnail_url: string;
    source: string;
    item_title: string | null;
}

interface EmailPreview {
    store_name: string;
    store_logo: string | null;
    customer_name: string;
    transaction_number: string;
    offer_amount: string;
    tier: string;
    tier_label: string;
    reasoning: string;
    images: Image[];
    portal_url: string;
    expires_at: string | null;
    item_count: number;
    items_summary: { title: string; category: string | null }[];
}

const props = defineProps<{
    open: boolean;
    transactionId: number;
    offers: Offer[];
    customerEmail: string | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const selectedOfferId = ref<number | null>(null);
const reasoning = ref('');
const selectedImageIds = ref<number[]>([]);
const customSubject = ref('');
const availableImages = ref<Image[]>([]);
const loadingImages = ref(false);
const showPreview = ref(false);
const preview = ref<EmailPreview | null>(null);
const loadingPreview = ref(false);
const sending = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (value) => emit('update:open', value),
});

const pendingOffers = computed(() => {
    return props.offers.filter(o => o.status === 'pending');
});

const selectedOffer = computed(() => {
    return pendingOffers.value.find(o => o.id === selectedOfferId.value);
});

const canSend = computed(() => {
    return (
        props.customerEmail &&
        selectedOfferId.value &&
        reasoning.value.trim().length > 0 &&
        !sending.value
    );
});

// Reset when modal opens
watch(() => props.open, async (open) => {
    if (open) {
        // Auto-select first pending offer
        selectedOfferId.value = pendingOffers.value[0]?.id || null;
        reasoning.value = '';
        selectedImageIds.value = [];
        customSubject.value = '';
        showPreview.value = false;
        preview.value = null;
        await loadAvailableImages();
    }
});

const loadAvailableImages = async () => {
    loadingImages.value = true;
    try {
        const response = await fetch(`/transactions/${props.transactionId}/offer-email/images`);
        const data = await response.json();
        availableImages.value = data.images || [];
    } catch (error) {
        console.error('Failed to load images:', error);
        availableImages.value = [];
    } finally {
        loadingImages.value = false;
    }
};

const toggleImage = (imageId: number) => {
    const idx = selectedImageIds.value.indexOf(imageId);
    if (idx === -1) {
        selectedImageIds.value.push(imageId);
    } else {
        selectedImageIds.value.splice(idx, 1);
    }
};

const isImageSelected = (imageId: number) => {
    return selectedImageIds.value.includes(imageId);
};

const loadPreview = async () => {
    if (!selectedOfferId.value || !reasoning.value.trim()) return;

    loadingPreview.value = true;
    try {
        const response = await fetch(`/transactions/${props.transactionId}/offer-email/preview`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                offer_id: selectedOfferId.value,
                reasoning: reasoning.value,
                image_ids: selectedImageIds.value,
            }),
        });
        const data = await response.json();
        preview.value = data.preview;
        showPreview.value = true;
    } catch (error) {
        console.error('Failed to load preview:', error);
    } finally {
        loadingPreview.value = false;
    }
};

const send = () => {
    if (!canSend.value) return;

    sending.value = true;
    router.post(`/transactions/${props.transactionId}/offer-email`, {
        offer_id: selectedOfferId.value,
        reasoning: reasoning.value,
        image_ids: selectedImageIds.value,
        custom_subject: customSubject.value || null,
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

const formatMoney = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
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
                                <div class="flex size-10 items-center justify-center rounded-full bg-purple-100 dark:bg-purple-900/30">
                                    <EnvelopeIcon class="size-5 text-purple-600 dark:text-purple-400" />
                                </div>
                                <div>
                                    <DialogTitle as="h3" class="text-lg font-semibold text-gray-900 dark:text-white">
                                        Send Offer Email
                                    </DialogTitle>
                                    <p v-if="customerEmail" class="text-sm text-gray-500 dark:text-gray-400">
                                        To: {{ customerEmail }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="!customerEmail" class="text-center py-8">
                                <InformationCircleIcon class="mx-auto size-12 text-gray-400" />
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    No email address on file for this customer.
                                </p>
                            </div>

                            <div v-else-if="pendingOffers.length === 0" class="text-center py-8">
                                <InformationCircleIcon class="mx-auto size-12 text-gray-400" />
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    No pending offers available. Create an offer first.
                                </p>
                            </div>

                            <template v-else>
                                <div class="space-y-4 max-h-[60vh] overflow-y-auto pr-2">
                                    <!-- Offer Selection -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                            Select Offer *
                                        </label>
                                        <div class="grid gap-2">
                                            <label
                                                v-for="offer in pendingOffers"
                                                :key="offer.id"
                                                class="relative flex items-center justify-between rounded-lg border p-4 cursor-pointer transition-colors"
                                                :class="selectedOfferId === offer.id
                                                    ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20'
                                                    : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50'"
                                            >
                                                <div class="flex items-center gap-3">
                                                    <input
                                                        type="radio"
                                                        :value="offer.id"
                                                        v-model="selectedOfferId"
                                                        class="size-4 border-gray-300 text-purple-600 focus:ring-purple-600 dark:border-gray-600 dark:bg-gray-700"
                                                    />
                                                    <div>
                                                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                            {{ formatMoney(offer.amount) }}
                                                        </p>
                                                        <p v-if="offer.tier_label" class="text-xs text-gray-500 dark:text-gray-400">
                                                            {{ offer.tier_label }} Offer
                                                        </p>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Reasoning -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                            Reasoning / Assessment *
                                        </label>
                                        <textarea
                                            v-model="reasoning"
                                            rows="4"
                                            maxlength="5000"
                                            class="block w-full rounded-md border-0 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            placeholder="Explain the offer and assessment of the items..."
                                        />
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 text-right">
                                            {{ reasoning.length }}/5000
                                        </p>
                                    </div>

                                    <!-- Custom Subject -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                            Email Subject (optional)
                                        </label>
                                        <input
                                            v-model="customSubject"
                                            type="text"
                                            maxlength="255"
                                            class="block w-full rounded-md border-0 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-purple-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            placeholder="Leave blank for default subject"
                                        />
                                    </div>

                                    <!-- Image Selection -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                            <PhotoIcon class="inline size-4 mr-1" />
                                            Include Images
                                        </label>

                                        <div v-if="loadingImages" class="animate-pulse flex gap-2">
                                            <div class="size-20 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                            <div class="size-20 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                            <div class="size-20 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                        </div>

                                        <div v-else-if="availableImages.length === 0" class="text-center py-4 border border-dashed border-gray-300 dark:border-gray-600 rounded-md">
                                            <PhotoIcon class="mx-auto size-8 text-gray-400" />
                                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                                No images available
                                            </p>
                                        </div>

                                        <div v-else class="flex flex-wrap gap-2">
                                            <button
                                                v-for="image in availableImages"
                                                :key="image.id"
                                                type="button"
                                                class="relative group"
                                                @click="toggleImage(image.id)"
                                            >
                                                <div
                                                    class="size-20 rounded-md overflow-hidden ring-2 transition-all"
                                                    :class="isImageSelected(image.id) ? 'ring-purple-500' : 'ring-transparent group-hover:ring-gray-300'"
                                                >
                                                    <img
                                                        :src="image.thumbnail_url"
                                                        :alt="image.item_title || 'Image'"
                                                        class="w-full h-full object-cover"
                                                    />
                                                </div>
                                                <div
                                                    v-if="isImageSelected(image.id)"
                                                    class="absolute inset-0 bg-purple-500/20 rounded-md flex items-center justify-center"
                                                >
                                                    <div class="bg-purple-500 rounded-full p-1">
                                                        <svg class="size-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                        </svg>
                                                    </div>
                                                </div>
                                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate max-w-[80px]">
                                                    {{ image.item_title || image.source }}
                                                </p>
                                            </button>
                                        </div>

                                        <p v-if="selectedImageIds.length > 0" class="mt-2 text-xs text-purple-600 dark:text-purple-400">
                                            {{ selectedImageIds.length }} image{{ selectedImageIds.length === 1 ? '' : 's' }} selected
                                        </p>
                                    </div>
                                </div>

                                <!-- Preview Section -->
                                <div v-if="showPreview && preview" class="mt-4 border border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-gray-50 dark:bg-gray-900/50">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Preview</h4>
                                    <div class="text-sm text-gray-600 dark:text-gray-300 space-y-2">
                                        <p><strong>To:</strong> {{ customerEmail }}</p>
                                        <p><strong>Offer:</strong> {{ preview.offer_amount }}</p>
                                        <p><strong>Images:</strong> {{ preview.images.length }}</p>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="mt-6 flex justify-between">
                                    <button
                                        type="button"
                                        :disabled="!selectedOfferId || !reasoning.trim() || loadingPreview"
                                        class="inline-flex items-center gap-1.5 rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50 disabled:cursor-not-allowed dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
                                        @click="loadPreview"
                                    >
                                        <EyeIcon class="size-4" />
                                        {{ loadingPreview ? 'Loading...' : 'Preview' }}
                                    </button>

                                    <div class="flex gap-3">
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
                                            class="inline-flex items-center gap-1.5 rounded-md bg-purple-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                            @click="send"
                                        >
                                            <PaperAirplaneIcon class="size-4" />
                                            {{ sending ? 'Sending...' : 'Send Email' }}
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
