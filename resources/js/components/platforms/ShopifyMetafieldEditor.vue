<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
    CheckCircleIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';

interface MetafieldDefinition {
    id: number;
    name: string;
    key: string;
    namespace: string;
    type: string;
    description: string | null;
    mapped_template_field: string | null;
    resolved_value: string | null;
    is_listing_override: boolean;
}

interface TemplateField {
    id: number;
    name: string;
    label: string;
    type: string;
    is_required: boolean;
    is_private: boolean;
    value: string | null;
    options: Array<{ value: string; label: string }>;
}

interface ShopifyMetafieldsData {
    definitions: MetafieldDefinition[];
    has_definitions: boolean;
}

interface Props {
    data: ShopifyMetafieldsData;
    templateFields: TemplateField[];
    aiSuggestions?: Record<string, string> | null;
    aiLoading?: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'field-mapping-changed', mappings: Record<string, string>): void;
    (e: 'value-overrides-changed', overrides: Record<string, string>): void;
}>();

// Local state tracking changes
const localMappings = ref<Record<string, string>>(buildInitialMappings());
const localOverrides = ref<Record<string, string>>(buildInitialOverrides());

function fullKey(def: MetafieldDefinition): string {
    return `${def.namespace}.${def.key}`;
}

function buildInitialMappings(): Record<string, string> {
    const mappings: Record<string, string> = {};
    for (const def of props.data.definitions) {
        if (def.mapped_template_field) {
            mappings[fullKey(def)] = def.mapped_template_field;
        }
    }
    return mappings;
}

function buildInitialOverrides(): Record<string, string> {
    const overrides: Record<string, string> = {};
    for (const def of props.data.definitions) {
        if (def.is_listing_override && def.resolved_value) {
            overrides[fullKey(def)] = def.resolved_value;
        }
    }
    return overrides;
}

// Build a lookup of template field values
const templateFieldValues = computed(() => {
    const lookup: Record<string, string> = {};
    for (const field of props.templateFields) {
        if (field.value) {
            // Resolve display value for select fields
            if (field.options && field.options.length > 0) {
                const option = field.options.find(opt => opt.value === field.value);
                lookup[field.name] = option ? option.label : field.value;
            } else {
                lookup[field.name] = field.value;
            }
        }
    }
    return lookup;
});

// Compute the effective value for each definition
function getEffectiveValue(def: MetafieldDefinition): string {
    const key = fullKey(def);
    // Listing override takes precedence
    if (localOverrides.value[key] !== undefined) {
        return localOverrides.value[key];
    }
    // Mapped template field value
    const mappedField = localMappings.value[key];
    if (mappedField && templateFieldValues.value[mappedField]) {
        return templateFieldValues.value[mappedField];
    }
    // Server-resolved value
    return def.resolved_value ?? '';
}

function hasValue(def: MetafieldDefinition): boolean {
    return getEffectiveValue(def) !== '';
}

const mappedCount = computed(() => {
    return props.data.definitions.filter((d) => hasValue(d)).length;
});

function updateMapping(def: MetafieldDefinition, templateFieldName: string) {
    const key = fullKey(def);
    if (templateFieldName) {
        localMappings.value[key] = templateFieldName;
    } else {
        delete localMappings.value[key];
    }
    emit('field-mapping-changed', { ...localMappings.value });
}

function updateValueOverride(def: MetafieldDefinition, value: string) {
    const key = fullKey(def);
    if (value !== '') {
        localOverrides.value[key] = value;
    } else {
        delete localOverrides.value[key];
    }
    emit('value-overrides-changed', { ...localOverrides.value });
}

function clearOverride(def: MetafieldDefinition) {
    const key = fullKey(def);
    delete localOverrides.value[key];
    emit('value-overrides-changed', { ...localOverrides.value });
}

function autoMap() {
    for (const def of props.data.definitions) {
        const key = fullKey(def);
        if (localMappings.value[key]) continue;

        const normalizedName = def.name.toLowerCase().replace(/[^a-z0-9]/g, '');
        const normalizedKey = def.key.toLowerCase().replace(/[^a-z0-9]/g, '');

        const match = props.templateFields.find((f) => {
            const normalizedField = f.name.toLowerCase().replace(/[^a-z0-9]/g, '');
            const normalizedLabel = f.label.toLowerCase().replace(/[^a-z0-9]/g, '');
            return (
                normalizedField === normalizedName ||
                normalizedLabel === normalizedName ||
                normalizedField === normalizedKey ||
                normalizedLabel === normalizedKey
            );
        });

        if (match) {
            localMappings.value[key] = match.name;
        }
    }
    emit('field-mapping-changed', { ...localMappings.value });
}

