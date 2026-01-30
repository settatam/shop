<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plug, ExternalLink, Truck, MessageSquare, Trash2, Check, AlertCircle, Gem, Package } from 'lucide-vue-next';
import { ref, computed } from 'vue';

interface Integration {
    id: number;
    provider: string;
    name: string;
    environment: string;
    status: string;
    last_error: string | null;
    last_used_at: string | null;
    has_credentials: boolean;
}

interface Props {
    integrations: Record<string, Integration>;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Integrations', href: '/integrations' },
];

// FedEx form
const fedexForm = useForm({
    client_id: '',
    client_secret: '',
    account_number: '',
    environment: 'sandbox' as 'sandbox' | 'production',
});

const showFedexForm = ref(false);

const fedexIntegration = computed(() => props.integrations?.fedex);

function saveFedex() {
    fedexForm.post('/integrations/fedex', {
        preserveScroll: true,
        onSuccess: () => {
            showFedexForm.value = false;
            fedexForm.reset();
        },
    });
}

function deleteFedex() {
    if (!fedexIntegration.value?.id) return;
    if (!confirm('Are you sure you want to remove the FedEx integration?')) return;

    router.delete(`/integrations/${fedexIntegration.value.id}`, {
        preserveScroll: true,
    });
}

// Twilio form
const twilioForm = useForm({
    account_sid: '',
    auth_token: '',
    phone_number: '',
    messaging_service_sid: '',
    environment: 'sandbox' as 'sandbox' | 'production',
});

const showTwilioForm = ref(false);

const twilioIntegration = computed(() => props.integrations?.twilio);

function saveTwilio() {
    twilioForm.post('/integrations/twilio', {
        preserveScroll: true,
        onSuccess: () => {
            showTwilioForm.value = false;
            twilioForm.reset();
        },
    });
}

function deleteTwilio() {
    if (!twilioIntegration.value?.id) return;
    if (!confirm('Are you sure you want to remove the Twilio integration?')) return;

    router.delete(`/integrations/${twilioIntegration.value.id}`, {
        preserveScroll: true,
    });
}

// GIA form
const giaForm = useForm({
    api_key: '',
    api_url: 'https://api.gia.edu/graphql',
});

const showGiaForm = ref(false);

const giaIntegration = computed(() => props.integrations?.gia);

function saveGia() {
    giaForm.post('/integrations/gia', {
        preserveScroll: true,
        onSuccess: () => {
            showGiaForm.value = false;
            giaForm.reset();
        },
    });
}

function deleteGia() {
    if (!giaIntegration.value?.id) return;
    if (!confirm('Are you sure you want to remove the GIA integration?')) return;

    router.delete(`/integrations/${giaIntegration.value.id}`, {
        preserveScroll: true,
    });
}

// ShipStation form
const shipstationForm = useForm({
    api_key: '',
    api_secret: '',
    store_id: '' as string | number,
    auto_sync_orders: true,
});

const showShipstationForm = ref(false);

const shipstationIntegration = computed(() => props.integrations?.shipstation);

function saveShipstation() {
    shipstationForm.post('/integrations/shipstation', {
        preserveScroll: true,
        onSuccess: () => {
            showShipstationForm.value = false;
            shipstationForm.reset();
        },
    });
}

function deleteShipstation() {
    if (!shipstationIntegration.value?.id) return;
    if (!confirm('Are you sure you want to remove the ShipStation integration?')) return;

    router.delete(`/integrations/${shipstationIntegration.value.id}`, {
        preserveScroll: true,
    });
}

const platforms = [
    {
        name: 'Shopify',
        description: 'Sync products and orders with your Shopify store',
        logo: '/images/platforms/shopify.svg',
        status: 'available',
    },
    {
        name: 'eBay',
        description: 'List products and manage orders on eBay',
        logo: '/images/platforms/ebay.svg',
        status: 'available',
    },
    {
        name: 'Amazon',
        description: 'Sell on Amazon Marketplace',
        logo: '/images/platforms/amazon.svg',
        status: 'available',
    },
    {
        name: 'Etsy',
        description: 'Connect your Etsy shop for handmade and vintage items',
        logo: '/images/platforms/etsy.svg',
        status: 'available',
    },
    {
        name: 'Walmart',
        description: 'Sell on Walmart Marketplace',
        logo: '/images/platforms/walmart.svg',
        status: 'available',
    },
    {
        name: 'WooCommerce',
        description: 'Integrate with your WooCommerce store',
        logo: '/images/platforms/woocommerce.svg',
        status: 'available',
    },
];

function getStatusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' {
    if (status === 'active') return 'default';
    if (status === 'error') return 'destructive';
    return 'secondary';
}
</script>

<template>
    <Head title="Integrations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div>
                <h1 class="text-2xl font-semibold">Integrations</h1>
                <p class="text-sm text-muted-foreground">
                    Connect shipping, messaging, and e-commerce platforms
                </p>
            </div>

            <!-- Services & APIs -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <!-- FedEx -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900">
                                    <Truck class="h-5 w-5 text-purple-600 dark:text-purple-400" />
                                </div>
                                <div>
                                    <CardTitle class="text-lg">FedEx</CardTitle>
                                    <CardDescription>Shipping labels and tracking</CardDescription>
                                </div>
                            </div>
                            <Badge
                                v-if="fedexIntegration?.has_credentials"
                                :variant="getStatusBadgeVariant(fedexIntegration.status)"
                            >
                                <Check v-if="fedexIntegration.status === 'active'" class="mr-1 h-3 w-3" />
                                <AlertCircle v-else-if="fedexIntegration.status === 'error'" class="mr-1 h-3 w-3" />
                                {{ fedexIntegration.status === 'active' ? 'Connected' : fedexIntegration.status }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <!-- Show configured state -->
                        <div v-if="fedexIntegration?.has_credentials && !showFedexForm" class="space-y-4">
                            <div class="rounded-lg border p-3 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="text-muted-foreground">Environment</span>
                                    <Badge variant="outline">{{ fedexIntegration.environment }}</Badge>
                                </div>
                                <div v-if="fedexIntegration.last_error" class="mt-2 text-red-600 dark:text-red-400">
                                    {{ fedexIntegration.last_error }}
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" @click="showFedexForm = true">
                                    Update Credentials
                                </Button>
                                <Button variant="ghost" size="sm" class="text-red-600" @click="deleteFedex">
                                    <Trash2 class="mr-1 h-4 w-4" />
                                    Remove
                                </Button>
                            </div>
                        </div>

                        <!-- Show form -->
                        <form v-else @submit.prevent="saveFedex" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Client ID</label>
                                <input
                                    v-model="fedexForm.client_id"
                                    type="text"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter FedEx API Client ID"
                                />
                                <p v-if="fedexForm.errors.client_id" class="mt-1 text-sm text-red-600">{{ fedexForm.errors.client_id }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Client Secret</label>
                                <input
                                    v-model="fedexForm.client_secret"
                                    type="password"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter FedEx API Client Secret"
                                />
                                <p v-if="fedexForm.errors.client_secret" class="mt-1 text-sm text-red-600">{{ fedexForm.errors.client_secret }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Account Number</label>
                                <input
                                    v-model="fedexForm.account_number"
                                    type="text"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter FedEx Account Number"
                                />
                                <p v-if="fedexForm.errors.account_number" class="mt-1 text-sm text-red-600">{{ fedexForm.errors.account_number }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Environment</label>
                                <select
                                    v-model="fedexForm.environment"
                                    class="w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option value="sandbox">Sandbox (Testing)</option>
                                    <option value="production">Production</option>
                                </select>
                            </div>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="fedexForm.processing">
                                    {{ fedexForm.processing ? 'Saving...' : 'Save FedEx' }}
                                </Button>
                                <Button
                                    v-if="fedexIntegration?.has_credentials"
                                    type="button"
                                    variant="ghost"
                                    @click="showFedexForm = false"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <!-- Twilio -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-red-100 dark:bg-red-900">
                                    <MessageSquare class="h-5 w-5 text-red-600 dark:text-red-400" />
                                </div>
                                <div>
                                    <CardTitle class="text-lg">Twilio</CardTitle>
                                    <CardDescription>SMS notifications to customers</CardDescription>
                                </div>
                            </div>
                            <Badge
                                v-if="twilioIntegration?.has_credentials"
                                :variant="getStatusBadgeVariant(twilioIntegration.status)"
                            >
                                <Check v-if="twilioIntegration.status === 'active'" class="mr-1 h-3 w-3" />
                                <AlertCircle v-else-if="twilioIntegration.status === 'error'" class="mr-1 h-3 w-3" />
                                {{ twilioIntegration.status === 'active' ? 'Connected' : twilioIntegration.status }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <!-- Show configured state -->
                        <div v-if="twilioIntegration?.has_credentials && !showTwilioForm" class="space-y-4">
                            <div class="rounded-lg border p-3 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="text-muted-foreground">Environment</span>
                                    <Badge variant="outline">{{ twilioIntegration.environment }}</Badge>
                                </div>
                                <div v-if="twilioIntegration.last_error" class="mt-2 text-red-600 dark:text-red-400">
                                    {{ twilioIntegration.last_error }}
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" @click="showTwilioForm = true">
                                    Update Credentials
                                </Button>
                                <Button variant="ghost" size="sm" class="text-red-600" @click="deleteTwilio">
                                    <Trash2 class="mr-1 h-4 w-4" />
                                    Remove
                                </Button>
                            </div>
                        </div>

                        <!-- Show form -->
                        <form v-else @submit.prevent="saveTwilio" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Account SID</label>
                                <input
                                    v-model="twilioForm.account_sid"
                                    type="text"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter Twilio Account SID"
                                />
                                <p v-if="twilioForm.errors.account_sid" class="mt-1 text-sm text-red-600">{{ twilioForm.errors.account_sid }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Auth Token</label>
                                <input
                                    v-model="twilioForm.auth_token"
                                    type="password"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter Twilio Auth Token"
                                />
                                <p v-if="twilioForm.errors.auth_token" class="mt-1 text-sm text-red-600">{{ twilioForm.errors.auth_token }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Phone Number</label>
                                <input
                                    v-model="twilioForm.phone_number"
                                    type="text"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="+1234567890"
                                />
                                <p v-if="twilioForm.errors.phone_number" class="mt-1 text-sm text-red-600">{{ twilioForm.errors.phone_number }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Messaging Service SID <span class="text-muted-foreground">(optional)</span></label>
                                <input
                                    v-model="twilioForm.messaging_service_sid"
                                    type="text"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter Messaging Service SID (optional)"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Environment</label>
                                <select
                                    v-model="twilioForm.environment"
                                    class="w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option value="sandbox">Sandbox (Testing)</option>
                                    <option value="production">Production</option>
                                </select>
                            </div>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="twilioForm.processing">
                                    {{ twilioForm.processing ? 'Saving...' : 'Save Twilio' }}
                                </Button>
                                <Button
                                    v-if="twilioIntegration?.has_credentials"
                                    type="button"
                                    variant="ghost"
                                    @click="showTwilioForm = false"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <!-- GIA -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900">
                                    <Gem class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div>
                                    <CardTitle class="text-lg">GIA</CardTitle>
                                    <CardDescription>Diamond certification lookup</CardDescription>
                                </div>
                            </div>
                            <Badge
                                v-if="giaIntegration?.has_credentials"
                                :variant="getStatusBadgeVariant(giaIntegration.status)"
                            >
                                <Check v-if="giaIntegration.status === 'active'" class="mr-1 h-3 w-3" />
                                <AlertCircle v-else-if="giaIntegration.status === 'error'" class="mr-1 h-3 w-3" />
                                {{ giaIntegration.status === 'active' ? 'Connected' : giaIntegration.status }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <!-- Show configured state -->
                        <div v-if="giaIntegration?.has_credentials && !showGiaForm" class="space-y-4">
                            <div class="rounded-lg border p-3 text-sm">
                                <p class="text-muted-foreground">API credentials configured</p>
                                <div v-if="giaIntegration.last_error" class="mt-2 text-red-600 dark:text-red-400">
                                    {{ giaIntegration.last_error }}
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" @click="showGiaForm = true">
                                    Update Credentials
                                </Button>
                                <Button variant="ghost" size="sm" class="text-red-600" @click="deleteGia">
                                    <Trash2 class="mr-1 h-4 w-4" />
                                    Remove
                                </Button>
                            </div>
                        </div>

                        <!-- Show form -->
                        <form v-else @submit.prevent="saveGia" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">API Key</label>
                                <input
                                    v-model="giaForm.api_key"
                                    type="password"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter GIA API Key"
                                />
                                <p v-if="giaForm.errors.api_key" class="mt-1 text-sm text-red-600">{{ giaForm.errors.api_key }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">API URL <span class="text-muted-foreground">(optional)</span></label>
                                <input
                                    v-model="giaForm.api_url"
                                    type="text"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="https://api.gia.edu/graphql"
                                />
                                <p class="mt-1 text-xs text-muted-foreground">Default: https://api.gia.edu/graphql</p>
                            </div>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="giaForm.processing">
                                    {{ giaForm.processing ? 'Saving...' : 'Save GIA' }}
                                </Button>
                                <Button
                                    v-if="giaIntegration?.has_credentials"
                                    type="button"
                                    variant="ghost"
                                    @click="showGiaForm = false"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <!-- ShipStation -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900">
                                    <Package class="h-5 w-5 text-green-600 dark:text-green-400" />
                                </div>
                                <div>
                                    <CardTitle class="text-lg">ShipStation</CardTitle>
                                    <CardDescription>Order fulfillment & shipping</CardDescription>
                                </div>
                            </div>
                            <Badge
                                v-if="shipstationIntegration?.has_credentials"
                                :variant="getStatusBadgeVariant(shipstationIntegration.status)"
                            >
                                <Check v-if="shipstationIntegration.status === 'active'" class="mr-1 h-3 w-3" />
                                <AlertCircle v-else-if="shipstationIntegration.status === 'error'" class="mr-1 h-3 w-3" />
                                {{ shipstationIntegration.status === 'active' ? 'Connected' : shipstationIntegration.status }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <!-- Show configured state -->
                        <div v-if="shipstationIntegration?.has_credentials && !showShipstationForm" class="space-y-4">
                            <div class="rounded-lg border p-3 text-sm">
                                <p class="text-muted-foreground">API credentials configured</p>
                                <p class="text-xs text-muted-foreground mt-1">Completed orders will be sent to ShipStation automatically</p>
                                <div v-if="shipstationIntegration.last_error" class="mt-2 text-red-600 dark:text-red-400">
                                    {{ shipstationIntegration.last_error }}
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" @click="showShipstationForm = true">
                                    Update Credentials
                                </Button>
                                <Button variant="ghost" size="sm" class="text-red-600" @click="deleteShipstation">
                                    <Trash2 class="mr-1 h-4 w-4" />
                                    Remove
                                </Button>
                            </div>
                        </div>

                        <!-- Show form -->
                        <form v-else @submit.prevent="saveShipstation" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">API Key</label>
                                <input
                                    v-model="shipstationForm.api_key"
                                    type="text"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter ShipStation API Key"
                                />
                                <p v-if="shipstationForm.errors.api_key" class="mt-1 text-sm text-red-600">{{ shipstationForm.errors.api_key }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">API Secret</label>
                                <input
                                    v-model="shipstationForm.api_secret"
                                    type="password"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter ShipStation API Secret"
                                />
                                <p v-if="shipstationForm.errors.api_secret" class="mt-1 text-sm text-red-600">{{ shipstationForm.errors.api_secret }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Store ID <span class="text-muted-foreground">(optional)</span></label>
                                <input
                                    v-model="shipstationForm.store_id"
                                    type="number"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="For multi-store accounts"
                                />
                                <p class="mt-1 text-xs text-muted-foreground">Required if you have multiple stores in ShipStation</p>
                            </div>
                            <div>
                                <label class="flex items-center gap-2">
                                    <input
                                        v-model="shipstationForm.auto_sync_orders"
                                        type="checkbox"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                    />
                                    <span class="text-sm">Auto-sync confirmed orders to ShipStation</span>
                                </label>
                            </div>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="shipstationForm.processing">
                                    {{ shipstationForm.processing ? 'Saving...' : 'Save ShipStation' }}
                                </Button>
                                <Button
                                    v-if="shipstationIntegration?.has_credentials"
                                    type="button"
                                    variant="ghost"
                                    @click="showShipstationForm = false"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>

            <!-- E-commerce Platforms -->
            <div>
                <h2 class="text-lg font-semibold mb-4">E-commerce Platforms</h2>
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Card v-for="platform in platforms" :key="platform.name">
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <CardTitle class="text-lg">{{ platform.name }}</CardTitle>
                                <Badge variant="secondary">Available</Badge>
                            </div>
                            <CardDescription>
                                {{ platform.description }}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button class="w-full" variant="outline">
                                <Plug class="mr-2 h-4 w-4" />
                                Connect
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- AI Services -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <ExternalLink class="h-5 w-5" />
                        AI Services
                    </CardTitle>
                    <CardDescription>
                        Configure AI-powered features for your store
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Description Generator</h4>
                            <p class="text-sm text-muted-foreground">
                                AI-powered product descriptions optimized for each platform
                            </p>
                        </div>
                        <Badge>Configured</Badge>
                    </div>
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Auto-Categorization</h4>
                            <p class="text-sm text-muted-foreground">
                                Automatically categorize products for each marketplace
                            </p>
                        </div>
                        <Badge>Configured</Badge>
                    </div>
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Price Optimizer</h4>
                            <p class="text-sm text-muted-foreground">
                                Get AI-powered pricing suggestions based on market data
                            </p>
                        </div>
                        <Badge>Configured</Badge>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
