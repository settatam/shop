<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowUturnLeftIcon,
    ArrowUturnRightIcon,
    DocumentDuplicateIcon,
    TrashIcon,
    Bars3Icon,
    QrCodeIcon,
    MinusIcon,
} from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useLabelDesigner, type LabelElement, type AvailableFields, type SampleData } from '@/composables/useLabelDesigner';
import { type BreadcrumbItem } from '@/types';

interface Template {
    id?: number;
    name: string;
    type: 'product' | 'transaction';
    canvas_width: number;
    canvas_height: number;
    is_default: boolean;
    elements: LabelElement[];
}

interface Props {
    template: Template | null;
    types: Record<string, string>;
    elementTypes: Record<string, string>;
    productFields: AvailableFields;
    transactionFields: AvailableFields;
    sampleProductData: SampleData;
    sampleTransactionData: SampleData;
}

const props = defineProps<Props>();

const breadcrumbItems = computed<BreadcrumbItem[]>(() => [
    { title: 'Labels', href: '/labels' },
    { title: props.template ? 'Edit template' : 'Create template', href: '#' },
]);

const designer = useLabelDesigner();
const canvasRef = ref<HTMLDivElement | null>(null);
const isSubmitting = ref(false);
const formErrors = ref<Record<string, string>>({});

// Canvas display scale (for better editing)
const displayScale = ref(2);

// Active fields based on template type
const activeFields = computed(() => {
    return designer.template.value.type === 'product'
        ? props.productFields
        : props.transactionFields;
});

const activeSampleData = computed(() => {
    return designer.template.value.type === 'product'
        ? props.sampleProductData
        : props.sampleTransactionData;
});

// Preset sizes
const presetSizes = [
    { name: '2" x 1"', width: 406, height: 203 },
    { name: '2.25" x 1.25"', width: 457, height: 254 },
    { name: '3" x 1"', width: 609, height: 203 },
    { name: '3" x 2"', width: 609, height: 406 },
    { name: '4" x 2"', width: 812, height: 406 },
    { name: '4" x 3"', width: 812, height: 609 },
];

