<script setup lang="ts">
import { ref, computed } from 'vue';
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
    ArrowTopRightOnSquareIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    ArrowPathIcon,
    EyeIcon,
    EyeSlashIcon,
    PlusIcon,
    TrashIcon,
    SparklesIcon,
    LockClosedIcon,
} from '@heroicons/vue/20/solid';
import RichTextEditor from '@/components/ui/RichTextEditor.vue';
import PlatformCategoryBrowser from '@/components/platforms/PlatformCategoryBrowser.vue';
import EbayLocationFormModal from '@/components/platforms/EbayLocationFormModal.vue';
import EbayItemSpecificsEditor from '@/components/platforms/EbayItemSpecificsEditor.vue';
import ShopifyMetafieldEditor from '@/components/platforms/ShopifyMetafieldEditor.vue';
import axios from 'axios';

interface Product {
    id: number;
    title: string;
    description: string | null;
    handle: string | null;
    category: string | null;
    brand: string | null;
    weight: number | null;
    weight_unit: string;
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
    should_list: boolean;
    external_listing_id: string | null;
    listing_url: string | null;
    platform_price: number | null;
    platform_quantity: number | null;
    quantity_override: number | null;
    inventory_quantity: number;
    effective_quantity: number;
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

interface PolicyOption {
    id: string;
    name: string;
    is_default: boolean;
}

interface MarketplaceSettings {
    listing_type: string;
    marketplace_id: string;
    auction_markup: number | null;
    fixed_price_markup: number | null;
    fulfillment_policy_id: string | null;
    payment_policy_id: string | null;
    return_policy_id: string | null;
    location_key: string | null;
    listing_duration_auction: string | null;
    listing_duration_fixed: string | null;
    best_offer_enabled: boolean;
    default_condition: string | null;
}

interface CategoryMapping {
    primary_category_id: string | null;
    secondary_category_id: string | null;
    primary_category_name: string | null;
    secondary_category_name: string | null;
}

interface WarehouseOption {
    id: number;
    name: string;
    code: string | null;
    address_line1: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    is_default: boolean;
}

interface EbayItemSpecific {
    id: number;
    name: string;
    is_required: boolean;
    is_recommended: boolean;
    aspect_mode: string;
    allowed_values: string[];
    mapped_template_field: string | null;
    resolved_value: string | null;
    is_listing_override: boolean;
}

interface EbayItemSpecificsData {
    specifics: EbayItemSpecific[];
    category_mapping_id: number | null;
    category_id: number | null;
    synced_at: string | null;
    needs_sync: boolean;
}

interface ShopifyMetafieldDef {
    id: number;
    name: string;
    key: string;
    namespace: string;
    type: string;
    description: string | null;
    mapped_template_field: string | null;
    resolved_value: string | null;
    is_listing_override: boolean;
}

interface ShopifyMetafieldsData {
    definitions: ShopifyMetafieldDef[];
    has_definitions: boolean;
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
    marketplaceSettings: MarketplaceSettings;
    policies: { return: PolicyOption[]; payment: PolicyOption[]; fulfillment: PolicyOption[] };
    categoryMapping: CategoryMapping;
    calculatedPrice: number | null;
    warehouses: WarehouseOption[];
    ebayItemSpecifics: EbayItemSpecificsData | null;
    shopifyMetafields: ShopifyMetafieldsData | null;
}

const props = defineProps<Props>();

const isEbay = computed(() => props.marketplace.platform === 'ebay');
const isShopify = computed(() => props.marketplace.platform === 'shopify');

// Platform icons
const platformIcons: Record<string, string> = {
    shopify: '/images/platforms/shopify.svg',
    ebay: '/images/platforms/ebay.svg',
    amazon: '/images/platforms/amazon.svg',
    etsy: '/images/platforms/etsy.svg',
    walmart: '/images/platforms/walmart.svg',
};

const selectClass = 'mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

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
    attributes: props.override?.attributes || {},
    attribute_overrides: props.override?.attribute_overrides || {},
    platform_settings: props.override?.platform_settings || {},
});

const saving = ref(false);
const publishing = ref(false);
const syncing = ref(false);
const showJsonPreview = ref(false);
const localPreview = ref(props.preview);
const aiLoading = ref<string | null>(null);
const publishError = ref<string | null>(null);
const publishErrors = ref<string[]>([]);
const successMessage = ref<string | null>(null);
const showPrimaryCategoryBrowser = ref(false);
const showSecondaryCategoryBrowser = ref(false);
const primaryCategoryName = ref<string | null>(props.categoryMapping?.primary_category_name ?? null);
const secondaryCategoryName = ref<string | null>(props.categoryMapping?.secondary_category_name ?? null);
const showLocationModal = ref(false);
const selectedWarehouseForLocation = ref<WarehouseOption | null>(null);
const ebayLocations = ref<Array<{ merchantLocationKey: string; name: string; merchantLocationStatus: string }>>([]);
const locationsLoading = ref(false);
const locationError = ref<string | null>(null);
const togglingShouldList = ref(false);
const aiIncludeTitle = ref(true);
const aiIncludeDescription = ref(true);
const ebayAiSuggestions = ref<Record<string, string> | null>(null);
const shopifyAiSuggestions = ref<Record<string, string> | null>(null);
const dynamicItemSpecifics = ref<EbayItemSpecificsData | null>(null);
const loadingItemSpecifics = ref(false);

// Computed
const isPublished = computed(() => ['listed', 'active'].includes(props.listing?.status ?? ''));
const isEnded = computed(() => ['ended', 'unlisted'].includes(props.listing?.status ?? ''));
const isExcluded = computed(() => props.listing?.should_list === false);
const effectiveItemSpecifics = computed(() => dynamicItemSpecifics.value ?? props.ebayItemSpecifics);
const hasItemSpecifics = computed(() => {
    const data = effectiveItemSpecifics.value;
    return data && (data.category_mapping_id || (data.specifics && data.specifics.length > 0));
});

const effectiveTitle = computed(() => form.value.title || props.preview.listing.title);
const effectiveDescription = computed(() => form.value.description || props.preview.listing.description);
const effectivePrice = computed(() => form.value.price ?? props.preview.listing.price);
const effectiveQuantity = computed(() => {
    const inventory = props.listing?.inventory_quantity ?? props.preview.listing.quantity ?? 0;
    if (form.value.quantity !== null) {
        return Math.min(form.value.quantity, inventory);
    }
    return inventory;
});

