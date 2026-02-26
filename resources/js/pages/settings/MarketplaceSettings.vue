<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { type BreadcrumbItem } from '@/types';
import {
    ArrowPathIcon,
    CheckCircleIcon,
} from '@heroicons/vue/20/solid';
import axios from 'axios';
import { EbayPoliciesSection } from '@/components/platforms';

interface Policy {
    [key: string]: unknown;
    name?: string;
    description?: string;
}

interface ReturnPolicy extends Policy {
    returnPolicyId: string;
}

interface PaymentPolicy extends Policy {
    paymentPolicyId: string;
}

interface FulfillmentPolicy extends Policy {
    fulfillmentPolicyId: string;
}

interface EbayLocation {
    merchantLocationKey: string;
    name?: string;
    location?: {
        address?: {
            city?: string;
            stateOrProvince?: string;
            country?: string;
        };
    };
    merchantLocationStatus?: string;
}

interface EtsyShippingProfile {
    shipping_profile_id: string;
    title: string;
    origin_country_iso?: string;
    processing_days_display_label?: string;
}

interface EtsyReturnPolicy {
    return_policy_id: string;
    accepts_returns: boolean;
    accepts_exchanges: boolean;
    return_deadline?: number | null;
}

interface LocationMapping {
    warehouse_id: number | null;
    location_key: string;
}

interface Warehouse {
    id: number;
    name: string;
    code: string | null;
    is_default: boolean;
}

interface Settings {
    // Common
    price_markup?: number;
    use_ai_details?: boolean;

    // eBay
    marketplace_id?: string;
    default_condition?: string;
    listing_type?: string;
    listing_duration_fixed?: string;
    listing_duration_auction?: string;
    return_policy_id?: string;
    payment_policy_id?: string;
    fulfillment_policy_id?: string;
    auction_markup?: number;
    fixed_price_markup?: number;
    best_offer_enabled?: boolean;
    location_key?: string;
    location_mappings?: LocationMapping[];

    // Amazon
    fulfillment_channel?: string;
    language_tag?: string;

    // Etsy
    currency?: string;
    who_made?: string;
    when_made?: string;
    is_supply?: boolean;
    shipping_profile_id?: string;
    auto_renew?: boolean;

    // Walmart
    product_id_type?: string;
    fulfillment_type?: string;
    shipping_method?: string;
    weight_unit?: string;

    // Shopify
    default_product_status?: string;
    inventory_tracking?: string;
}

interface Marketplace {
    id: number;
    platform: string;
    platform_label: string;
    name: string;
    status: string;
    settings: Settings;
}

