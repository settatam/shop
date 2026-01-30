<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { XMarkIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';

interface Image {
    id: number;
    url: string;
    thumbnail_url?: string | null;
    alt_text?: string | null;
    alt?: string | null; // Alternative field name for alt text
}

interface Props {
    images: Image[];
    initialIndex?: number;
    modelValue: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    initialIndex: 0,
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void;
}>();

const currentIndex = ref(props.initialIndex);

// Reset index when lightbox opens with new initial index
watch(() => props.modelValue, (open) => {
    if (open) {
        currentIndex.value = props.initialIndex;
    }
});

watch(() => props.initialIndex, (newIndex) => {
    if (props.modelValue) {
        currentIndex.value = newIndex;
    }
});

const currentImage = computed(() => props.images[currentIndex.value]);

const hasMultipleImages = computed(() => props.images.length > 1);

const close = () => {
    emit('update:modelValue', false);
};

const previous = () => {
    if (currentIndex.value > 0) {
        currentIndex.value--;
    } else {
        currentIndex.value = props.images.length - 1;
    }
};

const next = () => {
    if (currentIndex.value < props.images.length - 1) {
        currentIndex.value++;
    } else {
        currentIndex.value = 0;
    }
};

const goToImage = (index: number) => {
    currentIndex.value = index;
};

// Handle keyboard navigation
const handleKeydown = (event: KeyboardEvent) => {
    if (!props.modelValue) return;

    switch (event.key) {
        case 'Escape':
            close();
            break;
        case 'ArrowLeft':
            previous();
            break;
        case 'ArrowRight':
            next();
            break;
    }
};

// Add/remove keyboard listener
watch(() => props.modelValue, (open) => {
    if (open) {
        document.addEventListener('keydown', handleKeydown);
        document.body.style.overflow = 'hidden';
    } else {
        document.removeEventListener('keydown', handleKeydown);
        document.body.style.overflow = '';
    }
}, { immediate: true });
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            leave-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modelValue"
                class="fixed inset-0 z-50 flex items-center justify-center"
                @click.self="close"
            >
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/90" @click="close" />

                <!-- Close button -->
                <button
                    type="button"
                    class="absolute right-4 top-4 z-10 rounded-full bg-black/50 p-2 text-white hover:bg-black/70 focus:outline-none focus:ring-2 focus:ring-white"
                    @click="close"
                >
                    <XMarkIcon class="size-6" />
                </button>

                <!-- Previous button -->
                <button
                    v-if="hasMultipleImages"
                    type="button"
                    class="absolute left-4 z-10 rounded-full bg-black/50 p-2 text-white hover:bg-black/70 focus:outline-none focus:ring-2 focus:ring-white"
                    @click="previous"
                >
                    <ChevronLeftIcon class="size-6" />
                </button>

                <!-- Next button -->
                <button
                    v-if="hasMultipleImages"
                    type="button"
                    class="absolute right-4 z-10 rounded-full bg-black/50 p-2 text-white hover:bg-black/70 focus:outline-none focus:ring-2 focus:ring-white"
                    @click="next"
                >
                    <ChevronRightIcon class="size-6" />
                </button>

                <!-- Main image -->
                <div class="relative z-10 flex max-h-[90vh] max-w-[90vw] flex-col items-center">
                    <img
                        v-if="currentImage"
                        :src="currentImage.url"
                        :alt="currentImage.alt_text || currentImage.alt || ''"
                        class="max-h-[80vh] max-w-full object-contain"
                    />

                    <!-- Image counter -->
                    <div v-if="hasMultipleImages" class="mt-4 text-sm text-white">
                        {{ currentIndex + 1 }} / {{ images.length }}
                    </div>

                    <!-- Thumbnails -->
                    <div v-if="hasMultipleImages" class="mt-4 flex gap-2 overflow-x-auto pb-2">
                        <button
                            v-for="(image, index) in images"
                            :key="image.id"
                            type="button"
                            class="shrink-0 overflow-hidden rounded-md ring-2 transition"
                            :class="index === currentIndex ? 'ring-white' : 'ring-transparent opacity-60 hover:opacity-100'"
                            @click="goToImage(index)"
                        >
                            <img
                                :src="image.thumbnail_url || image.url"
                                :alt="image.alt_text || image.alt || ''"
                                class="h-12 w-12 object-cover"
                            />
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