// eBay setting helpers
function getSettingValue(key: string): unknown {
    const listingVal = form.value.platform_settings[key];
    if (listingVal !== undefined && listingVal !== null) {
        return listingVal;
    }
    return props.marketplaceSettings?.[key as keyof MarketplaceSettings] ?? null;
}

function isSettingOverridden(key: string): boolean {
    return form.value.platform_settings[key] !== undefined && form.value.platform_settings[key] !== null;
}

function setSetting(key: string, value: unknown) {
    form.value.platform_settings = { ...form.value.platform_settings, [key]: value };
}

function clearSetting(key: string) {
    const copy = { ...form.value.platform_settings };
    delete copy[key];
    form.value.platform_settings = copy;
}

const effectiveListingType = computed(() => (getSettingValue('listing_type') as string) || 'FIXED_PRICE');

const effectiveMarkupKey = computed(() =>
    effectiveListingType.value === 'AUCTION' ? 'auction_markup' : 'fixed_price_markup',
);

const effectiveMarkup = computed(() => {
    const val = getSettingValue(effectiveMarkupKey.value);
    return val !== null && val !== undefined ? Number(val) : 0;
});

const computedEbayPrice = computed(() => {
    const base = effectivePrice.value ?? 0;
    if (base <= 0 || effectiveMarkup.value <= 0) return base;
    return Math.round(base * (1 + effectiveMarkup.value / 100) * 100) / 100;
});

// Condition options for eBay
const conditionOptions = [
    { value: '1000', label: 'New with tags' },
    { value: '1500', label: 'New without tags' },
    { value: '1750', label: 'New with defects' },
    { value: '2000', label: 'Certified Refurbished' },
    { value: '2500', label: 'Seller Refurbished' },
    { value: '2750', label: 'Like New' },
    { value: '3000', label: 'Pre-owned' },
    { value: '4000', label: 'Good' },
    { value: '5000', label: 'Acceptable' },
    { value: '7000', label: 'For parts or not working' },
];

const auctionDurationOptions = [
    { value: 'DAYS_1', label: '1 Day' },
    { value: 'DAYS_3', label: '3 Days' },
    { value: 'DAYS_5', label: '5 Days' },
    { value: 'DAYS_7', label: '7 Days' },
    { value: 'DAYS_10', label: '10 Days' },
];

const fixedDurationOptions = [
    { value: 'GTC', label: 'Good \'Til Cancelled' },
    { value: 'DAYS_30', label: '30 Days' },
];

const durationOptions = computed(() =>
    effectiveListingType.value === 'AUCTION' ? auctionDurationOptions : fixedDurationOptions,
);

const durationKey = computed(() =>
    effectiveListingType.value === 'AUCTION' ? 'listing_duration_auction' : 'listing_duration_fixed',
);

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

function getFieldDisplayValue(field: TemplateField): string {
    if (!field.value) return '-';
    if (field.options && field.options.length > 0) {
        const option = field.options.find(opt => opt.value === field.value);
        if (option) return option.label;
    }
    return formatSlugValue(field.value);
}

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
            form.value,
        );
        localPreview.value = response.data.preview;
        successMessage.value = 'Changes saved successfully.';
    } catch (error) {
        console.error('Failed to save:', error);
    } finally {
        saving.value = false;
    }
}

async function toggleShouldList() {
    togglingShouldList.value = true;
    try {
        await axios.post(`/products/${props.product.id}/listings/${props.marketplace.id}/toggle-should-list`);
        router.reload();
    } catch (error) {
        console.error('Toggle should_list failed:', error);
    } finally {
        togglingShouldList.value = false;
    }
}

async function publish() {
    publishing.value = true;
    publishError.value = null;
    publishErrors.value = [];
    successMessage.value = null;
    try {
        await save();
        const response = await axios.post(`/products/${props.product.id}/platforms/${props.marketplace.id}/publish`);
        if (response.data.success) {
            successMessage.value = `Product listed on ${props.marketplace.platform_label} successfully.`;
            router.reload();
        } else {
            publishError.value = response.data.message || 'Failed to publish';
            publishErrors.value = response.data.errors || [];
        }
    } catch (error: unknown) {
        if (axios.isAxiosError(error) && error.response?.data) {
            const data = error.response.data;
            publishError.value = data.message || 'Failed to publish';
            publishErrors.value = data.errors || [];
        } else {
            publishError.value = 'An unexpected error occurred while publishing.';
        }
    } finally {
        publishing.value = false;
    }
}

async function sync() {
    syncing.value = true;
    successMessage.value = null;
    try {
        await axios.post(`/products/${props.product.id}/platforms/${props.marketplace.id}/sync`);
        successMessage.value = 'Listing synced successfully.';
        router.reload();
    } catch (error) {
        console.error('Failed to sync:', error);
    } finally {
        syncing.value = false;
    }
}

async function unpublish() {
    if (!confirm('Are you sure you want to unlist this product from the platform?')) {
        return;
    }
    successMessage.value = null;
    try {
        await axios.delete(`/products/${props.product.id}/platforms/${props.marketplace.id}`);
        successMessage.value = 'Product unlisted successfully.';
        router.reload();
    } catch (error) {
        console.error('Failed to unlist:', error);
    }
}

const relisting = ref(false);

async function relist() {
    relisting.value = true;
    successMessage.value = null;
    try {
        await axios.post(`/products/${props.product.id}/platforms/${props.marketplace.id}/relist`);
        successMessage.value = `Product relisted on ${props.marketplace.platform_label} successfully.`;
        router.reload();
    } catch (error: any) {
        publishError.value = error.response?.data?.message || 'Failed to relist';
    } finally {
        relisting.value = false;
    }
}

interface PlatformCategory {
    id: number;
    name: string;
    ebay_category_id?: string;
    path?: string | null;
}

async function selectPrimaryCategory(category: PlatformCategory) {
    const catId = category.ebay_category_id || String(category.id);
    setSetting('primary_category_id', catId);
    primaryCategoryName.value = category.path || category.name;
    showPrimaryCategoryBrowser.value = false;

    // Fetch item specifics for the newly selected category
    await fetchItemSpecificsForCategory(catId);
}

