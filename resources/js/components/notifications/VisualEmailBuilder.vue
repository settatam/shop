<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import {
    PlusIcon,
    TrashIcon,
    Bars3Icon,
    ArrowUpIcon,
    ArrowDownIcon,
} from '@heroicons/vue/24/outline';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';

interface EmailBlock {
    id: string;
    type: 'header' | 'text' | 'button' | 'divider' | 'image' | 'table' | 'spacer';
    content: Record<string, unknown>;
}

interface Props {
    content: string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:content': [value: string];
}>();

const blocks = ref<EmailBlock[]>([]);
const selectedBlockId = ref<string | null>(null);
const draggedIndex = ref<number | null>(null);

const blockTypes = [
    { type: 'header', label: 'Header', icon: 'H1' },
    { type: 'text', label: 'Text', icon: 'Aa' },
    { type: 'button', label: 'Button', icon: 'Btn' },
    { type: 'divider', label: 'Divider', icon: '—' },
    { type: 'image', label: 'Image', icon: 'Img' },
    { type: 'table', label: 'Table', icon: 'Tbl' },
    { type: 'spacer', label: 'Spacer', icon: '↕' },
] as const;

const selectedBlock = computed(() => {
    if (!selectedBlockId.value) return null;
    return blocks.value.find(b => b.id === selectedBlockId.value) || null;
});

