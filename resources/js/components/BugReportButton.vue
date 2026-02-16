<script setup lang="ts">
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { BugAntIcon, CameraIcon, XMarkIcon, PaperAirplaneIcon } from '@heroicons/vue/24/outline';
import html2canvas from 'html2canvas';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { toast } from 'vue-sonner';

const page = usePage();
const isOpen = ref(false);
const description = ref('');
const screenshot = ref<string | null>(null);
const isCapturing = ref(false);
const isSubmitting = ref(false);

function openModal() {
    isOpen.value = true;
    description.value = '';
    screenshot.value = null;
}

function closeModal() {
    isOpen.value = false;
    description.value = '';
    screenshot.value = null;
}

async function captureScreenshot() {
    isCapturing.value = true;

    // Temporarily hide the modal for the screenshot
    const modal = document.querySelector('[role="dialog"]');
    if (modal) {
        (modal as HTMLElement).style.display = 'none';
    }

    try {
        // Wait a moment for the modal to hide
        await new Promise(resolve => setTimeout(resolve, 100));

        const canvas = await html2canvas(document.body, {
            useCORS: true,
            allowTaint: true,
            logging: false,
            windowWidth: document.documentElement.scrollWidth,
            windowHeight: document.documentElement.scrollHeight,
        });

        screenshot.value = canvas.toDataURL('image/png');
        toast.success('Screenshot captured');
    } catch (error) {
        console.error('Failed to capture screenshot:', error);
        toast.error('Failed to capture screenshot');
    } finally {
        // Show the modal again
        if (modal) {
            (modal as HTMLElement).style.display = '';
        }
        isCapturing.value = false;
    }
}

function removeScreenshot() {
    screenshot.value = null;
}

async function submitBugReport() {
    if (!description.value.trim()) {
        toast.error('Please describe the issue');
        return;
    }

    isSubmitting.value = true;

    try {
        const response = await fetch('/api/bug-reports', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({
                description: description.value,
                screenshot: screenshot.value,
                url: window.location.href,
                user_agent: navigator.userAgent,
            }),
        });

        if (response.ok) {
            toast.success('Bug report submitted. Thank you!');
            closeModal();
        } else {
            throw new Error('Failed to submit');
        }
    } catch (error) {
        console.error('Failed to submit bug report:', error);
        toast.error('Failed to submit bug report. Please try again.');
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <!-- Bug Report Floating Button -->
    <button
        type="button"
        class="fixed bottom-6 left-6 z-40 flex items-center justify-center size-10 rounded-full bg-gray-100 text-gray-600 shadow-md hover:bg-gray-200 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700"
        title="Report a bug"
        @click="openModal"
    >
        <BugAntIcon class="size-5" />
        <span class="sr-only">Report a bug</span>
    </button>

    <!-- Bug Report Modal -->
    <TransitionRoot as="template" :show="isOpen">
        <Dialog class="relative z-50" @close="closeModal">
            <TransitionChild
                as="template"
                enter="ease-out duration-300"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="ease-in duration-200"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" />
            </TransitionChild>

            <div class="fixed inset-0 z-10 overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <TransitionChild
                        as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    >
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div class="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    class="rounded-md bg-white dark:bg-gray-800 text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                    @click="closeModal"
                                >
                                    <span class="sr-only">Close</span>
                                    <XMarkIcon class="h-6 w-6" />
                                </button>
                            </div>

                            <div class="sm:flex sm:items-start">
                                <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30 sm:mx-0 sm:h-10 sm:w-10">
                                    <BugAntIcon class="h-6 w-6 text-red-600 dark:text-red-400" />
                                </div>
                                <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left flex-1">
                                    <DialogTitle class="text-base font-semibold text-gray-900 dark:text-white">
                                        Report a Bug
                                    </DialogTitle>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        Help us improve by reporting any issues you encounter.
                                    </p>
                                </div>
                            </div>

                            <div class="mt-4 space-y-4">
                                <!-- Description -->
                                <div>
                                    <label for="bug-description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Describe the issue *
                                    </label>
                                    <Textarea
                                        id="bug-description"
                                        v-model="description"
                                        placeholder="What happened? What did you expect to happen?"
                                        class="mt-1"
                                        rows="4"
                                    />
                                </div>

                                <!-- Screenshot -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Screenshot (optional)
                                    </label>

                                    <div v-if="screenshot" class="relative">
                                        <img
                                            :src="screenshot"
                                            alt="Screenshot"
                                            class="w-full rounded-lg border border-gray-200 dark:border-gray-700"
                                        />
                                        <button
                                            type="button"
                                            class="absolute top-2 right-2 rounded-full bg-red-600 p-1 text-white shadow-sm hover:bg-red-500"
                                            @click="removeScreenshot"
                                        >
                                            <XMarkIcon class="h-4 w-4" />
                                        </button>
                                    </div>

                                    <Button
                                        v-else
                                        type="button"
                                        variant="outline"
                                        class="w-full"
                                        :disabled="isCapturing"
                                        @click="captureScreenshot"
                                    >
                                        <CameraIcon class="h-4 w-4 mr-2" />
                                        {{ isCapturing ? 'Capturing...' : 'Capture Screenshot' }}
                                    </Button>
                                </div>

                                <!-- Current URL (read-only info) -->
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    <span class="font-medium">Page:</span> {{ page.url }}
                                </div>
                            </div>

                            <div class="mt-5 sm:mt-6 sm:flex sm:flex-row-reverse gap-3">
                                <Button
                                    type="button"
                                    :disabled="isSubmitting || !description.trim()"
                                    @click="submitBugReport"
                                >
                                    <PaperAirplaneIcon class="h-4 w-4 mr-2" />
                                    {{ isSubmitting ? 'Submitting...' : 'Submit Report' }}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="closeModal"
                                >
                                    Cancel
                                </Button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
