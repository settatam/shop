<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
    ArrowPathIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';

interface ItemSpecific {
    id: number;
    name: string;
    is_required: boolean;
    is_recommended: boolean;
    aspect_mode: string;
    allowed_values: string[];
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

interface EbayItemSpecificsData {
    specifics: ItemSpecific[];
    category_mapping_id: number | null;
    category_id: number | null;
    synced_at: string | null;
    needs_sync: boolean;
}

interface Props {
    data: EbayItemSpecificsData;
    templateFields: TemplateField[];
    aiSuggestions?: Record<string, string> | null;
    aiLoading?: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'field-mapping-changed', mappings: Record<string, string>): void;
    (e: 'value-overrides-changed', overrides: Record<string, string>): void;
    (e: 'sync-requested'): void;
}>();

// Local state tracking changes
const localMappings = ref<Record<string, string>>(buildInitialMappings());
const localOverrides = ref<Record<string, string>>(buildInitialOverrides());
const syncing = ref(false);

function buildInitialMappings(): Record<string, string> {
    const mappings: Record<string, string> = {};
    for (const specific of props.data.specifics) {
        if (specific.mapped_template_field) {
            mappings[specific.name] = specific.mapped_template_field;
        }
    }
    return mappings;
}

function buildInitialOverrides(): Record<string, string> {
    const overrides: Record<string, string> = {};
    for (const specific of props.data.specifics) {
        if (specific.is_listing_override && specific.resolved_value) {
            overrides[specific.name] = specific.resolved_value;
        }
    }
    return overrides;
}

// Build a lookup of template field values for auto-populating
const templateFieldValues = computed(() => {
    const lookup: Record<string, string> = {};
    for (const field of props.templateFields) {
        if (field.value) {
            lookup[field.name] = field.value;
        }
    }
    return lookup;
});

// Compute the effective value for each specific
function getEffectiveValue(specific: ItemSpecific): string {
    // Listing override takes precedence
    if (localOverrides.value[specific.name] !== undefined) {
        return localOverrides.value[specific.name];
    }
    // Mapped template field value
    const mappedField = localMappings.value[specific.name];
    if (mappedField && templateFieldValues.value[mappedField]) {
        return templateFieldValues.value[mappedField];
    }
    // Server-resolved value (if no local changes yet)
    return specific.resolved_value ?? '';
}

function hasValue(specific: ItemSpecific): boolean {
    return getEffectiveValue(specific) !== '';
}

const requiredSpecifics = computed(() => props.data.specifics.filter((s) => s.is_required));
const recommendedSpecifics = computed(() => props.data.specifics.filter((s) => s.is_recommended && !s.is_required));
const optionalSpecifics = computed(() => props.data.specifics.filter((s) => !s.is_required && !s.is_recommended));

const mappedCount = computed(() => {
    return props.data.specifics.filter((s) => hasValue(s)).length;
});

const unmappedRequiredCount = computed(() => {
    return requiredSpecifics.value.filter((s) => !hasValue(s)).length;
});

function updateMapping(specificName: string, templateFieldName: string) {
    if (templateFieldName) {
        localMappings.value[specificName] = templateFieldName;
        // Auto-populate value if no listing override exists
        if (!localOverrides.value[specificName] && templateFieldValues.value[templateFieldName]) {
            // Don't set as override — let it resolve from mapping
        }
    } else {
        delete localMappings.value[specificName];
    }
    emit('field-mapping-changed', { ...localMappings.value });
}

function updateValueOverride(specificName: string, value: string) {
    if (value !== '') {
        localOverrides.value[specificName] = value;
    } else {
        delete localOverrides.value[specificName];
    }
    emit('value-overrides-changed', { ...localOverrides.value });
}

function pickAllowedValue(specificName: string, value: string) {
    if (value) {
        localOverrides.value[specificName] = value;
        emit('value-overrides-changed', { ...localOverrides.value });
    }
}

function clearOverride(specificName: string) {
    delete localOverrides.value[specificName];
    emit('value-overrides-changed', { ...localOverrides.value });
}

function autoMap() {
    for (const specific of props.data.specifics) {
        if (localMappings.value[specific.name]) continue;

        const normalizedName = specific.name.toLowerCase().replace(/[^a-z0-9]/g, '');
        const match = props.templateFields.find((f) => {
            const normalizedField = f.name.toLowerCase().replace(/[^a-z0-9]/g, '');
            const normalizedLabel = f.label.toLowerCase().replace(/[^a-z0-9]/g, '');
            return normalizedField === normalizedName || normalizedLabel === normalizedName;
        });

        if (match) {
            localMappings.value[specific.name] = match.name;
        }
    }
    emit('field-mapping-changed', { ...localMappings.value });
}

// Watch for AI suggestions and merge them into localOverrides
watch(() => props.aiSuggestions, (suggestions) => {
    if (!suggestions) return;
    for (const [name, value] of Object.entries(suggestions)) {
        // Only set if user hasn't already set an override
        if (localOverrides.value[name] === undefined && value) {
            localOverrides.value[name] = value;
        }
    }
    emit('value-overrides-changed', { ...localOverrides.value });
});

