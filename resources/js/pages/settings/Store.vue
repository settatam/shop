<script setup lang="ts">
import StoreSettingsController from '@/actions/App/Http/Controllers/Settings/StoreSettingsController';
import { edit } from '@/routes/store-settings';
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface SelectOption {
    value: string;
    label: string;
}

interface EditionOption {
    value: string;
    label: string;
    description: string;
}

interface MetalPriceSettings {
    buy_percentages: Record<string, number>;
}

interface Store {
    id: number;
    name: string;
    logo: string | null;
    logo_url: string | null;
    business_name: string | null;
    account_email: string | null;
    customer_email: string | null;
    phone: string | null;
    address: string | null;
    address2: string | null;
    city: string | null;
    state: string | null;
    zip: string | null;
    store_domain: string | null;
    order_id_prefix: string | null;
    order_id_suffix: string | null;
    buy_id_prefix: string | null;
    buy_id_suffix: string | null;
    repair_id_prefix: string | null;
    repair_id_suffix: string | null;
    memo_id_prefix: string | null;
    memo_id_suffix: string | null;
    currency: string;
    timezone: string;
    meta_title: string | null;
    meta_description: string | null;
    default_tax_rate: number | null;
    tax_id_number: string | null;
    edition: string;
    metal_price_settings: MetalPriceSettings;
}

interface MetalType {
    value: string;
    label: string;
    group: string;
}

interface Props {
    store: Store;
    currencies: SelectOption[];
    timezones: SelectOption[];
    availableEditions: EditionOption[];
    metalTypes: MetalType[];
}

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Store settings',
        href: edit().url,
    },
];

const logoUploading = ref(false);
const logoRemoving = ref(false);
const logoError = ref<string | null>(null);
const logoFileInput = ref<HTMLInputElement | null>(null);

function handleLogoUpload(event: Event) {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    if (!file) return;

    logoError.value = null;
    logoUploading.value = true;

    router.post(
        StoreSettingsController.uploadLogo.url(),
        { logo: file },
        {
            forceFormData: true,
            onSuccess: () => {
                logoUploading.value = false;
                if (logoFileInput.value) {
                    logoFileInput.value.value = '';
                }
            },
            onError: (errors) => {
                logoUploading.value = false;
                logoError.value = errors.logo || 'Failed to upload logo';
            },
        },
    );
}

