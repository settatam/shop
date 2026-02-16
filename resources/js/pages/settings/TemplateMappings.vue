<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import FieldMapper from '@/components/platforms/FieldMapper.vue';
import {
    ChevronDownIcon,
    ChevronRightIcon,
    CheckCircleIcon,
    ExclamationCircleIcon,
    PlusIcon,
} from '@heroicons/vue/20/solid';
import axios from 'axios';

interface TemplateField {
    name: string;
    label: string;
    type: string;
}

interface PlatformField {
    name: string;
    label: string;
    type: string;
    is_required: boolean;
    field_type: 'standard' | 'metafield' | 'item_specific';
}

interface PlatformMapping {
    id: number | null;
    platform: string;
    platform_label: string;
    field_mappings: Record<string, string>;
    metafield_mappings: Record<string, { namespace: string; key: string; enabled: boolean }>;
    is_ai_generated: boolean;
    mapped_count: number;
    required_count: number;
    unmapped_required_count: number;
}

interface Template {
    id: number;
    name: string;
    description: string | null;
    fields: TemplateField[];
    mappings: PlatformMapping[];
}

interface AvailablePlatform {
    value: string;
    label: string;
    supports_metafields: boolean;
}

interface Props {
    templates: Template[];
    availablePlatforms: AvailablePlatform[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Template Mappings', href: '/settings/template-mappings' },
];

// State
const expandedTemplates = ref<number[]>([]);
const selectedMapping = ref<{ template: Template; platform: string } | null>(null);
const showMappingModal = ref(false);
const loadingPlatformFields = ref(false);
const platformFields = ref<PlatformField[]>([]);
const localFieldMappings = ref<Record<string, string>>({});
const localMetafieldMappings = ref<Record<string, { namespace: string; key: string; enabled: boolean }>>({});
const aiSuggestions = ref<Array<{ templateField: string; platformField: string; confidence: number }>>([]);
const suggestingAi = ref(false);
const saving = ref(false);

// Platform icons
const platformIcons: Record<string, string> = {
    shopify: '/images/platforms/shopify.svg',
    ebay: '/images/platforms/ebay.svg',
    amazon: '/images/platforms/amazon.svg',
    etsy: '/images/platforms/etsy.svg',
    walmart: '/images/platforms/walmart.svg',
};

function toggleTemplate(templateId: number) {
    const index = expandedTemplates.value.indexOf(templateId);
    if (index === -1) {
        expandedTemplates.value.push(templateId);
    } else {
        expandedTemplates.value.splice(index, 1);
    }
}

function isExpanded(templateId: number): boolean {
    return expandedTemplates.value.includes(templateId);
}

function getMappingStatus(mapping: PlatformMapping): 'complete' | 'partial' | 'none' {
    if (mapping.unmapped_required_count === 0 && mapping.mapped_count > 0) return 'complete';
    if (mapping.mapped_count > 0) return 'partial';
    return 'none';
}

function getStatusColor(status: 'complete' | 'partial' | 'none'): string {
    switch (status) {
        case 'complete':
            return 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400';
        case 'partial':
            return 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400';
        default:
            return 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-400';
    }
}

async function openMappingModal(template: Template, platform: string) {
    selectedMapping.value = { template, platform };
    showMappingModal.value = true;
    loadingPlatformFields.value = true;
    aiSuggestions.value = [];

    // Find existing mapping
    const existingMapping = template.mappings.find(m => m.platform === platform);
    localFieldMappings.value = existingMapping?.field_mappings || {};
    localMetafieldMappings.value = existingMapping?.metafield_mappings || {};

    try {
        const response = await axios.get(`/settings/template-mappings/${template.id}/${platform}/fields`);
        platformFields.value = response.data.fields;
    } catch (error) {
        console.error('Failed to load platform fields:', error);
    } finally {
        loadingPlatformFields.value = false;
    }
}

async function suggestMappings() {
    if (!selectedMapping.value) return;

    suggestingAi.value = true;
    try {
        const response = await axios.post(
            `/settings/template-mappings/${selectedMapping.value.template.id}/${selectedMapping.value.platform}/suggest`
        );
        aiSuggestions.value = response.data.suggestions || [];
    } catch (error) {
        console.error('AI suggestion failed:', error);
    } finally {
        suggestingAi.value = false;
    }
}

async function saveMapping() {
    if (!selectedMapping.value) return;

    saving.value = true;
    try {
        await axios.put(
            `/settings/template-mappings/${selectedMapping.value.template.id}/${selectedMapping.value.platform}`,
            {
                field_mappings: localFieldMappings.value,
                metafield_mappings: localMetafieldMappings.value,
            }
        );
        router.reload({ only: ['templates'] });
        showMappingModal.value = false;
    } catch (error) {
        console.error('Failed to save mapping:', error);
    } finally {
        saving.value = false;
    }
}

function getSelectedPlatform(): AvailablePlatform | undefined {
    return props.availablePlatforms.find(p => p.value === selectedMapping.value?.platform);
}

function closeModal() {
    showMappingModal.value = false;
    selectedMapping.value = null;
}
</script>

<template>
    <Head title="Template Mappings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Template Mappings</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Configure how template fields map to platform-specific fields
                    </p>
                </div>
            </div>

