<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    ArrowLeftIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    ArrowPathIcon,
    EyeIcon,
    EyeSlashIcon,
    PlusIcon,
    TrashIcon,
    ArrowsUpDownIcon,
} from '@heroicons/vue/20/solid';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import axios from 'axios';

interface Product {
    id: number;
    title: string;
    description: string | null;
    handle: string | null;
    category: string | null;
    brand: string | null;
}

interface Marketplace {
    id: number;
    name: string;
    platform: string;
    platform_label: string;
}

interface Listing {
    id: number;
    status: string;
    external_listing_id: string | null;
    listing_url: string | null;
    platform_price: number | null;
    platform_quantity: number | null;
    published_at: string | null;
    last_synced_at: string | null;
    last_error: string | null;
}

interface Override {
    id: number;
    title: string | null;
    description: string | null;
    price: number | null;
    compare_at_price: number | null;
    quantity: number | null;
    category_id: string | null;
    attributes: Record<string, string> | null;
    excluded_image_ids: number[];
    image_order: number[];
    excluded_metafields: string[];
    custom_metafields: Metafield[];
    attribute_overrides: Record<string, AttributeOverride> | null;
    platform_settings: Record<string, unknown> | null;
}

interface TemplateField {
    id: number;
    name: string;
    label: string;
    type: string;
    is_required: boolean;
    is_private: boolean;
    value: string | null;
    options: Array<{ value: string; label: string }>;
}

interface PlatformImage {
    id: number;
    url: string;
    alt: string | null;
    is_primary: boolean;
    included: boolean;
    source: string;
}

interface Metafield {
    namespace: string;
    key: string;
    value: string;
    type: string;
    included: boolean;
    source: string;
}

interface AttributeOverride {
    value?: string;
    enabled?: boolean;
    platform_field?: string;
}

interface Preview {
    listing: {
        title: string;
        description: string | null;
        price: number | null;
        compare_at_price: number | null;
        quantity: number | null;
        images: string[];
        attributes: Record<string, string>;
        metafields?: Metafield[];
        item_specifics?: Array<{ Name: string; Value: string }>;
    };
    validation: {
        valid: boolean;
        errors: string[];
        warnings: string[];
    };
}

interface Props {
    product: Product;
    marketplace: Marketplace;
    listing: Listing | null;
    override: Override | null;
    preview: Preview;
    templateFields: TemplateField[];
    platformFields: Record<string, unknown>;
    images: PlatformImage[];
    metafields: Metafield[];
    supportsMetafields: boolean;
}

const props = defineProps<Props>();

// Platform icons
const platformIcons: Record<string, string> = {
    shopify: '/images/platforms/shopify.svg',
    ebay: '/images/platforms/ebay.svg',
    amazon: '/images/platforms/amazon.svg',
    etsy: '/images/platforms/etsy.svg',
    walmart: '/images/platforms/walmart.svg',
};

// Form state
const form = ref({
    title: props.override?.title || '',
    description: props.override?.description || '',
    price: props.override?.price ?? null,
    compare_at_price: props.override?.compare_at_price ?? null,
    quantity: props.override?.quantity ?? null,
    category_id: props.override?.category_id || '',
    excluded_image_ids: props.override?.excluded_image_ids || [],
    image_order: props.override?.image_order || [],
    excluded_metafields: props.override?.excluded_metafields || [],
    custom_metafields: props.override?.custom_metafields || [],
    attribute_overrides: props.override?.attribute_overrides || {},
    platform_settings: props.override?.platform_settings || {},
});

const saving = ref(false);
const publishing = ref(false);
const syncing = ref(false);
const showJsonPreview = ref(false);
const localPreview = ref(props.preview);

// Computed
const isPublished = computed(() => props.listing?.status === 'active');
const hasChanges = computed(() => {
    // Simple check - in real app would deep compare
    return true;
});

