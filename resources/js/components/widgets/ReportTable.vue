<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Cog6ToothIcon, ArrowDownTrayIcon, EnvelopeIcon } from '@heroicons/vue/20/solid';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';

const props = withDefaults(
    defineProps<{
        title: string;
        exportUrl?: string;
        emailUrl?: string;
        tableId?: string;
    }>(),
    {
        tableId: () => `report-table-${Math.random().toString(36).substr(2, 9)}`,
    }
);

const emit = defineEmits<{
    (e: 'export'): void;
    (e: 'email'): void;
}>();

const page = usePage();
const showEmailModal = ref(false);
const emailAddresses = ref('');
const emailSubject = ref('');
const isSending = ref(false);
const emailSent = ref(false);
const emailError = ref('');

// Get current user's email from Inertia shared data
const currentUserEmail = computed(() => {
    const auth = page.props.auth as { user?: { email?: string } } | undefined;
    return auth?.user?.email || '';
});

const hasActions = computed(() => props.exportUrl || props.emailUrl);

function handleExport() {
    if (props.exportUrl) {
        window.location.href = props.exportUrl;
    }
    emit('export');
}

function openEmailModal() {
    emailSubject.value = props.title;
    emailAddresses.value = currentUserEmail.value;
    showEmailModal.value = true;
    emailSent.value = false;
    emailError.value = '';
}

async function sendEmail() {
    if (!emailAddresses.value || !props.emailUrl) return;

    // Parse and validate email addresses
    const emails = emailAddresses.value
        .split(',')
        .map(e => e.trim())
        .filter(e => e.length > 0);

    if (emails.length === 0) {
        emailError.value = 'Please enter at least one email address';
        return;
    }

    // Basic email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const invalidEmails = emails.filter(e => !emailRegex.test(e));
    if (invalidEmails.length > 0) {
        emailError.value = `Invalid email address${invalidEmails.length > 1 ? 'es' : ''}: ${invalidEmails.join(', ')}`;
        return;
    }

    isSending.value = true;
    emailError.value = '';

    try {
        const response = await fetch(props.emailUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                emails: emails,
                subject: emailSubject.value,
            }),
        });

        if (!response.ok) {
            const data = await response.json();
            throw new Error(data.message || 'Failed to send email');
        }

        emailSent.value = true;
        setTimeout(() => {
            showEmailModal.value = false;
            emailAddresses.value = '';
            emailSent.value = false;
        }, 2000);
    } catch (error) {
        emailError.value = error instanceof Error ? error.message : 'Failed to send email';
    } finally {
        isSending.value = false;
    }
}

function closeEmailModal() {
    showEmailModal.value = false;
    emailAddresses.value = '';
    emailError.value = '';
}
</script>

<template>
    <div
        class="overflow-hidden bg-white shadow ring-1 ring-black/5 sm:rounded-lg dark:bg-gray-800 dark:ring-white/10"
    >
        <!-- Header with title and actions -->
        <div
            class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700"
        >
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                {{ title }}
            </h2>
            <DropdownMenu v-if="hasActions">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                    >
                        <Cog6ToothIcon class="size-5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-48">
                    <DropdownMenuItem
                        v-if="exportUrl"
                        class="flex cursor-pointer items-center gap-2"
                        @click="handleExport"
                    >
                        <ArrowDownTrayIcon class="size-4" />
                        <span>Download CSV</span>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator v-if="exportUrl && emailUrl" />
                    <DropdownMenuItem
                        v-if="emailUrl"
                        class="flex cursor-pointer items-center gap-2"
                        @click="openEmailModal"
                    >
                        <EnvelopeIcon class="size-4" />
                        <span>Email Report</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- Table content slot -->
        <div class="overflow-x-auto">
            <slot />
        </div>

        <!-- Email Modal -->
        <Teleport to="body">
            <div
                v-if="showEmailModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                @click.self="closeEmailModal"
            >
                <div
                    class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl dark:bg-gray-800"
                >
                    <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">
                        Email Report
                    </h3>

                    <div v-if="emailSent" class="py-8 text-center">
                        <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900">
                            <svg class="size-6 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <p class="text-gray-600 dark:text-gray-300">Report sent successfully!</p>
                    </div>

                    <div v-else class="space-y-4">
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Email Address(es)
                            </label>
                            <input
                                v-model="emailAddresses"
                                type="text"
                                placeholder="Enter email addresses (comma-separated)"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Separate multiple addresses with commas
                            </p>
                        </div>

                        <div>
                            <label
                                class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                Subject
                            </label>
                            <input
                                v-model="emailSubject"
                                type="text"
                                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            />
                        </div>

                        <div class="rounded-md bg-blue-50 p-3 dark:bg-blue-900/20">
                            <p class="text-xs text-blue-700 dark:text-blue-300">
                                The email will include the report data displayed as a table and a downloadable CSV attachment.
                            </p>
                        </div>

                        <p
                            v-if="emailError"
                            class="text-sm text-red-600 dark:text-red-400"
                        >
                            {{ emailError }}
                        </p>

                        <div class="flex justify-end gap-3 pt-2">
                            <button
                                type="button"
                                class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
                                @click="closeEmailModal"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                :disabled="!emailAddresses || isSending"
                                @click="sendEmail"
                            >
                                {{ isSending ? 'Sending...' : 'Send' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
