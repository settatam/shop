<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import axios from 'axios';

interface PlatformListing {
    id: number;
    platform: string;
    platform_label: string;
    marketplace: {
        id: number;
        name: string;
    };
}

interface Override {
    id?: number;
    title: string | null;
    description: string | null;
    price: number | null;
    compare_at_price: number | null;
    quantity: number | null;
    category_id: string | null;
    attributes: Record<string, string> | null;
}

interface ProductData {
    title: string;
    description: string | null;
    price: number | null;
    compare_at_price: number | null;
    quantity: number | null;
}

interface Props {
    open: boolean;
    productId: number;
    listing: PlatformListing | null;
    override?: Override;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'saved'): void;
}>();

const loading = ref(false);
const saving = ref(false);
const productData = ref<ProductData | null>(null);

const form = useForm({
    title: '',
    description: '',
    price: null as number | null,
    compare_at_price: null as number | null,
    quantity: null as number | null,
    category_id: '',
    attributes: {} as Record<string, string>,
});

// Load product data and reset form when modal opens
watch(() => props.open, async (isOpen) => {
    if (isOpen && props.listing) {
        loading.value = true;
        try {
            const response = await axios.get(`/products/${props.productId}/listings/${props.listing.marketplace.id}/preview`);
            productData.value = response.data.listing;

            // Initialize form with override values or defaults
            form.title = props.override?.title || '';
            form.description = props.override?.description || '';
            form.price = props.override?.price ?? null;
            form.compare_at_price = props.override?.compare_at_price ?? null;
            form.quantity = props.override?.quantity ?? null;
            form.category_id = props.override?.category_id || '';
            form.attributes = props.override?.attributes || {};
        } catch (error) {
            console.error('Failed to load preview:', error);
        } finally {
            loading.value = false;
        }
    }
});

const effectiveTitle = computed(() => form.title || productData.value?.title || '');
const effectiveDescription = computed(() => form.description || productData.value?.description || '');
const effectivePrice = computed(() => form.price ?? productData.value?.price ?? null);

function formatPrice(price: number | null): string {
    if (price === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
}

async function saveOverride() {
    if (!props.listing) return;

    saving.value = true;
    try {
        await axios.put(`/products/${props.productId}/listings/${props.listing.marketplace.id}/override`, {
            title: form.title || null,
            description: form.description || null,
            price: form.price,
            compare_at_price: form.compare_at_price,
            quantity: form.quantity,
            category_id: form.category_id || null,
            attributes: Object.keys(form.attributes).length > 0 ? form.attributes : null,
        });
        emit('saved');
    } catch (error) {
        console.error('Failed to save override:', error);
    } finally {
        saving.value = false;
    }
}

function closeModal() {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-w-2xl max-h-[90vh] flex flex-col">
            <DialogHeader>
                <DialogTitle>
                    Edit Override for {{ listing?.marketplace.name }}
                </DialogTitle>
            </DialogHeader>

            <!-- Loading State -->
            <div v-if="loading" class="space-y-4 py-4">
                <div class="space-y-2">
                    <Skeleton class="h-4 w-16" />
                    <Skeleton class="h-10 w-full" />
                </div>
                <div class="space-y-2">
                    <Skeleton class="h-4 w-20" />
                    <Skeleton class="h-32 w-full" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <Skeleton class="h-4 w-12" />
                        <Skeleton class="h-10 w-full" />
                    </div>
                    <div class="space-y-2">
                        <Skeleton class="h-4 w-20" />
                        <Skeleton class="h-10 w-full" />
                    </div>
                </div>
            </div>

            <!-- Form -->
            <div v-else class="flex-1 overflow-y-auto space-y-6 py-4">
                <!-- Title -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label for="title">Title</Label>
                        <span v-if="!form.title && productData?.title" class="text-xs text-gray-500 dark:text-gray-400">
                            Using product title
                        </span>
                    </div>
                    <Input
                        id="title"
                        v-model="form.title"
                        :placeholder="productData?.title || 'Enter title'"
                    />
                    <p v-if="form.title" class="text-xs text-gray-500 dark:text-gray-400">
                        Original: {{ productData?.title }}
                    </p>
                </div>

                <!-- Description -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label for="description">Description</Label>
                        <span v-if="!form.description && productData?.description" class="text-xs text-gray-500 dark:text-gray-400">
                            Using product description
                        </span>
                    </div>
                    <RichTextEditor
                        v-model="form.description"
                        :placeholder="productData?.description ? 'Enter description to override' : 'Enter description'"
                    />
                </div>

                <!-- Pricing -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <Label for="price">Price</Label>
                            <span v-if="form.price === null && productData?.price" class="text-xs text-gray-500 dark:text-gray-400">
                                Using {{ formatPrice(productData.price) }}
                            </span>
                        </div>
                        <Input
                            id="price"
                            v-model.number="form.price"
                            type="number"
                            step="0.01"
                            min="0"
                            :placeholder="productData?.price?.toString() || '0.00'"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="compare_at_price">Compare at Price</Label>
                        <Input
                            id="compare_at_price"
                            v-model.number="form.compare_at_price"
                            type="number"
                            step="0.01"
                            min="0"
                            :placeholder="productData?.compare_at_price?.toString() || '0.00'"
                        />
                    </div>
                </div>

                <!-- Quantity -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label for="quantity">Quantity</Label>
                        <span v-if="form.quantity === null && productData?.quantity !== null" class="text-xs text-gray-500 dark:text-gray-400">
                            Using {{ productData.quantity }}
                        </span>
                    </div>
                    <Input
                        id="quantity"
                        v-model.number="form.quantity"
                        type="number"
                        min="0"
                        :placeholder="productData?.quantity?.toString() || '0'"
                        class="max-w-xs"
                    />
                </div>

                <!-- Preview -->
                <div class="rounded-lg bg-gray-50 dark:bg-gray-900 p-4">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-3">Preview</h4>
                    <dl class="space-y-2 text-sm">
                        <div class="flex items-start justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">Title</dt>
                            <dd class="text-gray-900 dark:text-white text-right max-w-xs truncate">
                                {{ effectiveTitle }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">Price</dt>
                            <dd class="text-gray-900 dark:text-white">
                                {{ formatPrice(effectivePrice) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="closeModal">Cancel</Button>
                <Button
                    @click="saveOverride"
                    :disabled="saving"
                >
                    {{ saving ? 'Saving...' : 'Save Override' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