const effectiveTitle = computed(() => form.value.title || props.preview.listing.title);
const effectiveDescription = computed(() => form.value.description || props.preview.listing.description);
const effectivePrice = computed(() => form.value.price ?? props.preview.listing.price);
const effectiveQuantity = computed(() => form.value.quantity ?? props.preview.listing.quantity);

// Images with inclusion state
const imagesWithState = computed(() => {
    return props.images.map(img => ({
        ...img,
        included: !form.value.excluded_image_ids.includes(img.id),
    }));
});

// Metafields with inclusion state
const metafieldsWithState = computed(() => {
    const templateMetafields = props.metafields.map(mf => {
        const key = `${mf.namespace}.${mf.key}`;
        return {
            ...mf,
            included: !form.value.excluded_metafields.includes(key),
        };
    });

    // Add custom metafields
    const customMetafields = form.value.custom_metafields.map(mf => ({
        ...mf,
        included: true,
        source: 'custom',
    }));

    return [...templateMetafields, ...customMetafields];
});

// Template fields with override state
const fieldsWithOverrides = computed(() => {
    return props.templateFields.map(field => {
        const override = form.value.attribute_overrides[field.name];
        return {
            ...field,
            overrideValue: override?.value || '',
            enabled: override?.enabled !== false && !field.is_private,
            platformField: override?.platform_field || '',
        };
    });
});

// Methods
function formatPrice(price: number | null): string {
    if (price === null) return '-';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(price);
}

/**
 * Get the display label for a field value.
 * If the field has options, find the matching label. Otherwise, format the raw value.
 */
function getFieldDisplayValue(field: TemplateField): string {
    if (!field.value) return '-';

    // If field has options, find the label
    if (field.options && field.options.length > 0) {
        const option = field.options.find(opt => opt.value === field.value);
        if (option) return option.label;
    }

    // Otherwise format the raw value (convert snake_case to Title Case)
    return formatSlugValue(field.value);
}

/**
 * Format a slug value to human-readable text.
 * e.g., "very_good" -> "Very Good", "silver_tone" -> "Silver Tone"
 */
function formatSlugValue(value: string): string {
    if (!value) return '-';
    return value
        .split('_')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');
}

function toggleImage(imageId: number) {
    const idx = form.value.excluded_image_ids.indexOf(imageId);
    if (idx === -1) {
        form.value.excluded_image_ids.push(imageId);
    } else {
        form.value.excluded_image_ids.splice(idx, 1);
    }
}

function toggleMetafield(namespace: string, key: string) {
    const fullKey = `${namespace}.${key}`;
    const idx = form.value.excluded_metafields.indexOf(fullKey);
    if (idx === -1) {
        form.value.excluded_metafields.push(fullKey);
    } else {
        form.value.excluded_metafields.splice(idx, 1);
    }
}

function toggleField(fieldName: string) {
    if (!form.value.attribute_overrides[fieldName]) {
        form.value.attribute_overrides[fieldName] = {};
    }
    form.value.attribute_overrides[fieldName].enabled = !form.value.attribute_overrides[fieldName].enabled;
}

function updateFieldOverride(fieldName: string, value: string) {
    if (!form.value.attribute_overrides[fieldName]) {
        form.value.attribute_overrides[fieldName] = {};
    }
    form.value.attribute_overrides[fieldName].value = value;
}

function addCustomMetafield() {
    form.value.custom_metafields.push({
        namespace: 'custom',
        key: '',
        value: '',
        type: 'single_line_text_field',
        included: true,
        source: 'custom',
    });
}

function removeCustomMetafield(index: number) {
    form.value.custom_metafields.splice(index, 1);
}

async function save() {
    saving.value = true;
    try {
        const response = await axios.put(
            `/products/${props.product.id}/platforms/${props.marketplace.id}`,
            form.value
        );
        localPreview.value = response.data.preview;
    } catch (error) {
        console.error('Failed to save:', error);
    } finally {
        saving.value = false;
    }
}

