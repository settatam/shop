<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import {
    CalendarDaysIcon,
    EllipsisVerticalIcon,
    PencilIcon,
    TrashIcon,
    PlayIcon,
    PlusIcon,
    XMarkIcon,
    ClockIcon,
    EnvelopeIcon,
    SparklesIcon,
} from '@heroicons/vue/24/outline';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface ScheduledReport {
    id: number;
    report_type: string;
    report_slug: string | null;
    template_id: number | null;
    name: string | null;
    display_name: string;
    recipients: string[];
    schedule_time: string;
    timezone: string;
    schedule_days: number[] | null;
    schedule_description: string;
    is_enabled: boolean;
    last_sent_at: string | null;
    last_failed_at: string | null;
    last_error: string | null;
}

interface ReportType {
    value: string;
    label: string;
    slug: string;
}

interface Timezone {
    value: string;
    label: string;
}

interface Props {
    scheduledReports: ScheduledReport[];
    reportTypes: ReportType[];
    timezones: Timezone[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    { title: 'Notifications', href: '/settings/notifications' },
    { title: 'Scheduled Reports', href: '/settings/notifications/scheduled-reports' },
];

const showFormModal = ref(false);
const showDeleteModal = ref(false);
const isEditing = ref(false);
const selectedReport = ref<ScheduledReport | null>(null);
const isSaving = ref(false);
const isDeleting = ref(false);
const isTesting = ref(false);

const daysOfWeek = [
    { value: 0, label: 'Sun' },
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
];

const form = ref({
    report_type: '',
    name: '',
    recipients: [''],
    schedule_time: '00:00',
    timezone: 'America/New_York',
    schedule_days: null as number[] | null,
    is_enabled: true,
});

const formErrors = ref<Record<string, string>>({});

function openCreateModal() {
    isEditing.value = false;
    selectedReport.value = null;
    form.value = {
        report_type: '',
        name: '',
        recipients: [''],
        schedule_time: '00:00',
        timezone: 'America/New_York',
        schedule_days: null,
        is_enabled: true,
    };
    formErrors.value = {};
    showFormModal.value = true;
}

function openEditModal(report: ScheduledReport) {
    isEditing.value = true;
    selectedReport.value = report;
    form.value = {
        report_type: report.report_type,
        name: report.name || '',
        recipients: report.recipients.length > 0 ? [...report.recipients] : [''],
        schedule_time: report.schedule_time.substring(0, 5), // Remove seconds
        timezone: report.timezone,
        schedule_days: report.schedule_days ? [...report.schedule_days] : null,
        is_enabled: report.is_enabled,
    };
    formErrors.value = {};
    showFormModal.value = true;
}

function openDeleteModal(report: ScheduledReport) {
    selectedReport.value = report;
    showDeleteModal.value = true;
}

function closeModals() {
    showFormModal.value = false;
    showDeleteModal.value = false;
    selectedReport.value = null;
    formErrors.value = {};
}

function addRecipient() {
    form.value.recipients.push('');
}

function removeRecipient(index: number) {
    if (form.value.recipients.length > 1) {
        form.value.recipients.splice(index, 1);
    }
}

function toggleDay(day: number) {
    if (form.value.schedule_days === null) {
        form.value.schedule_days = [day];
    } else if (form.value.schedule_days.includes(day)) {
        form.value.schedule_days = form.value.schedule_days.filter(d => d !== day);
        if (form.value.schedule_days.length === 0) {
            form.value.schedule_days = null;
        }
    } else {
        form.value.schedule_days.push(day);
    }
}

function isDaySelected(day: number): boolean {
    return form.value.schedule_days?.includes(day) ?? false;
}

const scheduleMode = computed({
    get: () => form.value.schedule_days === null ? 'daily' : 'specific',
    set: (value) => {
        if (value === 'daily') {
            form.value.schedule_days = null;
        } else {
            form.value.schedule_days = [1, 2, 3, 4, 5]; // Default to weekdays
        }
    },
});

function submitForm() {
    if (isSaving.value) return;

    isSaving.value = true;
    formErrors.value = {};

    // Filter out empty recipients
    const recipients = form.value.recipients.filter(r => r.trim() !== '');
    if (recipients.length === 0) {
        formErrors.value.recipients = 'At least one recipient is required';
        isSaving.value = false;
        return;
    }

    const url = isEditing.value && selectedReport.value
        ? `/settings/notifications/scheduled-reports/${selectedReport.value.id}`
        : '/settings/notifications/scheduled-reports';

    const method = isEditing.value ? 'put' : 'post';

    router[method](url, {
        ...form.value,
        recipients,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSaving.value = false;
        },
    });
}

