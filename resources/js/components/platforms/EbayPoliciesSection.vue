<script setup lang="ts">
import { ref, computed } from 'vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import EbayPolicyFormModal from './EbayPolicyFormModal.vue';
import EbayLocationFormModal from './EbayLocationFormModal.vue';
import {
    ArrowPathIcon,
    PencilSquareIcon,
    TrashIcon,
    PlusIcon,
} from '@heroicons/vue/20/solid';
import axios from 'axios';

interface Props {
    marketplaceId: number;
    ebayMarketplaceId: string;
}

const props = defineProps<Props>();

type Tab = 'return' | 'fulfillment' | 'payment' | 'locations' | 'programs';

const activeTab = ref<Tab>('return');

const tabs: { key: Tab; label: string }[] = [
    { key: 'return', label: 'Return Policies' },
    { key: 'fulfillment', label: 'Fulfillment Policies' },
    { key: 'payment', label: 'Payment Policies' },
    { key: 'locations', label: 'Locations' },
    { key: 'programs', label: 'Programs' },
];

// State
const loading = ref(false);
const error = ref('');
const returnPolicies = ref<Record<string, unknown>[]>([]);
const fulfillmentPolicies = ref<Record<string, unknown>[]>([]);
const paymentPolicies = ref<Record<string, unknown>[]>([]);
const locations = ref<Record<string, unknown>[]>([]);
const programs = ref<Record<string, unknown>[]>([]);
const privileges = ref<Record<string, unknown> | null>(null);

// Modal state
const policyModalOpen = ref(false);
const policyModalType = ref<'return' | 'fulfillment' | 'payment'>('return');
const policyModalEditData = ref<Record<string, unknown> | null>(null);
const locationModalOpen = ref(false);
const locationModalEditData = ref<Record<string, unknown> | null>(null);

const baseUrl = computed(() => `/settings/marketplaces/${props.marketplaceId}/ebay`);

// Fetch functions
async function fetchReturnPolicies() {
    loading.value = true;
    error.value = '';
    try {
        const response = await axios.get(`${baseUrl.value}/return-policies`);
        returnPolicies.value = response.data ?? [];
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to fetch return policies';
    } finally {
        loading.value = false;
    }
}

async function fetchFulfillmentPolicies() {
    loading.value = true;
    error.value = '';
    try {
        const response = await axios.get(`${baseUrl.value}/fulfillment-policies`);
        fulfillmentPolicies.value = response.data ?? [];
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to fetch fulfillment policies';
    } finally {
        loading.value = false;
    }
}

async function fetchPaymentPolicies() {
    loading.value = true;
    error.value = '';
    try {
        const response = await axios.get(`${baseUrl.value}/payment-policies`);
        paymentPolicies.value = response.data ?? [];
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to fetch payment policies';
    } finally {
        loading.value = false;
    }
}

async function fetchLocations() {
    loading.value = true;
    error.value = '';
    try {
        const response = await axios.get(`${baseUrl.value}/locations`);
        locations.value = response.data ?? [];
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to fetch locations';
    } finally {
        loading.value = false;
    }
}

async function fetchPrograms() {
    loading.value = true;
    error.value = '';
    try {
        const [programsRes, privilegesRes] = await Promise.all([
            axios.get(`${baseUrl.value}/programs`),
            axios.get(`${baseUrl.value}/privileges`),
        ]);
        programs.value = programsRes.data?.programs ?? [];
        privileges.value = privilegesRes.data ?? null;
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to fetch programs';
    } finally {
        loading.value = false;
    }
}

function refreshCurrentTab() {
    const fetchMap: Record<Tab, () => Promise<void>> = {
        return: fetchReturnPolicies,
        fulfillment: fetchFulfillmentPolicies,
        payment: fetchPaymentPolicies,
        locations: fetchLocations,
        programs: fetchPrograms,
    };
    fetchMap[activeTab.value]();
}

// CRUD operations
async function deletePolicy(type: 'return' | 'fulfillment' | 'payment', policyId: string) {
    if (!confirm('Are you sure you want to delete this policy? This cannot be undone.')) return;

    const urlMap = {
        return: 'return-policies',
        fulfillment: 'fulfillment-policies',
        payment: 'payment-policies',
    };

    try {
        await axios.delete(`${baseUrl.value}/${urlMap[type]}/${policyId}`);
        refreshCurrentTab();
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to delete policy';
    }
}

