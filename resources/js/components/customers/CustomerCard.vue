<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { UserIcon, PencilIcon } from '@heroicons/vue/24/outline';

interface LeadSource {
    id: number;
    name: string;
}

interface Customer {
    id: number;
    full_name?: string;
    first_name?: string;
    last_name?: string;
    display_name?: string;
    company_name?: string | null;
    email?: string | null;
    phone?: string | null;
    phone_number?: string | null;
    address?: string | null;
    city?: string | null;
    state?: string | null;
    zip?: string | null;
    primary_address?: { address: string | null; city: string | null; state_abbreviation: string | null; zip: string | null; one_line_address: string } | null;
    lead_source?: LeadSource | null;
    leadSource?: LeadSource | null;
}

interface Props {
    customer: Customer;
    showEditButton?: boolean;
    showViewLink?: boolean;
    compact?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEditButton: false,
    showViewLink: true,
    compact: false,
});

const emit = defineEmits<{
    edit: [];
}>();

// Get customer name with fallbacks
const customerName = computed(() => {
    if (props.customer.full_name) return props.customer.full_name;
    if (props.customer.display_name) return props.customer.display_name;
    const first = props.customer.first_name || '';
    const last = props.customer.last_name || '';
    return `${first} ${last}`.trim() || 'Unknown Customer';
});

// Get phone with fallback for different field names
const customerPhone = computed(() => {
    return props.customer.phone || props.customer.phone_number || null;
});

// Get address with fallback: primary_address > direct customer fields
const customerAddress = computed(() => {
    if (props.customer.primary_address?.one_line_address) {
        return props.customer.primary_address.one_line_address;
    }
    const parts = [
        props.customer.address,
        props.customer.city,
        props.customer.state,
        props.customer.zip,
    ].filter(Boolean);
    return parts.length > 0 ? parts.join(', ') : null;
});

// Get lead source (handle both camelCase and snake_case)
const leadSource = computed(() => {
    return props.customer.leadSource || props.customer.lead_source || null;
});
</script>

<template>
    <div class="flex items-start gap-3">
        <!-- Avatar -->
        <div
            :class="[
                'flex shrink-0 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900',
                compact ? 'size-10' : 'size-12'
            ]"
        >
            <UserIcon :class="[compact ? 'size-5' : 'size-6', 'text-indigo-600 dark:text-indigo-400']" />
        </div>

        <!-- Customer Info -->
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <Link
                    v-if="showViewLink"
                    :href="`/customers/${customer.id}`"
                    class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400 truncate"
                >
                    {{ customerName }}
                </Link>
                <span v-else class="font-medium text-gray-900 dark:text-white truncate">
                    {{ customerName }}
                </span>

                <button
                    v-if="showEditButton"
                    type="button"
                    class="ml-auto shrink-0 rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                    @click="emit('edit')"
                >
                    <PencilIcon class="size-4" />
                </button>
            </div>

            <p v-if="customer.company_name" class="text-sm text-gray-500 dark:text-gray-400 truncate">
                {{ customer.company_name }}
            </p>
            <p v-if="customer.email" class="text-sm text-gray-500 dark:text-gray-400 truncate">
                {{ customer.email }}
            </p>
            <p v-if="customerPhone" class="text-sm text-gray-500 dark:text-gray-400">
                {{ customerPhone }}
            </p>
            <p v-if="customerAddress" class="text-sm text-gray-500 dark:text-gray-400 truncate">
                {{ customerAddress }}
            </p>

            <!-- Lead Source -->
            <div v-if="!compact" class="mt-2 flex items-center gap-2">
                <span class="text-xs text-gray-500 dark:text-gray-400">Lead Source:</span>
                <span
                    v-if="leadSource"
                    class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30"
                >
                    {{ leadSource.name }}
                </span>
                <span v-else class="text-xs text-gray-400 dark:text-gray-500 italic">
                    Unknown
                </span>
            </div>
        </div>
    </div>
</template>