// Generate unique ID
function generateId(): string {
    return `block-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
}

// Add a new block
function addBlock(type: EmailBlock['type']) {
    const defaultContent: Record<string, Record<string, unknown>> = {
        header: { title: 'Header Title', align: 'center', color: '#333333' },
        text: { content: 'Enter your text here...', align: 'left' },
        button: { text: 'Click Here', url: '#', bgColor: '#4F46E5', textColor: '#ffffff', align: 'center' },
        divider: { color: '#e5e7eb', height: 1 },
        image: { url: '', alt: '', width: '100%', align: 'center' },
        table: { showOrderItems: true },
        spacer: { height: 20 },
    };

    const newBlock: EmailBlock = {
        id: generateId(),
        type,
        content: defaultContent[type] || {},
    };

    blocks.value.push(newBlock);
    selectedBlockId.value = newBlock.id;
    generateCode();
}

// Remove a block
function removeBlock(id: string) {
    blocks.value = blocks.value.filter(b => b.id !== id);
    if (selectedBlockId.value === id) {
        selectedBlockId.value = null;
    }
    generateCode();
}

// Move block up
function moveBlockUp(index: number) {
    if (index > 0) {
        const temp = blocks.value[index];
        blocks.value[index] = blocks.value[index - 1];
        blocks.value[index - 1] = temp;
        generateCode();
    }
}

// Move block down
function moveBlockDown(index: number) {
    if (index < blocks.value.length - 1) {
        const temp = blocks.value[index];
        blocks.value[index] = blocks.value[index + 1];
        blocks.value[index + 1] = temp;
        generateCode();
    }
}

// Update block content
function updateBlockContent(key: string, value: unknown) {
    if (!selectedBlock.value) return;
    selectedBlock.value.content[key] = value;
    generateCode();
}

// Generate Twig/HTML code from blocks
function generateCode() {
    const html = blocks.value.map(block => {
        switch (block.type) {
            case 'header':
                return `<div style="text-align: ${block.content.align}; padding: 20px 0;">
  <h1 style="margin: 0; color: ${block.content.color}; font-size: 24px; font-weight: bold;">${block.content.title}</h1>
</div>`;

            case 'text':
                return `<div style="text-align: ${block.content.align}; padding: 15px 0;">
  <p style="margin: 0; color: #374151; font-size: 16px; line-height: 1.6;">${block.content.content}</p>
</div>`;

            case 'button':
                return `<div style="text-align: ${block.content.align}; padding: 20px 0;">
  <a href="${block.content.url}" style="display: inline-block; background-color: ${block.content.bgColor}; color: ${block.content.textColor}; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 500;">${block.content.text}</a>
</div>`;

            case 'divider':
                return `<div style="padding: 10px 0;">
  <hr style="border: none; border-top: ${block.content.height}px solid ${block.content.color}; margin: 0;" />
</div>`;

            case 'image':
                return `<div style="text-align: ${block.content.align}; padding: 15px 0;">
  <img src="${block.content.url}" alt="${block.content.alt}" style="max-width: ${block.content.width}; height: auto;" />
</div>`;

            case 'table':
                return `<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
  <thead>
    <tr style="background-color: #f9fafb;">
      <th style="border-bottom: 1px solid #e5e7eb; padding: 12px; text-align: left; font-weight: 600;">Item</th>
      <th style="border-bottom: 1px solid #e5e7eb; padding: 12px; text-align: right; font-weight: 600;">Qty</th>
      <th style="border-bottom: 1px solid #e5e7eb; padding: 12px; text-align: right; font-weight: 600;">Total</th>
    </tr>
  </thead>
  <tbody>
    {% for item in order.items %}
    <tr>
      <td style="border-bottom: 1px solid #e5e7eb; padding: 12px;">{{ item.name }}</td>
      <td style="border-bottom: 1px solid #e5e7eb; padding: 12px; text-align: right;">{{ item.quantity }}</td>
      <td style="border-bottom: 1px solid #e5e7eb; padding: 12px; text-align: right;">\${{ item.total|money }}</td>
    </tr>
    {% endfor %}
  </tbody>
</table>`;

            case 'spacer':
                return `<div style="height: ${block.content.height}px;"></div>`;

            default:
                return '';
        }
    }).join('\n\n');

    emit('update:content', html);
}

// Drag and drop handlers
function onDragStart(index: number) {
    draggedIndex.value = index;
}

function onDragOver(event: DragEvent, index: number) {
    event.preventDefault();
    if (draggedIndex.value === null || draggedIndex.value === index) return;

    const draggedBlock = blocks.value[draggedIndex.value];
    blocks.value.splice(draggedIndex.value, 1);
    blocks.value.splice(index, 0, draggedBlock);
    draggedIndex.value = index;
}

function onDragEnd() {
    draggedIndex.value = null;
    generateCode();
}

// Watch for external content changes (when switching from code to visual)
watch(() => props.content, (newContent) => {
    // Simple detection - if content is empty or very different, reset blocks
    // In a real implementation, you'd parse the HTML back to blocks
    if (!newContent && blocks.value.length > 0) {
        // Content was cleared externally
    }
}, { immediate: true });
</script>

<template>
    <div class="flex h-[500px]">
        <!-- Block Palette -->
        <div class="w-48 shrink-0 border-r border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
            <p class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Add Block</p>
            <div class="grid grid-cols-2 gap-2">
                <button
                    v-for="blockType in blockTypes"
                    :key="blockType.type"
                    type="button"
                    class="flex flex-col items-center justify-center rounded-lg border border-gray-200 bg-white p-3 text-xs font-medium text-gray-700 transition-colors hover:border-indigo-300 hover:bg-indigo-50 dark:border-white/10 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20"
                    @click="addBlock(blockType.type)"
                >
                    <span class="mb-1 text-lg">{{ blockType.icon }}</span>
                    {{ blockType.label }}
                </button>
            </div>
        </div>

        <!-- Canvas -->
        <div class="flex-1 overflow-y-auto bg-gray-100 p-4 dark:bg-gray-900">
            <div v-if="blocks.length === 0" class="flex h-full items-center justify-center">
                <div class="text-center">
                    <PlusIcon class="mx-auto h-12 w-12 text-gray-400" />
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Click a block type to add it</p>
                </div>
            </div>

            <div v-else class="mx-auto max-w-lg space-y-2 rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
                <div
                    v-for="(block, index) in blocks"
                    :key="block.id"
                    :class="[
                        'group relative rounded border-2 p-2 transition-colors cursor-pointer',
                        selectedBlockId === block.id
                            ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                            : 'border-transparent hover:border-gray-300 dark:hover:border-gray-600',
                    ]"
                    draggable="true"
                    @click="selectedBlockId = block.id"
                    @dragstart="onDragStart(index)"
                    @dragover="e => onDragOver(e, index)"
                    @dragend="onDragEnd"
                >
                    <!-- Block Actions -->
                    <div class="absolute -right-2 -top-2 flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                        <button
                            type="button"
                            class="rounded bg-gray-100 p-1 text-gray-500 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600"
                            @click.stop="moveBlockUp(index)"
                        >
                            <ArrowUpIcon class="h-3 w-3" />
                        </button>
                        <button
                            type="button"
                            class="rounded bg-gray-100 p-1 text-gray-500 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600"
                            @click.stop="moveBlockDown(index)"
                        >
                            <ArrowDownIcon class="h-3 w-3" />
                        </button>
                        <button
                            type="button"
                            class="rounded bg-red-100 p-1 text-red-500 hover:bg-red-200 dark:bg-red-900/30 dark:hover:bg-red-900/50"
                            @click.stop="removeBlock(block.id)"
                        >
                            <TrashIcon class="h-3 w-3" />
                        </button>
                    </div>

                    <!-- Drag Handle -->
                    <div class="absolute left-1 top-1/2 -translate-y-1/2 cursor-move opacity-0 transition-opacity group-hover:opacity-100">
                        <Bars3Icon class="h-4 w-4 text-gray-400" />
                    </div>

                    <!-- Block Preview -->
                    <div class="ml-4">
                        <div v-if="block.type === 'header'" :style="{ textAlign: block.content.align as string }">
                            <h1 :style="{ color: block.content.color as string }" class="text-xl font-bold">
                                {{ block.content.title }}
                            </h1>
                        </div>

                        <div v-else-if="block.type === 'text'" :style="{ textAlign: block.content.align as string }">
                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ block.content.content }}</p>
                        </div>

                        <div v-else-if="block.type === 'button'" :style="{ textAlign: block.content.align as string }">
                            <span
                                class="inline-block rounded px-4 py-2 text-sm font-medium"
                                :style="{ backgroundColor: block.content.bgColor as string, color: block.content.textColor as string }"
                            >
                                {{ block.content.text }}
                            </span>
                        </div>

                        <div v-else-if="block.type === 'divider'" class="py-2">
                            <hr :style="{ borderColor: block.content.color as string }" />
                        </div>

                        <div v-else-if="block.type === 'image'" :style="{ textAlign: block.content.align as string }">
                            <div class="inline-block rounded bg-gray-200 p-4 text-xs text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                                Image: {{ block.content.url || 'No URL' }}
                            </div>
                        </div>

                        <div v-else-if="block.type === 'table'" class="text-xs text-gray-500 dark:text-gray-400">
                            <div class="rounded border border-gray-200 p-2 dark:border-gray-600">
                                Order Items Table (Twig loop)
                            </div>
                        </div>

                        <div v-else-if="block.type === 'spacer'" :style="{ height: `${block.content.height}px` }" class="bg-gray-100 dark:bg-gray-700">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Properties Panel -->
        <div class="w-64 shrink-0 border-l border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
            <p class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Properties</p>

            <div v-if="selectedBlock" class="space-y-4">
                <!-- Header properties -->
                <template v-if="selectedBlock.type === 'header'">
                    <div>
                        <Label>Title</Label>
                        <Input
                            :model-value="selectedBlock.content.title as string"
                            @update:model-value="v => updateBlockContent('title', v)"
                            class="mt-1"
                        />
                    </div>
                    <div>
                        <Label>Alignment</Label>
                        <select
                            :value="selectedBlock.content.align"
                            @change="e => updateBlockContent('align', (e.target as HTMLSelectElement).value)"
                            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white"
                        >
                            <option value="left">Left</option>
                            <option value="center">Center</option>
                            <option value="right">Right</option>
                        </select>
                    </div>
                    <div>
                        <Label>Color</Label>
                        <input
                            type="color"
                            :value="selectedBlock.content.color"
                            @input="e => updateBlockContent('color', (e.target as HTMLInputElement).value)"
                            class="mt-1 h-10 w-full rounded-md border border-gray-300 dark:border-white/10"
                        />
                    </div>
                </template>

                <!-- Text properties -->
                <template v-else-if="selectedBlock.type === 'text'">
                    <div>
                        <Label>Content</Label>
                        <textarea
                            :value="selectedBlock.content.content"
                            @input="e => updateBlockContent('content', (e.target as HTMLTextAreaElement).value)"
                            rows="4"
                            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white"
                        ></textarea>
                        <p class="mt-1 text-xs text-gray-500">Use <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">&#123;&#123; variable &#125;&#125;</code> for dynamic content</p>
                    </div>
                    <div>
                        <Label>Alignment</Label>
                        <select
                            :value="selectedBlock.content.align"
                            @change="e => updateBlockContent('align', (e.target as HTMLSelectElement).value)"
                            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white"
                        >
                            <option value="left">Left</option>
                            <option value="center">Center</option>
                            <option value="right">Right</option>
                        </select>
                    </div>
                </template>

                <!-- Button properties -->
                <template v-else-if="selectedBlock.type === 'button'">
                    <div>
                        <Label>Button Text</Label>
                        <Input
                            :model-value="selectedBlock.content.text as string"
                            @update:model-value="v => updateBlockContent('text', v)"
                            class="mt-1"
                        />
                    </div>
                    <div>
                        <Label>URL</Label>
                        <Input
                            :model-value="selectedBlock.content.url as string"
                            @update:model-value="v => updateBlockContent('url', v)"
                            class="mt-1"
                            placeholder="https://..."
                        />
                    </div>
                    <div>
                        <Label>Background Color</Label>
                        <input
                            type="color"
                            :value="selectedBlock.content.bgColor"
                            @input="e => updateBlockContent('bgColor', (e.target as HTMLInputElement).value)"
                            class="mt-1 h-10 w-full rounded-md border border-gray-300 dark:border-white/10"
                        />
                    </div>
                    <div>
                        <Label>Text Color</Label>
                        <input
                            type="color"
                            :value="selectedBlock.content.textColor"
                            @input="e => updateBlockContent('textColor', (e.target as HTMLInputElement).value)"
                            class="mt-1 h-10 w-full rounded-md border border-gray-300 dark:border-white/10"
                        />
                    </div>
                </template>

                <!-- Spacer properties -->
                <template v-else-if="selectedBlock.type === 'spacer'">
                    <div>
                        <Label>Height (px)</Label>
                        <Input
                            type="number"
                            :model-value="String(selectedBlock.content.height)"
                            @update:model-value="v => updateBlockContent('height', Number(v))"
                            class="mt-1"
                            min="10"
                            max="200"
                        />
                    </div>
                </template>

                <!-- Image properties -->
                <template v-else-if="selectedBlock.type === 'image'">
                    <div>
                        <Label>Image URL</Label>
                        <Input
                            :model-value="selectedBlock.content.url as string"
                            @update:model-value="v => updateBlockContent('url', v)"
                            class="mt-1"
                            placeholder="https://..."
                        />
                    </div>
                    <div>
                        <Label>Alt Text</Label>
                        <Input
                            :model-value="selectedBlock.content.alt as string"
                            @update:model-value="v => updateBlockContent('alt', v)"
                            class="mt-1"
                        />
                    </div>
                    <div>
                        <Label>Width</Label>
                        <Input
                            :model-value="selectedBlock.content.width as string"
                            @update:model-value="v => updateBlockContent('width', v)"
                            class="mt-1"
                            placeholder="100% or 300px"
                        />
                    </div>
                </template>

                <!-- Divider properties -->
                <template v-else-if="selectedBlock.type === 'divider'">
                    <div>
                        <Label>Color</Label>
                        <input
                            type="color"
                            :value="selectedBlock.content.color"
                            @input="e => updateBlockContent('color', (e.target as HTMLInputElement).value)"
                            class="mt-1 h-10 w-full rounded-md border border-gray-300 dark:border-white/10"
                        />
                    </div>
                    <div>
                        <Label>Thickness (px)</Label>
                        <Input
                            type="number"
                            :model-value="String(selectedBlock.content.height)"
                            @update:model-value="v => updateBlockContent('height', Number(v))"
                            class="mt-1"
                            min="1"
                            max="10"
                        />
                    </div>
                </template>

                <!-- Table - minimal config -->
                <template v-else-if="selectedBlock.type === 'table'">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        This block will render order items using Twig's for loop.
                    </p>
                </template>
            </div>

            <div v-else class="text-sm text-gray-500 dark:text-gray-400">
                Select a block to edit its properties
            </div>
        </div>
    </div>
</template>
