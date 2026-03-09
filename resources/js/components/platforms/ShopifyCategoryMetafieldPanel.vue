<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    ArrowPathIcon,
    CheckCircleIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/20/solid';

interface MetafieldDefinition {
    id: number;
    name: string;
    key: string;
    namespace: string;
    type: string;
    description: string | null;
    enabled: boolean;
    mapped_template_field: string | null;
}

interface TemplateField {
    name: string;
    label: string;
}

interface MetafieldConfig {
    metafield_mappings: Record<string, string>;
    enabled_metafields: string[];
}

interface Props {
    categoryId: number;
    mappingId: number;
    templateFields: TemplateField[];
    existingConfig: MetafieldConfig;
}

const props = withDefaults(defineProps<Props>(), {
    existingConfig: () => ({ metafield_mappings: {}, enabled_metafields: [] }),
});

const emit = defineEmits<{
    (e: 'config-changed', config: MetafieldConfig): void;
}>();

const definitions = ref<MetafieldDefinition[]>([]);
const loading = ref(false);
const saving = ref(false);
const searchQuery = ref('');

// Local state for enabled metafields and mappings
const enabledKeys = ref<Set<string>>(new Set(props.existingConfig.enabled_metafields));
const fieldMappings = ref<Record<string, string>>({ ...props.existingConfig.metafield_mappings });

// Track if config has been initialized (no enabled_metafields means "all enabled")
const hasExistingConfig = computed(() => props.existingConfig.enabled_metafields.length > 0);

function fullKey(def: MetafieldDefinition): string {
    return `${def.namespace}.${def.key}`;
}

const filteredDefinitions = computed(() => {
    if (!searchQuery.value) return definitions.value;
    const q = searchQuery.value.toLowerCase();
    return definitions.value.filter(
        (d) => d.name.toLowerCase().includes(q) || d.key.toLowerCase().includes(q) || d.namespace.toLowerCase().includes(q),
    );
});

const enabledCount = computed(() => {
    if (!hasExistingConfig.value && enabledKeys.value.size === 0) {
        return definitions.value.length;
    }
    return enabledKeys.value.size;
});

const mappedCount = computed(() => {
    return Object.keys(fieldMappings.value).length;
});

async function fetchDefinitions() {
    loading.value = true;
    try {
        const response = await axios.get(`/categories/${props.categoryId}/platform-mappings/${props.mappingId}/shopify-metafields`);
        definitions.value = response.data.definitions ?? [];

        // If no existing config, initialize enabledKeys from server response
        if (!hasExistingConfig.value && enabledKeys.value.size === 0) {
            // All are enabled by default — don't populate the set yet
        }

        // Merge server-side mappings into local state
        for (const def of definitions.value) {
            const key = fullKey(def);
            if (def.mapped_template_field && !fieldMappings.value[key]) {
                fieldMappings.value[key] = def.mapped_template_field;
            }
        }
    } catch (error) {
        console.error('Failed to fetch metafield definitions:', error);
    } finally {
        loading.value = false;
    }
}

function isEnabled(def: MetafieldDefinition): boolean {
    const key = fullKey(def);
    // If no config exists yet, all are enabled
    if (!hasExistingConfig.value && enabledKeys.value.size === 0) {
        return true;
    }
    return enabledKeys.value.has(key);
}

function toggleEnabled(def: MetafieldDefinition) {
    const key = fullKey(def);

    // If first toggle and no existing config, initialize with all keys
    if (!hasExistingConfig.value && enabledKeys.value.size === 0) {
        for (const d of definitions.value) {
            enabledKeys.value.add(fullKey(d));
        }
    }

    if (enabledKeys.value.has(key)) {
        enabledKeys.value.delete(key);
        // Also remove mapping when disabling
        delete fieldMappings.value[key];
    } else {
        enabledKeys.value.add(key);
    }

    saveConfig();
}

function enableAll() {
    for (const def of definitions.value) {
        enabledKeys.value.add(fullKey(def));
    }
    saveConfig();
}

function disableAll() {
    enabledKeys.value.clear();
    fieldMappings.value = {};
    saveConfig();
}

function updateMapping(def: MetafieldDefinition, templateFieldName: string) {
    const key = fullKey(def);
    if (templateFieldName) {
        fieldMappings.value[key] = templateFieldName;
    } else {
        delete fieldMappings.value[key];
    }
    saveConfig();
}

function normalize(str: string): string {
    return str.toLowerCase().replace(/[^a-z0-9]/g, '');
}

