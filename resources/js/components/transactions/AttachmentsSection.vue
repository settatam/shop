<script setup lang="ts">
import { ref, computed } from 'vue';
import axios from 'axios';
import { PhotoIcon, XMarkIcon, ArrowUpTrayIcon, TrashIcon, DocumentTextIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';

interface Attachment {
    id: number;
    url: string;
    thumbnail_url: string | null;
    alt_text: string | null;
    file_name: string | null;
    file_type: string | null;
}

interface Props {
    transactionId: number;
    attachments: Attachment[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    updated: [];
}>();

const localAttachments = ref<Attachment[]>([...props.attachments]);
const uploading = ref(false);
const uploadProgress = ref(0);
const error = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const dragOver = ref(false);

// Image previews for pending uploads
const pendingFiles = ref<File[]>([]);
const pendingPreviews = ref<string[]>([]);

function triggerFileInput() {
    fileInput.value?.click();
}

function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files) {
        addFiles(Array.from(input.files));
    }
    // Reset input so same file can be selected again
    input.value = '';
}

function handleDrop(event: DragEvent) {
    event.preventDefault();
    dragOver.value = false;
    if (event.dataTransfer?.files) {
        addFiles(Array.from(event.dataTransfer.files));
    }
}

function handleDragOver(event: DragEvent) {
    event.preventDefault();
    dragOver.value = true;
}

function handleDragLeave() {
    dragOver.value = false;
}

// Allowed file types
const allowedTypes = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
];

function isImageFile(file: File): boolean {
    return file.type.startsWith('image/');
}

function getFileIcon(file: File): string {
    if (file.type === 'application/pdf') return 'pdf';
    if (file.type.includes('word')) return 'doc';
    if (file.type.includes('excel') || file.type.includes('spreadsheet')) return 'xls';
    return 'file';
}

function isAttachmentImage(attachment: Attachment): boolean {
    // Check file_type if available
    if (attachment.file_type) {
        return attachment.file_type.startsWith('image/');
    }
    // Fallback to URL extension check
    const url = attachment.url.toLowerCase();
    return /\.(jpg|jpeg|png|gif|webp|bmp|svg)(\?|$)/i.test(url);
}

function addFiles(files: File[]) {
    const validFiles = files.filter(file => allowedTypes.includes(file.type));
    for (const file of validFiles) {
        pendingFiles.value.push(file);
        if (isImageFile(file)) {
            const reader = new FileReader();
            reader.onload = (e) => {
                pendingPreviews.value.push(e.target?.result as string);
            };
            reader.readAsDataURL(file);
        } else {
            // For non-image files, use a placeholder
            pendingPreviews.value.push(`file:${file.name}:${getFileIcon(file)}`);
        }
    }
}

function removePendingFile(index: number) {
    pendingFiles.value.splice(index, 1);
    pendingPreviews.value.splice(index, 1);
}

async function uploadFiles() {
    if (pendingFiles.value.length === 0) return;

    uploading.value = true;
    uploadProgress.value = 0;
    error.value = null;

    try {
        const formData = new FormData();
        pendingFiles.value.forEach((file) => {
            formData.append('images[]', file);
        });

        const response = await axios.post(
            `/transactions/${props.transactionId}/attachments`,
            formData,
            {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
                onUploadProgress: (progressEvent) => {
                    if (progressEvent.total) {
                        uploadProgress.value = Math.round(
                            (progressEvent.loaded * 100) / progressEvent.total
                        );
                    }
                },
            }
        );

        // Add newly uploaded attachments to local state
        if (response.data.attachments) {
            localAttachments.value.push(...response.data.attachments);
        }

        // Clear pending files
        pendingFiles.value = [];
        pendingPreviews.value = [];

        emit('updated');
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to upload files';
    } finally {
        uploading.value = false;
        uploadProgress.value = 0;
    }
}

async function deleteAttachment(attachment: Attachment) {
    if (!confirm('Are you sure you want to delete this attachment?')) return;

    try {
        await axios.delete(`/transactions/${props.transactionId}/attachments/${attachment.id}`);
        localAttachments.value = localAttachments.value.filter(a => a.id !== attachment.id);
        emit('updated');
    } catch (err: any) {
        error.value = err.response?.data?.message || 'Failed to delete attachment';
    }
}

// Lightbox state
const lightboxOpen = ref(false);
const lightboxIndex = ref(0);

function openLightbox(index: number) {
    lightboxIndex.value = index;
    lightboxOpen.value = true;
}

function closeLightbox() {
    lightboxOpen.value = false;
}

function nextImage() {
    if (lightboxIndex.value < localAttachments.value.length - 1) {
        lightboxIndex.value++;
    }
}

function prevImage() {
    if (lightboxIndex.value > 0) {
        lightboxIndex.value--;
    }
}
</script>

