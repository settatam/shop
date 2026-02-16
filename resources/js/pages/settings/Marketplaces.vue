<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
    DialogDescription,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    PlusIcon,
    EllipsisVerticalIcon,
    ArrowPathIcon,
    TrashIcon,
    PencilIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    XCircleIcon,
    LinkIcon,
    ArrowTopRightOnSquareIcon,
} from '@heroicons/vue/20/solid';
import axios from 'axios';

interface Connection {
    id: number;
    platform: string;
    platform_label: string;
    name: string;
    shop_domain: string | null;
    external_store_id: string | null;
    status: string;
    is_connected: boolean;
    last_sync_at: string | null;
    last_error: string | null;
    created_at: string;
}

interface AvailablePlatform {
    value: string;
    label: string;
    description: string;
    auth_type: 'oauth' | 'credentials';
    requires_credentials: boolean;
}

interface Props {
    connections: Connection[];
    availablePlatforms: AvailablePlatform[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Marketplaces', href: '/settings/marketplaces' },
];

// Platform icons
const platformIcons: Record<string, string> = {
    shopify: '/images/platforms/shopify.svg',
    ebay: '/images/platforms/ebay.svg',
    amazon: '/images/platforms/amazon.svg',
    etsy: '/images/platforms/etsy.svg',
    walmart: '/images/platforms/walmart.svg',
    woocommerce: '/images/platforms/woocommerce.svg',
};

// State
const showAddModal = ref(false);
const showCredentialsModal = ref(false);
const showEditModal = ref(false);
const showDeleteDialog = ref(false);
const selectedPlatform = ref<AvailablePlatform | null>(null);
const selectedConnection = ref<Connection | null>(null);
const testing = ref<number | null>(null);
const syncing = ref<number | null>(null);

// Forms
const connectForm = useForm({
    name: '',
});

const credentialsForm = useForm({
    platform: '',
    name: '',
    shop_domain: '',
    credentials: {
        api_key: '',
        api_secret: '',
        access_token: '',
        seller_id: '',
        marketplace_id: '',
    },
});

const editForm = useForm({
    name: '',
    shop_domain: '',
});

// Grouped connections by platform
const connectionsByPlatform = computed(() => {
    const grouped: Record<string, Connection[]> = {};
    for (const conn of props.connections) {
        if (!grouped[conn.platform]) {
            grouped[conn.platform] = [];
        }
        grouped[conn.platform].push(conn);
    }
    return grouped;
});

function getStatusBadge(connection: Connection) {
    if (connection.is_connected && connection.status === 'active') {
        return { class: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400', label: 'Connected', icon: CheckCircleIcon };
    }
    if (connection.status === 'error') {
        return { class: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400', label: 'Error', icon: XCircleIcon };
    }
    return { class: 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400', label: 'Pending', icon: ExclamationCircleIcon };
}

function openAddModal() {
    showAddModal.value = true;
}

function selectPlatform(platform: AvailablePlatform) {
    selectedPlatform.value = platform;
    connectForm.name = platform.label;

    if (platform.requires_credentials) {
        // Show credentials modal
        credentialsForm.platform = platform.value;
        credentialsForm.name = platform.label;
        credentialsForm.shop_domain = '';
        credentialsForm.credentials = {
            api_key: '',
            api_secret: '',
            access_token: '',
            seller_id: '',
            marketplace_id: '',
        };
        showAddModal.value = false;
        showCredentialsModal.value = true;
    } else {
        // OAuth flow - redirect
        showAddModal.value = false;
        window.location.href = `/settings/marketplaces/connect/${platform.value}?name=${encodeURIComponent(connectForm.name)}`;
    }
}

function submitCredentials() {
    credentialsForm.post('/settings/marketplaces', {
        onSuccess: () => {
            showCredentialsModal.value = false;
            credentialsForm.reset();
        },
    });
}

function openEditModal(connection: Connection) {
    selectedConnection.value = connection;
    editForm.name = connection.name;
    editForm.shop_domain = connection.shop_domain || '';
    showEditModal.value = true;
}

function submitEdit() {
    if (!selectedConnection.value) return;

    editForm.put(`/settings/marketplaces/${selectedConnection.value.id}`, {
        onSuccess: () => {
            showEditModal.value = false;
            selectedConnection.value = null;
        },
    });
}

function openDeleteDialog(connection: Connection) {
    selectedConnection.value = connection;
    showDeleteDialog.value = true;
}

function confirmDelete() {
    if (!selectedConnection.value) return;

    router.delete(`/settings/marketplaces/${selectedConnection.value.id}`, {
        onSuccess: () => {
            showDeleteDialog.value = false;
            selectedConnection.value = null;
        },
    });
}

async function testConnection(connection: Connection) {
    testing.value = connection.id;
    try {
        await axios.post(`/settings/marketplaces/${connection.id}/test`);
        router.reload({ only: ['connections'] });
    } catch (error) {
        console.error('Test failed:', error);
    } finally {
        testing.value = null;
    }
}

async function syncConnection(connection: Connection) {
    syncing.value = connection.id;
    try {
        await axios.post(`/settings/marketplaces/${connection.id}/sync`);
        router.reload({ only: ['connections'] });
    } catch (error) {
        console.error('Sync failed:', error);
    } finally {
        syncing.value = null;
    }
}

function formatDate(dateString: string | null) {
    if (!dateString) return 'Never';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Marketplace Integrations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Marketplace Integrations</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Connect your store to external marketplaces to sync products and orders
                    </p>
                </div>
                <Button @click="openAddModal">
                    <PlusIcon class="h-4 w-4 mr-2" />
                    Add Marketplace
                </Button>
            </div>

            <!-- Connected Marketplaces -->
            <div v-if="connections.length > 0" class="space-y-6 mb-8">
                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Connected Marketplaces</h2>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="connection in connections"
                        :key="connection.id"
                        class="relative rounded-lg bg-white p-6 shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div class="h-12 w-12 shrink-0 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                    <img
                                        v-if="platformIcons[connection.platform]"
                                        :src="platformIcons[connection.platform]"
                                        :alt="connection.platform_label"
                                        class="h-8 w-8"
                                    />
                                    <span v-else class="text-lg font-bold text-gray-400">
                                        {{ connection.platform_label.charAt(0) }}
                                    </span>
                                </div>
                                <div>
                                    <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ connection.name }}
                                    </h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ connection.platform_label }}
                                    </p>
                                </div>
                            </div>

                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button variant="ghost" size="sm" class="h-8 w-8 p-0">
                                        <EllipsisVerticalIcon class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem @click="testConnection(connection)">
                                        <LinkIcon class="h-4 w-4 mr-2" />
                                        Test Connection
                                    </DropdownMenuItem>
                                    <DropdownMenuItem @click="syncConnection(connection)">
                                        <ArrowPathIcon class="h-4 w-4 mr-2" />
                                        Sync Now
                                    </DropdownMenuItem>
                                    <DropdownMenuItem @click="openEditModal(connection)">
                                        <PencilIcon class="h-4 w-4 mr-2" />
                                        Edit
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        class="text-red-600 dark:text-red-400"
                                        @click="openDeleteDialog(connection)"
                                    >
                                        <TrashIcon class="h-4 w-4 mr-2" />
                                        Disconnect
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>

