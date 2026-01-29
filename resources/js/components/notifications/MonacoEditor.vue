<script setup lang="ts">
import { ref, onMounted, watch, onUnmounted, computed } from 'vue';
import * as monaco from 'monaco-editor';

interface Props {
    modelValue: string;
    language?: string;
    theme?: string;
    height?: string;
    readOnly?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    language: 'twig',
    theme: 'vs-dark',
    height: '400px',
    readOnly: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const editorRef = ref<HTMLDivElement | null>(null);
let editor: monaco.editor.IStandaloneCodeEditor | null = null;

// Check for dark mode
const isDarkMode = computed(() => {
    if (typeof window === 'undefined') return false;
    return document.documentElement.classList.contains('dark');
});

// Register Twig language
function registerTwigLanguage() {
    if (monaco.languages.getLanguages().find(l => l.id === 'twig')) {
        return;
    }

    monaco.languages.register({ id: 'twig' });

    monaco.languages.setMonarchTokensProvider('twig', {
        defaultToken: '',
        tokenPostfix: '.twig',

        brackets: [
            { open: '{{', close: '}}', token: 'delimiter.twig' },
            { open: '{%', close: '%}', token: 'delimiter.twig' },
            { open: '{#', close: '#}', token: 'comment.twig' },
            { open: '<', close: '>', token: 'delimiter.html' },
        ],

        keywords: [
            'if', 'else', 'elseif', 'endif',
            'for', 'endfor', 'in',
            'block', 'endblock',
            'extends', 'include', 'embed', 'endembed',
            'set', 'endset',
            'macro', 'endmacro',
            'import', 'from', 'as',
            'with', 'endwith',
            'autoescape', 'endautoescape',
            'spaceless', 'endspaceless',
            'filter', 'endfilter',
            'verbatim', 'endverbatim',
            'apply', 'endapply',
            'true', 'false', 'null',
            'and', 'or', 'not', 'is',
        ],

        filters: [
            'abs', 'batch', 'capitalize', 'column', 'convert_encoding', 'country_name',
            'currency_name', 'currency_symbol', 'data_uri', 'date', 'date_modify',
            'default', 'escape', 'e', 'first', 'format', 'format_currency',
            'format_date', 'format_datetime', 'format_number', 'format_time',
            'html_to_markdown', 'inky_to_html', 'inline_css', 'join', 'json_encode',
            'keys', 'language_name', 'last', 'length', 'locale_name', 'lower',
            'map', 'markdown_to_html', 'merge', 'nl2br', 'number_format', 'raw',
            'reduce', 'replace', 'reverse', 'round', 'slice', 'sort', 'spaceless',
            'split', 'striptags', 'timezone_name', 'title', 'trim', 'u', 'upper',
            'url_encode', 'money', 'date_format',
        ],

        tokenizer: {
            root: [
                [/\{#/, 'comment.twig', '@twigComment'],
                [/\{\{-?/, { token: 'delimiter.twig', next: '@twigVariable' }],
                [/\{%-?/, { token: 'delimiter.twig', next: '@twigTag' }],
                [/<!DOCTYPE/, 'metatag', '@doctype'],
                [/<!--/, 'comment', '@htmlComment'],
                [/(<)((?:[\w\-]+:)?[\w\-]+)(\s*)(\/>)/, ['delimiter', 'tag', '', 'delimiter']],
                [/(<)(script)/, ['delimiter', { token: 'tag', next: '@script' }]],
                [/(<)(style)/, ['delimiter', { token: 'tag', next: '@style' }]],
                [/(<)((?:[\w\-]+:)?[\w\-]+)/, ['delimiter', { token: 'tag', next: '@otherTag' }]],
                [/(<\/)((?:[\w\-]+:)?[\w\-]+)/, ['delimiter', { token: 'tag', next: '@otherTag' }]],
                [/[^<{]+/, ''],
            ],

            twigComment: [
                [/#\}/, 'comment.twig', '@pop'],
                [/./, 'comment.twig'],
            ],

            twigVariable: [
                [/-?\}\}/, { token: 'delimiter.twig', next: '@pop' }],
                [/\|/, 'operator'],
                [/\./, 'delimiter'],
                [/[a-zA-Z_]\w*/, {
                    cases: {
                        '@filters': 'support.function',
                        '@default': 'variable',
                    },
                }],
                [/\(/, 'delimiter.parenthesis', '@twigArguments'],
                [/"([^"\\]|\\.)*"/, 'string'],
                [/'([^'\\]|\\.)*'/, 'string'],
                [/\d+(\.\d+)?/, 'number'],
                [/\s+/, ''],
            ],

            twigTag: [
                [/-?%\}/, { token: 'delimiter.twig', next: '@pop' }],
                [/\|/, 'operator'],
                [/\./, 'delimiter'],
                [/[a-zA-Z_]\w*/, {
                    cases: {
                        '@keywords': 'keyword',
                        '@filters': 'support.function',
                        '@default': 'variable',
                    },
                }],
                [/\(/, 'delimiter.parenthesis', '@twigArguments'],
                [/"([^"\\]|\\.)*"/, 'string'],
                [/'([^'\\]|\\.)*'/, 'string'],
                [/\d+(\.\d+)?/, 'number'],
                [/\s+/, ''],
            ],

            twigArguments: [
                [/\)/, 'delimiter.parenthesis', '@pop'],
                [/,/, 'delimiter'],
                [/"([^"\\]|\\.)*"/, 'string'],
                [/'([^'\\]|\\.)*'/, 'string'],
                [/\d+(\.\d+)?/, 'number'],
                [/[a-zA-Z_]\w*/, 'variable'],
                [/\s+/, ''],
            ],

            doctype: [
                [/[^>]+/, 'metatag.content'],
                [/>/, 'metatag', '@pop'],
            ],

            htmlComment: [
                [/-->/, 'comment', '@pop'],
                [/[^-]+/, 'comment.content'],
                [/./, 'comment.content'],
            ],

            otherTag: [
                [/\/?>/, 'delimiter', '@pop'],
                [/"([^"]*)"/, 'attribute.value'],
                [/'([^']*)'/, 'attribute.value'],
                [/[\w\-]+/, 'attribute.name'],
                [/=/, 'delimiter'],
                [/[ \t\r\n]+/, ''],
            ],

            script: [
                [/<\/script/, { token: 'delimiter', next: '@pop' }],
                [/./, ''],
            ],

            style: [
                [/<\/style/, { token: 'delimiter', next: '@pop' }],
                [/./, ''],
            ],
        },
    });

    monaco.languages.setLanguageConfiguration('twig', {
        brackets: [
            ['{{', '}}'],
            ['{%', '%}'],
            ['{#', '#}'],
            ['<', '>'],
            ['(', ')'],
            ['[', ']'],
        ],
        autoClosingPairs: [
            { open: '{{', close: '}}' },
            { open: '{%', close: '%}' },
            { open: '{#', close: '#}' },
            { open: '<', close: '>' },
            { open: '"', close: '"' },
            { open: "'", close: "'" },
            { open: '(', close: ')' },
            { open: '[', close: ']' },
        ],
        surroundingPairs: [
            { open: '"', close: '"' },
            { open: "'", close: "'" },
            { open: '<', close: '>' },
        ],
    });
}