async function fetchItemSpecificsForCategory(categoryId: string) {
    loadingItemSpecifics.value = true;
    dynamicItemSpecifics.value = null;

    try {
        const response = await axios.post(
            `/products/${props.product.id}/platforms/${props.marketplace.id}/item-specifics`,
            { category_id: categoryId },
        );

        if (response.data.success) {
            dynamicItemSpecifics.value = {
                specifics: response.data.specifics,
                category_mapping_id: response.data.category_mapping_id,
                category_id: response.data.category_id,
                synced_at: response.data.synced_at,
                needs_sync: response.data.needs_sync,
            };
        }
    } catch (error) {
        console.error('Failed to fetch item specifics:', error);
    } finally {
        loadingItemSpecifics.value = false;
    }
}

function selectSecondaryCategory(category: PlatformCategory) {
    const catId = category.ebay_category_id || String(category.id);
    setSetting('secondary_category_id', catId);
    secondaryCategoryName.value = category.path || category.name;
    showSecondaryCategoryBrowser.value = false;
}

function clearPrimaryCategory() {
    clearSetting('primary_category_id');
    primaryCategoryName.value = props.categoryMapping?.primary_category_name ?? null;
}

function clearSecondaryCategory() {
    clearSetting('secondary_category_id');
    secondaryCategoryName.value = props.categoryMapping?.secondary_category_name ?? null;
}

// eBay Item Specifics handlers
async function handleFieldMappingChanged(mappings: Record<string, string>) {
    const data = effectiveItemSpecifics.value;
    if (!data?.category_mapping_id || !data?.category_id) return;
    try {
        await axios.put(
            `/categories/${data.category_id}/platform-mappings/${data.category_mapping_id}`,
            { field_mappings: mappings },
        );
    } catch (error) {
        console.error('Failed to save field mappings:', error);
    }
}

function handleValueOverridesChanged(overrides: Record<string, string>) {
    // Store listing-level attribute overrides directly on the form's attributes field
    const attrs: Record<string, string> = {};
    for (const [key, value] of Object.entries(overrides)) {
        if (value !== '') {
            attrs[key] = value;
        }
    }
    form.value.attributes = attrs;
}

async function handleSyncItemSpecifics() {
    const data = effectiveItemSpecifics.value;

    // For listing-level override (no category mapping), re-fetch via the new endpoint
    if (!data?.category_mapping_id) {
        const categoryId = form.value.platform_settings?.primary_category_id as string | undefined;
        if (categoryId) {
            await fetchItemSpecificsForCategory(categoryId);
        }
        return;
    }

    if (!data?.category_id) return;
    try {
        await axios.post(
            `/categories/${data.category_id}/platform-mappings/${data.category_mapping_id}/sync-specifics`,
        );
        router.reload();
    } catch (error) {
        console.error('Failed to sync item specifics:', error);
    }
}

// Shopify Metafield handlers
function handleShopifyFieldMappingChanged(mappings: Record<string, string>) {
    // Store field mappings in metafield_overrides
    const currentOverrides = form.value.metafield_overrides || {};
    form.value.metafield_overrides = { ...currentOverrides, field_mappings: mappings };
}

function handleShopifyValueOverridesChanged(overrides: Record<string, string>) {
    // Store listing-level metafield overrides in the attributes field
    const attrs: Record<string, string> = {};
    for (const [key, value] of Object.entries(overrides)) {
        if (value !== '') {
            attrs[key] = value;
        }
    }
    form.value.attributes = { ...form.value.attributes, ...attrs };
}

async function fetchEbayLocations() {
    locationsLoading.value = true;
    locationError.value = null;
    try {
        const response = await axios.get(`/settings/marketplaces/${props.marketplace.id}/ebay/locations`);
        ebayLocations.value = response.data ?? [];
    } catch {
        locationError.value = 'Failed to fetch locations from eBay';
    } finally {
        locationsLoading.value = false;
    }
}

async function createEbayLocation(data: Record<string, unknown>) {
    try {
        await axios.post(`/settings/marketplaces/${props.marketplace.id}/ebay/locations`, data);
        showLocationModal.value = false;
        setSetting('location_key', data.location_key as string);
        await fetchEbayLocations();
    } catch {
        locationError.value = 'Failed to create location on eBay';
    }
}

function prefillLocationFromWarehouse(warehouse: WarehouseOption) {
    // Will be used by the modal via editData
    return {
        name: warehouse.name,
        location_key: (warehouse.code || warehouse.name).toLowerCase().replace(/[^a-z0-9_-]/g, '-'),
        location: {
            address: {
                addressLine1: warehouse.address_line1 || '',
                city: warehouse.city || '',
                stateOrProvince: warehouse.state || '',
                postalCode: warehouse.postal_code || '',
                country: warehouse.country || 'US',
            },
        },
    };
}

// Fetch eBay locations on mount if eBay
if (props.marketplace.platform === 'ebay') {
    fetchEbayLocations();
}

