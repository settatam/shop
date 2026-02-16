<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    Bars3Icon,
    XMarkIcon,
    CheckCircleIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline';
import AppSidebar from '@/components/layout/AppSidebar.vue';
import AppHeader from '@/components/layout/AppHeader.vue';
import BugReportButton from '@/components/BugReportButton.vue';
import { ChatPanel } from '@/components/chat';
import { CommandPalette } from '@/components/search';
import VoiceAssistant from '@/components/voice/VoiceAssistant.vue';
import { useBarcodeScanner } from '@/composables/useBarcodeScanner';
import { Toaster, toast } from 'vue-sonner';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const sidebarOpen = ref(false);
const chatOpen = ref(false);
const commandPaletteRef = ref<InstanceType<typeof CommandPalette> | null>(null);

const openSearch = () => {
    commandPaletteRef.value?.open();
};

// Global Barcode Scanner
const page = usePage();

// Flash message handling
interface FlashMessages {
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
}

function showFlashMessages() {
    const flash = page.props.flash as FlashMessages | undefined;
    if (!flash) return;

    if (flash.success) {
        toast.success(flash.success);
    }
    if (flash.error) {
        toast.error(flash.error, { duration: 6000 });
    }
    if (flash.warning) {
        toast.warning(flash.warning);
    }
    if (flash.info) {
        toast.info(flash.info);
    }
}

// Show flash messages on mount and after navigation
onMounted(() => {
    showFlashMessages();
});

// Watch for navigation events to show flash messages
router.on('finish', () => {
    showFlashMessages();
});

// Pages that handle their own barcode scanning
const pagesWithOwnScanner = [
    '/orders/create',
    '/transactions/buy',
    '/memos/create',
    '/repairs/create',
];

const shouldEnableGlobalScanner = computed(() => {
    const url = page.url;
    return !pagesWithOwnScanner.some(path => url.startsWith(path));
});

const scannerFeedback = ref<{ type: 'success' | 'error'; message: string } | null>(null);
let feedbackTimeout: ReturnType<typeof setTimeout> | null = null;

function showScannerFeedback(type: 'success' | 'error', message: string) {
    scannerFeedback.value = { type, message };
    if (feedbackTimeout) {
        clearTimeout(feedbackTimeout);
    }
    feedbackTimeout = setTimeout(() => {
        scannerFeedback.value = null;
    }, 3000);
}

async function handleGlobalBarcodeScan(barcode: string) {
    if (!shouldEnableGlobalScanner.value) {
        return;
    }

    try {
        const response = await fetch(`/products/lookup-barcode?barcode=${encodeURIComponent(barcode)}`);
        const data = await response.json();

        if (data.found && data.product) {
            showScannerFeedback('success', `Found: ${data.product.title}`);

            // Play success sound
            try {
                const audio = new Audio('/sounds/beep-success.mp3');
                audio.volume = 0.3;
                audio.play().catch(() => {});
            } catch {}

            // Navigate to the product page
            router.visit(`/products/${data.product.id}`);
        } else {
            showScannerFeedback('error', `Product not found: ${barcode}`);

            // Play error sound
            try {
                const audio = new Audio('/sounds/beep-error.mp3');
                audio.volume = 0.3;
                audio.play().catch(() => {});
            } catch {}
        }
    } catch (error) {
        console.error('Barcode lookup error:', error);
        showScannerFeedback('error', 'Failed to lookup barcode');
    }
}

useBarcodeScanner({
    onScan: handleGlobalBarcodeScan,
    maxKeystrokeDelay: 50,
    minLength: 3,
    preventDefault: true,
    enabled: true,
});

onUnmounted(() => {
    if (feedbackTimeout) {
        clearTimeout(feedbackTimeout);
    }
});
</script>