function removeLogo() {
    logoError.value = null;
    logoRemoving.value = true;

    router.delete(StoreSettingsController.removeLogo.url(), {
        onSuccess: () => {
            logoRemoving.value = false;
        },
        onError: () => {
            logoRemoving.value = false;
            logoError.value = 'Failed to remove logo';
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Store settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-8">
                <!-- Store Logo -->
                <div>
                    <HeadingSmall title="Store Logo" description="Upload your store's logo for branding" />

                    <div class="mt-6 flex items-start gap-6">
                        <!-- Logo Preview -->
                        <div
                            class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted"
                        >
                            <img
                                v-if="store.logo_url"
                                :src="store.logo_url"
                                :alt="store.name + ' logo'"
                                class="h-full w-full object-contain"
                            />
                            <span v-else class="text-3xl font-semibold text-muted-foreground">
                                {{ store.name.charAt(0).toUpperCase() }}
                            </span>
                        </div>

                        <!-- Upload Controls -->
                        <div class="flex flex-col gap-3">
                            <div class="flex items-center gap-3">
                                <input
                                    ref="logoFileInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/gif,image/svg+xml,image/webp"
                                    class="hidden"
                                    @change="handleLogoUpload"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="logoUploading"
                                    @click="logoFileInput?.click()"
                                >
                                    {{ logoUploading ? 'Uploading...' : store.logo_url ? 'Change Logo' : 'Upload Logo' }}
                                </Button>
                                <Button
                                    v-if="store.logo_url"
                                    type="button"
                                    variant="ghost"
                                    :disabled="logoRemoving"
                                    @click="removeLogo"
                                >
                                    {{ logoRemoving ? 'Removing...' : 'Remove' }}
                                </Button>
                            </div>
                            <p class="text-sm text-muted-foreground">JPG, PNG, GIF, SVG, or WebP. Max 2MB.</p>
                            <p v-if="logoError" class="text-sm text-destructive">{{ logoError }}</p>
                        </div>
                    </div>
                </div>

                <!-- Store Edition -->
                <div>
                    <HeadingSmall
                        title="Store Edition"
                        description="Choose the edition that best fits your business type"
                    />

                    <Form
                        v-bind="StoreSettingsController.update.form()"
                        class="mt-6"
                        v-slot="{ errors, processing, recentlySuccessful }"
                    >
                        <div class="grid gap-2">
                            <Label for="edition">Edition</Label>
                            <select
                                id="edition"
                                name="edition"
                                :value="store.edition"
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option v-for="edition in availableEditions" :key="edition.value" :value="edition.value">
                                    {{ edition.label }}
                                </option>
                            </select>
                            <InputError :message="errors.edition" />
                            <p class="text-sm text-muted-foreground">
                                {{
                                    availableEditions.find((e) => e.value === store.edition)?.description ||
                                    'Select an edition to see available features'
                                }}
                            </p>
                        </div>

                        <div class="mt-4 flex items-center gap-4">
                            <Button :disabled="processing">
                                {{ processing ? 'Saving...' : 'Update Edition' }}
                            </Button>

                            <Transition
                                enter-active-class="transition ease-in-out"
                                enter-from-class="opacity-0"
                                leave-active-class="transition ease-in-out"
                                leave-to-class="opacity-0"
                            >
                                <p v-show="recentlySuccessful" class="text-sm text-green-600">
                                    Saved successfully.
                                </p>
                            </Transition>
                        </div>
                    </Form>
                </div>

                <!-- General Information -->
                <div>
                    <HeadingSmall
                        title="Store Information"
                        description="Update your store's name and contact details"
                    />

                    <Form
                        v-bind="StoreSettingsController.update.form()"
                        class="mt-6 space-y-6"
                        v-slot="{ errors, processing, recentlySuccessful }"
                    >
                        <!-- Store Name -->
                        <div class="grid gap-2">
                            <Label for="name">Store Name *</Label>
                            <Input
                                id="name"
                                name="name"
                                :default-value="store.name"
                                required
                                placeholder="My Store"
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <!-- Business Name -->
                        <div class="grid gap-2">
                            <Label for="business_name">Legal Business Name</Label>
                            <Input
                                id="business_name"
                                name="business_name"
                                :default-value="store.business_name ?? ''"
                                placeholder="My Business LLC"
                            />
                            <InputError :message="errors.business_name" />
                            <p class="text-sm text-muted-foreground">
                                Your legal business name for invoices and receipts
                            </p>
                        </div>

                        <!-- Email Addresses -->
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="account_email">Account Email</Label>
                                <Input
                                    id="account_email"
                                    type="email"
                                    name="account_email"
                                    :default-value="store.account_email ?? ''"
                                    placeholder="admin@mystore.com"
                                />
                                <InputError :message="errors.account_email" />
                                <p class="text-sm text-muted-foreground">
                                    For account notifications
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label for="customer_email">Customer Email</Label>
                                <Input
                                    id="customer_email"
                                    type="email"
                                    name="customer_email"
                                    :default-value="store.customer_email ?? ''"
                                    placeholder="support@mystore.com"
                                />
                                <InputError :message="errors.customer_email" />
                                <p class="text-sm text-muted-foreground">
                                    Shown to customers on invoices
                                </p>
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="grid gap-2">
                            <Label for="phone">Phone Number</Label>
                            <Input
                                id="phone"
                                type="tel"
                                name="phone"
                                :default-value="store.phone ?? ''"
                                placeholder="(555) 123-4567"
                            />
                            <InputError :message="errors.phone" />
                        </div>

                        <!-- Address Section -->
                        <div class="border-t pt-6">
                            <h4 class="mb-4 text-sm font-medium">Store Address</h4>

                            <div class="space-y-4">
                                <div class="grid gap-2">
                                    <Label for="address">Street Address</Label>
                                    <Input
                                        id="address"
                                        name="address"
                                        :default-value="store.address ?? ''"
                                        placeholder="123 Main Street"
                                    />
                                    <InputError :message="errors.address" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="address2">Address Line 2</Label>
                                    <Input
                                        id="address2"
                                        name="address2"
                                        :default-value="store.address2 ?? ''"
                                        placeholder="Suite 100"
                                    />
                                    <InputError :message="errors.address2" />
                                </div>

                                <div class="grid gap-4 sm:grid-cols-3">
                                    <div class="grid gap-2">
                                        <Label for="city">City</Label>
                                        <Input
                                            id="city"
                                            name="city"
                                            :default-value="store.city ?? ''"
                                            placeholder="New York"
                                        />
                                        <InputError :message="errors.city" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="state">State / Province</Label>
                                        <Input
                                            id="state"
                                            name="state"
                                            :default-value="store.state ?? ''"
                                            placeholder="NY"
                                        />
                                        <InputError :message="errors.state" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="zip">ZIP / Postal Code</Label>
                                        <Input
                                            id="zip"
                                            name="zip"
                                            :default-value="store.zip ?? ''"
                                            placeholder="10001"
                                        />
                                        <InputError :message="errors.zip" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Regional Settings -->
                        <div class="border-t pt-6">
                            <h4 class="mb-4 text-sm font-medium">Regional Settings</h4>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="currency">Currency</Label>
                                    <select
                                        id="currency"
                                        name="currency"
                                        :value="store.currency"
                                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <option v-for="currency in currencies" :key="currency.value" :value="currency.value">
                                            {{ currency.label }}
                                        </option>
                                    </select>
                                    <InputError :message="errors.currency" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="timezone">Timezone</Label>
                                    <select
                                        id="timezone"
                                        name="timezone"
                                        :value="store.timezone"
                                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <option v-for="tz in timezones" :key="tz.value" :value="tz.value">
                                            {{ tz.label }}
                                        </option>
                                    </select>
                                    <InputError :message="errors.timezone" />
                                </div>
                            </div>
                        </div>

                        <!-- Invoice Settings -->
                        <div class="border-t pt-6">
                            <h4 class="mb-4 text-sm font-medium">Invoice Settings</h4>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="order_id_prefix">Invoice Prefix</Label>
                                    <Input
                                        id="order_id_prefix"
                                        name="order_id_prefix"
                                        :default-value="store.order_id_prefix ?? ''"
                                        placeholder="INV-"
                                    />
                                    <InputError :message="errors.order_id_prefix" />
                                    <p class="text-sm text-muted-foreground">
                                        Added before the invoice number (e.g., INV-1001)
                                    </p>
                                </div>

                                <div class="grid gap-2">
                                    <Label for="order_id_suffix">Invoice Suffix</Label>
                                    <Input
                                        id="order_id_suffix"
                                        name="order_id_suffix"
                                        :default-value="store.order_id_suffix ?? ''"
                                        placeholder="-2024"
                                    />
                                    <InputError :message="errors.order_id_suffix" />
                                    <p class="text-sm text-muted-foreground">
                                        Added after the invoice number (e.g., 1001-2024)
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- ID Prefix/Suffix Settings -->
                        <div class="border-t pt-6">
                            <h4 class="mb-4 text-sm font-medium">ID Format Settings</h4>
                            <p class="mb-4 text-sm text-muted-foreground">
                                Customize how IDs are displayed for transactions, repairs, and memos. If no
                                prefix/suffix is set, just the ID number will be shown.
                            </p>

                            <!-- Buy/Transaction -->
                            <div class="mb-6">
                                <h5 class="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Buy/Transaction
                                </h5>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="buy_id_prefix">Prefix</Label>
                                        <Input
                                            id="buy_id_prefix"
                                            name="buy_id_prefix"
                                            :default-value="store.buy_id_prefix ?? ''"
                                            placeholder="BUY-"
                                        />
                                        <InputError :message="errors.buy_id_prefix" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="buy_id_suffix">Suffix</Label>
                                        <Input
                                            id="buy_id_suffix"
                                            name="buy_id_suffix"
                                            :default-value="store.buy_id_suffix ?? ''"
                                            placeholder="-2024"
                                        />
                                        <InputError :message="errors.buy_id_suffix" />
                                    </div>
                                </div>
                            </div>

                            <!-- Repair -->
                            <div class="mb-6">
                                <h5 class="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Repair
                                </h5>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="repair_id_prefix">Prefix</Label>
                                        <Input
                                            id="repair_id_prefix"
                                            name="repair_id_prefix"
                                            :default-value="store.repair_id_prefix ?? ''"
                                            placeholder="REP-"
                                        />
                                        <InputError :message="errors.repair_id_prefix" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="repair_id_suffix">Suffix</Label>
                                        <Input
                                            id="repair_id_suffix"
                                            name="repair_id_suffix"
                                            :default-value="store.repair_id_suffix ?? ''"
                                            placeholder="-2024"
                                        />
                                        <InputError :message="errors.repair_id_suffix" />
                                    </div>
                                </div>
                            </div>

                            <!-- Memo -->
                            <div>
                                <h5 class="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Memo
                                </h5>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="memo_id_prefix">Prefix</Label>
                                        <Input
                                            id="memo_id_prefix"
                                            name="memo_id_prefix"
                                            :default-value="store.memo_id_prefix ?? ''"
                                            placeholder="MEM-"
                                        />
                                        <InputError :message="errors.memo_id_prefix" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="memo_id_suffix">Suffix</Label>
                                        <Input
                                            id="memo_id_suffix"
                                            name="memo_id_suffix"
                                            :default-value="store.memo_id_suffix ?? ''"
                                            placeholder="-2024"
                                        />
                                        <InputError :message="errors.memo_id_suffix" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tax Settings -->
                        <div class="border-t pt-6">
                            <h4 class="mb-4 text-sm font-medium">Tax Settings</h4>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="default_tax_rate">Default Tax Rate (%)</Label>
                                    <Input
                                        id="default_tax_rate"
                                        name="default_tax_rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        :default-value="store.default_tax_rate ?? ''"
                                        placeholder="8.00"
                                    />
                                    <InputError :message="errors.default_tax_rate" />
                                    <p class="text-sm text-muted-foreground">
                                        Default tax rate applied to memos and repairs (e.g., 8 for 8%)
                                    </p>
                                </div>

                                <div class="grid gap-2">
                                    <Label for="tax_id_number">Tax ID Number</Label>
                                    <Input
                                        id="tax_id_number"
                                        name="tax_id_number"
                                        :default-value="store.tax_id_number ?? ''"
                                        placeholder="12-3456789"
                                    />
                                    <InputError :message="errors.tax_id_number" />
                                    <p class="text-sm text-muted-foreground">
                                        Your business tax ID (EIN, VAT, etc.) for invoices
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Metal Price Settings -->
                        <div class="border-t pt-6">
                            <h4 class="mb-4 text-sm font-medium">Precious Metal Buy Rates</h4>
                            <p class="mb-4 text-sm text-muted-foreground">
                                Set the percentage of spot price you pay when buying precious metals.
                                For example, 75% means you pay 75% of the current spot price.
                                Leave empty to use the default rate (75%).
                            </p>

                            <!-- Gold -->
                            <div class="mb-6">
                                <h5 class="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Gold
                                </h5>
                                <div class="grid gap-4 sm:grid-cols-5">
                                    <div
                                        v-for="metal in metalTypes.filter((m) => m.group === 'Gold')"
                                        :key="metal.value"
                                        class="grid gap-2"
                                    >
                                        <Label :for="`metal_${metal.value}`">{{ metal.label }}</Label>
                                        <div class="relative">
                                            <Input
                                                :id="`metal_${metal.value}`"
                                                :name="`metal_price_settings[buy_percentages][${metal.value}]`"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="100"
                                                :default-value="
                                                    store.metal_price_settings?.buy_percentages?.[metal.value]
                                                        ? store.metal_price_settings.buy_percentages[metal.value] * 100
                                                        : ''
                                                "
                                                placeholder="75"
                                                class="pr-8"
                                            />
                                            <span
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground"
                                            >
                                                %
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Silver -->
                            <div class="mb-6">
                                <h5 class="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Silver
                                </h5>
                                <div class="grid gap-4 sm:grid-cols-5">
                                    <div
                                        v-for="metal in metalTypes.filter((m) => m.group === 'Silver')"
                                        :key="metal.value"
                                        class="grid gap-2"
                                    >
                                        <Label :for="`metal_${metal.value}`">{{ metal.label }}</Label>
                                        <div class="relative">
                                            <Input
                                                :id="`metal_${metal.value}`"
                                                :name="`metal_price_settings[buy_percentages][${metal.value}]`"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="100"
                                                :default-value="
                                                    store.metal_price_settings?.buy_percentages?.[metal.value]
                                                        ? store.metal_price_settings.buy_percentages[metal.value] * 100
                                                        : ''
                                                "
                                                placeholder="75"
                                                class="pr-8"
                                            />
                                            <span
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground"
                                            >
                                                %
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Other Precious Metals -->
                            <div>
                                <h5 class="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Other Precious Metals
                                </h5>
                                <div class="grid gap-4 sm:grid-cols-5">
                                    <div
                                        v-for="metal in metalTypes.filter((m) => m.group === 'Other')"
                                        :key="metal.value"
                                        class="grid gap-2"
                                    >
                                        <Label :for="`metal_${metal.value}`">{{ metal.label }}</Label>
                                        <div class="relative">
                                            <Input
                                                :id="`metal_${metal.value}`"
                                                :name="`metal_price_settings[buy_percentages][${metal.value}]`"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="100"
                                                :default-value="
                                                    store.metal_price_settings?.buy_percentages?.[metal.value]
                                                        ? store.metal_price_settings.buy_percentages[metal.value] * 100
                                                        : ''
                                                "
                                                placeholder="75"
                                                class="pr-8"
                                            />
                                            <span
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground"
                                            >
                                                %
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Domain Settings -->
                        <div class="border-t pt-6">
                            <h4 class="mb-4 text-sm font-medium">Domain Settings</h4>

                            <div class="grid gap-2">
                                <Label for="store_domain">Custom Domain</Label>
                                <Input
                                    id="store_domain"
                                    name="store_domain"
                                    :default-value="store.store_domain ?? ''"
                                    placeholder="mystore.com"
                                />
                                <InputError :message="errors.store_domain" />
                                <p class="text-sm text-muted-foreground">
                                    Your custom domain for the online store (requires DNS configuration)
                                </p>
                            </div>
                        </div>

                        <!-- SEO Settings -->
                        <div class="border-t pt-6">
                            <h4 class="mb-4 text-sm font-medium">SEO Settings</h4>

                            <div class="space-y-4">
                                <div class="grid gap-2">
                                    <Label for="meta_title">Meta Title</Label>
                                    <Input
                                        id="meta_title"
                                        name="meta_title"
                                        :default-value="store.meta_title ?? ''"
                                        placeholder="My Store - Quality Products"
                                    />
                                    <InputError :message="errors.meta_title" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="meta_description">Meta Description</Label>
                                    <textarea
                                        id="meta_description"
                                        name="meta_description"
                                        :value="store.meta_description ?? ''"
                                        placeholder="A brief description of your store for search engines..."
                                        rows="3"
                                        class="flex min-h-[60px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                    />
                                    <InputError :message="errors.meta_description" />
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex items-center gap-4 border-t pt-6">
                            <Button :disabled="processing">
                                {{ processing ? 'Saving...' : 'Save Changes' }}
                            </Button>

                            <Transition
                                enter-active-class="transition ease-in-out"
                                enter-from-class="opacity-0"
                                leave-active-class="transition ease-in-out"
                                leave-to-class="opacity-0"
                            >
                                <p v-show="recentlySuccessful" class="text-sm text-green-600">
                                    Saved successfully.
                                </p>
                            </Transition>
                        </div>
                    </Form>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