                        <div class="mt-4 space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Status</span>
                                <Badge
                                    :class="['text-xs ring-1 ring-inset', getStatusBadge(connection).class]"
                                >
                                    <component :is="getStatusBadge(connection).icon" class="h-3 w-3 mr-1" />
                                    {{ getStatusBadge(connection).label }}
                                </Badge>
                            </div>

                            <div v-if="connection.shop_domain" class="flex items-center justify-between">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Domain</span>
                                <a
                                    :href="`https://${connection.shop_domain}`"
                                    target="_blank"
                                    class="text-xs text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 flex items-center gap-1"
                                >
                                    {{ connection.shop_domain }}
                                    <ArrowTopRightOnSquareIcon class="h-3 w-3" />
                                </a>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Last Synced</span>
                                <span class="text-xs text-gray-700 dark:text-gray-300">
                                    {{ formatDate(connection.last_sync_at) }}
                                </span>
                            </div>

                            <div v-if="connection.last_error" class="mt-2 p-2 rounded bg-red-50 dark:bg-red-900/20">
                                <p class="text-xs text-red-700 dark:text-red-400 line-clamp-2">
                                    {{ connection.last_error }}
                                </p>
                            </div>
                        </div>

                        <!-- Loading overlay -->
                        <div
                            v-if="testing === connection.id || syncing === connection.id"
                            class="absolute inset-0 rounded-lg bg-white/80 dark:bg-gray-800/80 flex items-center justify-center"
                        >
                            <ArrowPathIcon class="h-6 w-6 text-indigo-600 animate-spin" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Available Platforms -->
            <div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Available Platforms</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                    Click on a platform to connect a new account. You can connect multiple accounts per platform.
                </p>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                    <button
                        v-for="platform in availablePlatforms"
                        :key="platform.value"
                        type="button"
                        class="relative rounded-lg bg-white p-4 shadow ring-1 ring-black/5 hover:ring-indigo-500 hover:shadow-md transition-all dark:bg-gray-800 dark:ring-white/10 dark:hover:ring-indigo-500 text-left"
                        @click="selectPlatform(platform)"
                    >
                        <div class="flex flex-col items-center text-center">
                            <div class="h-16 w-16 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-3">
                                <img
                                    v-if="platformIcons[platform.value]"
                                    :src="platformIcons[platform.value]"
                                    :alt="platform.label"
                                    class="h-10 w-10"
                                />
                                <span v-else class="text-2xl font-bold text-gray-400">
                                    {{ platform.label.charAt(0) }}
                                </span>
                            </div>
                            <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ platform.label }}
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ platform.auth_type === 'oauth' ? 'OAuth' : 'API Key' }}
                            </p>
                        </div>

                        <!-- Connected count badge -->
                        <div
                            v-if="connectionsByPlatform[platform.value]?.length"
                            class="absolute -top-2 -right-2 h-5 w-5 rounded-full bg-indigo-600 text-white text-xs font-medium flex items-center justify-center"
                        >
                            {{ connectionsByPlatform[platform.value].length }}
                        </div>
                    </button>
                </div>
            </div>
        </div>

        <!-- Add Marketplace Modal -->
        <Dialog :open="showAddModal" @update:open="showAddModal = $event">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add Marketplace</DialogTitle>
                    <DialogDescription>
                        Select a platform to connect to your store.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid grid-cols-2 gap-3 py-4">
                    <button
                        v-for="platform in availablePlatforms"
                        :key="platform.value"
                        type="button"
                        class="flex items-center gap-3 rounded-lg border border-gray-200 p-3 hover:border-indigo-500 hover:bg-indigo-50/50 transition-all dark:border-gray-700 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20"
                        @click="selectPlatform(platform)"
                    >
                        <div class="h-10 w-10 shrink-0 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                            <img
                                v-if="platformIcons[platform.value]"
                                :src="platformIcons[platform.value]"
                                :alt="platform.label"
                                class="h-6 w-6"
                            />
                            <span v-else class="text-lg font-bold text-gray-400">
                                {{ platform.label.charAt(0) }}
                            </span>
                        </div>
                        <div class="text-left">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ platform.label }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ platform.auth_type === 'oauth' ? 'OAuth Login' : 'API Credentials' }}
                            </p>
                        </div>
                    </button>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Credentials Modal (for non-OAuth platforms) -->
        <Dialog :open="showCredentialsModal" @update:open="showCredentialsModal = $event">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Connect {{ selectedPlatform?.label }}</DialogTitle>
                    <DialogDescription>
                        Enter your API credentials to connect this marketplace.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="submitCredentials" class="space-y-4 py-4">
                    <div class="space-y-2">
                        <Label for="cred-name">Account Name</Label>
                        <Input
                            id="cred-name"
                            v-model="credentialsForm.name"
                            placeholder="My Store"
                            required
                        />
                    </div>

                    <div v-if="selectedPlatform?.value === 'woocommerce'" class="space-y-2">
                        <Label for="shop-domain">Store URL</Label>
                        <Input
                            id="shop-domain"
                            v-model="credentialsForm.shop_domain"
                            placeholder="mystore.com"
                            required
                        />
                    </div>

                    <div v-if="selectedPlatform?.value === 'walmart'" class="space-y-4">
                        <div class="space-y-2">
                            <Label for="seller-id">Seller ID</Label>
                            <Input
                                id="seller-id"
                                v-model="credentialsForm.credentials.seller_id"
                                required
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="api-key">Client ID</Label>
                            <Input
                                id="api-key"
                                v-model="credentialsForm.credentials.api_key"
                                required
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="api-secret">Client Secret</Label>
                            <Input
                                id="api-secret"
                                v-model="credentialsForm.credentials.api_secret"
                                type="password"
                                required
                            />
                        </div>
                    </div>

                    <div v-if="selectedPlatform?.value === 'woocommerce'" class="space-y-4">
                        <div class="space-y-2">
                            <Label for="woo-key">Consumer Key</Label>
                            <Input
                                id="woo-key"
                                v-model="credentialsForm.credentials.api_key"
                                placeholder="ck_..."
                                required
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="woo-secret">Consumer Secret</Label>
                            <Input
                                id="woo-secret"
                                v-model="credentialsForm.credentials.api_secret"
                                type="password"
                                placeholder="cs_..."
                                required
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showCredentialsModal = false">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="credentialsForm.processing">
                            {{ credentialsForm.processing ? 'Connecting...' : 'Connect' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Edit Modal -->
        <Dialog :open="showEditModal" @update:open="showEditModal = $event">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit {{ selectedConnection?.name }}</DialogTitle>
                </DialogHeader>

                <form @submit.prevent="submitEdit" class="space-y-4 py-4">
                    <div class="space-y-2">
                        <Label for="edit-name">Account Name</Label>
                        <Input
                            id="edit-name"
                            v-model="editForm.name"
                            required
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="edit-domain">Store Domain</Label>
                        <Input
                            id="edit-domain"
                            v-model="editForm.shop_domain"
                            placeholder="mystore.myshopify.com"
                        />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="showEditModal = false">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="editForm.processing">
                            {{ editForm.processing ? 'Saving...' : 'Save Changes' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Delete Confirmation -->
        <Dialog :open="showDeleteDialog" @update:open="showDeleteDialog = $event">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Disconnect {{ selectedConnection?.name }}?</DialogTitle>
                    <DialogDescription>
                        This will disconnect this marketplace from your store. Any synced data will remain, but new syncs will stop.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="showDeleteDialog = false">Cancel</Button>
                    <Button variant="destructive" @click="confirmDelete">
                        Disconnect
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