// Watch for AI suggestions and merge them into localOverrides
watch(() => props.aiSuggestions, (suggestions) => {
    if (!suggestions) return;
    for (const [key, value] of Object.entries(suggestions)) {
        // Only set if user hasn't already set an override
        if (localOverrides.value[key] === undefined && value) {
            localOverrides.value[key] = String(value);
        }
    }
    emit('value-overrides-changed', { ...localOverrides.value });
});

function metafieldTypeBadge(type: string): string {
    const typeMap: Record<string, string> = {
        single_line_text_field: 'Text',
        multi_line_text_field: 'Multiline',
        number_integer: 'Integer',
        number_decimal: 'Decimal',
        boolean: 'Boolean',
        json: 'JSON',
        color: 'Color',
        date: 'Date',
        date_time: 'DateTime',
        url: 'URL',
        rich_text_field: 'Rich Text',
        dimension: 'Dimension',
        weight: 'Weight',
        volume: 'Volume',
    };
    return typeMap[type] || type;
}
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">Shopify Metafields</h4>
                <p class="text-xs text-muted-foreground mt-0.5">
                    Map your template fields to Shopify metafield definitions.
                    <span v-if="data.definitions.length > 0">
                        {{ mappedCount }}/{{ data.definitions.length }} have values.
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button type="button" variant="outline" size="sm" @click="autoMap">
                    Auto-Map
                </Button>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="data.definitions.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
            No metafield definitions found. Sync metafield definitions from your Shopify Marketplace Settings page first.
        </div>

        <!-- Column headers -->
        <div v-if="data.definitions.length > 0" class="text-[10px] text-muted-foreground grid grid-cols-12 gap-3 px-2">
            <div class="col-span-3">Metafield</div>
            <div class="col-span-3">Template Field</div>
            <div class="col-span-2">Type</div>
            <div class="col-span-3">Value to Send</div>
            <div class="col-span-1"></div>
        </div>

        <!-- Definitions list -->
        <div v-if="data.definitions.length > 0" class="space-y-2">
            <div
                v-for="def in data.definitions"
                :key="def.id"
                class="grid grid-cols-12 items-center gap-3 p-2 rounded-md border dark:border-gray-700"
                :class="{
                    'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-900/10': hasValue(def),
                }"
            >
                <!-- Metafield Name -->
                <div class="col-span-3 min-w-0">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm font-medium truncate">{{ def.name }}</span>
                        <span class="text-[10px] text-muted-foreground truncate">{{ def.namespace }}.{{ def.key }}</span>
                    </div>
                </div>

                <!-- Template Field -->
                <div class="col-span-3">
                    <select
                        :value="localMappings[fullKey(def)] ?? ''"
                        class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                        @change="updateMapping(def, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">-- Map to field --</option>
                        <option v-for="field in templateFields" :key="field.name" :value="field.name">
                            {{ field.label || field.name }}
                        </option>
                    </select>
                </div>

                <!-- Type -->
                <div class="col-span-2">
                    <Badge variant="secondary" class="text-[10px]">
                        {{ metafieldTypeBadge(def.type) }}
                    </Badge>
                </div>

                <!-- Value to Send -->
                <div class="col-span-3 flex items-center gap-1">
                    <Input
                        :model-value="getEffectiveValue(def)"
                        type="text"
                        placeholder="Value to send"
                        class="text-xs h-8 flex-1"
                        :class="{ 'animate-pulse bg-indigo-50 dark:bg-indigo-900/20': aiLoading && !hasValue(def) }"
                        @update:model-value="updateValueOverride(def, $event as string)"
                    />
                    <button
                        v-if="localOverrides[fullKey(def)] !== undefined"
                        class="shrink-0 p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                        title="Clear override"
                        @click="clearOverride(def)"
                    >
                        <XMarkIcon class="h-3.5 w-3.5 text-gray-400" />
                    </button>
                </div>

                <!-- Status icon -->
                <div class="col-span-1 flex justify-end">
                    <CheckCircleIcon
                        v-if="hasValue(def)"
                        class="h-4 w-4 text-green-500 shrink-0"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