            <!-- Templates List -->
            <div class="space-y-4">
                <div
                    v-for="template in templates"
                    :key="template.id"
                    class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10"
                >
                    <Collapsible :open="isExpanded(template.id)">
                        <CollapsibleTrigger
                            class="w-full px-4 py-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors rounded-lg"
                            @click="toggleTemplate(template.id)"
                        >
                            <div class="flex items-center gap-4">
                                <component
                                    :is="isExpanded(template.id) ? ChevronDownIcon : ChevronRightIcon"
                                    class="h-5 w-5 text-gray-400"
                                />
                                <div class="text-left">
                                    <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ template.name }}
                                    </h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ template.fields.length }} fields
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge
                                    v-for="mapping in template.mappings"
                                    :key="mapping.platform"
                                    :class="['text-xs ring-1 ring-inset', getStatusColor(getMappingStatus(mapping))]"
                                >
                                    {{ mapping.platform_label }}
                                </Badge>
                            </div>
                        </CollapsibleTrigger>

                        <CollapsibleContent>
                            <div class="px-4 pb-4 border-t border-gray-200 dark:border-gray-700">
                                <div class="pt-4 space-y-3">
                                    <!-- Platform Mappings -->
                                    <div
                                        v-for="platform in availablePlatforms"
                                        :key="platform.value"
                                        class="flex items-center justify-between p-3 rounded-lg border border-gray-200 dark:border-gray-700"
                                    >
                                        <div class="flex items-center gap-3">
                                            <div class="h-8 w-8 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                                <img
                                                    v-if="platformIcons[platform.value]"
                                                    :src="platformIcons[platform.value]"
                                                    :alt="platform.label"
                                                    class="h-5 w-5"
                                                />
                                                <span v-else class="text-xs font-medium text-gray-500 uppercase">
                                                    {{ platform.value.slice(0, 2) }}
                                                </span>
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                    {{ platform.label }}
                                                </p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                                    <template v-if="template.mappings.find(m => m.platform === platform.value)">
                                                        {{ template.mappings.find(m => m.platform === platform.value)?.mapped_count }} fields mapped
                                                    </template>
                                                    <template v-else>
                                                        Not configured
                                                    </template>
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-3">
                                            <Badge
                                                v-if="template.mappings.find(m => m.platform === platform.value)"
                                                :class="[
                                                    'text-xs ring-1 ring-inset',
                                                    getStatusColor(getMappingStatus(template.mappings.find(m => m.platform === platform.value)!))
                                                ]"
                                            >
                                                <CheckCircleIcon
                                                    v-if="getMappingStatus(template.mappings.find(m => m.platform === platform.value)!) === 'complete'"
                                                    class="h-3 w-3 mr-1"
                                                />
                                                <ExclamationCircleIcon
                                                    v-else-if="getMappingStatus(template.mappings.find(m => m.platform === platform.value)!) === 'partial'"
                                                    class="h-3 w-3 mr-1"
                                                />
                                                {{ getMappingStatus(template.mappings.find(m => m.platform === platform.value)!) }}
                                            </Badge>

                                            <Button
                                                variant="outline"
                                                size="sm"
                                                @click="openMappingModal(template, platform.value)"
                                            >
                                                {{ template.mappings.find(m => m.platform === platform.value) ? 'Edit' : 'Configure' }}
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>

                <!-- Empty State -->
                <div
                    v-if="templates.length === 0"
                    class="rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10 p-8 text-center"
                >
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No templates found. Create a product template first to configure platform mappings.
                    </p>
                </div>
            </div>
        </div>

        <!-- Mapping Modal -->
        <Dialog :open="showMappingModal" @update:open="closeModal">
            <DialogContent class="max-w-4xl max-h-[90vh] flex flex-col">
                <DialogHeader>
                    <DialogTitle>
                        Configure {{ getSelectedPlatform()?.label }} Mapping for {{ selectedMapping?.template.name }}
                    </DialogTitle>
                </DialogHeader>

                <div class="flex-1 overflow-y-auto py-4">
                    <!-- Loading State -->
                    <div v-if="loadingPlatformFields" class="space-y-4">
                        <Skeleton class="h-6 w-32" />
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <Skeleton class="h-12 w-full" />
                                <Skeleton class="h-12 w-full" />
                                <Skeleton class="h-12 w-full" />
                            </div>
                            <div class="space-y-2">
                                <Skeleton class="h-12 w-full" />
                                <Skeleton class="h-12 w-full" />
                                <Skeleton class="h-12 w-full" />
                            </div>
                        </div>
                    </div>

                    <!-- Field Mapper -->
                    <FieldMapper
                        v-else-if="selectedMapping"
                        :template-fields="selectedMapping.template.fields"
                        :platform-fields="platformFields"
                        :field-mappings="localFieldMappings"
                        :metafield-mappings="localMetafieldMappings"
                        :supports-metafields="getSelectedPlatform()?.supports_metafields || false"
                        :suggestions="aiSuggestions"
                        :suggesting-ai="suggestingAi"
                        @update:field-mappings="localFieldMappings = $event"
                        @update:metafield-mappings="localMetafieldMappings = $event"
                        @suggest-mappings="suggestMappings"
                    />
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="closeModal">Cancel</Button>
                    <Button @click="saveMapping" :disabled="saving">
                        {{ saving ? 'Saving...' : 'Save Mapping' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
