<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import {
    ArrowPathIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/20/solid';
import axios from 'axios';

interface ItemSpecific {
    id: number;
    name: string;
    type: string;
    is_required: boolean;
    is_recommended: boolean;
    aspect_mode: string;
    template_field_type: string;
    values: string[];
    values_count: number;
}

interface TemplateField {
    name: string;
    label: string;
    type: string;
}

interface FieldMapping {
    [specificName: string]: string; // maps to template field name
}

interface DefaultValues {
    [specificName: string]: string;
}

interface Props {
    categoryId: number;
    ebayCategoryId: string;
    templateFields: TemplateField[];
    existingMappings?: FieldMapping;
    existingDefaults?: DefaultValues;
}

const props = withDefaults(defineProps<Props>(), {
    existingMappings: () => ({}),
    existingDefaults: () => ({}),
});

const emit = defineEmits<{
    (e: 'mappings-changed', mappings: FieldMapping, defaults: DefaultValues): void;
}>();

const itemSpecifics = ref<ItemSpecific[]>([]);
const loading = ref(false);
const fieldMappings = ref<FieldMapping>({ ...props.existingMappings });
const defaultValues = ref<DefaultValues>({ ...props.existingDefaults });

const requiredSpecifics = computed(() => itemSpecifics.value.filter((s) => s.is_required));
const recommendedSpecifics = computed(() => itemSpecifics.value.filter((s) => s.is_recommended && !s.is_required));
const optionalSpecifics = computed(() => itemSpecifics.value.filter((s) => !s.is_required && !s.is_recommended));

const mappedCount = computed(() => {
    return itemSpecifics.value.filter(
        (s) => fieldMappings.value[s.name] || defaultValues.value[s.name],
    ).length;
});

const unmappedRequiredCount = computed(() => {
    return requiredSpecifics.value.filter(
        (s) => !fieldMappings.value[s.name] && !defaultValues.value[s.name],
    ).length;
});

async function fetchItemSpecifics() {
    loading.value = true;
    try {
        const response = await axios.get(`/api/taxonomy/ebay/categories/${props.categoryId}`);
        itemSpecifics.value = response.data.item_specifics ?? [];
    } catch (error) {
        console.error('Failed to fetch item specifics:', error);
    } finally {
        loading.value = false;
    }
}

function updateMapping(specificName: string, templateFieldName: string) {
    if (templateFieldName) {
        fieldMappings.value[specificName] = templateFieldName;
    } else {
        delete fieldMappings.value[specificName];
    }
    emitChanges();
}

function updateDefaultValue(specificName: string, value: string) {
    if (value) {
        defaultValues.value[specificName] = value;
    } else {
        delete defaultValues.value[specificName];
    }
    emitChanges();
}

function emitChanges() {
    emit('mappings-changed', { ...fieldMappings.value }, { ...defaultValues.value });
}

function autoMap() {
    for (const specific of itemSpecifics.value) {
        if (fieldMappings.value[specific.name]) continue;

        // Try to auto-match by name similarity
        const normalizedName = specific.name.toLowerCase().replace(/[^a-z0-9]/g, '');
        const match = props.templateFields.find((f) => {
            const normalizedField = f.name.toLowerCase().replace(/[^a-z0-9]/g, '');
            const normalizedLabel = f.label.toLowerCase().replace(/[^a-z0-9]/g, '');
            return normalizedField === normalizedName || normalizedLabel === normalizedName;
        });

        if (match) {
            fieldMappings.value[specific.name] = match.name;
        }
    }
    emitChanges();
}

onMounted(() => {
    fetchItemSpecifics();
});
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-sm font-medium text-gray-900 dark:text-white">Item Specifics Mapping</h4>
                <p class="text-xs text-muted-foreground mt-0.5">
                    Map your template fields to platform item specifics.
                    <span v-if="itemSpecifics.length > 0">
                        {{ mappedCount }}/{{ itemSpecifics.length }} mapped.
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button type="button" variant="outline" size="sm" @click="autoMap">
                    Auto-Map
                </Button>
                <Button type="button" variant="outline" size="sm" :disabled="loading" @click="fetchItemSpecifics">
                    <ArrowPathIcon class="h-3.5 w-3.5" :class="{ 'animate-spin': loading }" />
                </Button>
            </div>
        </div>

        <!-- Warning for unmapped required -->
        <div
            v-if="unmappedRequiredCount > 0"
            class="flex items-center gap-2 p-2.5 rounded-md bg-amber-50 dark:bg-amber-900/20 text-xs text-amber-700 dark:text-amber-400"
        >
            <ExclamationTriangleIcon class="h-4 w-4 shrink-0" />
            {{ unmappedRequiredCount }} required item specific(s) not yet mapped.
        </div>

        <!-- Loading -->
        <div v-if="loading" class="space-y-3">
            <div v-for="i in 5" :key="i" class="h-10 animate-pulse rounded-md bg-muted" />
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
                        class="flex items-center gap-3 p-2 rounded-md border dark:border-gray-700"
                        :class="{
                            'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-900/10': fieldMappings[specific.name] || defaultValues[specific.name],
                            'border-amber-200 bg-amber-50/50 dark:border-amber-800 dark:bg-amber-900/10': !fieldMappings[specific.name] && !defaultValues[specific.name],
                        }"
                    >
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-sm font-medium truncate">{{ specific.name }}</span>
                                <Badge variant="destructive" class="text-[10px] shrink-0">Required</Badge>
                            </div>
                        </div>

                        <select
                            :value="fieldMappings[specific.name] ?? ''"
                            class="w-40 rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                            @change="updateMapping(specific.name, ($event.target as HTMLSelectElement).value)"
                        >
                            <option value="">-- Map to field --</option>
                            <option v-for="field in templateFields" :key="field.name" :value="field.name">
                                {{ field.label || field.name }}
                            </option>
                        </select>

                        <template v-if="specific.values.length > 0 && !fieldMappings[specific.name]">
                            <select
                                :value="defaultValues[specific.name] ?? ''"
                                class="w-36 rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                @change="updateDefaultValue(specific.name, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">-- Default --</option>
                                <option v-for="val in specific.values.slice(0, 50)" :key="val" :value="val">
                                    {{ val }}
                                </option>
                            </select>
                        </template>
                        <template v-else-if="!fieldMappings[specific.name]">
                            <Input
                                :model-value="defaultValues[specific.name] ?? ''"
                                type="text"
                                placeholder="Default value"
                                class="w-36 text-xs h-8"
                                @update:model-value="updateDefaultValue(specific.name, $event as string)"
                            />
                        </template>

                        <CheckCircleIcon
                            v-if="fieldMappings[specific.name] || defaultValues[specific.name]"
                            class="h-4 w-4 text-green-500 shrink-0"
                        />
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
                        class="flex items-center gap-3 p-2 rounded-md border dark:border-gray-700"
                        :class="{
                            'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-900/10': fieldMappings[specific.name] || defaultValues[specific.name],
                        }"
                    >
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-sm font-medium truncate">{{ specific.name }}</span>
                                <Badge variant="outline" class="text-[10px] shrink-0">Recommended</Badge>
                            </div>
                        </div>

                        <select
                            :value="fieldMappings[specific.name] ?? ''"
                            class="w-40 rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                            @change="updateMapping(specific.name, ($event.target as HTMLSelectElement).value)"
                        >
                            <option value="">-- Map to field --</option>
                            <option v-for="field in templateFields" :key="field.name" :value="field.name">
                                {{ field.label || field.name }}
                            </option>
                        </select>

                        <template v-if="specific.values.length > 0 && !fieldMappings[specific.name]">
                            <select
                                :value="defaultValues[specific.name] ?? ''"
                                class="w-36 rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                @change="updateDefaultValue(specific.name, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">-- Default --</option>
                                <option v-for="val in specific.values.slice(0, 50)" :key="val" :value="val">
                                    {{ val }}
                                </option>
                            </select>
                        </template>
                        <template v-else-if="!fieldMappings[specific.name]">
                            <Input
                                :model-value="defaultValues[specific.name] ?? ''"
                                type="text"
                                placeholder="Default value"
                                class="w-36 text-xs h-8"
                                @update:model-value="updateDefaultValue(specific.name, $event as string)"
                            />
                        </template>

                        <CheckCircleIcon
                            v-if="fieldMappings[specific.name] || defaultValues[specific.name]"
                            class="h-4 w-4 text-green-500 shrink-0"
                        />
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
                            class="flex items-center gap-3 p-2 rounded-md border dark:border-gray-700"
                            :class="{
                                'border-green-200 bg-green-50/50 dark:border-green-800 dark:bg-green-900/10': fieldMappings[specific.name] || defaultValues[specific.name],
                            }"
                        >
                            <div class="flex-1 min-w-0">
                                <span class="text-sm truncate">{{ specific.name }}</span>
                            </div>

                            <select
                                :value="fieldMappings[specific.name] ?? ''"
                                class="w-40 rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                @change="updateMapping(specific.name, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">-- Map to field --</option>
                                <option v-for="field in templateFields" :key="field.name" :value="field.name">
                                    {{ field.label || field.name }}
                                </option>
                            </select>

                            <template v-if="specific.values.length > 0 && !fieldMappings[specific.name]">
                                <select
                                    :value="defaultValues[specific.name] ?? ''"
                                    class="w-36 rounded-md border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:ring-1 focus:ring-primary"
                                    @change="updateDefaultValue(specific.name, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">-- Default --</option>
                                    <option v-for="val in specific.values.slice(0, 50)" :key="val" :value="val">
                                        {{ val }}
                                    </option>
                                </select>
                            </template>
                            <template v-else-if="!fieldMappings[specific.name]">
                                <Input
                                    :model-value="defaultValues[specific.name] ?? ''"
                                    type="text"
                                    placeholder="Default value"
                                    class="w-36 text-xs h-8"
                                    @update:model-value="updateDefaultValue(specific.name, $event as string)"
                                />
                            </template>

                            <CheckCircleIcon
                                v-if="fieldMappings[specific.name] || defaultValues[specific.name]"
                                class="h-4 w-4 text-green-500 shrink-0"
                            />
                        </div>
                    </div>
                </details>
            </div>
        </div>
    </div>
</template>