function deleteReport() {
    if (!selectedReport.value || isDeleting.value) return;

    isDeleting.value = true;

    router.delete(`/settings/notifications/scheduled-reports/${selectedReport.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModals();
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

function toggleEnabled(report: ScheduledReport) {
    router.post(`/settings/notifications/scheduled-reports/${report.id}/toggle`, {}, {
        preserveScroll: true,
    });
}

const testMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null);

async function testReport(report: ScheduledReport) {
    if (isTesting.value) return;

    isTesting.value = true;
    testMessage.value = null;

    try {
        const response = await fetch(`/settings/notifications/scheduled-reports/${report.id}/test`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });

        const data = await response.json();

        if (data.success) {
            testMessage.value = { type: 'success', text: data.message };
        } else {
            testMessage.value = { type: 'error', text: data.error || 'Failed to send test report' };
        }

        // Auto-hide message after 5 seconds
        setTimeout(() => {
            testMessage.value = null;
        }, 5000);
    } catch (error) {
        testMessage.value = { type: 'error', text: 'Failed to send test report' };
    } finally {
        isTesting.value = false;
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Scheduled Reports" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <!-- Toast Message -->
                <Transition
                    enter-active-class="transition ease-out duration-300"
                    enter-from-class="opacity-0 -translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-2"
                >
                    <div v-if="testMessage" :class="[
                        'rounded-md p-4',
                        testMessage.type === 'success' ? 'bg-green-50 dark:bg-green-500/10' : 'bg-red-50 dark:bg-red-500/10'
                    ]">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg v-if="testMessage.type === 'success'" class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                                </svg>
                                <svg v-else class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p :class="[
                                    'text-sm font-medium',
                                    testMessage.type === 'success' ? 'text-green-800 dark:text-green-200' : 'text-red-800 dark:text-red-200'
                                ]">
                                    {{ testMessage.text }}
                                </p>
                            </div>
                            <div class="ml-auto pl-3">
                                <button @click="testMessage = null" :class="[
                                    'inline-flex rounded-md p-1.5',
                                    testMessage.type === 'success'
                                        ? 'text-green-500 hover:bg-green-100 dark:hover:bg-green-500/20'
                                        : 'text-red-500 hover:bg-red-100 dark:hover:bg-red-500/20'
                                ]">
                                    <XMarkIcon class="h-5 w-5" />
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>

                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="Scheduled Reports"
                        description="Configure automated email reports"
                    />
                    <Button size="sm" @click="openCreateModal">
                        <PlusIcon class="mr-2 h-4 w-4" />
                        Add Report
                    </Button>
                </div>

                <!-- Navigation Tabs -->
                <div class="border-b border-gray-200 dark:border-white/10">
                    <nav class="-mb-px flex space-x-8">
                        <Link href="/settings/notifications" class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Overview</Link>
                        <Link href="/settings/notifications/templates" class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Templates</Link>
                        <Link href="/settings/notifications/subscriptions" class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Triggers</Link>
                        <Link href="/settings/notifications/scheduled-reports" class="border-b-2 border-indigo-500 px-1 pb-4 text-sm font-medium text-indigo-600 dark:text-indigo-400">Scheduled Reports</Link>
                        <Link href="/settings/notifications/channels" class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Channels</Link>
                        <Link href="/settings/notifications/logs" class="border-b-2 border-transparent px-1 pb-4 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">Logs</Link>
                    </nav>
                </div>

                <!-- Scheduled Reports List -->
                <div v-if="scheduledReports.length > 0" class="overflow-visible rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">Report</th>
                                <th class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white md:table-cell">Recipients</th>
                                <th class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white sm:table-cell">Schedule</th>
                                <th class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Status</th>
                                <th class="relative py-3.5 pl-3 pr-4 sm:pr-6"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/10 dark:bg-transparent">
                            <tr v-for="report in scheduledReports" :key="report.id">
                                <td class="whitespace-nowrap py-4 pl-4 pr-3 sm:pl-6">
                                    <div class="flex items-center gap-2">
                                        <CalendarDaysIcon class="h-5 w-5 text-gray-400" />
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-white">
                                                {{ report.display_name }}
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ report.report_type }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 md:table-cell">
                                    <div class="flex items-center gap-1">
                                        <EnvelopeIcon class="h-4 w-4" />
                                        <span>{{ report.recipients.length }} recipient{{ report.recipients.length !== 1 ? 's' : '' }}</span>
                                    </div>
                                </td>
                                <td class="hidden whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400 sm:table-cell">
                                    <div class="flex items-center gap-1">
                                        <ClockIcon class="h-4 w-4" />
                                        <span>{{ report.schedule_description }}</span>
                                    </div>
                                    <div v-if="report.last_sent_at" class="mt-1 text-xs text-gray-400">
                                        Last sent: {{ report.last_sent_at }}
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <button
                                        @click="toggleEnabled(report)"
                                        :class="[
                                            'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors',
                                            report.is_enabled ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700',
                                        ]"
                                    >
                                        <span :class="['pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition', report.is_enabled ? 'translate-x-5' : 'translate-x-0']" />
                                    </button>
                                </td>
                                <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                    <Menu as="div" class="relative inline-block text-left">
                                        <MenuButton class="-m-2.5 block p-2.5 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                                            <EllipsisVerticalIcon class="h-5 w-5" />
                                        </MenuButton>
                                        <transition enter-active-class="transition ease-out duration-100" enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100" leave-active-class="transition ease-in duration-75" leave-from-class="transform opacity-100 scale-100" leave-to-class="transform opacity-0 scale-95">
                                            <MenuItems class="absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-md bg-white py-2 shadow-lg ring-1 ring-gray-900/5 focus:outline-none dark:bg-gray-800 dark:ring-white/10">
                                                <MenuItem v-slot="{ active }">
                                                    <button @click="openEditModal(report)" :class="[active ? 'bg-gray-50 dark:bg-white/5' : '', 'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white']">
                                                        <PencilIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Edit
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <Link :href="`/settings/notifications/scheduled-reports/${report.id}/template`" :class="[active ? 'bg-gray-50 dark:bg-white/5' : '', 'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white']">
                                                        <SparklesIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Edit Template
                                                    </Link>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button @click="testReport(report)" :disabled="isTesting" :class="[active ? 'bg-gray-50 dark:bg-white/5' : '', 'flex w-full items-center px-3 py-2 text-sm text-gray-900 dark:text-white']">
                                                        <PlayIcon class="mr-3 h-5 w-5 text-gray-400" />
                                                        Send Test
                                                    </button>
                                                </MenuItem>
                                                <MenuItem v-slot="{ active }">
                                                    <button @click="openDeleteModal(report)" :class="[active ? 'bg-gray-50 dark:bg-white/5' : '', 'flex w-full items-center px-3 py-2 text-sm text-red-600 dark:text-red-400']">
                                                        <TrashIcon class="mr-3 h-5 w-5 text-red-400" />
                                                        Delete
                                                    </button>
                                                </MenuItem>
                                            </MenuItems>
                                        </transition>
                                    </Menu>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="rounded-lg border border-gray-200 bg-gray-50 py-12 text-center dark:border-white/10 dark:bg-white/5">
                    <CalendarDaysIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No scheduled reports</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Schedule automated reports to be sent via email.</p>
                    <div class="mt-6">
                        <Button size="sm" @click="openCreateModal">Add Report</Button>
                    </div>
                </div>
            </div>
        </SettingsLayout>

        <!-- Form Modal -->
        <Teleport to="body">
            <div v-if="showFormModal" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="closeModals"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div class="absolute right-4 top-4">
                                <button @click="closeModals" class="text-gray-400 hover:text-gray-500">
                                    <XMarkIcon class="h-6 w-6" />
                                </button>
                            </div>

                            <div class="mb-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ isEditing ? 'Edit Scheduled Report' : 'Add Scheduled Report' }}
                                </h3>
                            </div>

                            <div class="space-y-4 max-h-[60vh] overflow-y-auto">
                                <!-- Report Type -->
                                <div>
                                    <Label>Report Type *</Label>
                                    <select v-model="form.report_type" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                        <option value="">Select report type...</option>
                                        <option v-for="type in reportTypes" :key="type.value" :value="type.value">
                                            {{ type.label }}
                                        </option>
                                    </select>
                                    <p v-if="formErrors.report_type" class="mt-1 text-sm text-red-500">{{ formErrors.report_type }}</p>
                                </div>

                                <!-- Name -->
                                <div>
                                    <Label>Custom Name (optional)</Label>
                                    <Input v-model="form.name" type="text" placeholder="e.g., Morning Sales Summary" class="mt-1" />
                                </div>

                                <!-- Recipients -->
                                <div>
                                    <Label>Recipients *</Label>
                                    <div class="mt-2 space-y-2">
                                        <div v-for="(recipient, index) in form.recipients" :key="index" class="flex items-center gap-2">
                                            <Input v-model="form.recipients[index]" type="email" placeholder="email@example.com" class="flex-1" />
                                            <button v-if="form.recipients.length > 1" @click="removeRecipient(index)" class="p-2 text-red-500 hover:text-red-700">
                                                <XMarkIcon class="h-4 w-4" />
                                            </button>
                                        </div>
                                        <Button variant="outline" size="sm" @click="addRecipient">Add Recipient</Button>
                                    </div>
                                    <p v-if="formErrors.recipients" class="mt-1 text-sm text-red-500">{{ formErrors.recipients }}</p>
                                </div>

                                <!-- Schedule Time -->
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label>Send Time *</Label>
                                        <Input v-model="form.schedule_time" type="time" class="mt-1" />
                                    </div>
                                    <div>
                                        <Label>Timezone *</Label>
                                        <select v-model="form.timezone" class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                            <option v-for="tz in timezones" :key="tz.value" :value="tz.value">
                                                {{ tz.label }}
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Schedule Days -->
                                <div>
                                    <Label>Frequency</Label>
                                    <div class="mt-2 flex items-center gap-4">
                                        <label class="flex items-center gap-2">
                                            <input v-model="scheduleMode" type="radio" value="daily" class="h-4 w-4 text-indigo-600" />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Daily</span>
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input v-model="scheduleMode" type="radio" value="specific" class="h-4 w-4 text-indigo-600" />
                                            <span class="text-sm text-gray-700 dark:text-gray-300">Specific days</span>
                                        </label>
                                    </div>

                                    <div v-if="scheduleMode === 'specific'" class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            v-for="day in daysOfWeek"
                                            :key="day.value"
                                            @click="toggleDay(day.value)"
                                            :class="[
                                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                                isDaySelected(day.value)
                                                    ? 'bg-indigo-600 text-white'
                                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600',
                                            ]"
                                        >
                                            {{ day.label }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Enabled -->
                                <div class="flex items-center gap-2">
                                    <input id="is_enabled" v-model="form.is_enabled" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600" />
                                    <Label for="is_enabled" class="mb-0">Enabled</Label>
                                </div>
                            </div>

                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button @click="submitForm" :disabled="!form.report_type || isSaving" class="sm:col-start-2">
                                    {{ isSaving ? 'Saving...' : (isEditing ? 'Save Changes' : 'Create') }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 sm:col-start-1 sm:mt-0">Cancel</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal && selectedReport" class="relative z-50">
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="closeModals"></div>
                <div class="fixed inset-0 z-10 overflow-y-auto">
                    <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-0">
                        <div class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl sm:my-8 sm:w-full sm:max-w-sm sm:p-6">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-500/10">
                                <TrashIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                            </div>
                            <div class="mt-3 text-center sm:mt-5">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Delete scheduled report</h3>
                                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                    Are you sure you want to delete "{{ selectedReport.display_name }}"? This action cannot be undone.
                                </p>
                            </div>
                            <div class="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                <Button variant="destructive" @click="deleteReport" :disabled="isDeleting" class="sm:col-start-2">
                                    {{ isDeleting ? 'Deleting...' : 'Delete' }}
                                </Button>
                                <Button variant="outline" @click="closeModals" class="mt-3 sm:col-start-1 sm:mt-0">Cancel</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