async function deleteLocation(locationKey: string) {
    if (!confirm('Are you sure you want to delete this location?')) return;

    try {
        await axios.delete(`${baseUrl.value}/locations/${locationKey}`);
        fetchLocations();
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to delete location';
    }
}

function openCreatePolicyModal(type: 'return' | 'fulfillment' | 'payment') {
    policyModalType.value = type;
    policyModalEditData.value = null;
    policyModalOpen.value = true;
}

function openEditPolicyModal(type: 'return' | 'fulfillment' | 'payment', data: Record<string, unknown>) {
    policyModalType.value = type;
    policyModalEditData.value = data;
    policyModalOpen.value = true;
}

async function savePolicyFromModal(data: Record<string, unknown>) {
    const urlMap = {
        return: 'return-policies',
        fulfillment: 'fulfillment-policies',
        payment: 'payment-policies',
    };

    const idMap = {
        return: 'returnPolicyId',
        fulfillment: 'fulfillmentPolicyId',
        payment: 'paymentPolicyId',
    };

    try {
        if (policyModalEditData.value) {
            const policyId = policyModalEditData.value[idMap[policyModalType.value]] as string;
            await axios.put(`${baseUrl.value}/${urlMap[policyModalType.value]}/${policyId}`, data);
        } else {
            await axios.post(`${baseUrl.value}/${urlMap[policyModalType.value]}`, data);
        }
        policyModalOpen.value = false;
        refreshCurrentTab();
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to save policy';
    }
}

function openCreateLocationModal() {
    locationModalEditData.value = null;
    locationModalOpen.value = true;
}

function openEditLocationModal(data: Record<string, unknown>) {
    locationModalEditData.value = data;
    locationModalOpen.value = true;
}

async function saveLocationFromModal(data: Record<string, unknown>) {
    try {
        if (locationModalEditData.value) {
            const locationKey = locationModalEditData.value.merchantLocationKey as string;
            await axios.put(`${baseUrl.value}/locations/${locationKey}`, data);
        } else {
            await axios.post(`${baseUrl.value}/locations`, data);
        }
        locationModalOpen.value = false;
        fetchLocations();
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to save location';
    }
}

async function optInToProgram(programType: string) {
    try {
        await axios.post(`${baseUrl.value}/programs/opt-in`, { program_type: programType });
        fetchPrograms();
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to opt in to program';
    }
}

async function optOutOfProgram(programType: string) {
    if (!confirm('Are you sure you want to opt out of this program?')) return;

    try {
        await axios.post(`${baseUrl.value}/programs/opt-out`, { program_type: programType });
        fetchPrograms();
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { error?: string } } };
        error.value = axiosError.response?.data?.error ?? 'Failed to opt out of program';
    }
}

function getPolicyName(policy: Record<string, unknown>): string {
    return (policy.name as string) ?? 'Unnamed Policy';
}

function getLocationDisplay(loc: Record<string, unknown>): string {
    const name = (loc.name as string) ?? (loc.merchantLocationKey as string) ?? 'Unknown';
    const address = (loc.location as Record<string, unknown>)?.address as Record<string, unknown> | undefined;
    if (address?.city) {
        return `${name} (${address.city}, ${address.stateOrProvince ?? ''})`;
    }
    return name;
}
</script>

