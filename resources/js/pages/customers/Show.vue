<script setup lang="ts">
import ActivityTimeline from '@/components/ActivityTimeline.vue';
import { NotesSection } from '@/components/notes';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    ArrowLeftIcon,
    PencilIcon,
    CheckIcon,
    XMarkIcon,
    PlusIcon,
    DocumentArrowUpIcon,
    TrashIcon,
    EyeIcon,
} from '@heroicons/vue/20/solid';
import {
    UserIcon,
    EnvelopeIcon,
    PhoneIcon,
    MapPinIcon,
    BuildingOfficeIcon,
    CalendarIcon,
    DocumentIcon,
    HomeIcon,
} from '@heroicons/vue/24/outline';
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';

interface LeadSource {
    id: number;
    name: string;
    slug: string;
}

interface Address {
    id: number;
    nickname: string | null;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    company: string | null;
    address: string;
    address2: string | null;
    city: string;
    state_id: string | null;
    zip: string;
    phone: string | null;
    type: string;
    is_default: boolean;
    is_shipping: boolean;
    is_billing: boolean;
    one_line_address: string;
    formatted_address: string;
}

interface AddressType {
    value: string;
    label: string;
}

interface CustomerDocument {
    id: number;
    type: string;
    path: string;
    url: string;
    original_filename: string | null;
    mime_type: string | null;
    size: number | null;
    notes: string | null;
    created_at: string;
}

interface TransactionItem {
    id: number;
    title: string;
    buy_price: number | null;
}

interface Transaction {
    id: number;
    transaction_number: string;
    status: string;
    type: string;
    final_offer: number | null;
    created_at: string;
    items: TransactionItem[];
}

interface Order {
    id: number;
    invoice_number: string | null;
    status: string;
    total: number | null;
    created_at: string;
    items: any[];
}

interface DocumentType {
    value: string;
    label: string;
}

interface Customer {
    id: number;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone_number: string | null;
    company_name: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    lead_source_id: number | null;
    notes: string | null;
    created_at: string;
    lead_source: LeadSource | null;
    documents: CustomerDocument[];
    addresses: Address[];
    transactions: Transaction[];
    orders: Order[];
}

interface Stats {
    total_buys: number;
    total_sales: number;
    total_buy_value: number;
    total_sales_value: number;
    store_credit_balance: number;
    last_activity: string | null;
}

interface ActivityItem {
    id: number;
    activity: string;
    description: string;
    user: { name: string } | null;
    changes: Record<string, { old: string; new: string }> | null;
    time: string;
    created_at: string;
    icon: string;
    color: string;
}

interface ActivityDay {
    date: string;
    dateTime: string;
    items: ActivityItem[];
}

