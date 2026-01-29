<script setup lang="ts">
import { ref, computed } from 'vue';
import axios from 'axios';
import type { WidgetData } from '@/composables/useWidget';

interface FormField {
    type: string;
    name: string;
    label?: string;
    value?: unknown;
    options?: Array<{ value: string; label: string }>;
    placeholder?: string;
    required?: boolean;
}

interface FormGroup {
    fields: FormField | FormField[];
    label?: string;
}

interface Props {
    data: WidgetData;
    loading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    loading: false,
});

const emit = defineEmits<{
    submitted: [data: Record<string, unknown>];
}>();

const submitting = ref(false);
const errors = ref<Record<string, string>>({});

const formGroups = computed(() => (props.data.formGroups as FormGroup[]) || []);
const buttons = computed(() => (props.data.buttons as Array<{ type: string; label: string }>) || []);
const formAction = computed(() => props.data.action || '/widgets/process');
const formMethod = computed(() => props.data.method || 'POST');
const hasButtons = computed(() => props.data.hasButtons !== false);

// Form values
const formValues = ref<Record<string, unknown>>({});

// Initialize form values from field defaults
formGroups.value.forEach((group) => {
    const fields = Array.isArray(group.fields) ? group.fields : [group.fields];
    fields.forEach((field) => {
        if (field.name && field.value !== undefined) {
            formValues.value[field.name] = field.value;
        }
    });
});

async function handleSubmit() {
    submitting.value = true;
    errors.value = {};

    try {
        const response = await axios({
            method: formMethod.value,
            url: formAction.value,
            data: {
                type: props.data.widget,
                formGroups: formGroups.value.map((group) => ({
                    ...group,
                    fields: Array.isArray(group.fields)
                        ? group.fields.map((f) => ({ ...f, value: formValues.value[f.name] }))
                        : { ...group.fields, value: formValues.value[group.fields.name] },
                })),
            },
        });

        emit('submitted', response.data);
    } catch (err: unknown) {
        if (axios.isAxiosError(err) && err.response?.data?.errors) {
            errors.value = err.response.data.errors;
        }
    } finally {
        submitting.value = false;
    }
}

function getFieldComponent(type: string): string {
    switch (type) {
        case 'textarea':
            return 'textarea';
        case 'select':
            return 'select';
        case 'checkbox':
        case 'radio':
            return 'input';
        default:
            return 'input';
    }
}
</script>

<template>
    <div class="overflow-hidden rounded-lg bg-white shadow ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="px-4 py-5 sm:p-6">
            <h3 v-if="data.name" class="mb-6 text-lg font-medium text-gray-900 dark:text-white">
                {{ data.name }}
            </h3>

            <form @submit.prevent="handleSubmit">
                <div class="space-y-6">
                    <div v-for="(group, index) in formGroups" :key="index">
                        <label v-if="group.label" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ group.label }}
                        </label>

                        <template v-for="field in (Array.isArray(group.fields) ? group.fields : [group.fields])" :key="field.name">
                            <div class="mt-2">
                                <label
                                    v-if="field.label"
                                    :for="field.name"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                                >
                                    {{ field.label }}
                                </label>

                                <!-- Text input -->
                                <input
                                    v-if="field.type === 'input' || field.type === 'text' || field.type === 'email' || field.type === 'password'"
                                    :id="field.name"
                                    v-model="formValues[field.name]"
                                    :type="field.type === 'input' ? 'text' : field.type"
                                    :name="field.name"
                                    :placeholder="field.placeholder"
                                    :required="field.required"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />

                                <!-- Textarea -->
                                <textarea
                                    v-else-if="field.type === 'textarea'"
                                    :id="field.name"
                                    v-model="formValues[field.name] as string"
                                    :name="field.name"
                                    :placeholder="field.placeholder"
                                    :required="field.required"
                                    rows="3"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />

                                <!-- Select -->
                                <select
                                    v-else-if="field.type === 'select'"
                                    :id="field.name"
                                    v-model="formValues[field.name]"
                                    :name="field.name"
                                    :required="field.required"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                >
                                    <option v-for="option in field.options" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>

                                <!-- Checkbox -->
                                <div v-else-if="field.type === 'checkbox'" class="mt-1 flex items-center">
                                    <input
                                        :id="field.name"
                                        v-model="formValues[field.name]"
                                        :name="field.name"
                                        type="checkbox"
                                        class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700"
                                    />
                                    <label v-if="field.label" :for="field.name" class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                                        {{ field.label }}
                                    </label>
                                </div>

                                <!-- Error message -->
                                <p v-if="errors[field.name]" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ errors[field.name] }}
                                </p>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Buttons -->
                <div v-if="hasButtons" class="mt-6 flex justify-end gap-3">
                    <button
                        v-for="button in buttons"
                        :key="button.label"
                        :type="button.type === 'button' ? 'submit' : 'button'"
                        :disabled="submitting"
                        class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                    >
                        {{ submitting ? 'Submitting...' : button.label }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
