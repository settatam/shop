<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    SparklesIcon,
    ArrowRightIcon,
    XMarkIcon,
    LinkIcon,
} from '@heroicons/vue/20/solid';

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

interface FieldMapping {
    [templateField: string]: string;
}

interface MetafieldMapping {
    [templateField: string]: {
        namespace: string;
        key: string;
        enabled: boolean;
    };
}

interface AISuggestion {
    templateField: string;
    platformField: string;
    confidence: number;
}

interface Props {
    templateFields: TemplateField[];
    platformFields: PlatformField[];
    fieldMappings: FieldMapping;
    metafieldMappings?: MetafieldMapping;
    supportsMetafields?: boolean;
    suggestions?: AISuggestion[];
    suggestingAi?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    metafieldMappings: () => ({}),
    supportsMetafields: false,
    suggestions: () => [],
    suggestingAi: false,
});

const emit = defineEmits<{
    (e: 'update:fieldMappings', value: FieldMapping): void;
    (e: 'update:metafieldMappings', value: MetafieldMapping): void;
    (e: 'suggestMappings'): void;
}>();

// Local state
const localMappings = ref<FieldMapping>({ ...props.fieldMappings });
const localMetafieldMappings = ref<MetafieldMapping>({ ...props.metafieldMappings });
const draggedField = ref<string | null>(null);
const hoveredTarget = ref<string | null>(null);

// Sync with props
watch(() => props.fieldMappings, (newVal) => {
    localMappings.value = { ...newVal };
}, { deep: true });

watch(() => props.metafieldMappings, (newVal) => {
    localMetafieldMappings.value = { ...newVal };
}, { deep: true });

// Computed
const unmappedTemplateFields = computed(() => {
    return props.templateFields.filter(f => !localMappings.value[f.name]);
});

const mappedFields = computed(() => {
    return Object.entries(localMappings.value).map(([templateField, platformField]) => {
        const template = props.templateFields.find(f => f.name === templateField);
        const platform = props.platformFields.find(f => f.name === platformField);
        return {
            templateField,
            platformField,
            templateLabel: template?.label || templateField,
            platformLabel: platform?.label || platformField,
            isRequired: platform?.is_required || false,
        };
    });
});

const unmappedRequiredFields = computed(() => {
    const mappedPlatformFields = Object.values(localMappings.value);
    return props.platformFields.filter(
        f => f.is_required && !mappedPlatformFields.includes(f.name)
    );
});

// Drag & Drop
function handleDragStart(fieldName: string) {
    draggedField.value = fieldName;
}

function handleDragEnd() {
    draggedField.value = null;
    hoveredTarget.value = null;
}

function handleDragOver(event: DragEvent, targetField: string) {
    event.preventDefault();
    hoveredTarget.value = targetField;
}

function handleDragLeave() {
    hoveredTarget.value = null;
}

function handleDrop(platformFieldName: string) {
    if (!draggedField.value) return;

    // Remove any existing mapping for this template field
    const existingPlatformField = localMappings.value[draggedField.value];
    if (existingPlatformField) {
        delete localMappings.value[existingPlatformField];
    }

    // Remove any existing mapping to this platform field
    Object.entries(localMappings.value).forEach(([tField, pField]) => {
        if (pField === platformFieldName) {
            delete localMappings.value[tField];
        }
    });

    // Create new mapping
    localMappings.value[draggedField.value] = platformFieldName;
    emitMappings();

    draggedField.value = null;
    hoveredTarget.value = null;
}

function removeMapping(templateField: string) {
    delete localMappings.value[templateField];
    emitMappings();
}

function emitMappings() {
    emit('update:fieldMappings', { ...localMappings.value });
}

// Metafield handling
function toggleMetafield(templateField: string, enabled: boolean) {
    if (enabled) {
        localMetafieldMappings.value[templateField] = {
            namespace: 'custom',
            key: templateField.toLowerCase().replace(/[^a-z0-9_]/g, '_'),
            enabled: true,
        };
    } else {
        delete localMetafieldMappings.value[templateField];
    }
    emit('update:metafieldMappings', { ...localMetafieldMappings.value });
}