interface NoteEntry {
    id: number;
    content: string;
    user: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    customer: Customer;
    stats: Stats;
    leadSources: LeadSource[];
    documentTypes: DocumentType[];
    addressTypes: AddressType[];
    activityLogs?: ActivityDay[];
    noteEntries?: NoteEntry[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/customers' },
    { title: props.customer.full_name || 'Customer', href: `/customers/${props.customer.id}` },
];

// Tab state for transactions
const activeTab = ref<'buys' | 'sales'>('buys');

// Edit modal
const isEditing = ref(false);
const editForm = useForm({
    first_name: props.customer.first_name || '',
    last_name: props.customer.last_name || '',
    email: props.customer.email || '',
    phone_number: props.customer.phone_number || '',
    company_name: props.customer.company_name || '',
    address: props.customer.address || '',
    city: props.customer.city || '',
    state: props.customer.state || '',
    postal_code: props.customer.postal_code || '',
    lead_source_id: props.customer.lead_source_id || '',
    notes: props.customer.notes || '',
});

const saveCustomer = () => {
    editForm.put(`/customers/${props.customer.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            isEditing.value = false;
        },
    });
};

// Lead source modal
const showLeadSourceModal = ref(false);
const newLeadSourceForm = useForm({
    name: '',
    description: '',
});

const createLeadSource = () => {
    newLeadSourceForm.post('/lead-sources', {
        preserveScroll: true,
        onSuccess: () => {
            showLeadSourceModal.value = false;
            newLeadSourceForm.reset();
            router.reload({ only: ['leadSources'] });
        },
    });
};

// Quick lead source update
const updateLeadSource = (leadSourceId: string) => {
    router.put(`/customers/${props.customer.id}`, {
        lead_source_id: leadSourceId || null,
    }, {
        preserveScroll: true,
    });
};

// Document upload modal
const showDocumentModal = ref(false);
const documentFile = ref<File | null>(null);
const documentForm = useForm({
    document: null as File | null,
    type: 'id_front',
    notes: '',
});

const onFileChange = (e: Event) => {
    const target = e.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        documentFile.value = target.files[0];
        documentForm.document = target.files[0];
    }
};

const uploadDocument = () => {
    if (!documentForm.document) return;

    documentForm.post(`/customers/${props.customer.id}/documents`, {
        preserveScroll: true,
        onSuccess: () => {
            showDocumentModal.value = false;
            documentForm.reset();
            documentFile.value = null;
        },
    });
};

const deleteDocument = (documentId: number) => {
    if (confirm('Are you sure you want to delete this document?')) {
        router.delete(`/customers/${props.customer.id}/documents/${documentId}`, {
            preserveScroll: true,
        });
    }
};

// Preview modal
const showPreviewModal = ref(false);
const previewDocument = ref<CustomerDocument | null>(null);

const openPreview = (doc: CustomerDocument) => {
    previewDocument.value = doc;
    showPreviewModal.value = true;
};

// Address modal
const showAddressModal = ref(false);
const editingAddress = ref<Address | null>(null);
const addressForm = useForm({
    nickname: '',
    first_name: '',
    last_name: '',
    company: '',
    address: '',
    address2: '',
    city: '',
    state: '',
    zip: '',
    phone: '',
    type: 'home',
    is_default: false,
});

const openAddressModal = (address?: Address) => {
    if (address) {
        editingAddress.value = address;
        addressForm.nickname = address.nickname || '';
        addressForm.first_name = address.first_name || '';
        addressForm.last_name = address.last_name || '';
        addressForm.company = address.company || '';
        addressForm.address = address.address || '';
        addressForm.address2 = address.address2 || '';
        addressForm.city = address.city || '';
        addressForm.state = address.state_id || '';
        addressForm.zip = address.zip || '';
        addressForm.phone = address.phone || '';
        addressForm.type = address.type || 'home';
        addressForm.is_default = address.is_default || false;
    } else {
        editingAddress.value = null;
        addressForm.reset();
        addressForm.type = 'home';
    }
    showAddressModal.value = true;
};

const saveAddress = () => {
    if (editingAddress.value) {
        addressForm.put(`/customers/${props.customer.id}/addresses/${editingAddress.value.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                showAddressModal.value = false;
                addressForm.reset();
                editingAddress.value = null;
            },
        });
    } else {
        addressForm.post(`/customers/${props.customer.id}/addresses`, {
            preserveScroll: true,
            onSuccess: () => {
                showAddressModal.value = false;
                addressForm.reset();
            },
        });
    }
};

const deleteAddress = (addressId: number) => {
    if (confirm('Are you sure you want to delete this address?')) {
        router.delete(`/customers/${props.customer.id}/addresses/${addressId}`, {
            preserveScroll: true,
        });
    }
};

const getAddressTypeLabel = (type: string) => {
    const found = props.addressTypes.find(t => t.value === type);
    return found?.label || type;
};

// Formatting helpers
const formatCurrency = (value: number | null) => {
    if (value === null || value === undefined) return '$0.00';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
};

const formatDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const formatDateTime = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const formatFileSize = (bytes: number | null) => {
    if (!bytes) return '-';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

// Status colors for transactions
const statusColors: Record<string, string> = {
    pending: 'bg-yellow-50 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400',
    offer_given: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400',
    offer_accepted: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400',
    offer_declined: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400',
    payment_processed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
    cancelled: 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-500/10 dark:text-gray-400',
};

const getDocumentsByType = (type: string) => {
    return props.customer.documents.filter(doc => doc.type === type);
};

const idFront = computed(() => getDocumentsByType('id_front')[0] || null);
const idBack = computed(() => getDocumentsByType('id_back')[0] || null);
</script>

<template>
    <Head :title="customer.full_name || 'Customer'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-4">
                    <Link
                        href="/customers"
                        class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700"
                    >
                        <ArrowLeftIcon class="size-5" />
                    </Link>
                    <div class="flex items-center gap-4">
                        <div class="flex size-14 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900">
                            <UserIcon class="size-7 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div>
                            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                                {{ customer.full_name || 'Unnamed Customer' }}
                            </h1>
                            <div class="flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                                <span v-if="customer.email" class="flex items-center gap-1">
                                    <EnvelopeIcon class="size-4" />
                                    {{ customer.email }}
                                </span>
                                <span v-if="customer.phone_number" class="flex items-center gap-1">
                                    <PhoneIcon class="size-4" />
                                    {{ customer.phone_number }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-x-1.5 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    @click="isEditing = true"
                >
                    <PencilIcon class="-ml-0.5 size-5" />
                    Edit
                </button>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Customer Details -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Customer Details</h3>
                            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">First Name</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ customer.first_name || '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Last Name</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ customer.last_name || '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Email</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ customer.email || '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Phone</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ customer.phone_number || '-' }}</dd>
                                </div>
                                <div v-if="customer.company_name">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Company</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">{{ customer.company_name }}</dd>
                                </div>
                                <div v-if="customer.address || customer.city">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Address</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                                        <span v-if="customer.address">{{ customer.address }}<br /></span>
                                        <span v-if="customer.city">{{ customer.city }}</span>
                                        <span v-if="customer.postal_code">, {{ customer.postal_code }}</span>
                                    </dd>
                                </div>
                                <div v-if="customer.notes" class="sm:col-span-2">
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Notes</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-white whitespace-pre-wrap">{{ customer.notes }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- ID Documents -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">ID Documents</h3>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="showDocumentModal = true"
                                >
                                    <DocumentArrowUpIcon class="-ml-0.5 size-4" />
                                    Upload
                                </button>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <!-- ID Front -->
                                <div class="relative rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">ID Front</p>
                                    <div v-if="idFront" class="group relative">
                                        <img
                                            v-if="idFront.mime_type?.startsWith('image/')"
                                            :src="idFront.url"
                                            :alt="idFront.original_filename || 'ID Front'"
                                            class="h-32 w-full rounded object-cover cursor-pointer"
                                            @click="openPreview(idFront)"
                                        />
                                        <div
                                            v-else
                                            class="flex h-32 w-full items-center justify-center rounded bg-gray-100 dark:bg-gray-700 cursor-pointer"
                                            @click="openPreview(idFront)"
                                        >
                                            <DocumentIcon class="size-10 text-gray-400" />
                                        </div>
                                        <div class="absolute inset-0 flex items-center justify-center gap-2 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity rounded">
                                            <button
                                                type="button"
                                                class="rounded-full bg-white p-2 text-gray-700 hover:bg-gray-100"
                                                @click="openPreview(idFront)"
                                            >
                                                <EyeIcon class="size-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-full bg-white p-2 text-red-600 hover:bg-red-50"
                                                @click="deleteDocument(idFront.id)"
                                            >
                                                <TrashIcon class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <div v-else class="flex h-32 w-full items-center justify-center rounded border-2 border-dashed border-gray-300 dark:border-gray-600">
                                        <p class="text-sm text-gray-400">No ID front uploaded</p>
                                    </div>
                                </div>

                                <!-- ID Back -->
                                <div class="relative rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">ID Back</p>
                                    <div v-if="idBack" class="group relative">
                                        <img
                                            v-if="idBack.mime_type?.startsWith('image/')"
                                            :src="idBack.url"
                                            :alt="idBack.original_filename || 'ID Back'"
                                            class="h-32 w-full rounded object-cover cursor-pointer"
                                            @click="openPreview(idBack)"
                                        />
                                        <div
                                            v-else
                                            class="flex h-32 w-full items-center justify-center rounded bg-gray-100 dark:bg-gray-700 cursor-pointer"
                                            @click="openPreview(idBack)"
                                        >
                                            <DocumentIcon class="size-10 text-gray-400" />
                                        </div>
                                        <div class="absolute inset-0 flex items-center justify-center gap-2 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity rounded">
                                            <button
                                                type="button"
                                                class="rounded-full bg-white p-2 text-gray-700 hover:bg-gray-100"
                                                @click="openPreview(idBack)"
                                            >
                                                <EyeIcon class="size-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-full bg-white p-2 text-red-600 hover:bg-red-50"
                                                @click="deleteDocument(idBack.id)"
                                            >
                                                <TrashIcon class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <div v-else class="flex h-32 w-full items-center justify-center rounded border-2 border-dashed border-gray-300 dark:border-gray-600">
                                        <p class="text-sm text-gray-400">No ID back uploaded</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Addresses -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Addresses</h3>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-x-1.5 rounded-md bg-white px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                                    @click="openAddressModal()"
                                >
                                    <PlusIcon class="-ml-0.5 size-4" />
                                    Add Address
                                </button>
                            </div>

                            <div v-if="customer.addresses && customer.addresses.length > 0" class="space-y-4">
                                <div
                                    v-for="addr in customer.addresses"
                                    :key="addr.id"
                                    class="relative rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                                >
                                    <div class="flex items-start justify-between">
                                        <div class="flex items-start gap-3">
                                            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                                <HomeIcon class="size-5 text-gray-500 dark:text-gray-400" />
                                            </div>
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-medium text-gray-900 dark:text-white">
                                                        {{ addr.nickname || getAddressTypeLabel(addr.type) }}
                                                    </span>
                                                    <span
                                                        v-if="addr.is_default"
                                                        class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400"
                                                    >
                                                        Default
                                                    </span>
                                                    <span
                                                        class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400"
                                                    >
                                                        {{ getAddressTypeLabel(addr.type) }}
                                                    </span>
                                                </div>
                                                <p v-if="addr.full_name" class="text-sm text-gray-500 dark:text-gray-400">{{ addr.full_name }}</p>
                                                <p v-if="addr.company" class="text-sm text-gray-500 dark:text-gray-400">{{ addr.company }}</p>
                                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                                    {{ addr.address }}
                                                    <span v-if="addr.address2"><br />{{ addr.address2 }}</span>
                                                </p>
                                                <p class="text-sm text-gray-700 dark:text-gray-300">
                                                    {{ addr.city }}<span v-if="addr.state_id">, {{ addr.state_id }}</span> {{ addr.zip }}
                                                </p>
                                                <p v-if="addr.phone" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                                    <PhoneIcon class="inline size-3 mr-1" />{{ addr.phone }}
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex gap-1">
                                            <button
                                                type="button"
                                                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                                                @click="openAddressModal(addr)"
                                            >
                                                <PencilIcon class="size-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
                                                @click="deleteAddress(addr.id)"
                                            >
                                                <TrashIcon class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">
                                No addresses added yet.
                            </p>
                        </div>
                    </div>

                    <!-- Transaction History -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Transaction History</h3>

                            <!-- Tabs -->
                            <div class="border-b border-gray-200 dark:border-gray-700 mb-4">
                                <nav class="-mb-px flex gap-x-6">
                                    <button
                                        type="button"
                                        :class="[
                                            'border-b-2 py-2 text-sm font-medium whitespace-nowrap',
                                            activeTab === 'buys'
                                                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300',
                                        ]"
                                        @click="activeTab = 'buys'"
                                    >
                                        Buys ({{ customer.transactions.length }})
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            'border-b-2 py-2 text-sm font-medium whitespace-nowrap',
                                            activeTab === 'sales'
                                                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300',
                                        ]"
                                        @click="activeTab = 'sales'"
                                    >
                                        Sales ({{ customer.orders.length }})
                                    </button>
                                </nav>
                            </div>

                            <!-- Buys Tab -->
                            <div v-if="activeTab === 'buys'">
                                <div v-if="customer.transactions.length > 0" class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead>
                                            <tr>
                                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Transaction</th>
                                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Date</th>
                                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
                                                <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                            <tr v-for="transaction in customer.transactions" :key="transaction.id">
                                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                                    <Link
                                                        :href="`/transactions/${transaction.id}`"
                                                        class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                                    >
                                                        {{ transaction.transaction_number }}
                                                    </Link>
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                    {{ formatDate(transaction.created_at) }}
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                                    <span
                                                        :class="[
                                                            'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset',
                                                            statusColors[transaction.status] || statusColors.pending,
                                                        ]"
                                                    >
                                                        {{ transaction.status.replace(/_/g, ' ') }}
                                                    </span>
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900 dark:text-white text-right">
                                                    {{ formatCurrency(transaction.final_offer) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">
                                    No buy transactions yet.
                                </p>
                            </div>

                            <!-- Sales Tab -->
                            <div v-if="activeTab === 'sales'">
                                <div v-if="customer.orders.length > 0" class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead>
                                            <tr>
                                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Order</th>
                                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Date</th>
                                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
                                                <th class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                            <tr v-for="order in customer.orders" :key="order.id">
                                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                                    <Link
                                                        :href="`/orders/${order.id}`"
                                                        class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                                    >
                                                        {{ order.invoice_number || `#${order.id}` }}
                                                    </Link>
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-300">
                                                    {{ formatDate(order.created_at) }}
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                                        {{ order.status }}
                                                    </span>
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900 dark:text-white text-right">
                                                    {{ formatCurrency(order.total) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic">
                                    No sales orders yet.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Notes Section -->
                    <NotesSection
                        :notes="noteEntries ?? []"
                        notable-type="customer"
                        :notable-id="customer.id"
                    />

                    <!-- Activity Log -->
                    <ActivityTimeline :activities="activityLogs" />
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Lead Source -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Lead Source</h3>
                            <select
                                :value="customer.lead_source_id || ''"
                                class="block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                @change="updateLeadSource(($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">No lead source</option>
                                <option v-for="source in leadSources" :key="source.id" :value="source.id">
                                    {{ source.name }}
                                </option>
                            </select>
                            <button
                                type="button"
                                class="mt-2 text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                @click="showLeadSourceModal = true"
                            >
                                <PlusIcon class="inline size-4 -mt-0.5" />
                                Add New Source
                            </button>
                        </div>
                    </div>

                    <!-- Statistics -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Statistics</h3>
                            <dl class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Total Buys</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">{{ stats.total_buys }}</dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Total Sales</dt>
                                    <dd class="text-sm font-medium text-gray-900 dark:text-white">{{ stats.total_sales }}</dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Buy Value</dt>
                                    <dd class="text-sm font-semibold text-gray-900 dark:text-white">{{ formatCurrency(stats.total_buy_value) }}</dd>
                                </div>
                                <div class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Sales Value</dt>
                                    <dd class="text-sm font-semibold text-gray-900 dark:text-white">{{ formatCurrency(stats.total_sales_value) }}</dd>
                                </div>
                                <div v-if="stats.last_activity" class="flex items-center justify-between">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">Last Activity</dt>
                                    <dd class="text-sm text-gray-900 dark:text-white">{{ formatDate(stats.last_activity) }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!-- Store Credit -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center justify-between mb-2">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Store Credit</h3>
                                <Link
                                    :href="`/customers/${customer.id}/store-credits`"
                                    class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    View History
                                </Link>
                            </div>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">
                                {{ formatCurrency(stats.store_credit_balance) }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Available balance</p>
                        </div>
                    </div>

                    <!-- Member Since -->
                    <div class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        <div class="px-4 py-5 sm:p-6">
                            <div class="flex items-center gap-3">
                                <CalendarIcon class="size-5 text-gray-400" />
                                <div>
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Member Since</p>
                                    <p class="text-sm text-gray-900 dark:text-white">{{ formatDate(customer.created_at) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Edit Customer Modal -->
        <TransitionRoot as="template" :show="isEditing">
            <Dialog class="relative z-50" @close="isEditing = false">
                <TransitionChild
                    as="template"
                    enter="ease-out duration-300"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="ease-in duration-200"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75 transition-opacity" />
                </TransitionChild>

                <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
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
                            <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6 dark:bg-gray-800">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Edit Customer</h3>
                                    <form @submit.prevent="saveCustomer" class="space-y-4">
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label for="edit_first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name</label>
                                                <input
                                                    id="edit_first_name"
                                                    v-model="editForm.first_name"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label for="edit_last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name</label>
                                                <input
                                                    id="edit_last_name"
                                                    v-model="editForm.last_name"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                        <div>
                                            <label for="edit_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                            <input
                                                id="edit_email"
                                                v-model="editForm.email"
                                                type="email"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div>
                                            <label for="edit_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                            <input
                                                id="edit_phone"
                                                v-model="editForm.phone_number"
                                                type="tel"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div>
                                            <label for="edit_company" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Company</label>
                                            <input
                                                id="edit_company"
                                                v-model="editForm.company_name"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div>
                                            <label for="edit_address" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address</label>
                                            <input
                                                id="edit_address"
                                                v-model="editForm.address"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label for="edit_city" class="block text-sm font-medium text-gray-700 dark:text-gray-300">City</label>
                                                <input
                                                    id="edit_city"
                                                    v-model="editForm.city"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                            <div>
                                                <label for="edit_postal_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Postal Code</label>
                                                <input
                                                    id="edit_postal_code"
                                                    v-model="editForm.postal_code"
                                                    type="text"
                                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                />
                                            </div>
                                        </div>
                                        <div>
                                            <label for="edit_state" class="block text-sm font-medium text-gray-700 dark:text-gray-300">State</label>
                                            <input
                                                id="edit_state"
                                                v-model="editForm.state"
                                                type="text"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div>
                                            <label for="edit_lead_source" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Lead Source</label>
                                            <select
                                                id="edit_lead_source"
                                                v-model="editForm.lead_source_id"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            >
                                                <option value="">No lead source</option>
                                                <option v-for="source in leadSources" :key="source.id" :value="source.id">
                                                    {{ source.name }}
                                                </option>
                                            </select>
                                        </div>
                                        <div>
                                            <label for="edit_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                            <textarea
                                                id="edit_notes"
                                                v-model="editForm.notes"
                                                rows="3"
                                                class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                            />
                                        </div>
                                        <div class="flex gap-3 justify-end pt-2">
                                            <button
                                                type="button"
                                                class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                                @click="isEditing = false"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                type="submit"
                                                :disabled="editForm.processing"
                                                class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                            >
                                                {{ editForm.processing ? 'Saving...' : 'Save Changes' }}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>

        <!-- Add Lead Source Modal -->
        <Teleport to="body">
            <div v-if="showLeadSourceModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showLeadSourceModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Add Lead Source</h3>
                        <form @submit.prevent="createLeadSource" class="space-y-4">
                            <div>
                                <label for="lead_source_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                                <input
                                    id="lead_source_name"
                                    v-model="newLeadSourceForm.name"
                                    type="text"
                                    required
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    placeholder="e.g., Trade Show, Billboard"
                                />
                            </div>
                            <div>
                                <label for="lead_source_description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description (optional)</label>
                                <textarea
                                    id="lead_source_description"
                                    v-model="newLeadSourceForm.description"
                                    rows="2"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div class="flex gap-3 justify-end pt-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showLeadSourceModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="newLeadSourceForm.processing"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {{ newLeadSourceForm.processing ? 'Creating...' : 'Create' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Upload Document Modal -->
        <Teleport to="body">
            <div v-if="showDocumentModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showDocumentModal = false" />
                    <div class="relative w-full max-w-md transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Upload Document</h3>
                        <form @submit.prevent="uploadDocument" class="space-y-4">
                            <div>
                                <label for="document_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Document Type</label>
                                <select
                                    id="document_type"
                                    v-model="documentForm.type"
                                    required
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                >
                                    <option v-for="docType in documentTypes" :key="docType.value" :value="docType.value">
                                        {{ docType.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">File</label>
                                <div class="mt-1 flex justify-center rounded-lg border border-dashed border-gray-900/25 px-6 py-10 dark:border-gray-700">
                                    <div class="text-center">
                                        <DocumentArrowUpIcon class="mx-auto size-12 text-gray-300 dark:text-gray-600" />
                                        <div class="mt-4 flex text-sm/6 text-gray-600 dark:text-gray-400">
                                            <label
                                                for="file-upload"
                                                class="relative cursor-pointer rounded-md font-semibold text-indigo-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-indigo-600 focus-within:ring-offset-2 hover:text-indigo-500 dark:text-indigo-400"
                                            >
                                                <span>Upload a file</span>
                                                <input
                                                    id="file-upload"
                                                    name="file-upload"
                                                    type="file"
                                                    class="sr-only"
                                                    accept=".jpg,.jpeg,.png,.pdf"
                                                    @change="onFileChange"
                                                />
                                            </label>
                                            <p class="pl-1">or drag and drop</p>
                                        </div>
                                        <p class="text-xs/5 text-gray-600 dark:text-gray-400">PNG, JPG, PDF up to 10MB</p>
                                        <p v-if="documentFile" class="mt-2 text-sm font-medium text-gray-900 dark:text-white">
                                            {{ documentFile.name }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label for="document_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes (optional)</label>
                                <textarea
                                    id="document_notes"
                                    v-model="documentForm.notes"
                                    rows="2"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div class="flex gap-3 justify-end pt-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showDocumentModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="documentForm.processing || !documentFile"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {{ documentForm.processing ? 'Uploading...' : 'Upload' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Preview Document Modal -->
        <TransitionRoot as="template" :show="showPreviewModal">
            <Dialog class="relative z-50" @close="showPreviewModal = false">
                <TransitionChild
                    as="template"
                    enter="ease-out duration-300"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="ease-in duration-200"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-black/80 transition-opacity" />
                </TransitionChild>

                <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center p-4">
                        <TransitionChild
                            as="template"
                            enter="ease-out duration-300"
                            enter-from="opacity-0 scale-95"
                            enter-to="opacity-100 scale-100"
                            leave="ease-in duration-200"
                            leave-from="opacity-100 scale-100"
                            leave-to="opacity-0 scale-95"
                        >
                            <DialogPanel class="relative max-w-3xl transform overflow-hidden rounded-lg bg-white shadow-xl transition-all dark:bg-gray-800">
                                <button
                                    type="button"
                                    class="absolute top-4 right-4 rounded-full bg-white/80 p-1 text-gray-600 hover:bg-white hover:text-gray-800"
                                    @click="showPreviewModal = false"
                                >
                                    <XMarkIcon class="size-6" />
                                </button>
                                <div v-if="previewDocument" class="p-4">
                                    <img
                                        v-if="previewDocument.mime_type?.startsWith('image/')"
                                        :src="previewDocument.url"
                                        :alt="previewDocument.original_filename || 'Document'"
                                        class="max-h-[80vh] w-auto mx-auto rounded"
                                    />
                                    <div v-else class="text-center py-8">
                                        <DocumentIcon class="size-16 text-gray-400 mx-auto mb-4" />
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ previewDocument.original_filename }}</p>
                                        <a
                                            :href="previewDocument.url"
                                            target="_blank"
                                            class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                        >
                                            Open in new tab
                                        </a>
                                    </div>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>

        <!-- Address Modal -->
        <Teleport to="body">
            <div v-if="showAddressModal" class="fixed inset-0 z-50 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="showAddressModal = false" />
                    <div class="relative w-full max-w-lg transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 shadow-xl dark:bg-gray-800 sm:p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            {{ editingAddress ? 'Edit Address' : 'Add Address' }}
                        </h3>
                        <form @submit.prevent="saveAddress" class="space-y-4">
                            <div>
                                <label for="address_nickname" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nickname (optional)</label>
                                <input
                                    id="address_nickname"
                                    v-model="addressForm.nickname"
                                    type="text"
                                    placeholder="e.g., Main Office, Vacation Home"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="address_first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name</label>
                                    <input
                                        id="address_first_name"
                                        v-model="addressForm.first_name"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div>
                                    <label for="address_last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name</label>
                                    <input
                                        id="address_last_name"
                                        v-model="addressForm.last_name"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>
                            <div>
                                <label for="address_company" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Company (optional)</label>
                                <input
                                    id="address_company"
                                    v-model="addressForm.company"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div>
                                <label for="address_address" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Street Address *</label>
                                <input
                                    id="address_address"
                                    v-model="addressForm.address"
                                    type="text"
                                    required
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div>
                                <label for="address_address2" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Apt, Suite, etc. (optional)</label>
                                <input
                                    id="address_address2"
                                    v-model="addressForm.address2"
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div class="grid grid-cols-6 gap-4">
                                <div class="col-span-3">
                                    <label for="address_city" class="block text-sm font-medium text-gray-700 dark:text-gray-300">City *</label>
                                    <input
                                        id="address_city"
                                        v-model="addressForm.city"
                                        type="text"
                                        required
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div class="col-span-1">
                                    <label for="address_state" class="block text-sm font-medium text-gray-700 dark:text-gray-300">State</label>
                                    <input
                                        id="address_state"
                                        v-model="addressForm.state"
                                        type="text"
                                        maxlength="2"
                                        placeholder="CA"
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                                <div class="col-span-2">
                                    <label for="address_zip" class="block text-sm font-medium text-gray-700 dark:text-gray-300">ZIP *</label>
                                    <input
                                        id="address_zip"
                                        v-model="addressForm.zip"
                                        type="text"
                                        required
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    />
                                </div>
                            </div>
                            <div>
                                <label for="address_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone (optional)</label>
                                <input
                                    id="address_phone"
                                    v-model="addressForm.phone"
                                    type="tel"
                                    class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="address_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address Type *</label>
                                    <select
                                        id="address_type"
                                        v-model="addressForm.type"
                                        required
                                        class="mt-1 block w-full rounded-md border-0 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm/6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    >
                                        <option v-for="type in addressTypes" :key="type.value" :value="type.value">
                                            {{ type.label }}
                                        </option>
                                    </select>
                                </div>
                                <div class="flex items-end pb-1.5">
                                    <label class="flex items-center gap-2">
                                        <input
                                            v-model="addressForm.is_default"
                                            type="checkbox"
                                            class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700"
                                        />
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Set as default address</span>
                                    </label>
                                </div>
                            </div>
                            <div class="flex gap-3 justify-end pt-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                                    @click="showAddressModal = false"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="addressForm.processing"
                                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {{ addressForm.processing ? 'Saving...' : (editingAddress ? 'Update Address' : 'Add Address') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
