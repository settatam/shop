<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plug, ExternalLink, Truck, MessageSquare, Trash2, Check, AlertCircle, Gem, Package, Sparkles, Search } from 'lucide-vue-next';
import { ref, computed } from 'vue';
import { toast } from 'vue-sonner';

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

interface PlatformConnection {
    id: number;
    platform: string;
    name: string;
    shop_domain: string | null;
    status: string;
    last_error: string | null;
    last_sync_at: string | null;
    settings: Record<string, unknown> | null;
}

interface Props {
    integrations: Record<string, Integration>;
    platforms: PlatformConnection[];
}

const props = defineProps<Props>();

// Shopify form
const shopifyForm = useForm({
    name: '',
    shop_domain: '',
    access_token: '',
});

const showShopifyForm = ref(false);
const testingShopify = ref(false);

const shopifyConnection = computed(() =>
    props.platforms?.find(p => p.platform === 'shopify')
);

function saveShopify() {
    shopifyForm.post('/integrations/platforms/shopify', {
        preserveScroll: true,
        onSuccess: () => {
            showShopifyForm.value = false;
            shopifyForm.reset();
        },
    });
}

function testShopify() {
    if (!shopifyConnection.value?.id) return;
    testingShopify.value = true;
    router.post(`/integrations/platforms/${shopifyConnection.value.id}/test`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            const page = usePage();
            const flash = page.props.flash as { success?: string; error?: string } | undefined;
            if (flash?.success) {
                toast.success(flash.success);
            }
        },
        onError: (errors) => {
            const errorMessage = errors.platform || errors.connection || 'Connection test failed';
            toast.error(errorMessage);
        },
        onFinish: () => {
            testingShopify.value = false;
        },
    });
}

function deleteShopify() {
    if (!shopifyConnection.value?.id) return;
    if (!confirm('Are you sure you want to remove the Shopify connection?')) return;

    router.delete(`/integrations/platforms/${shopifyConnection.value.id}`, {
        preserveScroll: true,
    });
}

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
    api_url: 'https://api.reportresults.gia.edu',
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

// Anthropic form
const anthropicForm = useForm({
    api_key: '',
    model: 'claude-sonnet-4-20250514',
});

const showAnthropicForm = ref(false);

const anthropicIntegration = computed(() => props.integrations?.anthropic);

function saveAnthropic() {
    anthropicForm.post('/integrations/anthropic', {
        preserveScroll: true,
        onSuccess: () => {
            showAnthropicForm.value = false;
            anthropicForm.reset();
        },
    });
}

function deleteAnthropic() {
    if (!anthropicIntegration.value?.id) return;
    if (!confirm('Are you sure you want to remove the Anthropic integration?')) return;

    router.delete(`/integrations/${anthropicIntegration.value.id}`, {
        preserveScroll: true,
    });
}

// SerpAPI form
const serpapiForm = useForm({
    api_key: '',
});

const showSerpapiForm = ref(false);

const serpapiIntegration = computed(() => props.integrations?.serpapi);

function saveSerpapi() {
    serpapiForm.post('/integrations/serpapi', {
        preserveScroll: true,
        onSuccess: () => {
            showSerpapiForm.value = false;
            serpapiForm.reset();
        },
    });
}

function deleteSerpapi() {
    if (!serpapiIntegration.value?.id) return;
    if (!confirm('Are you sure you want to remove the SerpAPI integration?')) return;

    router.delete(`/integrations/${serpapiIntegration.value.id}`, {
        preserveScroll: true,
    });
}

const availablePlatforms = [
    {
        key: 'shopify',
        name: 'Shopify',
        description: 'Sync products and orders with your Shopify store',
        logo: '/images/platforms/shopify.svg',
    },
    {
        key: 'ebay',
        name: 'eBay',
        description: 'List products and manage orders on eBay',
        logo: '/images/platforms/ebay.svg',
    },
    {
        key: 'amazon',
        name: 'Amazon',
        description: 'Sell on Amazon Marketplace',
        logo: '/images/platforms/amazon.svg',
    },
    {
        key: 'etsy',
        name: 'Etsy',
        description: 'Connect your Etsy shop for handmade and vintage items',
        logo: '/images/platforms/etsy.svg',
    },
    {
        key: 'walmart',
        name: 'Walmart',
        description: 'Sell on Walmart Marketplace',
        logo: '/images/platforms/walmart.svg',
    },
    {
        key: 'woocommerce',
        name: 'WooCommerce',
        description: 'Integrate with your WooCommerce store',
        logo: '/images/platforms/woocommerce.svg',
    },
];

