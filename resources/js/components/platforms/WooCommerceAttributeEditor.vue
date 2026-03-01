<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import {
    PlusIcon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';

interface WooAttribute {
    name: string;
    options: string[];
    visible: boolean;
    variation: boolean;
}

interface Props {
    attributes: WooAttribute[];
    templateFields: Array<{
        id: number;
        name: string;
        label: string;
        value: string | null;
    }>;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'attributes-changed', attributes: WooAttribute[]): void;
}>();

const localAttributes = ref<WooAttribute[]>(
    props.attributes.length > 0
        ? props.attributes.map((a) => ({ ...a, options: [...a.options] }))
        : [],
);

const newAttributeName = ref('');
const newOptionInputs = ref<Record<number, string>>({});

function addAttribute() {
    const name = newAttributeName.value.trim();
    if (!name) return;
    if (localAttributes.value.some((a) => a.name.toLowerCase() === name.toLowerCase())) return;

    localAttributes.value.push({
        name,
        options: [],
        visible: true,
        variation: false,
    });
    newAttributeName.value = '';
    emitChange();
}

function removeAttribute(index: number) {
    localAttributes.value.splice(index, 1);
    emitChange();
}

function addOption(attrIndex: number) {
    const val = (newOptionInputs.value[attrIndex] || '').trim();
    if (!val) return;
    if (localAttributes.value[attrIndex].options.includes(val)) return;

    localAttributes.value[attrIndex].options.push(val);
    newOptionInputs.value[attrIndex] = '';
    emitChange();
}

function removeOption(attrIndex: number, optIndex: number) {
    localAttributes.value[attrIndex].options.splice(optIndex, 1);
    emitChange();
}

function toggleVisible(attrIndex: number, val: boolean) {
    localAttributes.value[attrIndex].visible = val;
    emitChange();
}

function toggleVariation(attrIndex: number, val: boolean) {
    localAttributes.value[attrIndex].variation = val;
    emitChange();
}

function importFromTemplate(field: { name: string; label: string; value: string | null }) {
    const existing = localAttributes.value.find((a) => a.name.toLowerCase() === field.label.toLowerCase());
    if (existing) {
        if (field.value && !existing.options.includes(field.value)) {
            existing.options.push(field.value);
        }
    } else {
        localAttributes.value.push({
            name: field.label,
            options: field.value ? [field.value] : [],
            visible: true,
            variation: false,
        });
    }
    emitChange();
}

function emitChange() {
    emit('attributes-changed', localAttributes.value.map((a) => ({ ...a, options: [...a.options] })));
}

const unmappedTemplateFields = computed(() => {
    const existingNames = new Set(localAttributes.value.map((a) => a.name.toLowerCase()));
    return props.templateFields.filter((f) => !existingNames.has(f.label.toLowerCase()) && f.value);
});
</script>

<template>
    <div class="space-y-4">
        <!-- Existing attributes -->
        <div v-if="localAttributes.length > 0" class="space-y-4">
            <div
                v-for="(attr, attrIndex) in localAttributes"
                :key="attrIndex"
                class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
            >
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ attr.name }}</span>
                        <Badge v-if="attr.variation" variant="outline" class="text-xs">Variation</Badge>
                    </div>
                    <Button variant="ghost" size="sm" @click="removeAttribute(attrIndex)">
                        <TrashIcon class="h-4 w-4 text-gray-400" />
                    </Button>
                </div>

                <!-- Options list -->
                <div class="mb-3 flex flex-wrap gap-2">
                    <span
                        v-for="(opt, optIndex) in attr.options"
                        :key="optIndex"
                        class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 dark:bg-gray-700 dark:text-gray-300"
                    >
                        {{ opt }}
                        <button
                            type="button"
                            class="ml-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                            @click="removeOption(attrIndex, optIndex)"
                        >
                            <XMarkIcon class="h-3 w-3" />
                        </button>
                    </span>
                    <span v-if="attr.options.length === 0" class="text-sm text-gray-400 dark:text-gray-500">
                        No options yet
                    </span>
                </div>

                <!-- Add option input -->
                <div class="mb-3 flex gap-2">
                    <Input
                        v-model="newOptionInputs[attrIndex]"
                        placeholder="Add option value..."
                        class="text-sm"
                        @keydown.enter.prevent="addOption(attrIndex)"
                    />
                    <Button variant="outline" size="sm" @click="addOption(attrIndex)">
                        <PlusIcon class="h-4 w-4" />
                    </Button>
                </div>

                <!-- Attribute flags -->
                <div class="flex items-center gap-6 text-sm">
                    <label class="flex items-center gap-2">
                        <Checkbox
                            :model-value="attr.visible"
                            @update:model-value="toggleVisible(attrIndex, $event as boolean)"
                        />
                        <span class="text-gray-600 dark:text-gray-400">Visible on product page</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <Checkbox
                            :model-value="attr.variation"
                            @update:model-value="toggleVariation(attrIndex, $event as boolean)"
                        />
                        <span class="text-gray-600 dark:text-gray-400">Used for variations</span>
                    </label>
                </div>
            </div>
        </div>

        <div v-else class="rounded-lg border border-dashed border-gray-300 p-6 text-center dark:border-gray-600">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No product attributes defined. Add attributes like Size, Color, or Material.
            </p>
        </div>

        <!-- Add new attribute -->
        <div class="flex gap-2">
            <Input
                v-model="newAttributeName"
                placeholder="New attribute name (e.g. Color, Size)"
                @keydown.enter.prevent="addAttribute"
            />
            <Button variant="outline" @click="addAttribute" :disabled="!newAttributeName.trim()">
                <PlusIcon class="mr-1 h-4 w-4" />
                Add
            </Button>
        </div>

        <!-- Import from template fields -->
        <div v-if="unmappedTemplateFields.length > 0" class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
            <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Import from product template</p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="field in unmappedTemplateFields"
                    :key="field.id"
                    variant="outline"
                    size="sm"
                    @click="importFromTemplate(field)"
                >
                    {{ field.label }}: {{ field.value }}
                </Button>
            </div>
        </div>
    </div>
</template>