function updateMetafieldKey(templateField: string, key: string) {
    if (localMetafieldMappings.value[templateField]) {
        localMetafieldMappings.value[templateField].key = key;
        emit('update:metafieldMappings', { ...localMetafieldMappings.value });
    }
}

function updateMetafieldNamespace(templateField: string, namespace: string) {
    if (localMetafieldMappings.value[templateField]) {
        localMetafieldMappings.value[templateField].namespace = namespace;
        emit('update:metafieldMappings', { ...localMetafieldMappings.value });
    }
}

// Apply AI suggestions
function applySuggestion(suggestion: AISuggestion) {
    localMappings.value[suggestion.templateField] = suggestion.platformField;
    emitMappings();
}

function applyAllSuggestions() {
    props.suggestions.forEach(suggestion => {
        localMappings.value[suggestion.templateField] = suggestion.platformField;
    });
    emitMappings();
}

function getConfidenceColor(confidence: number): string {
    if (confidence >= 0.8) return 'text-green-600 dark:text-green-400';
    if (confidence >= 0.5) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-red-600 dark:text-red-400';
}
</script>

<template>
    <div class="space-y-6">
        <!-- AI Suggestions Header -->
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">Field Mappings</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Drag template fields to platform fields to create mappings
                </p>
            </div>
            <Button
                variant="outline"
                size="sm"
                @click="$emit('suggestMappings')"
                :disabled="suggestingAi"
            >
                <SparklesIcon class="h-4 w-4 mr-1" :class="{ 'animate-pulse': suggestingAi }" />
                {{ suggestingAi ? 'Analyzing...' : 'AI Suggest' }}
            </Button>
        </div>

        <!-- AI Suggestions -->
        <div
            v-if="suggestions.length > 0"
            class="rounded-lg bg-indigo-50 dark:bg-indigo-900/20 p-4"
        >
            <div class="flex items-center justify-between mb-3">
                <h5 class="text-sm font-medium text-indigo-900 dark:text-indigo-300 flex items-center gap-2">
                    <SparklesIcon class="h-4 w-4" />
                    AI Suggestions
                </h5>
                <Button variant="ghost" size="sm" @click="applyAllSuggestions">
                    Apply All
                </Button>
            </div>
            <div class="space-y-2">
                <div
                    v-for="suggestion in suggestions"
                    :key="suggestion.templateField"
                    class="flex items-center justify-between p-2 rounded bg-white dark:bg-gray-800"
                >
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">{{ suggestion.templateField }}</span>
                        <ArrowRightIcon class="h-4 w-4 text-gray-400" />
                        <span class="font-medium text-gray-900 dark:text-white">{{ suggestion.platformField }}</span>
                        <span :class="['text-xs', getConfidenceColor(suggestion.confidence)]">
                            {{ Math.round(suggestion.confidence * 100) }}%
                        </span>
                    </div>
                    <Button variant="ghost" size="sm" @click="applySuggestion(suggestion)">
                        Apply
                    </Button>
                </div>
            </div>
        </div>

        <!-- Mapping Area -->
        <div class="grid grid-cols-2 gap-6">
            <!-- Template Fields (Left) -->
            <div>
                <h5 class="text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-3">
                    Template Fields
                </h5>
                <div class="space-y-2">
                    <div
                        v-for="field in unmappedTemplateFields"
                        :key="field.name"
                        class="p-3 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 cursor-move hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors"
                        draggable="true"
                        @dragstart="handleDragStart(field.name)"
                        @dragend="handleDragEnd"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ field.label }}
                            </span>
                            <Badge variant="outline" class="text-xs">
                                {{ field.type }}
                            </Badge>
                        </div>
                    </div>

                    <p v-if="unmappedTemplateFields.length === 0" class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">
                        All fields mapped
                    </p>
                </div>
            </div>

            <!-- Platform Fields (Right) -->
            <div>
                <h5 class="text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-3">
                    Platform Fields
                </h5>
                <div class="space-y-2">
                    <div
                        v-for="field in platformFields"
                        :key="field.name"
                        class="p-3 rounded-lg border-2 transition-colors"
                        :class="[
                            hoveredTarget === field.name
                                ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                                : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800',
                            Object.values(localMappings).includes(field.name)
                                ? 'opacity-50'
                                : ''
                        ]"
                        @dragover="handleDragOver($event, field.name)"
                        @dragleave="handleDragLeave"
                        @drop="handleDrop(field.name)"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ field.label }}
                                </span>
                                <Badge
                                    v-if="field.is_required"
                                    variant="destructive"
                                    class="text-xs"
                                >
                                    Required
                                </Badge>
                            </div>
                            <Badge variant="outline" class="text-xs">
                                {{ field.type }}
                            </Badge>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Mappings -->
        <div v-if="mappedFields.length > 0">
            <h5 class="text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-3">
                Active Mappings
            </h5>
            <div class="space-y-2">
                <div
                    v-for="mapping in mappedFields"
                    :key="mapping.templateField"
                    class="flex items-center gap-3 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800"
                >
                    <LinkIcon class="h-4 w-4 text-green-600 dark:text-green-400 flex-shrink-0" />
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ mapping.templateLabel }}</span>
                    <ArrowRightIcon class="h-4 w-4 text-gray-400 flex-shrink-0" />
                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ mapping.platformLabel }}</span>
                    <div class="flex-1" />
                    <Button variant="ghost" size="sm" @click="removeMapping(mapping.templateField)">
                        <XMarkIcon class="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>

        <!-- Unmapped Required Fields Warning -->
        <div
            v-if="unmappedRequiredFields.length > 0"
            class="rounded-lg bg-yellow-50 dark:bg-yellow-900/20 p-4"
        >
            <h5 class="text-sm font-medium text-yellow-800 dark:text-yellow-300 mb-2">
                Unmapped Required Fields
            </h5>
            <div class="flex flex-wrap gap-2">
                <Badge
                    v-for="field in unmappedRequiredFields"
                    :key="field.name"
                    variant="outline"
                    class="text-xs text-yellow-700 dark:text-yellow-400 border-yellow-300 dark:border-yellow-700"
                >
                    {{ field.label }}
                </Badge>
            </div>
        </div>

        <!-- Metafield Configuration (for supported platforms) -->
        <div v-if="supportsMetafields && templateFields.length > 0" class="border-t border-gray-200 dark:border-gray-700 pt-6">
            <h5 class="text-xs font-medium text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-3">
                Metafield Configuration
            </h5>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                Enable metafields for template attributes that don't have a standard platform field.
            </p>

            <div class="space-y-3">
                <div
                    v-for="field in templateFields"
                    :key="`metafield-${field.name}`"
                    class="flex items-center gap-4 p-3 rounded-lg border border-gray-200 dark:border-gray-700"
                >
                    <Checkbox
                        :id="`metafield-${field.name}`"
                        :checked="!!localMetafieldMappings[field.name]?.enabled"
                        @update:checked="(val: boolean) => toggleMetafield(field.name, val)"
                    />
                    <Label :for="`metafield-${field.name}`" class="flex-1 cursor-pointer">
                        {{ field.label }}
                    </Label>

                    <div
                        v-if="localMetafieldMappings[field.name]?.enabled"
                        class="flex items-center gap-2"
                    >
                        <Input
                            :model-value="localMetafieldMappings[field.name]?.namespace || 'custom'"
                            @update:model-value="(val: string) => updateMetafieldNamespace(field.name, val)"
                            placeholder="Namespace"
                            class="w-24 text-xs"
                        />
                        <span class="text-gray-400">.</span>
                        <Input
                            :model-value="localMetafieldMappings[field.name]?.key || ''"
                            @update:model-value="(val: string) => updateMetafieldKey(field.name, val)"
                            placeholder="Key"
                            class="w-32 text-xs"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