interface Props {
    marketplace: Marketplace;
    warehouses: Warehouse[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Marketplaces', href: '/settings/marketplaces' },
    { title: props.marketplace.name, href: `/settings/marketplaces/${props.marketplace.id}/settings` },
];

const s = props.marketplace.settings;

// Form state
const form = ref<Settings>({
    // Common
    price_markup: s.price_markup ?? 0,
    use_ai_details: s.use_ai_details ?? false,

    // eBay
    marketplace_id: s.marketplace_id ?? 'EBAY_US',
    default_condition: s.default_condition ?? 'NEW',
    listing_type: s.listing_type ?? 'FIXED_PRICE',
    listing_duration_fixed: s.listing_duration_fixed ?? 'GTC',
    listing_duration_auction: s.listing_duration_auction ?? 'DAYS_7',
    return_policy_id: s.return_policy_id ?? '',
    payment_policy_id: s.payment_policy_id ?? '',
    fulfillment_policy_id: s.fulfillment_policy_id ?? '',
    auction_markup: s.auction_markup ?? 0,
    fixed_price_markup: s.fixed_price_markup ?? 0,
    best_offer_enabled: s.best_offer_enabled ?? false,
    location_key: s.location_key ?? '',
    location_mappings: s.location_mappings ?? [],

    // Amazon
    fulfillment_channel: s.fulfillment_channel ?? 'DEFAULT',
    language_tag: s.language_tag ?? 'en_US',

    // Etsy
    currency: s.currency ?? 'USD',
    who_made: s.who_made ?? 'i_did',
    when_made: s.when_made ?? 'made_to_order',
    is_supply: s.is_supply ?? false,
    shipping_profile_id: s.shipping_profile_id ?? '',
    auto_renew: s.auto_renew ?? false,

    // Walmart
    product_id_type: s.product_id_type ?? 'UPC',
    fulfillment_type: s.fulfillment_type ?? 'seller',
    shipping_method: s.shipping_method ?? 'STANDARD',
    weight_unit: s.weight_unit ?? 'LB',

    // Shopify
    default_product_status: s.default_product_status ?? 'active',
    inventory_tracking: s.inventory_tracking ?? 'shopify',
});

const errors = ref<Record<string, string>>({});
const saving = ref(false);
const recentlySaved = ref(false);

// Platform checks
const isEbay = computed(() => props.marketplace.platform === 'ebay');
const isAmazon = computed(() => props.marketplace.platform === 'amazon');
const isEtsy = computed(() => props.marketplace.platform === 'etsy');
const isWalmart = computed(() => props.marketplace.platform === 'walmart');
const isShopify = computed(() => props.marketplace.platform === 'shopify');

// eBay Policy state
const loadingPolicies = ref(false);
const returnPolicies = ref<ReturnPolicy[]>([]);
const paymentPolicies = ref<PaymentPolicy[]>([]);
const fulfillmentPolicies = ref<FulfillmentPolicy[]>([]);
const policiesLoaded = ref(false);
const policiesError = ref('');

// eBay Locations state
const loadingLocations = ref(false);
const ebayLocations = ref<EbayLocation[]>([]);
const locationsLoaded = ref(false);
const locationsError = ref('');

// Etsy Shipping Profiles state
const loadingShippingProfiles = ref(false);
const etsyShippingProfiles = ref<EtsyShippingProfile[]>([]);
const shippingProfilesLoaded = ref(false);
const shippingProfilesError = ref('');

// Etsy Return Policies state
const loadingEtsyReturnPolicies = ref(false);
const etsyReturnPolicies = ref<EtsyReturnPolicy[]>([]);
const etsyReturnPoliciesLoaded = ref(false);
const etsyReturnPoliciesError = ref('');

// eBay Options
const ebayMarketplaces = [
    { value: 'EBAY_US', label: 'eBay US' },
    { value: 'EBAY_GB', label: 'eBay UK' },
    { value: 'EBAY_DE', label: 'eBay Germany' },
    { value: 'EBAY_AU', label: 'eBay Australia' },
    { value: 'EBAY_CA', label: 'eBay Canada' },
    { value: 'EBAY_FR', label: 'eBay France' },
    { value: 'EBAY_IT', label: 'eBay Italy' },
    { value: 'EBAY_ES', label: 'eBay Spain' },
];

const conditions = [
    { value: 'NEW', label: 'New' },
    { value: 'LIKE_NEW', label: 'Like New' },
    { value: 'NEW_OTHER', label: 'New (Other)' },
    { value: 'NEW_WITH_DEFECTS', label: 'New with Defects' },
    { value: 'MANUFACTURER_REFURBISHED', label: 'Manufacturer Refurbished' },
    { value: 'CERTIFIED_REFURBISHED', label: 'Certified Refurbished' },
    { value: 'EXCELLENT_REFURBISHED', label: 'Excellent - Refurbished' },
    { value: 'VERY_GOOD_REFURBISHED', label: 'Very Good - Refurbished' },
    { value: 'GOOD_REFURBISHED', label: 'Good - Refurbished' },
    { value: 'SELLER_REFURBISHED', label: 'Seller Refurbished' },
    { value: 'USED_EXCELLENT', label: 'Used - Excellent' },
    { value: 'USED_VERY_GOOD', label: 'Used - Very Good' },
    { value: 'USED_GOOD', label: 'Used - Good' },
    { value: 'USED_ACCEPTABLE', label: 'Used - Acceptable' },
    { value: 'FOR_PARTS_OR_NOT_WORKING', label: 'For Parts or Not Working' },
];

const fixedPriceDurations = [
    { value: 'GTC', label: "Good 'Til Cancelled" },
    { value: 'DAYS_3', label: '3 Days' },
    { value: 'DAYS_5', label: '5 Days' },
    { value: 'DAYS_7', label: '7 Days' },
    { value: 'DAYS_10', label: '10 Days' },
    { value: 'DAYS_21', label: '21 Days' },
    { value: 'DAYS_30', label: '30 Days' },
];

const auctionDurations = [
    { value: 'DAYS_1', label: '1 Day' },
    { value: 'DAYS_3', label: '3 Days' },
    { value: 'DAYS_5', label: '5 Days' },
    { value: 'DAYS_7', label: '7 Days' },
    { value: 'DAYS_10', label: '10 Days' },
];

// Amazon Options
const amazonMarketplaces = [
    { value: 'ATVPDKIKX0DER', label: 'United States' },
    { value: 'A1F83G8C2ARO7P', label: 'United Kingdom' },
    { value: 'A1PA6795UKMFR9', label: 'Germany' },
    { value: 'A13V1IB3VIYZZH', label: 'France' },
    { value: 'APJ6JRA9NG5V4', label: 'Italy' },
    { value: 'A1RKKUPIHCS9HS', label: 'Spain' },
    { value: 'A2EUQ1WTGCTBG2', label: 'Canada' },
    { value: 'A39IBJ37TRP1C6', label: 'Australia' },
    { value: 'A1VC38T7YXB528', label: 'Japan' },
    { value: 'A21TJRUUN4KGV', label: 'India' },
];

const amazonFulfillmentChannels = [
    { value: 'DEFAULT', label: 'Merchant Fulfilled' },
    { value: 'AFN', label: 'Fulfillment by Amazon (FBA)' },
];

const amazonLanguageTags = [
    { value: 'en_US', label: 'English (US)' },
    { value: 'en_GB', label: 'English (UK)' },
    { value: 'de_DE', label: 'German' },
    { value: 'fr_FR', label: 'French' },
    { value: 'it_IT', label: 'Italian' },
    { value: 'es_ES', label: 'Spanish' },
    { value: 'ja_JP', label: 'Japanese' },
];

// Etsy Options
const etsyCurrencies = [
    { value: 'USD', label: 'USD - US Dollar' },
    { value: 'GBP', label: 'GBP - British Pound' },
    { value: 'EUR', label: 'EUR - Euro' },
    { value: 'CAD', label: 'CAD - Canadian Dollar' },
    { value: 'AUD', label: 'AUD - Australian Dollar' },
];

const etsyWhoMade = [
    { value: 'i_did', label: 'I did' },
    { value: 'someone_else', label: 'Someone else' },
    { value: 'collective', label: 'A member of my shop' },
];

const etsyWhenMade = [
    { value: 'made_to_order', label: 'Made to order' },
    { value: '2020_2026', label: '2020-2026' },
    { value: '2010_2019', label: '2010-2019' },
    { value: '2000_2009', label: '2000-2009' },
    { value: 'before_2000', label: 'Before 2000' },
    { value: '1990s', label: '1990s' },
    { value: '1980s', label: '1980s' },
    { value: '1970s', label: '1970s' },
    { value: '1960s', label: '1960s' },
];

// Walmart Options
const walmartProductIdTypes = [
    { value: 'UPC', label: 'UPC' },
    { value: 'GTIN', label: 'GTIN' },
    { value: 'EAN', label: 'EAN' },
    { value: 'ISBN', label: 'ISBN' },
];

const walmartFulfillmentTypes = [
    { value: 'seller', label: 'Seller Fulfilled' },
    { value: 'wfs', label: 'Walmart Fulfillment Services (WFS)' },
];

const walmartShippingMethods = [
    { value: 'STANDARD', label: 'Standard' },
    { value: 'EXPEDITED', label: 'Expedited' },
    { value: 'FREIGHT', label: 'Freight' },
    { value: 'VALUE', label: 'Value' },
];

const walmartWeightUnits = [
    { value: 'LB', label: 'Pounds (LB)' },
    { value: 'KG', label: 'Kilograms (KG)' },
    { value: 'OZ', label: 'Ounces (OZ)' },
];

// Shopify Options
const shopifyProductStatuses = [
    { value: 'active', label: 'Active' },
    { value: 'draft', label: 'Draft' },
];

const shopifyInventoryTracking = [
    { value: 'shopify', label: 'Shopify tracks inventory' },
    { value: 'not_managed', label: 'Not managed' },
];

const selectClass = 'mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

// eBay fetch functions
async function fetchPolicies() {
    loadingPolicies.value = true;
    policiesError.value = '';

    try {
        const response = await axios.post(`/settings/marketplaces/${props.marketplace.id}/fetch-policies`);
        returnPolicies.value = response.data.return_policies ?? [];
        paymentPolicies.value = response.data.payment_policies ?? [];
        fulfillmentPolicies.value = response.data.fulfillment_policies ?? [];
        policiesLoaded.value = true;
    } catch (error: unknown) {
        const axiosError = error as { response?: { data?: { error?: string } } };
        policiesError.value = axiosError.response?.data?.error ?? 'Failed to fetch policies';
    } finally {
        loadingPolicies.value = false;
    }
}

async function fetchLocations() {
    loadingLocations.value = true;
    locationsError.value = '';

    try {
        const response = await axios.post(`/settings/marketplaces/${props.marketplace.id}/fetch-locations`);
        ebayLocations.value = response.data ?? [];
        locationsLoaded.value = true;
    } catch (error: unknown) {
        const axiosError = error as { response?: { data?: { error?: string } } };
        locationsError.value = axiosError.response?.data?.error ?? 'Failed to fetch locations';
    } finally {
        loadingLocations.value = false;
    }
}

// Etsy fetch functions
async function fetchShippingProfiles() {
    loadingShippingProfiles.value = true;
    shippingProfilesError.value = '';

    try {
        const response = await axios.post(`/settings/marketplaces/${props.marketplace.id}/fetch-shipping-profiles`);
        etsyShippingProfiles.value = response.data.shipping_profiles ?? [];
        shippingProfilesLoaded.value = true;
    } catch (error: unknown) {
        const axiosError = error as { response?: { data?: { error?: string } } };
        shippingProfilesError.value = axiosError.response?.data?.error ?? 'Failed to fetch shipping profiles';
    } finally {
        loadingShippingProfiles.value = false;
    }
}

async function fetchEtsyReturnPolicies() {
    loadingEtsyReturnPolicies.value = true;
    etsyReturnPoliciesError.value = '';

    try {
        const response = await axios.post(`/settings/marketplaces/${props.marketplace.id}/fetch-return-policies`);
        etsyReturnPolicies.value = response.data.return_policies ?? [];
        etsyReturnPoliciesLoaded.value = true;
    } catch (error: unknown) {
        const axiosError = error as { response?: { data?: { error?: string } } };
        etsyReturnPoliciesError.value = axiosError.response?.data?.error ?? 'Failed to fetch return policies';
    } finally {
        loadingEtsyReturnPolicies.value = false;
    }
}

function submitForm() {
    saving.value = true;
    errors.value = {};

    router.put(`/settings/marketplaces/${props.marketplace.id}/settings`, form.value as Record<string, unknown>, {
        preserveScroll: true,
        onSuccess: () => {
            recentlySaved.value = true;
            setTimeout(() => {
                recentlySaved.value = false;
            }, 3000);
        },
        onError: (e) => {
            errors.value = e;
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}

function addLocationMapping() {
    if (!form.value.location_mappings) {
        form.value.location_mappings = [];
    }
    form.value.location_mappings.push({ warehouse_id: null, location_key: '' });
}

function removeLocationMapping(index: number) {
    form.value.location_mappings?.splice(index, 1);
}

onMounted(() => {
    if (isEbay.value) {
        fetchPolicies();
        fetchLocations();
    }
    if (isEtsy.value) {
        fetchShippingProfiles();
        fetchEtsyReturnPolicies();
    }
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="`${marketplace.name} Settings`" />
        <SettingsLayout>
            <div class="flex flex-col space-y-8">
                <!-- Header -->
                <div>
                    <HeadingSmall
                        :title="`${marketplace.name} Settings`"
                        description="Configure default listing settings for this marketplace connection."
                    />
                </div>

                <form @submit.prevent="submitForm" class="space-y-8">
                    <!-- eBay: Marketplace Region -->
                    <div v-if="isEbay">
                        <HeadingSmall
                            title="Marketplace Region"
                            description="Select the eBay marketplace you sell on."
                        />
                        <div class="mt-4 max-w-md">
                            <Label for="marketplace_id">eBay Marketplace</Label>
                            <select id="marketplace_id" v-model="form.marketplace_id" :class="selectClass">
                                <option v-for="mp in ebayMarketplaces" :key="mp.value" :value="mp.value">
                                    {{ mp.label }}
                                </option>
                            </select>
                            <InputError :message="errors.marketplace_id" />
                        </div>
                    </div>

                    <!-- Amazon: Marketplace & Fulfillment -->
                    <div v-if="isAmazon">
                        <HeadingSmall
                            title="Amazon Configuration"
                            description="Configure your Amazon marketplace and fulfillment settings."
                        />
                        <div class="mt-4 grid gap-4 max-w-2xl sm:grid-cols-2">
                            <div>
                                <Label for="marketplace_id">Amazon Marketplace</Label>
                                <select id="marketplace_id" v-model="form.marketplace_id" :class="selectClass">
                                    <option v-for="mp in amazonMarketplaces" :key="mp.value" :value="mp.value">
                                        {{ mp.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.marketplace_id" />
                            </div>

                            <div>
                                <Label for="fulfillment_channel">Fulfillment Channel</Label>
                                <select id="fulfillment_channel" v-model="form.fulfillment_channel" :class="selectClass">
                                    <option v-for="fc in amazonFulfillmentChannels" :key="fc.value" :value="fc.value">
                                        {{ fc.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.fulfillment_channel" />
                            </div>

                            <div>
                                <Label for="language_tag">Language</Label>
                                <select id="language_tag" v-model="form.language_tag" :class="selectClass">
                                    <option v-for="lt in amazonLanguageTags" :key="lt.value" :value="lt.value">
                                        {{ lt.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.language_tag" />
                            </div>
                        </div>
                    </div>

                    <!-- Etsy: Configuration -->
                    <div v-if="isEtsy">
                        <HeadingSmall
                            title="Etsy Configuration"
                            description="Configure your Etsy listing defaults."
                        />
                        <div class="mt-4 grid gap-4 max-w-2xl sm:grid-cols-2">
                            <div>
                                <Label for="currency">Currency</Label>
                                <select id="currency" v-model="form.currency" :class="selectClass">
                                    <option v-for="c in etsyCurrencies" :key="c.value" :value="c.value">
                                        {{ c.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.currency" />
                            </div>

                            <div>
                                <Label for="who_made">Who Made</Label>
                                <select id="who_made" v-model="form.who_made" :class="selectClass">
                                    <option v-for="wm in etsyWhoMade" :key="wm.value" :value="wm.value">
                                        {{ wm.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.who_made" />
                            </div>

                            <div>
                                <Label for="when_made">When Made</Label>
                                <select id="when_made" v-model="form.when_made" :class="selectClass">
                                    <option v-for="wm in etsyWhenMade" :key="wm.value" :value="wm.value">
                                        {{ wm.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.when_made" />
                            </div>
                        </div>

                        <div class="mt-4 space-y-3 max-w-2xl">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="form.is_supply"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-800"
                                />
                                <div>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">Is Supply</span>
                                    <p class="text-xs text-muted-foreground">This item is a craft supply or tool</p>
                                </div>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="form.auto_renew"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-800"
                                />
                                <div>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">Auto-Renew Listings</span>
                                    <p class="text-xs text-muted-foreground">Automatically renew listings when they expire</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Etsy: Shipping Profiles & Return Policies -->
                    <div v-if="isEtsy">
                        <Separator class="mb-8" />
                        <div class="flex items-center justify-between">
                            <HeadingSmall
                                title="Shipping & Return Policies"
                                description="Select the Etsy shipping profile and return policy for listings."
                            />
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="loadingShippingProfiles || loadingEtsyReturnPolicies"
                                    @click="fetchShippingProfiles(); fetchEtsyReturnPolicies();"
                                >
                                    <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': loadingShippingProfiles || loadingEtsyReturnPolicies }" />
                                    Refresh
                                </Button>
                            </div>
                        </div>

                        <div v-if="shippingProfilesError" class="mt-3 p-3 rounded-md bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-400">
                            {{ shippingProfilesError }}
                        </div>
                        <div v-if="etsyReturnPoliciesError" class="mt-3 p-3 rounded-md bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-400">
                            {{ etsyReturnPoliciesError }}
                        </div>

                        <div class="mt-4 grid gap-4 max-w-2xl">
                            <div>
                                <Label for="shipping_profile_id">Shipping Profile</Label>
                                <select id="shipping_profile_id" v-model="form.shipping_profile_id" :class="selectClass">
                                    <option value="">-- Select Shipping Profile --</option>
                                    <option
                                        v-for="profile in etsyShippingProfiles"
                                        :key="profile.shipping_profile_id"
                                        :value="profile.shipping_profile_id"
                                    >
                                        {{ profile.title || profile.shipping_profile_id }}
                                    </option>
                                </select>
                                <p v-if="!shippingProfilesLoaded && !loadingShippingProfiles" class="mt-1 text-xs text-muted-foreground">
                                    Click "Refresh" to load shipping profiles from Etsy
                                </p>
                                <InputError :message="errors.shipping_profile_id" />
                            </div>

                            <div>
                                <Label for="etsy_return_policy_id">Return Policy</Label>
                                <select id="etsy_return_policy_id" v-model="form.return_policy_id" :class="selectClass">
                                    <option value="">-- Select Return Policy --</option>
                                    <option
                                        v-for="policy in etsyReturnPolicies"
                                        :key="policy.return_policy_id"
                                        :value="policy.return_policy_id"
                                    >
                                        {{ policy.accepts_returns ? 'Accepts Returns' : 'No Returns' }}
                                        <template v-if="policy.return_deadline"> ({{ policy.return_deadline }} days)</template>
                                        {{ policy.accepts_exchanges ? '+ Exchanges' : '' }}
                                    </option>
                                </select>
                                <p v-if="!etsyReturnPoliciesLoaded && !loadingEtsyReturnPolicies" class="mt-1 text-xs text-muted-foreground">
                                    Click "Refresh" to load return policies from Etsy
                                </p>
                                <InputError :message="errors.return_policy_id" />
                            </div>
                        </div>
                    </div>

                    <!-- Walmart: Configuration -->
                    <div v-if="isWalmart">
                        <HeadingSmall
                            title="Walmart Configuration"
                            description="Configure your Walmart listing defaults."
                        />
                        <div class="mt-4 grid gap-4 max-w-2xl sm:grid-cols-2">
                            <div>
                                <Label for="product_id_type">Product ID Type</Label>
                                <select id="product_id_type" v-model="form.product_id_type" :class="selectClass">
                                    <option v-for="t in walmartProductIdTypes" :key="t.value" :value="t.value">
                                        {{ t.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.product_id_type" />
                            </div>

                            <div>
                                <Label for="fulfillment_type">Fulfillment Type</Label>
                                <select id="fulfillment_type" v-model="form.fulfillment_type" :class="selectClass">
                                    <option v-for="ft in walmartFulfillmentTypes" :key="ft.value" :value="ft.value">
                                        {{ ft.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.fulfillment_type" />
                            </div>

                            <div>
                                <Label for="shipping_method">Shipping Method</Label>
                                <select id="shipping_method" v-model="form.shipping_method" :class="selectClass">
                                    <option v-for="sm in walmartShippingMethods" :key="sm.value" :value="sm.value">
                                        {{ sm.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.shipping_method" />
                            </div>

                            <div>
                                <Label for="weight_unit">Weight Unit</Label>
                                <select id="weight_unit" v-model="form.weight_unit" :class="selectClass">
                                    <option v-for="wu in walmartWeightUnits" :key="wu.value" :value="wu.value">
                                        {{ wu.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.weight_unit" />
                            </div>
                        </div>
                    </div>

                    <!-- Shopify: Configuration -->
                    <div v-if="isShopify">
                        <HeadingSmall
                            title="Shopify Configuration"
                            description="Configure your Shopify listing defaults."
                        />
                        <div class="mt-4 grid gap-4 max-w-2xl sm:grid-cols-2">
                            <div>
                                <Label for="default_product_status">Default Product Status</Label>
                                <select id="default_product_status" v-model="form.default_product_status" :class="selectClass">
                                    <option v-for="ps in shopifyProductStatuses" :key="ps.value" :value="ps.value">
                                        {{ ps.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.default_product_status" />
                            </div>

                            <div>
                                <Label for="inventory_tracking">Inventory Tracking</Label>
                                <select id="inventory_tracking" v-model="form.inventory_tracking" :class="selectClass">
                                    <option v-for="it in shopifyInventoryTracking" :key="it.value" :value="it.value">
                                        {{ it.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.inventory_tracking" />
                            </div>
                        </div>
                    </div>

                    <Separator />

                    <!-- eBay: Listing Defaults -->
                    <div v-if="isEbay">
                        <HeadingSmall
                            title="Listing Defaults"
                            description="Default settings applied when creating new listings."
                        />
                        <div class="mt-4 grid gap-4 max-w-2xl sm:grid-cols-2">
                            <div>
                                <Label for="default_condition">Default Condition</Label>
                                <select id="default_condition" v-model="form.default_condition" :class="selectClass">
                                    <option v-for="c in conditions" :key="c.value" :value="c.value">
                                        {{ c.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.default_condition" />
                            </div>

                            <div>
                                <Label for="listing_type">Listing Type</Label>
                                <select id="listing_type" v-model="form.listing_type" :class="selectClass">
                                    <option value="FIXED_PRICE">Fixed Price</option>
                                    <option value="AUCTION">Auction</option>
                                </select>
                                <InputError :message="errors.listing_type" />
                            </div>

                            <div>
                                <Label for="listing_duration_fixed">Duration (Fixed Price)</Label>
                                <select id="listing_duration_fixed" v-model="form.listing_duration_fixed" :class="selectClass">
                                    <option v-for="d in fixedPriceDurations" :key="d.value" :value="d.value">
                                        {{ d.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.listing_duration_fixed" />
                            </div>

                            <div>
                                <Label for="listing_duration_auction">Duration (Auction)</Label>
                                <select id="listing_duration_auction" v-model="form.listing_duration_auction" :class="selectClass">
                                    <option v-for="d in auctionDurations" :key="d.value" :value="d.value">
                                        {{ d.label }}
                                    </option>
                                </select>
                                <InputError :message="errors.listing_duration_auction" />
                            </div>
                        </div>

                        <Separator class="my-8" />

                        <!-- Business Policies -->
                        <div class="flex items-center justify-between">
                            <HeadingSmall
                                title="Business Policies"
                                description="Select the eBay business policies to use for listings."
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="loadingPolicies"
                                @click="fetchPolicies"
                            >
                                <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': loadingPolicies }" />
                                Refresh
                            </Button>
                        </div>

                        <div v-if="policiesError" class="mt-3 p-3 rounded-md bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-400">
                            {{ policiesError }}
                        </div>

                        <div class="mt-4 grid gap-4 max-w-2xl">
                            <div>
                                <Label for="return_policy_id">Return Policy</Label>
                                <select id="return_policy_id" v-model="form.return_policy_id" :class="selectClass">
                                    <option value="">-- Select Return Policy --</option>
                                    <option
                                        v-for="policy in returnPolicies"
                                        :key="policy.returnPolicyId"
                                        :value="policy.returnPolicyId"
                                    >
                                        {{ policy.name || policy.returnPolicyId }}
                                    </option>
                                </select>
                                <p v-if="!policiesLoaded && !loadingPolicies" class="mt-1 text-xs text-muted-foreground">
                                    Click "Refresh" to load policies from eBay
                                </p>
                                <InputError :message="errors.return_policy_id" />
                            </div>

                            <div>
                                <Label for="payment_policy_id">Payment Policy</Label>
                                <select id="payment_policy_id" v-model="form.payment_policy_id" :class="selectClass">
                                    <option value="">-- Select Payment Policy --</option>
                                    <option
                                        v-for="policy in paymentPolicies"
                                        :key="policy.paymentPolicyId"
                                        :value="policy.paymentPolicyId"
                                    >
                                        {{ policy.name || policy.paymentPolicyId }}
                                    </option>
                                </select>
                                <InputError :message="errors.payment_policy_id" />
                            </div>

                            <div>
                                <Label for="fulfillment_policy_id">Fulfillment Policy</Label>
                                <select id="fulfillment_policy_id" v-model="form.fulfillment_policy_id" :class="selectClass">
                                    <option value="">-- Select Fulfillment Policy --</option>
                                    <option
                                        v-for="policy in fulfillmentPolicies"
                                        :key="policy.fulfillmentPolicyId"
                                        :value="policy.fulfillmentPolicyId"
                                    >
                                        {{ policy.name || policy.fulfillmentPolicyId }}
                                    </option>
                                </select>
                                <InputError :message="errors.fulfillment_policy_id" />
                            </div>
                        </div>
                    </div>

                    <Separator />

                    <!-- Price Markup -->
                    <div>
                        <HeadingSmall
                            title="Price Markup"
                            description="Percentage adjustment applied to product prices when listing on this marketplace."
                        />
                        <div class="mt-4 grid gap-4 max-w-2xl sm:grid-cols-2">
                            <div v-if="!isEbay">
                                <Label for="price_markup">Price Markup (%)</Label>
                                <Input
                                    id="price_markup"
                                    v-model.number="form.price_markup"
                                    type="number"
                                    step="0.1"
                                    class="mt-1"
                                    placeholder="0"
                                />
                                <p class="mt-1 text-xs text-muted-foreground">
                                    e.g. 10 = prices are 10% higher on this marketplace
                                </p>
                                <InputError :message="errors.price_markup" />
                            </div>

                            <template v-if="isEbay">
                                <div>
                                    <Label for="fixed_price_markup">Fixed Price Markup (%)</Label>
                                    <Input
                                        id="fixed_price_markup"
                                        v-model.number="form.fixed_price_markup"
                                        type="number"
                                        step="0.1"
                                        class="mt-1"
                                        placeholder="0"
                                    />
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        e.g. 10 = prices are 10% higher on this marketplace
                                    </p>
                                    <InputError :message="errors.fixed_price_markup" />
                                </div>

                                <div>
                                    <Label for="auction_markup">Auction Markup (%)</Label>
                                    <Input
                                        id="auction_markup"
                                        v-model.number="form.auction_markup"
                                        type="number"
                                        step="0.1"
                                        class="mt-1"
                                        placeholder="0"
                                    />
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        Applied when listing type is Auction
                                    </p>
                                    <InputError :message="errors.auction_markup" />
                                </div>
                            </template>
                        </div>
                    </div>

                    <Separator />

                    <!-- Listing Options -->
                    <div>
                        <HeadingSmall
                            title="Listing Options"
                            description="Additional options for listings on this marketplace."
                        />
                        <div class="mt-4 space-y-3 max-w-2xl">
                            <label v-if="isEbay" class="flex items-center gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="form.best_offer_enabled"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-800"
                                />
                                <div>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">Enable Best Offer</span>
                                    <p class="text-xs text-muted-foreground">Allow buyers to make offers on Fixed Price listings</p>
                                </div>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="form.use_ai_details"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-800"
                                />
                                <div>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">Use AI Details</span>
                                    <p class="text-xs text-muted-foreground">Use AI to enhance listing titles and descriptions</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <Separator />

                    <!-- eBay: Inventory Locations -->
                    <div v-if="isEbay">
                        <div class="flex items-center justify-between">
                            <HeadingSmall
                                title="Inventory Locations"
                                description="Map your warehouses to eBay inventory locations."
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="loadingLocations"
                                @click="fetchLocations"
                            >
                                <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': loadingLocations }" />
                                Refresh
                            </Button>
                        </div>

                        <div v-if="locationsError" class="mt-3 p-3 rounded-md bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-400">
                            {{ locationsError }}
                        </div>

                        <!-- Default location -->
                        <div class="mt-4 max-w-md">
                            <Label for="location_key">Default Inventory Location</Label>
                            <select id="location_key" v-model="form.location_key" :class="selectClass">
                                <option value="">-- Select Location --</option>
                                <option
                                    v-for="loc in ebayLocations"
                                    :key="loc.merchantLocationKey"
                                    :value="loc.merchantLocationKey"
                                >
                                    {{ loc.name || loc.merchantLocationKey }}
                                    <template v-if="loc.location?.address?.city">
                                        ({{ loc.location.address.city }}, {{ loc.location.address.stateOrProvince }})
                                    </template>
                                </option>
                            </select>
                            <p v-if="!locationsLoaded && !loadingLocations" class="mt-1 text-xs text-muted-foreground">
                                Click "Refresh" to load locations from eBay
                            </p>
                            <InputError :message="errors.location_key" />
                        </div>

                        <!-- Location mappings -->
                        <div class="mt-6">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-medium text-gray-900 dark:text-white">Warehouse Mappings</h4>
                                <Button type="button" variant="outline" size="sm" @click="addLocationMapping">
                                    Add Mapping
                                </Button>
                            </div>

                            <div v-if="form.location_mappings && form.location_mappings.length > 0" class="space-y-3">
                                <div
                                    v-for="(mapping, index) in form.location_mappings"
                                    :key="index"
                                    class="flex items-center gap-3"
                                >
                                    <select
                                        v-model="mapping.warehouse_id"
                                        class="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    >
                                        <option :value="null">-- Warehouse --</option>
                                        <option v-for="w in warehouses" :key="w.id" :value="w.id">
                                            {{ w.name }}
                                            <template v-if="w.is_default">(Default)</template>
                                        </option>
                                    </select>

                                    <span class="text-sm text-muted-foreground shrink-0">maps to</span>

                                    <select
                                        v-model="mapping.location_key"
                                        class="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    >
                                        <option value="">-- eBay Location --</option>
                                        <option
                                            v-for="loc in ebayLocations"
                                            :key="loc.merchantLocationKey"
                                            :value="loc.merchantLocationKey"
                                        >
                                            {{ loc.name || loc.merchantLocationKey }}
                                        </option>
                                    </select>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="text-red-500 hover:text-red-700"
                                        @click="removeLocationMapping(index)"
                                    >
                                        Remove
                                    </Button>
                                </div>
                            </div>

                            <p v-else class="text-sm text-muted-foreground">
                                No warehouse-to-location mappings configured. The default location will be used for all inventory.
                            </p>
                        </div>

                        <Separator class="my-8" />
                    </div>

                    <!-- eBay Account Management -->
                    <EbayPoliciesSection
                        v-if="isEbay"
                        :marketplace-id="marketplace.id"
                        :ebay-marketplace-id="form.marketplace_id ?? 'EBAY_US'"
                    />

                    <!-- Save -->
                    <div class="flex items-center gap-3">
                        <Button type="submit" :disabled="saving">
                            {{ saving ? 'Saving...' : 'Save Settings' }}
                        </Button>

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-show="recentlySaved" class="flex items-center gap-1 text-sm text-green-600 dark:text-green-400">
                                <CheckCircleIcon class="h-4 w-4" />
                                Saved
                            </p>
                        </Transition>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