function getPlatformConnection(platformKey: string) {
    return props.platforms?.find(p => p.platform === platformKey);
}

function isPlatformConnected(platformKey: string) {
    const connection = getPlatformConnection(platformKey);
    return connection && connection.status === 'active';
}

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
                                    placeholder="https://api.reportresults.gia.edu"
                                />
                                <p class="mt-1 text-xs text-muted-foreground">Default: https://api.reportresults.gia.edu</p>
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

                <!-- Anthropic -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-100 dark:bg-orange-900">
                                    <Sparkles class="h-5 w-5 text-orange-600 dark:text-orange-400" />
                                </div>
                                <div>
                                    <CardTitle class="text-lg">Anthropic</CardTitle>
                                    <CardDescription>AI research & pricing analysis</CardDescription>
                                </div>
                            </div>
                            <Badge
                                v-if="anthropicIntegration?.has_credentials"
                                :variant="getStatusBadgeVariant(anthropicIntegration.status)"
                            >
                                <Check v-if="anthropicIntegration.status === 'active'" class="mr-1 h-3 w-3" />
                                <AlertCircle v-else-if="anthropicIntegration.status === 'error'" class="mr-1 h-3 w-3" />
                                {{ anthropicIntegration.status === 'active' ? 'Connected' : anthropicIntegration.status }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <!-- Show configured state -->
                        <div v-if="anthropicIntegration?.has_credentials && !showAnthropicForm" class="space-y-4">
                            <div class="rounded-lg border p-3 text-sm">
                                <p class="text-muted-foreground">API credentials configured</p>
                                <p class="text-xs text-muted-foreground mt-1">Powers AI Research on transaction items</p>
                                <div v-if="anthropicIntegration.last_error" class="mt-2 text-red-600 dark:text-red-400">
                                    {{ anthropicIntegration.last_error }}
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" @click="showAnthropicForm = true">
                                    Update Credentials
                                </Button>
                                <Button variant="ghost" size="sm" class="text-red-600" @click="deleteAnthropic">
                                    <Trash2 class="mr-1 h-4 w-4" />
                                    Remove
                                </Button>
                            </div>
                        </div>

                        <!-- Show form -->
                        <form v-else @submit.prevent="saveAnthropic" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">API Key</label>
                                <input
                                    v-model="anthropicForm.api_key"
                                    type="password"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="sk-ant-..."
                                />
                                <p v-if="anthropicForm.errors.api_key" class="mt-1 text-sm text-red-600">{{ anthropicForm.errors.api_key }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">Get your API key from <a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-indigo-600 hover:underline">console.anthropic.com</a></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Model <span class="text-muted-foreground">(optional)</span></label>
                                <select
                                    v-model="anthropicForm.model"
                                    class="w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option value="claude-sonnet-4-20250514">Claude Sonnet 4 (Recommended)</option>
                                    <option value="claude-opus-4-20250514">Claude Opus 4 (Most Capable)</option>
                                    <option value="claude-3-5-haiku-20241022">Claude 3.5 Haiku (Fastest)</option>
                                </select>
                                <p class="mt-1 text-xs text-muted-foreground">Sonnet offers the best balance of speed and quality</p>
                            </div>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="anthropicForm.processing">
                                    {{ anthropicForm.processing ? 'Saving...' : 'Save Anthropic' }}
                                </Button>
                                <Button
                                    v-if="anthropicIntegration?.has_credentials"
                                    type="button"
                                    variant="ghost"
                                    @click="showAnthropicForm = false"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <!-- SerpAPI -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-cyan-100 dark:bg-cyan-900">
                                    <Search class="h-5 w-5 text-cyan-600 dark:text-cyan-400" />
                                </div>
                                <div>
                                    <CardTitle class="text-lg">SerpAPI</CardTitle>
                                    <CardDescription>Web price search & comparison</CardDescription>
                                </div>
                            </div>
                            <Badge
                                v-if="serpapiIntegration?.has_credentials"
                                :variant="getStatusBadgeVariant(serpapiIntegration.status)"
                            >
                                <Check v-if="serpapiIntegration.status === 'active'" class="mr-1 h-3 w-3" />
                                <AlertCircle v-else-if="serpapiIntegration.status === 'error'" class="mr-1 h-3 w-3" />
                                {{ serpapiIntegration.status === 'active' ? 'Connected' : serpapiIntegration.status }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <!-- Show configured state -->
                        <div v-if="serpapiIntegration?.has_credentials && !showSerpapiForm" class="space-y-4">
                            <div class="rounded-lg border p-3 text-sm">
                                <p class="text-muted-foreground">API credentials configured</p>
                                <p class="text-xs text-muted-foreground mt-1">Powers web price search from Google Shopping & eBay</p>
                                <div v-if="serpapiIntegration.last_error" class="mt-2 text-red-600 dark:text-red-400">
                                    {{ serpapiIntegration.last_error }}
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" @click="showSerpapiForm = true">
                                    Update Credentials
                                </Button>
                                <Button variant="ghost" size="sm" class="text-red-600" @click="deleteSerpapi">
                                    <Trash2 class="mr-1 h-4 w-4" />
                                    Remove
                                </Button>
                            </div>
                        </div>

                        <!-- Show form -->
                        <form v-else @submit.prevent="saveSerpapi" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">API Key</label>
                                <input
                                    v-model="serpapiForm.api_key"
                                    type="password"
                                    class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="Enter SerpAPI Key"
                                />
                                <p v-if="serpapiForm.errors.api_key" class="mt-1 text-sm text-red-600">{{ serpapiForm.errors.api_key }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">Get your API key from <a href="https://serpapi.com/manage-api-key" target="_blank" class="text-indigo-600 hover:underline">serpapi.com</a></p>
                            </div>
                            <div class="flex gap-2">
                                <Button type="submit" :disabled="serpapiForm.processing">
                                    {{ serpapiForm.processing ? 'Saving...' : 'Save SerpAPI' }}
                                </Button>
                                <Button
                                    v-if="serpapiIntegration?.has_credentials"
                                    type="button"
                                    variant="ghost"
                                    @click="showSerpapiForm = false"
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
                    <!-- Shopify -->
                    <Card>
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900">
                                        <svg class="h-5 w-5 text-green-600 dark:text-green-400" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M15.337 23.979l7.216-1.561s-2.604-17.613-2.625-17.756c-.021-.143-.163-.227-.285-.248s-2.403-.174-2.403-.174-.903-.89-1.205-1.191c-.052-.052-.118-.078-.185-.078l-1.27 19.008zm-1.238-18.917c-.092 0-.21.033-.312.03-.102-.003-.235.022-.235.022l-.393 1.209s-.783-.342-1.736-.342c-1.408 0-1.479.884-1.479 1.105 0 1.212 3.158 1.678 3.158 4.527 0 2.239-1.42 3.682-3.333 3.682-2.293 0-3.466-1.427-3.466-1.427l.614-2.03s1.217.878 2.248.878c.672 0 .948-.53.948-.917 0-1.586-2.593-1.658-2.593-4.26 0-2.19 1.57-4.312 4.749-4.312 1.227 0 1.83.352 1.83.352l-.727 2.232"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <CardTitle class="text-lg">Shopify</CardTitle>
                                        <CardDescription>Sync products and orders</CardDescription>
                                    </div>
                                </div>
                                <Badge
                                    v-if="shopifyConnection"
                                    :variant="getStatusBadgeVariant(shopifyConnection.status)"
                                >
                                    <Check v-if="shopifyConnection.status === 'active'" class="mr-1 h-3 w-3" />
                                    <AlertCircle v-else-if="shopifyConnection.status === 'error'" class="mr-1 h-3 w-3" />
                                    {{ shopifyConnection.status === 'active' ? 'Connected' : shopifyConnection.status }}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <!-- Show connected state -->
                            <div v-if="shopifyConnection && !showShopifyForm" class="space-y-4">
                                <div class="rounded-lg border p-3 text-sm">
                                    <div class="flex items-center justify-between">
                                        <span class="text-muted-foreground">Store</span>
                                        <span class="font-medium">{{ shopifyConnection.name }}</span>
                                    </div>
                                    <div class="flex items-center justify-between mt-1">
                                        <span class="text-muted-foreground">Domain</span>
                                        <span class="text-xs">{{ shopifyConnection.shop_domain }}</span>
                                    </div>
                                    <div v-if="shopifyConnection.last_error" class="mt-2 text-red-600 dark:text-red-400">
                                        {{ shopifyConnection.last_error }}
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <Button variant="outline" size="sm" @click="testShopify" :disabled="testingShopify">
                                        {{ testingShopify ? 'Testing...' : 'Test Connection' }}
                                    </Button>
                                    <Button variant="outline" size="sm" @click="showShopifyForm = true" :disabled="testingShopify">
                                        Update
                                    </Button>
                                    <Button variant="ghost" size="sm" class="text-red-600" @click="deleteShopify">
                                        <Trash2 class="mr-1 h-4 w-4" />
                                        Remove
                                    </Button>
                                </div>
                            </div>

                            <!-- Show form -->
                            <form v-else @submit.prevent="saveShopify" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1">Connection Name</label>
                                    <input
                                        v-model="shopifyForm.name"
                                        type="text"
                                        class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        placeholder="My Shopify Store"
                                    />
                                    <p v-if="shopifyForm.errors.name" class="mt-1 text-sm text-red-600">{{ shopifyForm.errors.name }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">Shop Domain</label>
                                    <input
                                        v-model="shopifyForm.shop_domain"
                                        type="text"
                                        class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        placeholder="yourstore.myshopify.com"
                                    />
                                    <p v-if="shopifyForm.errors.shop_domain" class="mt-1 text-sm text-red-600">{{ shopifyForm.errors.shop_domain }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1">Admin API Access Token</label>
                                    <input
                                        v-model="shopifyForm.access_token"
                                        type="password"
                                        class="w-full rounded-md border-0 px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                        placeholder="shpat_xxxxx"
                                    />
                                    <p v-if="shopifyForm.errors.access_token" class="mt-1 text-sm text-red-600">{{ shopifyForm.errors.access_token }}</p>
                                    <p class="mt-1 text-xs text-muted-foreground">Get from Shopify Admin → Settings → Apps → Develop apps</p>
                                </div>
                                <div class="flex gap-2">
                                    <Button type="submit" :disabled="shopifyForm.processing">
                                        {{ shopifyForm.processing ? 'Connecting...' : 'Connect Shopify' }}
                                    </Button>
                                    <Button
                                        v-if="shopifyConnection"
                                        type="button"
                                        variant="ghost"
                                        @click="showShopifyForm = false"
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    <!-- Other platforms (coming soon) -->
                    <Card v-for="platform in availablePlatforms.filter(p => p.key !== 'shopify')" :key="platform.key">
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <CardTitle class="text-lg">{{ platform.name }}</CardTitle>
                                <Badge variant="secondary">Coming Soon</Badge>
                            </div>
                            <CardDescription>
                                {{ platform.description }}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button class="w-full" variant="outline" disabled>
                                <Plug class="mr-2 h-4 w-4" />
                                Connect
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- AI Features (powered by Anthropic) -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Sparkles class="h-5 w-5" />
                        AI Features
                    </CardTitle>
                    <CardDescription>
                        AI-powered features for your store (requires Anthropic integration)
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Item Research</h4>
                            <p class="text-sm text-muted-foreground">
                                AI-powered market research, pricing analysis, and item descriptions
                            </p>
                        </div>
                        <Badge v-if="anthropicIntegration?.has_credentials" variant="default">
                            <Check class="mr-1 h-3 w-3" />
                            Active
                        </Badge>
                        <Badge v-else variant="secondary">Requires Anthropic</Badge>
                    </div>
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Description Generator</h4>
                            <p class="text-sm text-muted-foreground">
                                AI-powered product descriptions optimized for each platform
                            </p>
                        </div>
                        <Badge v-if="anthropicIntegration?.has_credentials" variant="default">
                            <Check class="mr-1 h-3 w-3" />
                            Active
                        </Badge>
                        <Badge v-else variant="secondary">Requires Anthropic</Badge>
                    </div>
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Price Optimizer</h4>
                            <p class="text-sm text-muted-foreground">
                                Get AI-powered pricing suggestions based on market data
                            </p>
                        </div>
                        <Badge v-if="anthropicIntegration?.has_credentials" variant="default">
                            <Check class="mr-1 h-3 w-3" />
                            Active
                        </Badge>
                        <Badge v-else variant="secondary">Requires Anthropic</Badge>
                    </div>
                    <div class="flex items-center justify-between p-4 border rounded-lg">
                        <div>
                            <h4 class="font-medium">Web Price Search</h4>
                            <p class="text-sm text-muted-foreground">
                                Search Google Shopping & eBay for comparable prices
                            </p>
                        </div>
                        <Badge v-if="serpapiIntegration?.has_credentials" variant="default">
                            <Check class="mr-1 h-3 w-3" />
                            Active
                        </Badge>
                        <Badge v-else variant="secondary">Requires SerpAPI</Badge>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