<template>
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
        <div class="px-4 py-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                Attachments
            </h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Upload images, PDFs, or documents (ID photos, receipts, invoices)
            </p>

            <!-- Error Message -->
            <div v-if="error" class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 rounded-md">
                <p class="text-sm text-red-700 dark:text-red-300">{{ error }}</p>
            </div>

            <!-- Upload Area -->
            <div
                class="border-2 border-dashed rounded-lg p-6 text-center transition-colors"
                :class="[
                    dragOver
                        ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                        : 'border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500'
                ]"
                @drop="handleDrop"
                @dragover="handleDragOver"
                @dragleave="handleDragLeave"
            >
                <PhotoIcon class="mx-auto h-12 w-12 text-gray-400" />
                <div class="mt-4">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500"
                        @click="triggerFileInput"
                    >
                        <ArrowUpTrayIcon class="h-5 w-5" />
                        Upload files
                    </button>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        or drag and drop
                    </p>
                </div>
                <input
                    ref="fileInput"
                    type="file"
                    class="hidden"
                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
                    multiple
                    @change="handleFileSelect"
                />
            </div>

            <!-- Pending Uploads Preview -->
            <div v-if="pendingPreviews.length > 0" class="mt-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ pendingFiles.length }} file(s) ready to upload
                    </span>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                        :disabled="uploading"
                        @click="uploadFiles"
                    >
                        <ArrowUpTrayIcon v-if="!uploading" class="h-4 w-4" />
                        <svg v-else class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        {{ uploading ? `Uploading ${uploadProgress}%` : 'Upload' }}
                    </button>
                </div>
                <div class="grid grid-cols-4 gap-2">
                    <div
                        v-for="(preview, index) in pendingPreviews"
                        :key="index"
                        class="relative group"
                    >
                        <!-- Image preview -->
                        <img
                            v-if="!preview.startsWith('file:')"
                            :src="preview"
                            alt="Preview"
                            class="w-full h-20 object-cover rounded-md"
                        />
                        <!-- Document preview -->
                        <div
                            v-else
                            class="w-full h-20 flex flex-col items-center justify-center bg-gray-100 dark:bg-gray-700 rounded-md"
                        >
                            <DocumentTextIcon class="h-8 w-8 text-gray-400" />
                            <span class="text-xs text-gray-500 dark:text-gray-400 mt-1 px-1 truncate w-full text-center">
                                {{ preview.split(':')[1] }}
                            </span>
                        </div>
                        <button
                            type="button"
                            class="absolute top-1 right-1 p-0.5 bg-red-600 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
                            @click="removePendingFile(index)"
                        >
                            <XMarkIcon class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Existing Attachments -->
            <div v-if="localAttachments.length > 0" class="mt-6">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                    Uploaded Attachments
                </h4>
                <div class="grid grid-cols-4 gap-3">
                    <div
                        v-for="(attachment, index) in localAttachments"
                        :key="attachment.id"
                        class="relative group"
                    >
                        <!-- Image attachment -->
                        <template v-if="isAttachmentImage(attachment)">
                            <button
                                type="button"
                                class="w-full"
                                @click="openLightbox(index)"
                            >
                                <img
                                    :src="attachment.thumbnail_url || attachment.url"
                                    :alt="attachment.alt_text || 'Attachment'"
                                    class="w-full h-24 object-cover rounded-md hover:opacity-80 transition-opacity"
                                />
                            </button>
                        </template>
                        <!-- Document attachment -->
                        <template v-else>
                            <a
                                :href="attachment.url"
                                target="_blank"
                                class="w-full h-24 flex flex-col items-center justify-center bg-gray-100 dark:bg-gray-700 rounded-md hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                            >
                                <DocumentTextIcon class="h-8 w-8 text-gray-400" />
                                <span class="text-xs text-gray-500 dark:text-gray-400 mt-1 px-1 truncate w-full text-center">
                                    {{ attachment.file_name || 'Document' }}
                                </span>
                                <ArrowDownTrayIcon class="h-4 w-4 text-indigo-500 mt-1" />
                            </a>
                        </template>
                        <button
                            type="button"
                            class="absolute top-1 right-1 p-1 bg-red-600 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity shadow"
                            title="Delete attachment"
                            @click.stop="deleteAttachment(attachment)"
                        >
                            <TrashIcon class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-else-if="pendingPreviews.length === 0"
                class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400"
            >
                No attachments uploaded yet
            </div>
        </div>
    </div>

    <!-- Lightbox Modal -->
    <Teleport to="body">
        <div
            v-if="lightboxOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80"
            @click="closeLightbox"
        >
            <button
                type="button"
                class="absolute top-4 right-4 text-white hover:text-gray-300"
                @click="closeLightbox"
            >
                <XMarkIcon class="h-8 w-8" />
            </button>

            <!-- Navigation arrows -->
            <button
                v-if="lightboxIndex > 0"
                type="button"
                class="absolute left-4 text-white hover:text-gray-300 p-2"
                @click.stop="prevImage"
            >
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <button
                v-if="lightboxIndex < localAttachments.length - 1"
                type="button"
                class="absolute right-4 text-white hover:text-gray-300 p-2"
                @click.stop="nextImage"
            >
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>

            <img
                :src="localAttachments[lightboxIndex]?.url"
                :alt="localAttachments[lightboxIndex]?.alt_text || 'Attachment'"
                class="max-h-[90vh] max-w-[90vw] object-contain"
                @click.stop
            />
        </div>
    </Teleport>
</template>
