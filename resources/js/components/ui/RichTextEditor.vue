<script setup lang="ts">
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import { watch, onBeforeUnmount } from 'vue';

interface Props {
    modelValue: string;
    placeholder?: string;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Start typing...',
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit,
        Link.configure({
            openOnClick: false,
            HTMLAttributes: {
                class: 'text-indigo-600 underline hover:text-indigo-800',
            },
        }),
        Placeholder.configure({
            placeholder: props.placeholder,
        }),
    ],
    editorProps: {
        attributes: {
            class: 'prose prose-sm max-w-none focus:outline-none min-h-[120px] px-3 py-2 dark:prose-invert',
        },
    },
    onUpdate: ({ editor }) => {
        emit('update:modelValue', editor.getHTML());
    },
});

watch(
    () => props.modelValue,
    (value) => {
        const isSame = editor.value?.getHTML() === value;
        if (!isSame) {
            editor.value?.commands.setContent(value, false);
        }
    }
);

onBeforeUnmount(() => {
    editor.value?.destroy();
});

function toggleBold() {
    editor.value?.chain().focus().toggleBold().run();
}

function toggleItalic() {
    editor.value?.chain().focus().toggleItalic().run();
}

function toggleStrike() {
    editor.value?.chain().focus().toggleStrike().run();
}

function toggleBulletList() {
    editor.value?.chain().focus().toggleBulletList().run();
}

function toggleOrderedList() {
    editor.value?.chain().focus().toggleOrderedList().run();
}

function setLink() {
    const previousUrl = editor.value?.getAttributes('link').href;
    const url = window.prompt('URL', previousUrl);

    if (url === null) {
        return;
    }

    if (url === '') {
        editor.value?.chain().focus().extendMarkRange('link').unsetLink().run();
        return;
    }

    editor.value?.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
}

function unsetLink() {
    editor.value?.chain().focus().unsetLink().run();
}
</script>

<template>
    <div class="rounded-md border border-gray-300 bg-white shadow-sm ring-1 ring-inset ring-gray-300 focus-within:ring-2 focus-within:ring-indigo-600 dark:border-gray-600 dark:bg-gray-700 dark:ring-gray-600">
        <!-- Toolbar -->
        <div class="flex flex-wrap items-center gap-1 border-b border-gray-200 px-2 py-1.5 dark:border-gray-600">
            <button
                type="button"
                :class="[
                    'rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-gray-200',
                    editor?.isActive('bold') ? 'bg-gray-100 text-gray-900 dark:bg-gray-600 dark:text-white' : ''
                ]"
                @click="toggleBold"
                title="Bold"
            >
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h8a4 4 0 014 4 4 4 0 01-4 4H6z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 12h9a4 4 0 014 4 4 4 0 01-4 4H6z" />
                </svg>
            </button>
            <button
                type="button"
                :class="[
                    'rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-gray-200',
                    editor?.isActive('italic') ? 'bg-gray-100 text-gray-900 dark:bg-gray-600 dark:text-white' : ''
                ]"
                @click="toggleItalic"
                title="Italic"
            >
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 4h4M14 20H6M15 4L9 20" />
                </svg>
            </button>
            <button
                type="button"
                :class="[
                    'rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-gray-200',
                    editor?.isActive('strike') ? 'bg-gray-100 text-gray-900 dark:bg-gray-600 dark:text-white' : ''
                ]"
                @click="toggleStrike"
                title="Strikethrough"
            >
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 12H7M12 8c2 0 4 1 4 3s-2 3-4 3-4-1-4-3 2-3 4-3z" />
                </svg>
            </button>

            <span class="mx-1 h-5 w-px bg-gray-300 dark:bg-gray-600" />

            <button
                type="button"
                :class="[
                    'rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-gray-200',
                    editor?.isActive('bulletList') ? 'bg-gray-100 text-gray-900 dark:bg-gray-600 dark:text-white' : ''
                ]"
                @click="toggleBulletList"
                title="Bullet List"
            >
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <circle cx="2" cy="6" r="1" fill="currentColor" />
                    <circle cx="2" cy="12" r="1" fill="currentColor" />
                    <circle cx="2" cy="18" r="1" fill="currentColor" />
                </svg>
            </button>
            <button
                type="button"
                :class="[
                    'rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-gray-200',
                    editor?.isActive('orderedList') ? 'bg-gray-100 text-gray-900 dark:bg-gray-600 dark:text-white' : ''
                ]"
                @click="toggleOrderedList"
                title="Numbered List"
            >
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 6h13M7 12h13M7 18h13" />
                    <text x="2" y="8" font-size="8" fill="currentColor">1</text>
                    <text x="2" y="14" font-size="8" fill="currentColor">2</text>
                    <text x="2" y="20" font-size="8" fill="currentColor">3</text>
                </svg>
            </button>

            <span class="mx-1 h-5 w-px bg-gray-300 dark:bg-gray-600" />

            <button
                type="button"
                :class="[
                    'rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-gray-200',
                    editor?.isActive('link') ? 'bg-gray-100 text-gray-900 dark:bg-gray-600 dark:text-white' : ''
                ]"
                @click="setLink"
                title="Add Link"
            >
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
            </button>
            <button
                v-if="editor?.isActive('link')"
                type="button"
                class="rounded p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-600 dark:hover:text-gray-200"
                @click="unsetLink"
                title="Remove Link"
            >
                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
            </button>
        </div>

        <!-- Editor Content -->
        <EditorContent :editor="editor" />
    </div>
</template>

<style>
.ProseMirror p.is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    float: left;
    color: #9ca3af;
    pointer-events: none;
    height: 0;
}

.dark .ProseMirror p.is-editor-empty:first-child::before {
    color: #6b7280;
}

.ProseMirror:focus {
    outline: none;
}

.ProseMirror ul,
.ProseMirror ol {
    padding-left: 1.5rem;
}

.ProseMirror ul {
    list-style-type: disc;
}

.ProseMirror ol {
    list-style-type: decimal;
}
</style>