function autoMap() {
    // Initialize enabled set if needed
    if (!hasExistingConfig.value && enabledKeys.value.size === 0) {
        for (const d of definitions.value) {
            enabledKeys.value.add(fullKey(d));
        }
    }

    for (const def of definitions.value) {
        const key = fullKey(def);
        if (!isEnabled(def)) continue;
        if (fieldMappings.value[key]) continue;

        const normalizedName = normalize(def.name);
        const normalizedKey = normalize(def.key);

        const match = props.templateFields.find((f) => {
            const nf = normalize(f.name);
            const nl = normalize(f.label);
            return nf === normalizedName || nl === normalizedName || nf === normalizedKey || nl === normalizedKey;
        });

        if (match) {
            fieldMappings.value[key] = match.name;
        }
    }
    saveConfig();
}

let saveTimeout: ReturnType<typeof setTimeout> | null = null;

function saveConfig() {
    if (saveTimeout) clearTimeout(saveTimeout);
    saveTimeout = setTimeout(doSave, 500);
}

async function doSave() {
    saving.value = true;
    const config: MetafieldConfig = {
        enabled_metafields: [...enabledKeys.value],
        metafield_mappings: { ...fieldMappings.value },
    };

    try {
        await axios.put(`/categories/${props.categoryId}/platform-mappings/${props.mappingId}`, {
            metadata: config,
        });
        emit('config-changed', config);
    } catch (error) {
        console.error('Failed to save metafield config:', error);
    } finally {
        saving.value = false;
    }
}

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

onMounted(() => {
    fetchDefinitions();
});
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">Shopify Metafield Configuration</h4>
                <p class="text-xs text-muted-foreground mt-0.5">
                    Choose which metafields appear on product listings and map them to template fields.
                    <span v-if="definitions.length > 0">
                        {{ enabledCount }}/{{ definitions.length }} enabled, {{ mappedCount }} mapped.
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span v-if="saving" class="text-xs text-gray-400">Saving...</span>
                <Button type="button" variant="outline" size="sm" @click="autoMap" :disabled="loading">
                    Auto-Map
                </Button>
                <Button type="button" variant="outline" size="sm" :disabled="loading" @click="fetchDefinitions">
                    <ArrowPathIcon class="h-3.5 w-3.5" :class="{ 'animate-spin': loading }" />
                </Button>
            </div>
        </div>

        <!-- Search and bulk actions -->
        <div v-if="definitions.length > 0" class="flex items-center gap-2">
            <div class="relative flex-1">
                <MagnifyingGlassIcon class="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-400" />
                <Input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search metafields..."
                    class="pl-8 text-xs h-8"
                />
            </div>
            <Button type="button" variant="outline" size="sm" @click="enableAll">Enable All</Button>
            <Button type="button" variant="outline" size="sm" @click="disableAll">Disable All</Button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="space-y-3">
            <div v-for="i in 5" :key="i" class="h-10 animate-pulse rounded-md bg-muted" />
        </div>

        <!-- Empty state -->
        <div v-else-if="definitions.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No metafield definitions found. Sync metafield definitions from your Shopify Marketplace Settings page first.
        </div>

        <!-- Column headers -->
        <div v-if="!loading && filteredDefinitions.length > 0" class="text-[10px] text-muted-foreground grid grid-cols-12 gap-3 px-2">
            <div class="col-span-1">Show</div>
            <div class="col-span-4">Metafield</div>
            <div class="col-span-4">Template Field</div>
            <div class="col-span-2">Type</div>
            <div class="col-span-1"></div>
        </div>

        <!-- Definitions list -->
        <div v-if="!loading && filteredDefinitions.length > 0" class="space-y-1.5 max-h-[500px] overflow-y-auto">
            <div
                v-for="def in filteredDefinitions"
                :key="def.id"
                class="grid grid-cols-12 items-center gap-3 p-2 rounded-md border dark:border-gray-700"
                :class="{
                    'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-900/10': isEnabled(def) && fieldMappings[fullKey(def)],
                    'opacity-50': !isEnabled(def),
                }"
            >
                <!-- Enable toggle -->
                <div class="col-span-1 flex justify-center">
                    <input
                        type="checkbox"
                        :checked="isEnabled(def)"
                        class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                        @change="toggleEnabled(def)"
                    />
                </div>

                <!-- Metafield Name -->
                <div class="col-span-4 min-w-0">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-sm font-medium truncate">{{ def.name }}</span>
                        <span class="text-[10px] text-muted-foreground truncate">{{ def.namespace }}.{{ def.key }}</span>
                    </div>
                </div>

                <!-- Template Field -->
                <div class="col-span-4">
                    <select
                        :value="fieldMappings[fullKey(def)] ?? ''"
                        :disabled="!isEnabled(def)"
                        class="w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary disabled:opacity-50"
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

                <!-- Status icon -->
                <div class="col-span-1 flex justify-end">
                    <CheckCircleIcon
                        v-if="isEnabled(def) && fieldMappings[fullKey(def)]"
                        class="h-4 w-4 text-green-500 shrink-0"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