<template>
    <div>
        <!-- Mobile sidebar -->
        <TransitionRoot as="template" :show="sidebarOpen">
            <Dialog class="relative z-50 lg:hidden" @close="sidebarOpen = false">
                <TransitionChild
                    as="template"
                    enter="transition-opacity ease-linear duration-300"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="transition-opacity ease-linear duration-300"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-gray-900/80" />
                </TransitionChild>

                <div class="fixed inset-0 flex">
                    <TransitionChild
                        as="template"
                        enter="transition ease-in-out duration-300 transform"
                        enter-from="-translate-x-full"
                        enter-to="translate-x-0"
                        leave="transition ease-in-out duration-300 transform"
                        leave-from="translate-x-0"
                        leave-to="-translate-x-full"
                    >
                        <DialogPanel class="relative mr-16 flex w-full max-w-xs flex-1">
                            <TransitionChild
                                as="template"
                                enter="ease-in-out duration-300"
                                enter-from="opacity-0"
                                enter-to="opacity-100"
                                leave="ease-in-out duration-300"
                                leave-from="opacity-100"
                                leave-to="opacity-0"
                            >
                                <div class="absolute left-full top-0 flex w-16 justify-center pt-5">
                                    <button type="button" class="-m-2.5 p-2.5" @click="sidebarOpen = false">
                                        <span class="sr-only">Close sidebar</span>
                                        <XMarkIcon class="size-6 text-white" aria-hidden="true" />
                                    </button>
                                </div>
                            </TransitionChild>
                            <!-- Mobile sidebar content -->
                            <AppSidebar />
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>

        <!-- Static sidebar for desktop -->
        <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-72 lg:flex-col">
            <AppSidebar />
        </div>

        <div class="lg:pl-72">
            <AppHeader :breadcrumbs="breadcrumbs" @open-sidebar="sidebarOpen = true" @open-search="openSearch" />

            <main class="py-10">
                <div class="px-4 sm:px-6 lg:px-8">
                    <slot />
                </div>
            </main>
        </div>

        <!-- AI Chat Floating Button -->
        <button
            type="button"
            class="fixed bottom-6 right-6 z-40 flex items-center justify-center size-14 rounded-full bg-primary text-primary-foreground shadow-lg hover:bg-primary/90 transition-colors focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
            @click="chatOpen = true"
        >
            <svg
                class="size-6"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                />
            </svg>
            <span class="sr-only">Ask AI</span>
        </button>

        <!-- AI Chat Panel -->
        <ChatPanel v-model:open="chatOpen" />

        <!-- Voice Assistant -->
        <VoiceAssistant />

        <!-- Bug Report Button -->
        <BugReportButton />

        <!-- Global Search Command Palette -->
        <CommandPalette ref="commandPaletteRef" />

        <!-- Global Barcode Scanner Feedback -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="translate-y-2 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-2 opacity-0"
        >
            <div
                v-if="scannerFeedback"
                class="fixed bottom-20 left-1/2 -translate-x-1/2 z-50 flex items-center gap-2 px-4 py-3 rounded-lg shadow-lg text-white"
                :class="[
                    scannerFeedback.type === 'success' ? 'bg-green-600' : 'bg-red-600'
                ]"
            >
                <CheckCircleIcon v-if="scannerFeedback.type === 'success'" class="size-5" />
                <XCircleIcon v-else class="size-5" />
                <span class="text-sm font-medium">{{ scannerFeedback.message }}</span>
            </div>
        </Transition>

        <!-- Toast Notifications -->
        <Toaster
            position="top-right"
            :toastOptions="{
                classNames: {
                    toast: 'group toast group-[.toaster]:bg-white group-[.toaster]:text-gray-900 group-[.toaster]:border-gray-200 group-[.toaster]:shadow-lg dark:group-[.toaster]:bg-gray-800 dark:group-[.toaster]:text-gray-100 dark:group-[.toaster]:border-gray-700',
                    error: 'group-[.toaster]:bg-red-50 group-[.toaster]:text-red-900 group-[.toaster]:border-red-200 dark:group-[.toaster]:bg-red-900/20 dark:group-[.toaster]:text-red-100 dark:group-[.toaster]:border-red-800',
                    success: 'group-[.toaster]:bg-green-50 group-[.toaster]:text-green-900 group-[.toaster]:border-green-200 dark:group-[.toaster]:bg-green-900/20 dark:group-[.toaster]:text-green-100 dark:group-[.toaster]:border-green-800',
                    warning: 'group-[.toaster]:bg-yellow-50 group-[.toaster]:text-yellow-900 group-[.toaster]:border-yellow-200 dark:group-[.toaster]:bg-yellow-900/20 dark:group-[.toaster]:text-yellow-100 dark:group-[.toaster]:border-yellow-800',
                    info: 'group-[.toaster]:bg-blue-50 group-[.toaster]:text-blue-900 group-[.toaster]:border-blue-200 dark:group-[.toaster]:bg-blue-900/20 dark:group-[.toaster]:text-blue-100 dark:group-[.toaster]:border-blue-800',
                },
            }"
            richColors
        />
    </div>
</template>