async function publish() {
    publishing.value = true;
    try {
        // Save first
        await save();

        // Then publish
        await axios.post(`/products/${props.product.id}/platforms/${props.marketplace.id}/publish`);

        // Refresh page
        router.reload();
    } catch (error) {
        console.error('Failed to publish:', error);
    } finally {
        publishing.value = false;
    }
}

async function sync() {
    syncing.value = true;
    try {
        await axios.post(`/products/${props.product.id}/platforms/${props.marketplace.id}/sync`);
        router.reload();
    } catch (error) {
        console.error('Failed to sync:', error);
    } finally {
        syncing.value = false;
    }
}

async function unpublish() {
    if (!confirm('Are you sure you want to unpublish this product from the platform?')) {
        return;
    }

    try {
        await axios.delete(`/products/${props.product.id}/platforms/${props.marketplace.id}`);
        router.reload();
    } catch (error) {
        console.error('Failed to unpublish:', error);
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="`${product.title} - ${marketplace.platform_label}`" />

        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Link
                        :href="`/products/${product.id}`"
                        class="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        <ArrowLeftIcon class="h-4 w-4" />
                        Back to Product
                    </Link>

                    <div class="flex items-center gap-3">
                        <div class="h-10 w-10 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                            <img
                                v-if="platformIcons[marketplace.platform]"
                                :src="platformIcons[marketplace.platform]"
                                :alt="marketplace.platform_label"
                                class="h-6 w-6"
                            />
                        </div>
                        <div>
                            <h1 class="text-lg font-semibold text-gray-900 dark:text-white">
                                {{ marketplace.name }}
                            </h1>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ product.title }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Status Badge -->
                    <Badge v-if="isPublished" variant="success" class="flex items-center gap-1">
                        <CheckCircleIcon class="h-3.5 w-3.5" />
                        Published
                    </Badge>
                    <Badge v-else variant="secondary">
                        Draft
                    </Badge>

                    <!-- Actions -->
                    <Button variant="outline" @click="save" :disabled="saving">
                        {{ saving ? 'Saving...' : 'Save Draft' }}
                    </Button>

                    <template v-if="isPublished">
                        <Button variant="outline" @click="sync" :disabled="syncing">
                            <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': syncing }" />
                            Sync
                        </Button>
                        <Button variant="destructive" @click="unpublish">
                            Unpublish
                        </Button>
                    </template>
                    <Button v-else @click="publish" :disabled="publishing || !preview.validation.valid">
                        {{ publishing ? 'Publishing...' : `Publish to ${marketplace.platform_label}` }}
                    </Button>
                </div>
            </div>

            <!-- Validation Status -->
            <div v-if="!preview.validation.valid || preview.validation.warnings.length > 0" class="mb-6 space-y-3">
                <div v-if="preview.validation.errors.length > 0" class="rounded-lg bg-red-50 dark:bg-red-900/20 p-4">
                    <div class="flex items-start gap-3">
                        <XCircleIcon class="h-5 w-5 text-red-500 flex-shrink-0 mt-0.5" />
                        <div>
                            <h3 class="text-sm font-medium text-red-800 dark:text-red-300">Cannot Publish</h3>
                            <ul class="mt-2 text-sm text-red-700 dark:text-red-400 list-disc list-inside space-y-1">
                                <li v-for="error in preview.validation.errors" :key="error">{{ error }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-if="preview.validation.warnings.length > 0" class="rounded-lg bg-yellow-50 dark:bg-yellow-900/20 p-4">
                    <div class="flex items-start gap-3">
                        <ExclamationTriangleIcon class="h-5 w-5 text-yellow-500 flex-shrink-0 mt-0.5" />
                        <div>
                            <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-300">Warnings</h3>
                            <ul class="mt-2 text-sm text-yellow-700 dark:text-yellow-400 list-disc list-inside space-y-1">
                                <li v-for="warning in preview.validation.warnings" :key="warning">{{ warning }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <Tabs default-value="basic" class="space-y-6">
                <TabsList>
                    <TabsTrigger value="basic">Basic Info</TabsTrigger>
                    <TabsTrigger value="images">Images</TabsTrigger>
                    <TabsTrigger value="attributes">Attributes</TabsTrigger>
                    <TabsTrigger v-if="supportsMetafields" value="metafields">Metafields</TabsTrigger>
                    <TabsTrigger value="preview">Preview</TabsTrigger>
                </TabsList>

                <!-- Basic Info Tab -->
                <TabsContent value="basic" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Basic Information</h2>

                        <div class="space-y-4">
                            <!-- Title -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label for="title">Title</Label>
                                    <Badge v-if="form.title && form.title !== preview.listing.title" variant="outline" class="text-xs">
                                        Overridden
                                    </Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                        Inherited from product
                                    </span>
                                </div>
                                <Input
                                    id="title"
                                    :model-value="form.title || preview.listing.title"
                                    @update:model-value="form.title = $event !== preview.listing.title ? $event : ''"
                                />
                            </div>

                            <!-- Description -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label for="description">Description</Label>
                                    <Badge v-if="form.description && form.description !== preview.listing.description" variant="outline" class="text-xs">
                                        Overridden
                                    </Badge>
                                    <span v-else-if="preview.listing.description" class="text-xs text-gray-500 dark:text-gray-400">
                                        Inherited from product
                                    </span>
                                </div>
                                <RichTextEditor
                                    :model-value="form.description || preview.listing.description || ''"
                                    @update:model-value="form.description = $event !== preview.listing.description ? $event : ''"
                                />
                            </div>

                            <!-- Pricing -->
                            <div class="grid grid-cols-3 gap-4">
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="price">Price</Label>
                                        <Badge v-if="form.price !== null && form.price !== preview.listing.price" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited
                                        </span>
                                    </div>
                                    <Input
                                        id="price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        :model-value="form.price ?? preview.listing.price ?? 0"
                                        @update:model-value="form.price = Number($event) !== preview.listing.price ? Number($event) : null"
                                    />
                                </div>
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="compare_at_price">Compare at Price</Label>
                                        <Badge v-if="form.compare_at_price !== null && form.compare_at_price !== preview.listing.compare_at_price" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else-if="preview.listing.compare_at_price" class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited
                                        </span>
                                    </div>
                                    <Input
                                        id="compare_at_price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        :model-value="form.compare_at_price ?? preview.listing.compare_at_price ?? ''"
                                        @update:model-value="form.compare_at_price = $event !== '' && Number($event) !== preview.listing.compare_at_price ? Number($event) : null"
                                    />
                                </div>
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="quantity">Quantity</Label>
                                        <Badge v-if="form.quantity !== null && form.quantity !== preview.listing.quantity" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited
                                        </span>
                                    </div>
                                    <Input
                                        id="quantity"
                                        type="number"
                                        min="0"
                                        :model-value="form.quantity ?? preview.listing.quantity ?? 0"
                                        @update:model-value="form.quantity = Number($event) !== preview.listing.quantity ? Number($event) : null"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </TabsContent>

                <!-- Images Tab -->
                <TabsContent value="images" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Images</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ imagesWithState.filter(i => i.included).length }} of {{ images.length }} selected
                            </p>
                        </div>

                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Select which images to include when publishing to {{ marketplace.platform_label }}.
                            Uncheck images you want to exclude from this platform.
                        </p>

                        <div v-if="images.length > 0" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                            <div
                                v-for="image in imagesWithState"
                                :key="image.id"
                                class="relative group"
                            >
                                <div
                                    class="aspect-square rounded-lg overflow-hidden border-2 transition-all cursor-pointer"
                                    :class="image.included
                                        ? 'border-indigo-500 ring-2 ring-indigo-500/20'
                                        : 'border-gray-200 dark:border-gray-700 opacity-50'"
                                    @click="toggleImage(image.id)"
                                >
                                    <img
                                        :src="image.url"
                                        :alt="image.alt || 'Product image'"
                                        class="h-full w-full object-cover"
                                    />

                                    <!-- Overlay -->
                                    <div
                                        class="absolute inset-0 flex items-center justify-center bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity"
                                    >
                                        <EyeIcon v-if="image.included" class="h-8 w-8 text-white" />
                                        <EyeSlashIcon v-else class="h-8 w-8 text-white" />
                                    </div>
                                </div>

                                <!-- Primary badge -->
                                <Badge v-if="image.is_primary" variant="secondary" class="absolute top-1 left-1 text-xs">
                                    Primary
                                </Badge>

                                <!-- Include checkbox -->
                                <div class="absolute top-1 right-1">
                                    <Checkbox
                                        :checked="image.included"
                                        @update:checked="toggleImage(image.id)"
                                    />
                                </div>
                            </div>
                        </div>

                        <p v-else class="text-center text-gray-500 dark:text-gray-400 py-8">
                            No images available for this product.
                        </p>
                    </div>
                </TabsContent>

                <!-- Attributes Tab -->
                <TabsContent value="attributes" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Attributes & Item Specifics</h2>

                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Control which attributes are sent to {{ marketplace.platform_label }} and override values if needed.
                            Private fields (marked with lock) are for internal use only.
                        </p>

                        <div v-if="templateFields.length > 0" class="space-y-3">
                            <div
                                v-for="field in fieldsWithOverrides"
                                :key="field.id"
                                class="flex items-center gap-4 py-3 border-b border-gray-100 dark:border-gray-700 last:border-0"
                            >
                                <!-- Enable/Disable checkbox -->
                                <Checkbox
                                    :checked="field.enabled"
                                    :disabled="field.is_private"
                                    @update:checked="toggleField(field.name)"
                                />

                                <!-- Field info -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ field.label }}
                                        </span>
                                        <Badge v-if="field.is_private" variant="secondary" class="text-xs">
                                            Private
                                        </Badge>
                                        <Badge v-if="field.is_required" variant="outline" class="text-xs">
                                            Required
                                        </Badge>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ field.name }} • {{ field.type }}
                                    </p>
                                </div>

                                <!-- Original value -->
                                <div class="w-40 text-right">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ getFieldDisplayValue(field) }}
                                    </span>
                                </div>

                                <!-- Override value -->
                                <div class="w-48">
                                    <Input
                                        v-model="field.overrideValue"
                                        :placeholder="field.value || 'Override value'"
                                        :disabled="!field.enabled || field.is_private"
                                        class="text-sm"
                                        @update:model-value="updateFieldOverride(field.name, $event)"
                                    />
                                </div>
                            </div>
                        </div>

                        <p v-else class="text-center text-gray-500 dark:text-gray-400 py-8">
                            No template fields available. Add a template to this product to configure attributes.
                        </p>
                    </div>
                </TabsContent>

                <!-- Metafields Tab (Shopify, etc.) -->
                <TabsContent v-if="supportsMetafields" value="metafields" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Metafields</h2>
                            <Button variant="outline" size="sm" @click="addCustomMetafield">
                                <PlusIcon class="h-4 w-4 mr-1" />
                                Add Custom
                            </Button>
                        </div>

                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Metafields are custom data fields that extend your product information.
                            Toggle to include or exclude specific metafields from this listing.
                        </p>

                        <div v-if="metafieldsWithState.length > 0" class="space-y-3">
                            <div
                                v-for="(metafield, index) in metafieldsWithState"
                                :key="`${metafield.namespace}.${metafield.key}`"
                                class="flex items-center gap-4 py-3 border-b border-gray-100 dark:border-gray-700 last:border-0"
                            >
                                <!-- Include checkbox -->
                                <Checkbox
                                    v-if="metafield.source !== 'custom'"
                                    :checked="metafield.included"
                                    @update:checked="toggleMetafield(metafield.namespace, metafield.key)"
                                />
                                <div v-else class="w-4" />

                                <!-- Metafield info -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ metafield.key }}
                                        </span>
                                        <Badge :variant="metafield.source === 'custom' ? 'default' : 'secondary'" class="text-xs">
                                            {{ metafield.source === 'custom' ? 'Custom' : 'Template' }}
                                        </Badge>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ metafield.namespace }}.{{ metafield.key }} • {{ metafield.type }}
                                    </p>
                                </div>

                                <!-- Value -->
                                <div class="w-64">
                                    <Input
                                        v-if="metafield.source === 'custom'"
                                        v-model="form.custom_metafields[index - (metafieldsWithState.length - form.custom_metafields.length)].value"
                                        placeholder="Value"
                                        class="text-sm"
                                    />
                                    <span v-else class="text-sm text-gray-700 dark:text-gray-300">
                                        {{ formatSlugValue(String(metafield.value)) }}
                                    </span>
                                </div>

                                <!-- Remove button for custom -->
                                <Button
                                    v-if="metafield.source === 'custom'"
                                    variant="ghost"
                                    size="sm"
                                    @click="removeCustomMetafield(index - (metafieldsWithState.length - form.custom_metafields.length))"
                                >
                                    <TrashIcon class="h-4 w-4 text-red-500" />
                                </Button>
                            </div>
                        </div>

                        <p v-else class="text-center text-gray-500 dark:text-gray-400 py-8">
                            No metafields configured. Configure metafields in your template settings or add custom metafields above.
                        </p>
                    </div>
                </TabsContent>

                <!-- Preview Tab -->
                <TabsContent value="preview" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Listing Preview</h2>
                            <Button variant="outline" size="sm" @click="showJsonPreview = !showJsonPreview">
                                {{ showJsonPreview ? 'Hide' : 'Show' }} JSON
                            </Button>
                        </div>

                        <!-- Summary Preview -->
                        <div v-if="!showJsonPreview" class="space-y-6">
                            <dl class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Title</dt>
                                    <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ effectiveTitle }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Price</dt>
                                    <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ formatPrice(effectivePrice) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Quantity</dt>
                                    <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ effectiveQuantity }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Images</dt>
                                    <dd class="mt-1 font-medium text-gray-900 dark:text-white">
                                        {{ imagesWithState.filter(i => i.included).length }} selected
                                    </dd>
                                </div>
                            </dl>

                            <!-- Attributes Preview -->
                            <div v-if="Object.keys(preview.listing.attributes || {}).length > 0">
                                <h3 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Attributes</h3>
                                <div class="flex flex-wrap gap-2">
                                    <Badge
                                        v-for="(value, key) in preview.listing.attributes"
                                        :key="key"
                                        variant="secondary"
                                    >
                                        {{ formatSlugValue(String(key)) }}: {{ formatSlugValue(String(value)) }}
                                    </Badge>
                                </div>
                            </div>

                            <!-- Metafields Preview -->
                            <div v-if="supportsMetafields && metafieldsWithState.filter(m => m.included).length > 0">
                                <h3 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Metafields</h3>
                                <div class="flex flex-wrap gap-2">
                                    <Badge
                                        v-for="mf in metafieldsWithState.filter(m => m.included)"
                                        :key="`${mf.namespace}.${mf.key}`"
                                        variant="outline"
                                    >
                                        {{ formatSlugValue(mf.key) }}: {{ formatSlugValue(String(mf.value)) }}
                                    </Badge>
                                </div>
                            </div>
                        </div>

                        <!-- JSON Preview -->
                        <div v-else>
                            <pre class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4 overflow-x-auto text-xs text-gray-700 dark:text-gray-300">{{ JSON.stringify(localPreview.listing, null, 2) }}</pre>
                        </div>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