async function requestSync() {
    syncing.value = true;
    emit('sync-requested');
    // Parent handles the actual request; syncing state will be reset on reload
}
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">eBay Item Specifics</h4>
                <p class="text-xs text-muted-foreground mt-0.5">
                    Map your template fields to eBay item specifics and set values to send.
                    <span v-if="data.specifics.length > 0">
                        {{ mappedCount }}/{{ data.specifics.length }} have values.
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button type="button" variant="outline" size="sm" @click="autoMap">
                    Auto-Map
                </Button>
                <Button type="button" variant="outline" size="sm" :disabled="syncing" @click="requestSync">
                    <ArrowPathIcon class="h-3.5 w-3.5" :class="{ 'animate-spin': syncing }" />
                </Button>
            </div>
        </div>

        <!-- Warning for unmapped required -->
        <div
            v-if="unmappedRequiredCount > 0"
            class="flex items-center gap-2 p-2.5 rounded-md bg-amber-50 dark:bg-amber-900/20 text-xs text-amber-700 dark:text-amber-400"
        >
            <ExclamationTriangleIcon class="h-4 w-4 shrink-0" />
            {{ unmappedRequiredCount }} required item specific(s) missing values.
        </div>

        <!-- Needs sync warning -->
        <div
            v-if="data.needs_sync"
            class="flex items-center gap-2 p-2.5 rounded-md bg-blue-50 dark:bg-blue-900/20 text-xs text-blue-700 dark:text-blue-400"
        >
            <ArrowPathIcon class="h-4 w-4 shrink-0" />
            Item specifics may be outdated.
            <button class="underline font-medium" @click="requestSync">Refresh from eBay</button>
        </div>

        <!-- Empty state -->
        <div v-if="data.specifics.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
            No item specifics found for this eBay category. Try syncing from eBay.
        </div>

        <!-- Specifics list -->
        <div v-else class="space-y-6">
            <!-- Required -->
            <div v-if="requiredSpecifics.length > 0">
                <h5 class="text-xs font-medium uppercase text-muted-foreground tracking-wider mb-2">Required</h5>
                <div class="space-y-2">
                    <div
                        v-for="specific in requiredSpecifics"
                        :key="specific.id"
                        class="grid grid-cols-12 items-center gap-3 p-2 rounded-md border dark:border-gray-700"
                        :class="{
                            'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-900/10': hasValue(specific),
                            'border-amber-200 bg-amber-50/50 dark:border-amber-800 dark:bg-amber-900/10': !hasValue(specific),
                        }"
                    >
                        <!-- eBay Field -->
                        <div class="col-span-3 min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-sm font-medium truncate">{{ specific.name }}</span>
                                <Badge variant="destructive" class="text-[10px] shrink-0">Required</Badge>
                            </div>
                        </div>

                        <!-- Template Field -->
                        <div class="col-span-3">
                            <select
                                :value="localMappings[specific.name] ?? ''"
                                class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                @change="updateMapping(specific.name, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">-- Map to field --</option>
                                <option v-for="field in templateFields" :key="field.name" :value="field.name">
                                    {{ field.label || field.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Allowed Values -->
                        <div class="col-span-2">
                            <template v-if="specific.aspect_mode === 'SELECTION_ONLY' && specific.allowed_values.length > 0">
                                <select
                                    :value="''"
                                    class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                    @change="pickAllowedValue(specific.name, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">Pick value...</option>
                                    <option v-for="val in specific.allowed_values.slice(0, 50)" :key="val" :value="val">
                                        {{ val }}
                                    </option>
                                </select>
                            </template>
                            <span v-else class="text-xs text-muted-foreground">(free text)</span>
                        </div>

                        <!-- Value to Send -->
                        <div class="col-span-3 flex items-center gap-1">
                            <Input
                                :model-value="getEffectiveValue(specific)"
                                type="text"
                                placeholder="Value to send"
                                class="text-xs h-8 flex-1"
                                :class="{ 'animate-pulse bg-indigo-50 dark:bg-indigo-900/20': aiLoading && !hasValue(specific) }"
                                @update:model-value="updateValueOverride(specific.name, $event as string)"
                            />
                            <button
                                v-if="localOverrides[specific.name] !== undefined"
                                class="shrink-0 p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                                title="Clear override"
                                @click="clearOverride(specific.name)"
                            >
                                <XMarkIcon class="h-3.5 w-3.5 text-gray-400" />
                            </button>
                        </div>

                        <!-- Status icon -->
                        <div class="col-span-1 flex justify-end">
                            <CheckCircleIcon
                                v-if="hasValue(specific)"
                                class="h-4 w-4 text-green-500 shrink-0"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recommended -->
            <div v-if="recommendedSpecifics.length > 0">
                <h5 class="text-xs font-medium uppercase text-muted-foreground tracking-wider mb-2">Recommended</h5>
                <div class="space-y-2">
                    <div
                        v-for="specific in recommendedSpecifics"
                        :key="specific.id"
                        class="grid grid-cols-12 items-center gap-3 p-2 rounded-md border dark:border-gray-700"
                        :class="{
                            'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-900/10': hasValue(specific),
                        }"
                    >
                        <div class="col-span-3 min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-sm font-medium truncate">{{ specific.name }}</span>
                                <Badge variant="outline" class="text-[10px] shrink-0">Recommended</Badge>
                            </div>
                        </div>

                        <div class="col-span-3">
                            <select
                                :value="localMappings[specific.name] ?? ''"
                                class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                @change="updateMapping(specific.name, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">-- Map to field --</option>
                                <option v-for="field in templateFields" :key="field.name" :value="field.name">
                                    {{ field.label || field.name }}
                                </option>
                            </select>
                        </div>

                        <div class="col-span-2">
                            <template v-if="specific.aspect_mode === 'SELECTION_ONLY' && specific.allowed_values.length > 0">
                                <select
                                    :value="''"
                                    class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                    @change="pickAllowedValue(specific.name, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">Pick value...</option>
                                    <option v-for="val in specific.allowed_values.slice(0, 50)" :key="val" :value="val">
                                        {{ val }}
                                    </option>
                                </select>
                            </template>
                            <span v-else class="text-xs text-muted-foreground">(free text)</span>
                        </div>

                        <div class="col-span-3 flex items-center gap-1">
                            <Input
                                :model-value="getEffectiveValue(specific)"
                                type="text"
                                placeholder="Value to send"
                                class="text-xs h-8 flex-1"
                                :class="{ 'animate-pulse bg-indigo-50 dark:bg-indigo-900/20': aiLoading && !hasValue(specific) }"
                                @update:model-value="updateValueOverride(specific.name, $event as string)"
                            />
                            <button
                                v-if="localOverrides[specific.name] !== undefined"
                                class="shrink-0 p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                                title="Clear override"
                                @click="clearOverride(specific.name)"
                            >
                                <XMarkIcon class="h-3.5 w-3.5 text-gray-400" />
                            </button>
                        </div>

                        <div class="col-span-1 flex justify-end">
                            <CheckCircleIcon
                                v-if="hasValue(specific)"
                                class="h-4 w-4 text-green-500 shrink-0"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Optional (collapsible) -->
            <div v-if="optionalSpecifics.length > 0">
                <details>
                    <summary class="text-xs font-medium uppercase text-muted-foreground tracking-wider mb-2 cursor-pointer select-none">
                        Optional ({{ optionalSpecifics.length }})
                    </summary>
                    <div class="space-y-2 mt-2">
                        <div
                            v-for="specific in optionalSpecifics"
                            :key="specific.id"
                            class="grid grid-cols-12 items-center gap-3 p-2 rounded-md border dark:border-gray-700"
                            :class="{
                                'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-900/10': hasValue(specific),
                            }"
                        >
                            <div class="col-span-3 min-w-0">
                                <span class="text-sm truncate">{{ specific.name }}</span>
                            </div>

                            <div class="col-span-3">
                                <select
                                    :value="localMappings[specific.name] ?? ''"
                                    class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                    @change="updateMapping(specific.name, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">-- Map to field --</option>
                                    <option v-for="field in templateFields" :key="field.name" :value="field.name">
                                        {{ field.label || field.name }}
                                    </option>
                                </select>
                            </div>

                            <div class="col-span-2">
                                <template v-if="specific.aspect_mode === 'SELECTION_ONLY' && specific.allowed_values.length > 0">
                                    <select
                                        :value="''"
                                        class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                        @change="pickAllowedValue(specific.name, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="">Pick value...</option>
                                        <option v-for="val in specific.allowed_values.slice(0, 50)" :key="val" :value="val">
                                            {{ val }}
                                        </option>
                                    </select>
                                </template>
                                <span v-else class="text-xs text-muted-foreground">(free text)</span>
                            </div>

                            <div class="col-span-3 flex items-center gap-1">
                                <Input
                                    :model-value="getEffectiveValue(specific)"
                                    type="text"
                                    placeholder="Value to send"
                                    class="text-xs h-8 flex-1"
                                    @update:model-value="updateValueOverride(specific.name, $event as string)"
                                />
                                <button
                                    v-if="localOverrides[specific.name] !== undefined"
                                    class="shrink-0 p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                                    title="Clear override"
                                    @click="clearOverride(specific.name)"
                                >
                                    <XMarkIcon class="h-3.5 w-3.5 text-gray-400" />
                                </button>
                            </div>

                            <div class="col-span-1 flex justify-end">
                                <CheckCircleIcon
                                    v-if="hasValue(specific)"
                                    class="h-4 w-4 text-green-500 shrink-0"
                                />
                            </div>
                        </div>
                    </div>
                </details>
            </div>
        </div>

        <!-- Column headers for context -->
        <div v-if="data.specifics.length > 0" class="mt-2 text-[10px] text-muted-foreground grid grid-cols-12 gap-3 px-2">
            <div class="col-span-3">eBay Field</div>
            <div class="col-span-3">Template Field</div>
            <div class="col-span-2">Allowed Values</div>
            <div class="col-span-3">Value to Send</div>
            <div class="col-span-1"></div>
        </div>
    </div>
</template>