// Initialize
onMounted(() => {
    designer.initTemplate(props.template);
    window.addEventListener('keydown', designer.handleKeyDown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', designer.handleKeyDown);
});

// Canvas event handlers
function handleCanvasMouseDown(event: MouseEvent) {
    // Deselect if clicking on canvas background
    if (event.target === canvasRef.value) {
        designer.selectElement(null);
    }
}

function handleElementMouseDown(element: LabelElement, event: MouseEvent) {
    event.stopPropagation();

    const rect = canvasRef.value?.getBoundingClientRect();
    if (!rect) return;

    const canvasX = (event.clientX - rect.left) / displayScale.value;
    const canvasY = (event.clientY - rect.top) / displayScale.value;

    const offsetX = canvasX - element.x;
    const offsetY = canvasY - element.y;

    designer.startDrag(element.id, offsetX, offsetY);
}

function handleCanvasMouseMove(event: MouseEvent) {
    if (!designer.isDragging.value) return;

    const rect = canvasRef.value?.getBoundingClientRect();
    if (!rect) return;

    const canvasX = (event.clientX - rect.left) / displayScale.value;
    const canvasY = (event.clientY - rect.top) / displayScale.value;

    designer.drag(canvasX, canvasY);
}

function handleCanvasMouseUp() {
    designer.endDrag();
}

// Add element from field picker
function addFieldElement(fieldKey: string) {
    designer.addElement('text_field', fieldKey);
}

function addBarcodeElement(fieldKey: string) {
    designer.addElement('barcode', fieldKey);
}

function addStaticText() {
    designer.addElement('static_text', 'Label');
}

function addLine() {
    designer.addElement('line');
}

// Apply preset size
function applyPreset(preset: { width: number; height: number }) {
    designer.setCanvasSize(preset.width, preset.height);
}

// Save template
function saveTemplate() {
    if (isSubmitting.value) return;

    const data = designer.getTemplateData();

    if (!data.name) {
        formErrors.value = { name: 'Name is required' };
        return;
    }

    isSubmitting.value = true;
    formErrors.value = {};

    const url = data.id ? `/labels/${data.id}` : '/labels';
    const method = data.id ? 'put' : 'post';

    router[method](url, data, {
        preserveScroll: true,
        onError: (errors) => {
            formErrors.value = errors;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

// Get element style for rendering
function getElementStyle(element: LabelElement) {
    return {
        left: `${element.x * displayScale.value}px`,
        top: `${element.y * displayScale.value}px`,
        width: `${element.width * displayScale.value}px`,
        height: `${element.height * displayScale.value}px`,
        fontSize: `${(element.styles?.fontSize || 14) * displayScale.value * 0.5}px`,
        textAlign: element.styles?.alignment || 'left',
    };
}

// Watch for type changes to clear elements if switching types
watch(() => designer.template.value.type, (newType, oldType) => {
    if (newType !== oldType && designer.template.value.elements.length > 0) {
        // Keep elements but they may reference invalid fields
    }
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="template ? 'Edit label template' : 'Create label template'" />

        <div class="flex h-[calc(100vh-8rem)] gap-6">
            <!-- Left Sidebar: Fields -->
            <div class="w-64 shrink-0 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">Add Elements</h3>

                <!-- Static elements -->
                <div class="mb-4 space-y-2">
                    <Button variant="outline" size="sm" class="w-full justify-start" @click="addStaticText">
                        <Bars3Icon class="mr-2 h-4 w-4" />
                        Static Text
                    </Button>
                    <Button variant="outline" size="sm" class="w-full justify-start" @click="addLine">
                        <MinusIcon class="mr-2 h-4 w-4" />
                        Line
                    </Button>
                </div>

                <div class="mb-4 border-t border-gray-200 pt-4 dark:border-white/10">
                    <h4 class="mb-2 text-xs font-medium uppercase tracking-wider text-gray-500">Dynamic Fields</h4>
                </div>

                <!-- Field groups -->
                <div class="space-y-2">
                    <Collapsible
                        v-for="(fields, groupName) in activeFields"
                        :key="groupName"
                        :default-open="true"
                    >
                        <CollapsibleTrigger class="flex w-full items-center justify-between rounded px-2 py-1.5 text-left text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">
                            {{ groupName }}
                        </CollapsibleTrigger>
                        <CollapsibleContent class="mt-1 space-y-1 pl-2">
                            <button
                                v-for="(label, key) in fields"
                                :key="key"
                                @click="addFieldElement(key as string)"
                                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5"
                            >
                                <span class="truncate">{{ label }}</span>
                            </button>
                            <template v-for="(label, key) in fields" :key="`barcode-${key}`">
                                <button
                                    v-if="String(key).includes('barcode') || String(key).includes('sku') || String(key).includes('upc') || String(key).includes('transaction_number')"
                                    @click="addBarcodeElement(key as string)"
                                    class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-500/10"
                                >
                                    <QrCodeIcon class="h-3 w-3" />
                                    <span class="truncate">{{ label }} (Barcode)</span>
                                </button>
                            </template>
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </div>

            <!-- Center: Canvas -->
            <div class="flex flex-1 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                <!-- Toolbar -->
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="!designer.canUndo.value"
                            @click="designer.undo()"
                            title="Undo (Ctrl+Z)"
                        >
                            <ArrowUturnLeftIcon class="h-4 w-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="!designer.canRedo.value"
                            @click="designer.redo()"
                            title="Redo (Ctrl+Y)"
                        >
                            <ArrowUturnRightIcon class="h-4 w-4" />
                        </Button>
                        <div class="mx-2 h-4 w-px bg-gray-200 dark:bg-white/10"></div>
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="!designer.selectedElementId.value"
                            @click="designer.duplicateElement(designer.selectedElementId.value!)"
                            title="Duplicate (Ctrl+D)"
                        >
                            <DocumentDuplicateIcon class="h-4 w-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="!designer.selectedElementId.value"
                            @click="designer.deleteElement(designer.selectedElementId.value!)"
                            title="Delete"
                        >
                            <TrashIcon class="h-4 w-4" />
                        </Button>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-500">
                        <span>{{ designer.template.value.canvas_width }} x {{ designer.template.value.canvas_height }} dots</span>
                        <span>({{ Math.round(designer.template.value.canvas_width / 203 * 100) / 100 }}" x {{ Math.round(designer.template.value.canvas_height / 203 * 100) / 100 }}")</span>
                    </div>
                </div>

                <!-- Canvas area -->
                <div class="flex flex-1 items-center justify-center overflow-auto bg-gray-100 p-8 dark:bg-gray-800">
                    <div
                        ref="canvasRef"
                        class="relative bg-white shadow-lg"
                        :style="{
                            width: `${designer.template.value.canvas_width * displayScale}px`,
                            height: `${designer.template.value.canvas_height * displayScale}px`,
                            backgroundImage: 'linear-gradient(to right, #f0f0f0 1px, transparent 1px), linear-gradient(to bottom, #f0f0f0 1px, transparent 1px)',
                            backgroundSize: `${20 * displayScale}px ${20 * displayScale}px`,
                        }"
                        @mousedown="handleCanvasMouseDown"
                        @mousemove="handleCanvasMouseMove"
                        @mouseup="handleCanvasMouseUp"
                        @mouseleave="handleCanvasMouseUp"
                    >
                        <!-- Elements -->
                        <div
                            v-for="element in designer.template.value.elements"
                            :key="element.id"
                            :class="[
                                'absolute cursor-move select-none overflow-hidden border',
                                designer.selectedElementId.value === element.id
                                    ? 'border-indigo-500 ring-2 ring-indigo-500/30'
                                    : 'border-gray-300 hover:border-indigo-300',
                                element.element_type === 'line' ? 'bg-gray-800' : 'bg-white',
                            ]"
                            :style="getElementStyle(element)"
                            @mousedown="handleElementMouseDown(element, $event)"
                        >
                            <!-- Text field / Static text -->
                            <template v-if="element.element_type === 'text_field' || element.element_type === 'static_text'">
                                <div class="flex h-full items-center px-1 truncate" :style="{ textAlign: element.styles?.alignment || 'left' }">
                                    {{ designer.renderElementContent(element, activeSampleData) }}
                                </div>
                            </template>

                            <!-- Barcode -->
                            <template v-else-if="element.element_type === 'barcode'">
                                <div class="flex h-full flex-col items-center justify-center">
                                    <div class="flex h-3/4 w-full items-end justify-center gap-px">
                                        <div v-for="i in 40" :key="i" class="h-full bg-black" :style="{ width: `${Math.random() > 0.5 ? 2 : 1}px` }"></div>
                                    </div>
                                    <div v-if="element.styles?.showText !== false" class="text-center text-xs truncate w-full">
                                        {{ designer.renderElementContent(element, activeSampleData) }}
                                    </div>
                                </div>
                            </template>

                            <!-- Line - no content needed -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar: Properties -->
            <div class="w-72 shrink-0 overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">Properties</h3>

                <!-- Template properties -->
                <div class="space-y-4">
                    <div>
                        <Label for="template-name">Template Name</Label>
                        <Input
                            id="template-name"
                            v-model="designer.template.value.name"
                            placeholder="My Label Template"
                            class="mt-1"
                        />
                        <p v-if="formErrors.name" class="mt-1 text-xs text-red-600">{{ formErrors.name }}</p>
                    </div>

                    <div>
                        <Label for="template-type">Type</Label>
                        <select
                            id="template-type"
                            v-model="designer.template.value.type"
                            class="mt-1 block w-full rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm dark:bg-gray-800 dark:text-white dark:ring-white/10"
                        >
                            <option v-for="(label, value) in types" :key="value" :value="value">
                                {{ label }}
                            </option>
                        </select>
                    </div>

                    <!-- Canvas size presets -->
                    <div>
                        <Label>Label Size</Label>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <button
                                v-for="preset in presetSizes"
                                :key="preset.name"
                                @click="applyPreset(preset)"
                                :class="[
                                    'rounded border px-2 py-1 text-xs',
                                    designer.template.value.canvas_width === preset.width && designer.template.value.canvas_height === preset.height
                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400'
                                        : 'border-gray-200 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5',
                                ]"
                            >
                                {{ preset.name }}
                            </button>
                        </div>
                    </div>

                    <!-- Custom size -->
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <Label for="canvas-width">Width (dots)</Label>
                            <Input
                                id="canvas-width"
                                v-model.number="designer.template.value.canvas_width"
                                type="number"
                                min="50"
                                max="2000"
                                class="mt-1"
                            />
                        </div>
                        <div>
                            <Label for="canvas-height">Height (dots)</Label>
                            <Input
                                id="canvas-height"
                                v-model.number="designer.template.value.canvas_height"
                                type="number"
                                min="50"
                                max="2000"
                                class="mt-1"
                            />
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">203 dots = 1 inch at 203 DPI</p>

                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="is-default"
                            :checked="designer.template.value.is_default"
                            @update:checked="designer.template.value.is_default = $event"
                        />
                        <Label for="is-default" class="mb-0 cursor-pointer">Set as default</Label>
                    </div>
                </div>

                <!-- Element properties -->
                <div v-if="designer.selectedElement.value" class="mt-6 border-t border-gray-200 pt-4 dark:border-white/10">
                    <h4 class="mb-4 text-sm font-semibold text-gray-900 dark:text-white">Element Properties</h4>

                    <div class="space-y-4">
                        <!-- Position -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <Label for="element-x">X</Label>
                                <Input
                                    id="element-x"
                                    :model-value="designer.selectedElement.value.x"
                                    @update:model-value="designer.updateElementPosition(designer.selectedElementId.value!, Number($event), designer.selectedElement.value!.y)"
                                    type="number"
                                    min="0"
                                    class="mt-1"
                                />
                            </div>
                            <div>
                                <Label for="element-y">Y</Label>
                                <Input
                                    id="element-y"
                                    :model-value="designer.selectedElement.value.y"
                                    @update:model-value="designer.updateElementPosition(designer.selectedElementId.value!, designer.selectedElement.value!.x, Number($event))"
                                    type="number"
                                    min="0"
                                    class="mt-1"
                                />
                            </div>
                        </div>

                        <!-- Size -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <Label for="element-width">Width</Label>
                                <Input
                                    id="element-width"
                                    :model-value="designer.selectedElement.value.width"
                                    @update:model-value="designer.updateElementSize(designer.selectedElementId.value!, Number($event), designer.selectedElement.value!.height)"
                                    type="number"
                                    min="20"
                                    class="mt-1"
                                />
                            </div>
                            <div>
                                <Label for="element-height">Height</Label>
                                <Input
                                    id="element-height"
                                    :model-value="designer.selectedElement.value.height"
                                    @update:model-value="designer.updateElementSize(designer.selectedElementId.value!, designer.selectedElement.value!.width, Number($event))"
                                    type="number"
                                    min="1"
                                    class="mt-1"
                                />
                            </div>
                        </div>

                        <!-- Content (for static text) -->
                        <div v-if="designer.selectedElement.value.element_type === 'static_text'">
                            <Label for="element-content">Text</Label>
                            <Input
                                id="element-content"
                                :model-value="designer.selectedElement.value.content || ''"
                                @update:model-value="designer.updateElement(designer.selectedElementId.value!, { content: $event as string })"
                                class="mt-1"
                            />
                        </div>

                        <!-- Font size (for text elements) -->
                        <div v-if="designer.selectedElement.value.element_type !== 'line'">
                            <Label for="element-font-size">Font Size</Label>
                            <Input
                                id="element-font-size"
                                :model-value="designer.selectedElement.value.styles?.fontSize || 20"
                                @update:model-value="designer.updateElementStyles(designer.selectedElementId.value!, { fontSize: Number($event) })"
                                type="number"
                                min="8"
                                max="100"
                                class="mt-1"
                            />
                        </div>

                        <!-- Alignment (for text elements) -->
                        <div v-if="designer.selectedElement.value.element_type === 'text_field' || designer.selectedElement.value.element_type === 'static_text'">
                            <Label>Alignment</Label>
                            <div class="mt-2 flex gap-1">
                                <button
                                    v-for="align in ['left', 'center', 'right'] as const"
                                    :key="align"
                                    @click="designer.updateElementStyles(designer.selectedElementId.value!, { alignment: align })"
                                    :class="[
                                        'flex-1 rounded border px-2 py-1 text-xs capitalize',
                                        designer.selectedElement.value.styles?.alignment === align || (!designer.selectedElement.value.styles?.alignment && align === 'left')
                                            ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400'
                                            : 'border-gray-200 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5',
                                    ]"
                                >
                                    {{ align }}
                                </button>
                            </div>
                        </div>

                        <!-- Barcode options -->
                        <template v-if="designer.selectedElement.value.element_type === 'barcode'">
                            <div>
                                <Label for="barcode-height">Barcode Height</Label>
                                <Input
                                    id="barcode-height"
                                    :model-value="designer.selectedElement.value.styles?.barcodeHeight || 50"
                                    @update:model-value="designer.updateElementStyles(designer.selectedElementId.value!, { barcodeHeight: Number($event) })"
                                    type="number"
                                    min="20"
                                    max="200"
                                    class="mt-1"
                                />
                            </div>

                            <div class="flex items-center gap-2">
                                <Checkbox
                                    id="show-barcode-text"
                                    :checked="designer.selectedElement.value.styles?.showText !== false"
                                    @update:checked="designer.updateElementStyles(designer.selectedElementId.value!, { showText: $event })"
                                />
                                <Label for="show-barcode-text" class="mb-0 cursor-pointer">Show text below barcode</Label>
                            </div>
                        </template>

                        <!-- Line thickness -->
                        <div v-if="designer.selectedElement.value.element_type === 'line'">
                            <Label for="line-thickness">Thickness</Label>
                            <Input
                                id="line-thickness"
                                :model-value="designer.selectedElement.value.styles?.thickness || 2"
                                @update:model-value="designer.updateElementStyles(designer.selectedElementId.value!, { thickness: Number($event) })"
                                type="number"
                                min="1"
                                max="20"
                                class="mt-1"
                            />
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="mt-6 space-y-2 border-t border-gray-200 pt-4 dark:border-white/10">
                    <Button @click="saveTemplate" :disabled="isSubmitting" class="w-full">
                        {{ isSubmitting ? 'Saving...' : (template ? 'Save changes' : 'Create template') }}
                    </Button>
                    <Button variant="outline" as="a" href="/labels" class="w-full">
                        Cancel
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
