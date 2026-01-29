<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import {
    PencilIcon,
    TrashIcon,
    PlusIcon,
    CheckIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';
import { ChatBubbleLeftEllipsisIcon } from '@heroicons/vue/24/outline';

interface NoteUser {
    id: number;
    name: string;
}

interface Note {
    id: number;
    content: string;
    user: NoteUser | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    notes: Note[];
    notableType: string;
    notableId: number;
}

const props = defineProps<Props>();

// Add note form
const isAdding = ref(false);
const addForm = useForm({
    notable_type: props.notableType,
    notable_id: props.notableId,
    content: '',
});

// Edit state
const editingNoteId = ref<number | null>(null);
const editForm = useForm({
    content: '',
});

// Format date
const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
};

// Add note
const startAdding = () => {
    isAdding.value = true;
    addForm.content = '';
};

const cancelAdding = () => {
    isAdding.value = false;
    addForm.reset();
};

const submitAdd = () => {
    addForm.post('/notes', {
        preserveScroll: true,
        onSuccess: () => {
            isAdding.value = false;
            addForm.reset();
        },
    });
};

// Edit note
const startEditing = (note: Note) => {
    editingNoteId.value = note.id;
    editForm.content = note.content;
};

const cancelEditing = () => {
    editingNoteId.value = null;
    editForm.reset();
};

const submitEdit = (noteId: number) => {
    editForm.put(`/notes/${noteId}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingNoteId.value = null;
            editForm.reset();
        },
    });
};

// Delete note
const deleteNote = (noteId: number) => {
    if (confirm('Are you sure you want to delete this note?')) {
        router.delete(`/notes/${noteId}`, {
            preserveScroll: true,
        });
    }
};

const hasNotes = computed(() => props.notes && props.notes.length > 0);
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
            <h3 class="text-sm font-medium text-gray-900 dark:text-white">Notes</h3>
            <button
                v-if="!isAdding"
                type="button"
                @click="startAdding"
                class="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            >
                <PlusIcon class="-ml-0.5 h-4 w-4" />
                Add Note
            </button>
        </div>

        <!-- Add Note Form -->
        <div v-if="isAdding" class="border-b border-gray-200 p-4 dark:border-gray-700">
            <form @submit.prevent="submitAdd">
                <div>
                    <label for="new-note" class="sr-only">Note</label>
                    <textarea
                        id="new-note"
                        v-model="addForm.content"
                        rows="3"
                        class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:placeholder:text-gray-400"
                        placeholder="Write a note..."
                    ></textarea>
                    <p v-if="addForm.errors.content" class="mt-1 text-sm text-red-600">
                        {{ addForm.errors.content }}
                    </p>
                </div>
                <div class="mt-3 flex justify-end gap-2">
                    <button
                        type="button"
                        @click="cancelAdding"
                        class="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-600"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="addForm.processing || !addForm.content.trim()"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                    >
                        {{ addForm.processing ? 'Saving...' : 'Save Note' }}
                    </button>
                </div>
            </form>
        </div>

        <!-- Notes List -->
        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            <div v-if="!hasNotes && !isAdding" class="px-4 py-8 text-center">
                <ChatBubbleLeftEllipsisIcon class="mx-auto h-8 w-8 text-gray-400" />
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No notes yet</p>
                <button
                    type="button"
                    @click="startAdding"
                    class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                >
                    <PlusIcon class="h-4 w-4" />
                    Add the first note
                </button>
            </div>

            <div
                v-for="note in notes"
                :key="note.id"
                class="px-4 py-3"
            >
                <!-- Edit Mode -->
                <div v-if="editingNoteId === note.id">
                    <form @submit.prevent="submitEdit(note.id)">
                        <textarea
                            v-model="editForm.content"
                            rows="3"
                            class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 dark:bg-gray-700 dark:text-white dark:ring-gray-600"
                        ></textarea>
                        <p v-if="editForm.errors.content" class="mt-1 text-sm text-red-600">
                            {{ editForm.errors.content }}
                        </p>
                        <div class="mt-2 flex justify-end gap-2">
                            <button
                                type="button"
                                @click="cancelEditing"
                                class="inline-flex items-center rounded p-1 text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                            >
                                <XMarkIcon class="h-4 w-4" />
                            </button>
                            <button
                                type="submit"
                                :disabled="editForm.processing || !editForm.content.trim()"
                                class="inline-flex items-center rounded p-1 text-indigo-600 hover:text-indigo-500 disabled:opacity-50 dark:text-indigo-400"
                            >
                                <CheckIcon class="h-4 w-4" />
                            </button>
                        </div>
                    </form>
                </div>

                <!-- View Mode -->
                <div v-else>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">
                                {{ note.content }}
                            </p>
                            <div class="mt-1 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="note.user">{{ note.user.name }}</span>
                                <span v-else>Unknown</span>
                                <span>&middot;</span>
                                <span>{{ formatDate(note.created_at) }}</span>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                @click="startEditing(note)"
                                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-500 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                                title="Edit note"
                            >
                                <PencilIcon class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                @click="deleteNote(note.id)"
                                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-red-500 dark:hover:bg-gray-700 dark:hover:text-red-400"
                                title="Delete note"
                            >
                                <TrashIcon class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