onMounted(() => {
    if (!editorRef.value) return;

    registerTwigLanguage();

    const editorTheme = isDarkMode.value ? 'vs-dark' : 'vs';

    editor = monaco.editor.create(editorRef.value, {
        value: props.modelValue,
        language: props.language,
        theme: editorTheme,
        readOnly: props.readOnly,
        minimap: { enabled: false },
        scrollBeyondLastLine: false,
        wordWrap: 'on',
        automaticLayout: true,
        fontSize: 14,
        lineNumbers: 'on',
        renderLineHighlight: 'line',
        tabSize: 2,
        insertSpaces: true,
        folding: true,
        bracketPairColorization: {
            enabled: true,
        },
    });

    editor.onDidChangeModelContent(() => {
        emit('update:modelValue', editor?.getValue() || '');
    });
});

watch(() => props.modelValue, (newValue) => {
    if (editor && editor.getValue() !== newValue) {
        editor.setValue(newValue);
    }
});

watch(isDarkMode, (isDark) => {
    if (editor) {
        monaco.editor.setTheme(isDark ? 'vs-dark' : 'vs');
    }
});

onUnmounted(() => {
    editor?.dispose();
});

function insertAtCursor(text: string) {
    if (!editor) return;
    const selection = editor.getSelection();
    if (selection) {
        editor.executeEdits('insert', [{
            range: selection,
            text,
            forceMoveMarkers: true,
        }]);
    }
    editor.focus();
}

function getValue(): string {
    return editor?.getValue() || '';
}

function setValue(value: string) {
    editor?.setValue(value);
}

defineExpose({ insertAtCursor, getValue, setValue });
</script>

<template>
    <div
        ref="editorRef"
        :style="{ height }"
        class="overflow-hidden rounded-md border border-gray-200 dark:border-white/10"
    ></div>
</template>