async function aiSuggest(type: 'auto_fill' | 'title' | 'description' | 'ebay_listing' | 'shopify_metafields') {
    aiLoading.value = type;
    try {
        const payload: Record<string, unknown> = { type };
        if (type === 'ebay_listing') {
            payload.include_title = aiIncludeTitle.value;
            payload.include_description = aiIncludeDescription.value;
        }

        const response = await axios.post(
            `/products/${props.product.id}/platforms/${props.marketplace.id}/ai-suggest`,
            payload,
        );
        const data = response.data;

        if (!data.success) return;

        if (type === 'title' && data.title) {
            form.value.title = data.title;
        } else if (type === 'description' && data.description) {
            form.value.description = data.description;
        } else if (type === 'auto_fill' && data.suggestions) {
            const s = data.suggestions;
            if (s.condition) {
                setSetting('default_condition', s.condition);
            }
            if (s.listing_type) {
                setSetting('listing_type', s.listing_type);
            }
            if (s.category_id) {
                form.value.platform_settings = {
                    ...form.value.platform_settings,
                    primary_category_id: s.category_id,
                };
            }
        } else if (type === 'ebay_listing' && data.suggestions) {
            const s = data.suggestions;
            if (s.title) {
                form.value.title = s.title;
            }
            if (s.description) {
                form.value.description = s.description;
            }
            if (s.item_specifics) {
                ebayAiSuggestions.value = s.item_specifics;
            }
        } else if (type === 'shopify_metafields' && data.suggestions) {
            shopifyAiSuggestions.value = data.suggestions;
        }
    } catch (error) {
        console.error('AI suggest failed:', error);
    } finally {
        aiLoading.value = null;
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
                        <div class="flex h-10 w-10 items-center justify-center rounded bg-gray-100 dark:bg-gray-700">
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
                    <Badge v-if="isExcluded" variant="destructive" class="flex items-center gap-1">
                        Excluded
                    </Badge>
                    <Badge v-else-if="isPublished" variant="success" class="flex items-center gap-1">
                        <CheckCircleIcon class="h-3.5 w-3.5" />
                        Listed
                    </Badge>
                    <Badge v-else-if="isEnded" variant="secondary" class="flex items-center gap-1">
                        Ended
                    </Badge>
                    <Badge v-else variant="secondary">
                        Draft
                    </Badge>

                    <!-- Listing URL -->
                    <a
                        v-if="listing?.listing_url"
                        :href="listing.listing_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                    >
                        <ArrowTopRightOnSquareIcon class="h-4 w-4" />
                        View Listing
                    </a>

                    <!-- Actions -->
                    <Button variant="outline" @click="save" :disabled="saving">
                        {{ saving ? 'Saving...' : 'Save' }}
                    </Button>

                    <Button
                        variant="outline"
                        @click="toggleShouldList"
                        :disabled="togglingShouldList"
                    >
                        {{ isExcluded ? 'Include on Platform' : 'Exclude from Platform' }}
                    </Button>

                    <template v-if="!isExcluded && isPublished">
                        <Button variant="outline" @click="sync" :disabled="syncing">
                            <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': syncing }" />
                            Sync
                        </Button>
                        <Button variant="destructive" @click="unpublish">
                            Unlist
                        </Button>
                    </template>
                    <template v-else-if="!isExcluded && isEnded">
                        <Button @click="relist" :disabled="relisting">
                            {{ relisting ? 'Relisting...' : `Relist on ${marketplace.platform_label}` }}
                        </Button>
                        <Button variant="outline" @click="publish" :disabled="publishing || !preview.validation.valid">
                            {{ publishing ? 'Publishing...' : 'Publish as New' }}
                        </Button>
                    </template>
                    <Button v-else-if="!isExcluded" @click="publish" :disabled="publishing || !preview.validation.valid">
                        {{ publishing ? 'Publishing...' : `Publish to ${marketplace.platform_label}` }}
                    </Button>
                </div>
            </div>

            <!-- Excluded Warning -->
            <div v-if="isExcluded" class="mb-6 rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20">
                <div class="flex items-start gap-3">
                    <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0 text-yellow-500" />
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-300">Product Excluded</h3>
                        <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-400">
                            This product is excluded from {{ marketplace.platform_label }}. Click "Include on Platform" to enable publishing.
                            You can still save draft overrides while excluded.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Success Notification -->
            <div v-if="successMessage" class="mb-6 rounded-lg bg-green-50 p-4 dark:bg-green-900/20">
                <div class="flex items-start gap-3">
                    <CheckCircleIcon class="mt-0.5 h-5 w-5 shrink-0 text-green-500" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-green-800 dark:text-green-300">{{ successMessage }}</p>
                        <button
                            @click="successMessage = null"
                            class="mt-1 text-xs font-medium text-green-600 underline hover:text-green-500 dark:text-green-400"
                        >Dismiss</button>
                    </div>
                </div>
            </div>

            <!-- Publish Error -->
            <div v-if="publishError" class="mb-6 rounded-lg bg-red-50 p-4 dark:bg-red-900/20">
                <div class="flex items-start gap-3">
                    <XCircleIcon class="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-medium text-red-800 dark:text-red-300">Publish Failed</h3>
                        <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ publishError }}</p>
                        <ul v-if="publishErrors.length > 0" class="mt-2 list-inside list-disc space-y-1 text-sm text-red-700 dark:text-red-400">
                            <li v-for="err in publishErrors" :key="err">{{ err }}</li>
                        </ul>
                        <button
                            @click="publishError = null; publishErrors = []"
                            class="mt-2 text-xs font-medium text-red-600 underline hover:text-red-500 dark:text-red-400"
                        >Dismiss</button>
                    </div>
                </div>
            </div>

            <!-- Validation Status -->
            <div v-if="!preview.validation.valid || preview.validation.warnings.length > 0" class="mb-6 space-y-3">
                <div v-if="preview.validation.errors.length > 0" class="rounded-lg bg-red-50 p-4 dark:bg-red-900/20">
                    <div class="flex items-start gap-3">
                        <XCircleIcon class="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
                        <div>
                            <h3 class="text-sm font-medium text-red-800 dark:text-red-300">Cannot Publish</h3>
                            <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-red-700 dark:text-red-400">
                                <li v-for="error in preview.validation.errors" :key="error">{{ error }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-if="preview.validation.warnings.length > 0" class="rounded-lg bg-yellow-50 p-4 dark:bg-yellow-900/20">
                    <div class="flex items-start gap-3">
                        <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0 text-yellow-500" />
                        <div>
                            <h3 class="text-sm font-medium text-yellow-800 dark:text-yellow-300">Warnings</h3>
                            <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-yellow-700 dark:text-yellow-400">
                                <li v-for="warning in preview.validation.warnings" :key="warning">{{ warning }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <Tabs default-value="listing" class="space-y-6">
                <TabsList>
                    <TabsTrigger value="listing">Listing</TabsTrigger>
                    <TabsTrigger value="images">Images</TabsTrigger>
                    <TabsTrigger v-if="isEbay" value="settings">Settings</TabsTrigger>
                    <TabsTrigger value="preview">Preview</TabsTrigger>
                </TabsList>

                <!-- Unified eBay Listing Tab -->
                <TabsContent v-if="isEbay" value="listing" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">eBay Listing</h2>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="aiSuggest('ebay_listing')"
                                :disabled="aiLoading !== null"
                            >
                                <SparklesIcon class="mr-1 h-4 w-4" />
                                {{ aiLoading === 'ebay_listing' ? 'Generating...' : 'AI Fill' }}
                            </Button>
                        </div>

                        <div class="space-y-4">
                            <!-- Title with AI checkbox -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            :model-value="aiIncludeTitle"
                                            @update:model-value="aiIncludeTitle = $event as boolean"
                                        />
                                        <Label for="ebay-title">Title</Label>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Badge v-if="form.title && form.title !== preview.listing.title" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited from product
                                        </span>
                                    </div>
                                </div>
                                <Input
                                    id="ebay-title"
                                    :model-value="form.title || preview.listing.title"
                                    @update:model-value="form.title = $event !== preview.listing.title ? $event : ''"
                                />
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ (form.title || preview.listing.title || '').length }} / 80 characters
                                </p>
                            </div>

                            <!-- Description with AI checkbox -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <Checkbox
                                            :model-value="aiIncludeDescription"
                                            @update:model-value="aiIncludeDescription = $event as boolean"
                                        />
                                        <Label for="ebay-description">Description</Label>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Badge v-if="form.description && form.description !== preview.listing.description" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else-if="preview.listing.description" class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited from product
                                        </span>
                                    </div>
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
                                        <Label for="ebay-price">Price</Label>
                                        <Badge v-if="form.price !== null && form.price !== preview.listing.price" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited
                                        </span>
                                    </div>
                                    <Input
                                        id="ebay-price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        :model-value="form.price ?? preview.listing.price ?? 0"
                                        @update:model-value="form.price = Number($event) !== preview.listing.price ? Number($event) : null"
                                    />
                                </div>
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="ebay-compare">Compare at Price</Label>
                                        <Badge v-if="form.compare_at_price !== null && form.compare_at_price !== preview.listing.compare_at_price" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else-if="preview.listing.compare_at_price" class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited
                                        </span>
                                    </div>
                                    <Input
                                        id="ebay-compare"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        :model-value="form.compare_at_price ?? preview.listing.compare_at_price ?? ''"
                                        @update:model-value="form.compare_at_price = $event !== '' && Number($event) !== preview.listing.compare_at_price ? Number($event) : null"
                                    />
                                </div>
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="ebay-quantity">Quantity Cap</Label>
                                        <Badge v-if="form.quantity !== null" variant="outline" class="text-xs">
                                            Capped
                                        </Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                            Using inventory ({{ listing?.inventory_quantity ?? preview.listing.quantity ?? 0 }})
                                        </span>
                                    </div>
                                    <Input
                                        id="ebay-quantity"
                                        type="number"
                                        min="0"
                                        v-model.number="form.quantity"
                                        :placeholder="`Inventory: ${listing?.inventory_quantity ?? preview.listing.quantity ?? 0}`"
                                    />
                                    <p v-if="form.quantity !== null" class="text-xs text-gray-500 dark:text-gray-400">
                                        Effective: {{ Math.min(form.quantity, listing?.inventory_quantity ?? preview.listing.quantity ?? 0) }}
                                    </p>
                                </div>
                            </div>

                            <!-- Weight -->
                            <div class="grid grid-cols-3 gap-4">
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="ebay-weight">Package Weight</Label>
                                        <Badge v-if="isSettingOverridden('weight')" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else-if="product.weight" class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited ({{ product.weight }} {{ product.weight_unit }})
                                        </span>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                            Not set
                                        </span>
                                    </div>
                                    <Input
                                        id="ebay-weight"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        :model-value="(getSettingValue('weight') as number) ?? product.weight ?? ''"
                                        @update:model-value="$event !== '' && Number($event) !== product.weight ? setSetting('weight', Number($event)) : clearSetting('weight')"
                                        placeholder="e.g. 1.5"
                                    />
                                </div>
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between">
                                        <Label for="ebay-weight-unit">Weight Unit</Label>
                                        <Badge v-if="isSettingOverridden('weight_unit')" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited
                                        </span>
                                    </div>
                                    <select
                                        id="ebay-weight-unit"
                                        :value="(getSettingValue('weight_unit') as string) || product.weight_unit || 'lb'"
                                        @change="setSetting('weight_unit', ($event.target as HTMLSelectElement).value)"
                                        :class="selectClass"
                                    >
                                        <option value="lb">Pounds (lb)</option>
                                        <option value="oz">Ounces (oz)</option>
                                        <option value="kg">Kilograms (kg)</option>
                                        <option value="g">Grams (g)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item Specifics section -->
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Item Specifics</h2>
                        <template v-if="loadingItemSpecifics">
                            <div class="space-y-3 py-4">
                                <div v-for="i in 5" :key="i" class="flex items-center gap-4">
                                    <div class="h-4 w-32 animate-pulse rounded bg-gray-200 dark:bg-gray-700"></div>
                                    <div class="h-9 flex-1 animate-pulse rounded bg-gray-200 dark:bg-gray-700"></div>
                                </div>
                            </div>
                        </template>
                        <template v-else-if="hasItemSpecifics">
                            <EbayItemSpecificsEditor
                                :data="effectiveItemSpecifics!"
                                :template-fields="templateFields"
                                :ai-suggestions="ebayAiSuggestions"
                                :ai-loading="aiLoading === 'ebay_listing'"
                                @field-mapping-changed="handleFieldMappingChanged"
                                @value-overrides-changed="handleValueOverridesChanged"
                                @sync-requested="handleSyncItemSpecifics"
                            />
                        </template>
                        <p v-else class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            Select an eBay category first to configure item specifics.
                        </p>
                    </div>
                </TabsContent>

                <!-- Unified Listing Tab (non-eBay) -->
                <TabsContent v-if="!isEbay" value="listing" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Listing</h2>

                        <div class="space-y-4">
                            <!-- Title -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label for="title">Title</Label>
                                    <div class="flex items-center gap-2">
                                        <Badge v-if="form.title && form.title !== preview.listing.title" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited from product
                                        </span>
                                    </div>
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
                                    <div class="flex items-center gap-2">
                                        <Badge v-if="form.description && form.description !== preview.listing.description" variant="outline" class="text-xs">
                                            Overridden
                                        </Badge>
                                        <span v-else-if="preview.listing.description" class="text-xs text-gray-500 dark:text-gray-400">
                                            Inherited from product
                                        </span>
                                    </div>
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
                                        <Label for="quantity">Quantity Cap</Label>
                                        <Badge v-if="form.quantity !== null" variant="outline" class="text-xs">
                                            Capped
                                        </Badge>
                                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                                            Using inventory ({{ listing?.inventory_quantity ?? preview.listing.quantity ?? 0 }})
                                        </span>
                                    </div>
                                    <Input
                                        id="quantity"
                                        type="number"
                                        min="0"
                                        v-model.number="form.quantity"
                                        :placeholder="`Inventory: ${listing?.inventory_quantity ?? preview.listing.quantity ?? 0}`"
                                    />
                                    <p v-if="form.quantity !== null" class="text-xs text-gray-500 dark:text-gray-400">
                                        Effective: {{ Math.min(form.quantity, listing?.inventory_quantity ?? preview.listing.quantity ?? 0) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Shopify Metafields -->
                    <div v-if="isShopify && shopifyMetafields" class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Metafields</h2>
                            <Button
                                v-if="shopifyMetafields.has_definitions"
                                variant="outline"
                                size="sm"
                                @click="aiSuggest('shopify_metafields')"
                                :disabled="aiLoading !== null"
                            >
                                <SparklesIcon class="mr-1 h-4 w-4" />
                                {{ aiLoading === 'shopify_metafields' ? 'Suggesting...' : 'AI Fill' }}
                            </Button>
                        </div>
                        <ShopifyMetafieldEditor
                            :data="shopifyMetafields"
                            :template-fields="templateFields"
                            :ai-suggestions="shopifyAiSuggestions"
                            :ai-loading="aiLoading === 'shopify_metafields'"
                            @field-mapping-changed="handleShopifyFieldMappingChanged"
                            @value-overrides-changed="handleShopifyValueOverridesChanged"
                        />
                    </div>

                    <!-- Attributes / Metafields (non-Shopify) -->
                    <div v-if="!isShopify && templateFields.length > 0" class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Attributes</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Sent as metafields to {{ marketplace.platform_label }}
                            </p>
                        </div>

                        <div class="space-y-3">
                            <div
                                v-for="field in fieldsWithOverrides"
                                :key="field.id"
                                class="flex items-center gap-4 border-b border-gray-100 py-3 last:border-0 dark:border-gray-700"
                                :class="{ 'opacity-50': field.is_private }"
                            >
                                <Checkbox
                                    v-if="!field.is_private"
                                    :model-value="field.enabled"
                                    @update:model-value="toggleField(field.name)"
                                />
                                <LockClosedIcon v-else class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />

                                <div class="min-w-0 flex-1">
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
                                        {{ field.name }}
                                    </p>
                                </div>

                                <div class="w-40 text-right">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ getFieldDisplayValue(field) }}
                                    </span>
                                </div>

                                <div class="w-48">
                                    <Input
                                        v-if="!field.is_private"
                                        v-model="field.overrideValue"
                                        :placeholder="field.value || 'Override value'"
                                        :disabled="!field.enabled"
                                        class="text-sm"
                                        @update:model-value="updateFieldOverride(field.name, $event)"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Metafields -->
                    <div v-if="supportsMetafields" class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Custom Metafields</h2>
                            <Button variant="outline" size="sm" @click="addCustomMetafield">
                                <PlusIcon class="mr-1 h-4 w-4" />
                                Add Custom
                            </Button>
                        </div>

                        <div v-if="form.custom_metafields.length > 0" class="space-y-3">
                            <div
                                v-for="(metafield, index) in form.custom_metafields"
                                :key="index"
                                class="flex items-center gap-4 border-b border-gray-100 py-3 last:border-0 dark:border-gray-700"
                            >
                                <div class="min-w-0 flex-1">
                                    <Input
                                        v-model="form.custom_metafields[index].key"
                                        placeholder="Key"
                                        class="text-sm"
                                    />
                                </div>
                                <div class="w-64">
                                    <Input
                                        v-model="form.custom_metafields[index].value"
                                        placeholder="Value"
                                        class="text-sm"
                                    />
                                </div>
                                <Button variant="ghost" size="sm" @click="removeCustomMetafield(index)">
                                    <TrashIcon class="h-4 w-4 text-red-500" />
                                </Button>
                            </div>
                        </div>

                        <p v-else class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            No custom metafields. Click "Add Custom" to add one.
                        </p>
                    </div>
                </TabsContent>

                <!-- Images Tab -->
                <TabsContent value="images" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Images</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ imagesWithState.filter(i => i.included).length }} of {{ images.length }} selected
                            </p>
                        </div>

                        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                            Select which images to include when publishing to {{ marketplace.platform_label }}.
                            Uncheck images you want to exclude from this platform.
                        </p>

                        <div v-if="images.length > 0" class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                            <div
                                v-for="image in imagesWithState"
                                :key="image.id"
                                class="group relative"
                            >
                                <div
                                    class="aspect-square cursor-pointer overflow-hidden rounded-lg border-2 transition-all"
                                    :class="image.included
                                        ? 'border-indigo-500 ring-2 ring-indigo-500/20'
                                        : 'border-gray-200 opacity-50 dark:border-gray-700'"
                                    @click="toggleImage(image.id)"
                                >
                                    <img
                                        :src="image.url"
                                        :alt="image.alt || 'Product image'"
                                        class="h-full w-full object-cover"
                                    />

                                    <div class="absolute inset-0 flex items-center justify-center bg-black/50 opacity-0 transition-opacity group-hover:opacity-100">
                                        <EyeIcon v-if="image.included" class="h-8 w-8 text-white" />
                                        <EyeSlashIcon v-else class="h-8 w-8 text-white" />
                                    </div>
                                </div>

                                <Badge v-if="image.is_primary" variant="secondary" class="absolute left-1 top-1 text-xs">
                                    Primary
                                </Badge>

                                <div class="absolute right-1 top-1">
                                    <Checkbox
                                        :model-value="image.included"
                                        @update:model-value="toggleImage(image.id)"
                                    />
                                </div>
                            </div>
                        </div>

                        <p v-else class="py-8 text-center text-gray-500 dark:text-gray-400">
                            No images available for this product.
                        </p>
                    </div>
                </TabsContent>

                <!-- Settings Tab (eBay) -->
                <TabsContent v-if="isEbay" value="settings" class="space-y-6">
                    <!-- Listing Configuration -->
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Listing Configuration</h2>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="aiSuggest('auto_fill')"
                                :disabled="aiLoading !== null"
                            >
                                <SparklesIcon class="mr-1 h-4 w-4" />
                                {{ aiLoading === 'auto_fill' ? 'Generating...' : 'Auto-fill with AI' }}
                            </Button>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <!-- Listing Type -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Listing Type</Label>
                                    <Badge v-if="isSettingOverridden('listing_type')" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Inherited</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select
                                        :value="getSettingValue('listing_type') || 'FIXED_PRICE'"
                                        @change="setSetting('listing_type', ($event.target as HTMLSelectElement).value)"
                                        :class="selectClass"
                                    >
                                        <option value="FIXED_PRICE">Fixed Price</option>
                                        <option value="AUCTION">Auction</option>
                                    </select>
                                    <button
                                        v-if="isSettingOverridden('listing_type')"
                                        @click="clearSetting('listing_type')"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>
                            </div>

                            <!-- Condition -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Condition</Label>
                                    <Badge v-if="isSettingOverridden('default_condition')" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Inherited</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select
                                        :value="getSettingValue('default_condition') || ''"
                                        @change="setSetting('default_condition', ($event.target as HTMLSelectElement).value)"
                                        :class="selectClass"
                                    >
                                        <option value="">Not set</option>
                                        <option v-for="opt in conditionOptions" :key="opt.value" :value="opt.value">
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                    <button
                                        v-if="isSettingOverridden('default_condition')"
                                        @click="clearSetting('default_condition')"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>
                            </div>

                            <!-- Listing Duration -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Listing Duration</Label>
                                    <Badge v-if="isSettingOverridden(durationKey)" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Inherited</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select
                                        :value="getSettingValue(durationKey) || ''"
                                        @change="setSetting(durationKey, ($event.target as HTMLSelectElement).value)"
                                        :class="selectClass"
                                    >
                                        <option value="">Default</option>
                                        <option v-for="opt in durationOptions" :key="opt.value" :value="opt.value">
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                    <button
                                        v-if="isSettingOverridden(durationKey)"
                                        @click="clearSetting(durationKey)"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>
                            </div>

                            <!-- Best Offer -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Best Offer</Label>
                                    <Badge v-if="isSettingOverridden('best_offer_enabled')" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Inherited</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="flex cursor-pointer items-center gap-3">
                                        <input
                                            type="checkbox"
                                            :checked="!!getSettingValue('best_offer_enabled')"
                                            @change="setSetting('best_offer_enabled', ($event.target as HTMLInputElement).checked)"
                                            class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-800"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Allow best offers</span>
                                    </label>
                                    <button
                                        v-if="isSettingOverridden('best_offer_enabled')"
                                        @click="clearSetting('best_offer_enabled')"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Pricing</h2>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="space-y-2">
                                <Label>Base Price</Label>
                                <p class="text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ formatPrice(effectivePrice) }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">From product / variant</p>
                            </div>

                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Markup %</Label>
                                    <Badge v-if="isSettingOverridden(effectiveMarkupKey)" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Inherited</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Input
                                        type="number"
                                        step="0.1"
                                        min="0"
                                        :model-value="getSettingValue(effectiveMarkupKey) ?? ''"
                                        @update:model-value="$event !== '' ? setSetting(effectiveMarkupKey, Number($event)) : clearSetting(effectiveMarkupKey)"
                                    />
                                    <button
                                        v-if="isSettingOverridden(effectiveMarkupKey)"
                                        @click="clearSetting(effectiveMarkupKey)"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label>Calculated eBay Price</Label>
                                <p class="text-lg font-semibold text-green-600 dark:text-green-400">
                                    {{ formatPrice(computedEbayPrice) }}
                                </p>
                                <p v-if="effectiveMarkup > 0" class="text-xs text-gray-500 dark:text-gray-400">
                                    Base &times; (1 + {{ effectiveMarkup }}%)
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Business Policies -->
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Business Policies</h2>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <!-- Return Policy -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Return Policy</Label>
                                    <Badge v-if="isSettingOverridden('return_policy_id')" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Inherited</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select
                                        :value="getSettingValue('return_policy_id') || ''"
                                        @change="setSetting('return_policy_id', ($event.target as HTMLSelectElement).value || null)"
                                        :class="selectClass"
                                    >
                                        <option value="">Not set</option>
                                        <option v-for="p in policies.return" :key="p.id" :value="p.id">
                                            {{ p.name }}
                                        </option>
                                    </select>
                                    <button
                                        v-if="isSettingOverridden('return_policy_id')"
                                        @click="clearSetting('return_policy_id')"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>
                            </div>

                            <!-- Payment Policy -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Payment Policy</Label>
                                    <Badge v-if="isSettingOverridden('payment_policy_id')" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Inherited</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select
                                        :value="getSettingValue('payment_policy_id') || ''"
                                        @change="setSetting('payment_policy_id', ($event.target as HTMLSelectElement).value || null)"
                                        :class="selectClass"
                                    >
                                        <option value="">Not set</option>
                                        <option v-for="p in policies.payment" :key="p.id" :value="p.id">
                                            {{ p.name }}
                                        </option>
                                    </select>
                                    <button
                                        v-if="isSettingOverridden('payment_policy_id')"
                                        @click="clearSetting('payment_policy_id')"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>
                            </div>

                            <!-- Fulfillment Policy -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Fulfillment Policy</Label>
                                    <Badge v-if="isSettingOverridden('fulfillment_policy_id')" variant="outline" class="text-xs">Overridden</Badge>
                                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Inherited</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select
                                        :value="getSettingValue('fulfillment_policy_id') || ''"
                                        @change="setSetting('fulfillment_policy_id', ($event.target as HTMLSelectElement).value || null)"
                                        :class="selectClass"
                                    >
                                        <option value="">Not set</option>
                                        <option v-for="p in policies.fulfillment" :key="p.id" :value="p.id">
                                            {{ p.name }}
                                        </option>
                                    </select>
                                    <button
                                        v-if="isSettingOverridden('fulfillment_policy_id')"
                                        @click="clearSetting('fulfillment_policy_id')"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>
                            </div>
                        </div>

                        <p v-if="!policies.return.length && !policies.payment.length && !policies.fulfillment.length" class="mt-3 text-xs text-yellow-600 dark:text-yellow-400">
                            No policies synced. Go to marketplace settings to sync your eBay business policies.
                        </p>
                    </div>

                    <!-- Category -->
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Category</h2>

                        <div class="space-y-4">
                            <!-- Primary Category -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Primary Category</Label>
                                    <div class="flex items-center gap-2">
                                        <Badge v-if="isSettingOverridden('primary_category_id')" variant="outline" class="text-xs">Overridden</Badge>
                                        <span v-else-if="categoryMapping?.primary_category_id" class="text-xs text-gray-500 dark:text-gray-400">From category mapping</span>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <div
                                        class="flex min-h-[38px] flex-1 cursor-pointer items-center rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm hover:bg-muted/50"
                                        @click="showPrimaryCategoryBrowser = !showPrimaryCategoryBrowser"
                                    >
                                        <span v-if="primaryCategoryName || (getSettingValue('primary_category_id') as string) || categoryMapping?.primary_category_id" class="text-foreground">
                                            {{ primaryCategoryName || categoryMapping?.primary_category_name || `Category #${(getSettingValue('primary_category_id') as string) || categoryMapping?.primary_category_id}` }}
                                        </span>
                                        <span v-else class="text-muted-foreground">Browse and select a category...</span>
                                    </div>
                                    <button
                                        v-if="isSettingOverridden('primary_category_id')"
                                        @click="clearPrimaryCategory"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>

                                <div v-if="showPrimaryCategoryBrowser" class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                                    <PlatformCategoryBrowser
                                        :platform="marketplace.platform"
                                        :selected-category-id="(getSettingValue('primary_category_id') as string) || categoryMapping?.primary_category_id || null"
                                        :category-name="product.category"
                                        @select="selectPrimaryCategory"
                                    />
                                </div>
                            </div>

                            <!-- Secondary Category -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <Label>Secondary Category (Optional)</Label>
                                    <div class="flex items-center gap-2">
                                        <Badge v-if="isSettingOverridden('secondary_category_id')" variant="outline" class="text-xs">Overridden</Badge>
                                        <span v-else-if="categoryMapping?.secondary_category_id" class="text-xs text-gray-500 dark:text-gray-400">From category mapping</span>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <div
                                        class="flex min-h-[38px] flex-1 cursor-pointer items-center rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm hover:bg-muted/50"
                                        @click="showSecondaryCategoryBrowser = !showSecondaryCategoryBrowser"
                                    >
                                        <span v-if="secondaryCategoryName || (getSettingValue('secondary_category_id') as string) || categoryMapping?.secondary_category_id" class="text-foreground">
                                            {{ secondaryCategoryName || categoryMapping?.secondary_category_name || `Category #${(getSettingValue('secondary_category_id') as string) || categoryMapping?.secondary_category_id}` }}
                                        </span>
                                        <span v-else class="text-muted-foreground">Browse and select a category...</span>
                                    </div>
                                    <button
                                        v-if="isSettingOverridden('secondary_category_id')"
                                        @click="clearSecondaryCategory"
                                        class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        title="Clear override"
                                    >&times;</button>
                                </div>

                                <div v-if="showSecondaryCategoryBrowser" class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                                    <PlatformCategoryBrowser
                                        :platform="marketplace.platform"
                                        :selected-category-id="(getSettingValue('secondary_category_id') as string) || categoryMapping?.secondary_category_id || null"
                                        :category-name="product.category"
                                        @select="selectSecondaryCategory"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Location -->
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Location</h2>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="showLocationModal = true"
                            >
                                <PlusIcon class="mr-1 h-4 w-4" />
                                Create Location
                            </Button>
                        </div>

                        <div class="max-w-md space-y-2">
                            <div class="flex items-center justify-between">
                                <Label>Merchant Location</Label>
                                <Badge v-if="isSettingOverridden('location_key')" variant="outline" class="text-xs">Overridden</Badge>
                                <span v-else class="text-xs text-gray-500 dark:text-gray-400">Inherited</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <select
                                    :value="(getSettingValue('location_key') as string) || ''"
                                    @change="($event.target as HTMLSelectElement).value ? setSetting('location_key', ($event.target as HTMLSelectElement).value) : clearSetting('location_key')"
                                    :class="selectClass"
                                >
                                    <option value="">{{ locationsLoading ? 'Loading locations...' : 'Select a location' }}</option>
                                    <option
                                        v-for="loc in ebayLocations"
                                        :key="loc.merchantLocationKey"
                                        :value="loc.merchantLocationKey"
                                    >
                                        {{ loc.name || loc.merchantLocationKey }}
                                        <template v-if="loc.merchantLocationStatus === 'DISABLED'"> (Disabled)</template>
                                    </option>
                                </select>
                                <button
                                    v-if="isSettingOverridden('location_key')"
                                    @click="clearSetting('location_key')"
                                    class="shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                    title="Clear override"
                                >&times;</button>
                            </div>

                            <p v-if="locationError" class="text-xs text-red-600 dark:text-red-400">{{ locationError }}</p>

                            <div v-if="ebayLocations.length === 0 && !locationsLoading" class="rounded-md bg-yellow-50 p-3 dark:bg-yellow-900/20">
                                <p class="text-xs text-yellow-700 dark:text-yellow-400">
                                    No eBay locations found. Create one to list products.
                                    <template v-if="warehouses.length > 0">
                                        You can use one of your warehouses as a starting point.
                                    </template>
                                </p>
                                <div v-if="warehouses.length > 0" class="mt-2 flex flex-wrap gap-2">
                                    <Button
                                        v-for="wh in warehouses"
                                        :key="wh.id"
                                        variant="outline"
                                        size="sm"
                                        class="text-xs"
                                        @click="selectedWarehouseForLocation = wh; showLocationModal = true"
                                    >
                                        Use "{{ wh.name }}"
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <EbayLocationFormModal
                            :open="showLocationModal"
                            :prefill-data="selectedWarehouseForLocation ? prefillLocationFromWarehouse(selectedWarehouseForLocation) : null"
                            @update:open="showLocationModal = $event; if (!$event) selectedWarehouseForLocation = null"
                            @save="createEbayLocation"
                        />
                    </div>
                </TabsContent>

                <!-- Preview Tab -->
                <TabsContent value="preview" class="space-y-6">
                    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                        <div class="mb-4 flex items-center justify-between">
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
                                <div v-if="isEbay">
                                    <dt class="text-gray-500 dark:text-gray-400">eBay Price (with markup)</dt>
                                    <dd class="mt-1 font-medium text-green-600 dark:text-green-400">{{ formatPrice(computedEbayPrice) }}</dd>
                                </div>
                                <div v-if="isEbay">
                                    <dt class="text-gray-500 dark:text-gray-400">Listing Type</dt>
                                    <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ effectiveListingType === 'AUCTION' ? 'Auction' : 'Fixed Price' }}</dd>
                                </div>
                            </dl>

                            <div v-if="Object.keys(preview.listing.attributes || {}).length > 0">
                                <h3 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Attributes</h3>
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

                            <div v-if="supportsMetafields && metafieldsWithState.filter(m => m.included).length > 0">
                                <h3 class="mb-2 text-sm font-medium text-gray-900 dark:text-white">Metafields</h3>
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
                            <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ JSON.stringify(localPreview.listing, null, 2) }}</pre>
                        </div>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