<template>
    <div>
        <Separator class="mb-8" />

        <HeadingSmall
            title="eBay Account Management"
            description="Manage your eBay business policies, inventory locations, and programs directly from here."
        />

        <!-- Tabs -->
        <div class="mt-4 flex gap-1 border-b border-border">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                class="px-4 py-2 text-sm font-medium border-b-2 transition-colors -mb-px"
                :class="activeTab === tab.key
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'"
                @click="activeTab = tab.key"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- Error -->
        <div v-if="error" class="mt-3 p-3 rounded-md bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-400">
            {{ error }}
            <button type="button" class="ml-2 underline" @click="error = ''">Dismiss</button>
        </div>

        <!-- Content -->
        <div class="mt-4">
            <!-- Return Policies -->
            <div v-if="activeTab === 'return'">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-sm text-muted-foreground">Manage return policies on your eBay account.</p>
                    <div class="flex gap-2">
                        <Button type="button" variant="outline" size="sm" :disabled="loading" @click="fetchReturnPolicies">
                            <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': loading }" />
                            Refresh
                        </Button>
                        <Button type="button" size="sm" @click="openCreatePolicyModal('return')">
                            <PlusIcon class="h-4 w-4 mr-1" />
                            Create
                        </Button>
                    </div>
                </div>

                <div v-if="returnPolicies.length > 0" class="space-y-2">
                    <div
                        v-for="policy in returnPolicies"
                        :key="(policy.returnPolicyId as string)"
                        class="flex items-center justify-between p-3 rounded-lg border border-border"
                    >
                        <div>
                            <p class="text-sm font-medium text-foreground">{{ getPolicyName(policy) }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ (policy.returnsAccepted as boolean) ? 'Returns accepted' : 'No returns' }}
                                <template v-if="policy.returnPeriod">
                                    &middot; {{ ((policy.returnPeriod as Record<string, unknown>).value as number) }} days
                                </template>
                            </p>
                        </div>
                        <div class="flex gap-1">
                            <Button type="button" variant="ghost" size="sm" @click="openEditPolicyModal('return', policy)">
                                <PencilSquareIcon class="h-4 w-4" />
                            </Button>
                            <Button type="button" variant="ghost" size="sm" class="text-red-500 hover:text-red-700" @click="deletePolicy('return', (policy.returnPolicyId as string))">
                                <TrashIcon class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No return policies loaded. Click "Refresh" to fetch from eBay.
                </p>
            </div>

            <!-- Fulfillment Policies -->
            <div v-if="activeTab === 'fulfillment'">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-sm text-muted-foreground">Manage fulfillment (shipping) policies on your eBay account.</p>
                    <div class="flex gap-2">
                        <Button type="button" variant="outline" size="sm" :disabled="loading" @click="fetchFulfillmentPolicies">
                            <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': loading }" />
                            Refresh
                        </Button>
                        <Button type="button" size="sm" @click="openCreatePolicyModal('fulfillment')">
                            <PlusIcon class="h-4 w-4 mr-1" />
                            Create
                        </Button>
                    </div>
                </div>

                <div v-if="fulfillmentPolicies.length > 0" class="space-y-2">
                    <div
                        v-for="policy in fulfillmentPolicies"
                        :key="(policy.fulfillmentPolicyId as string)"
                        class="flex items-center justify-between p-3 rounded-lg border border-border"
                    >
                        <div>
                            <p class="text-sm font-medium text-foreground">{{ getPolicyName(policy) }}</p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="policy.handlingTime">
                                    Handling: {{ ((policy.handlingTime as Record<string, unknown>).value as number) }} day(s)
                                </template>
                            </p>
                        </div>
                        <div class="flex gap-1">
                            <Button type="button" variant="ghost" size="sm" @click="openEditPolicyModal('fulfillment', policy)">
                                <PencilSquareIcon class="h-4 w-4" />
                            </Button>
                            <Button type="button" variant="ghost" size="sm" class="text-red-500 hover:text-red-700" @click="deletePolicy('fulfillment', (policy.fulfillmentPolicyId as string))">
                                <TrashIcon class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No fulfillment policies loaded. Click "Refresh" to fetch from eBay.
                </p>
            </div>

            <!-- Payment Policies -->
            <div v-if="activeTab === 'payment'">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-sm text-muted-foreground">Manage payment policies on your eBay account.</p>
                    <div class="flex gap-2">
                        <Button type="button" variant="outline" size="sm" :disabled="loading" @click="fetchPaymentPolicies">
                            <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': loading }" />
                            Refresh
                        </Button>
                        <Button type="button" size="sm" @click="openCreatePolicyModal('payment')">
                            <PlusIcon class="h-4 w-4 mr-1" />
                            Create
                        </Button>
                    </div>
                </div>

                <div v-if="paymentPolicies.length > 0" class="space-y-2">
                    <div
                        v-for="policy in paymentPolicies"
                        :key="(policy.paymentPolicyId as string)"
                        class="flex items-center justify-between p-3 rounded-lg border border-border"
                    >
                        <div>
                            <p class="text-sm font-medium text-foreground">{{ getPolicyName(policy) }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ (policy.immediatePay as boolean) ? 'Immediate payment required' : 'Payment not required immediately' }}
                            </p>
                        </div>
                        <div class="flex gap-1">
                            <Button type="button" variant="ghost" size="sm" @click="openEditPolicyModal('payment', policy)">
                                <PencilSquareIcon class="h-4 w-4" />
                            </Button>
                            <Button type="button" variant="ghost" size="sm" class="text-red-500 hover:text-red-700" @click="deletePolicy('payment', (policy.paymentPolicyId as string))">
                                <TrashIcon class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No payment policies loaded. Click "Refresh" to fetch from eBay.
                </p>
            </div>

            <!-- Locations -->
            <div v-if="activeTab === 'locations'">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-sm text-muted-foreground">Manage inventory locations on your eBay account.</p>
                    <div class="flex gap-2">
                        <Button type="button" variant="outline" size="sm" :disabled="loading" @click="fetchLocations">
                            <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': loading }" />
                            Refresh
                        </Button>
                        <Button type="button" size="sm" @click="openCreateLocationModal">
                            <PlusIcon class="h-4 w-4 mr-1" />
                            Create
                        </Button>
                    </div>
                </div>

                <div v-if="locations.length > 0" class="space-y-2">
                    <div
                        v-for="loc in locations"
                        :key="(loc.merchantLocationKey as string)"
                        class="flex items-center justify-between p-3 rounded-lg border border-border"
                    >
                        <div>
                            <p class="text-sm font-medium text-foreground">{{ getLocationDisplay(loc) }}</p>
                            <p class="text-xs text-muted-foreground">
                                Key: {{ (loc.merchantLocationKey as string) }}
                                &middot;
                                <span :class="(loc.merchantLocationStatus as string) === 'ENABLED' ? 'text-green-600 dark:text-green-400' : 'text-yellow-600 dark:text-yellow-400'">
                                    {{ (loc.merchantLocationStatus as string) }}
                                </span>
                            </p>
                        </div>
                        <div class="flex gap-1">
                            <Button type="button" variant="ghost" size="sm" @click="openEditLocationModal(loc)">
                                <PencilSquareIcon class="h-4 w-4" />
                            </Button>
                            <Button type="button" variant="ghost" size="sm" class="text-red-500 hover:text-red-700" @click="deleteLocation((loc.merchantLocationKey as string))">
                                <TrashIcon class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No locations loaded. Click "Refresh" to fetch from eBay.
                </p>
            </div>

            <!-- Programs -->
            <div v-if="activeTab === 'programs'">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-sm text-muted-foreground">View your eBay seller privileges and manage program enrollment.</p>
                    <Button type="button" variant="outline" size="sm" :disabled="loading" @click="fetchPrograms">
                        <ArrowPathIcon class="h-4 w-4 mr-1" :class="{ 'animate-spin': loading }" />
                        Refresh
                    </Button>
                </div>

                <div v-if="privileges" class="mb-6 p-4 rounded-lg border border-border">
                    <h4 class="text-sm font-medium text-foreground mb-2">Seller Privileges</h4>
                    <div class="text-xs text-muted-foreground space-y-1">
                        <p v-if="privileges.sellingLimit">
                            Selling Limit: {{ (privileges.sellingLimit as Record<string, unknown>)?.quantity ?? 'N/A' }} items /
                            ${{ (privileges.sellingLimit as Record<string, unknown>)?.amount?.value ?? 'N/A' }}
                        </p>
                    </div>
                </div>

                <div v-if="programs.length > 0" class="space-y-2">
                    <div
                        v-for="program in programs"
                        :key="(program.programType as string)"
                        class="flex items-center justify-between p-3 rounded-lg border border-border"
                    >
                        <div>
                            <p class="text-sm font-medium text-foreground">{{ (program.programType as string) }}</p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="text-red-500"
                            @click="optOutOfProgram((program.programType as string))"
                        >
                            Opt Out
                        </Button>
                    </div>
                </div>

                <div class="mt-4">
                    <h4 class="text-sm font-medium text-foreground mb-2">Available Programs</h4>
                    <div class="space-y-2">
                        <div class="flex items-center justify-between p-3 rounded-lg border border-border">
                            <div>
                                <p class="text-sm font-medium text-foreground">SELLING_POLICY_MANAGEMENT</p>
                                <p class="text-xs text-muted-foreground">Enable business policies for managing return, payment, and fulfillment policies.</p>
                            </div>
                            <Button type="button" size="sm" @click="optInToProgram('SELLING_POLICY_MANAGEMENT')">
                                Opt In
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modals -->
        <EbayPolicyFormModal
            v-model:open="policyModalOpen"
            :policy-type="policyModalType"
            :marketplace-id="ebayMarketplaceId"
            :edit-data="policyModalEditData"
            @save="savePolicyFromModal"
        />

        <EbayLocationFormModal
            v-model:open="locationModalOpen"
            :edit-data="locationModalEditData"
            @save="saveLocationFromModal"
        />
    </div>
</template>
