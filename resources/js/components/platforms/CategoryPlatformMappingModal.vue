<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
    DialogDescription,
} from '@/components/ui/dialog';
import PlatformCategoryBrowser from './PlatformCategoryBrowser.vue';
import axios from 'axios';

interface PlatformCategory {
    id: number;
    name: string;
    ebay_category_id?: string;
    etsy_id?: string;
    level: number;
    parent_id: number | null;
    children_count: number;
    has_children: boolean;
}

interface CategoryDetails {
    category: {
        id: number;
        name: string;
        ebay_category_id: string;
        path: { id: number; name: string }[];
    };
    item_specifics: {
        id: number;
        name: string;
        is_required: boolean;
        is_recommended: boolean;
        values: string[];
    }[];
    item_specifics_count: number;
}

interface Props {
    open: boolean;
    categoryId: number;
    categoryName: string;
    marketplaceId: number;
    platform: string;
    platformLabel: string;
    existingPrimaryCategoryId?: string | null;
    existingSecondaryCategoryId?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    existingPrimaryCategoryId: null,
    existingSecondaryCategoryId: null,
});

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'saved', mapping: Record<string, unknown>): void;
}>();

const step = ref<'primary' | 'secondary' | 'preview'>('primary');
const saving = ref(false);
const error = ref('');

// Selected categories
const primaryCategory = ref<PlatformCategory | null>(null);
const secondaryCategory = ref<PlatformCategory | null>(null);
const categoryDetails = ref<CategoryDetails | null>(null);
const loadingDetails = ref(false);

const supportsSecondaryCategory = computed(() => props.platform === 'ebay');

const selectedPrimaryCategoryId = computed(() => {
    if (primaryCategory.value) {
        return String(primaryCategory.value.ebay_category_id || primaryCategory.value.etsy_id || primaryCategory.value.id);
    }
    return props.existingPrimaryCategoryId ?? null;
});

function selectPrimaryCategory(category: PlatformCategory) {
    primaryCategory.value = category;
    fetchCategoryDetails(category);
}

function selectSecondaryCategory(category: PlatformCategory) {
    secondaryCategory.value = category;
    step.value = 'preview';
}

async function fetchCategoryDetails(category: PlatformCategory) {
    if (props.platform !== 'ebay') return;

    loadingDetails.value = true;
    try {
        const response = await axios.get(`/taxonomy/ebay/categories/${category.id}`);
        categoryDetails.value = response.data;
    } catch (e) {
        console.error('Failed to fetch category details:', e);
    } finally {
        loadingDetails.value = false;
    }
}

function goToSecondary() {
    step.value = 'secondary';
}

function goToPreview() {
    step.value = 'preview';
}

function getCategoryPath(category: PlatformCategory): string {
    return category.name;
}

function getPrimaryExternalId(): string {
    if (!primaryCategory.value) return '';
    return String(
        primaryCategory.value.ebay_category_id ||
        primaryCategory.value.etsy_id ||
        primaryCategory.value.id
    );
}

function getSecondaryExternalId(): string | null {
    if (!secondaryCategory.value) return null;
    return String(
        secondaryCategory.value.ebay_category_id ||
        secondaryCategory.value.etsy_id ||
        secondaryCategory.value.id
    );
}

async function saveMapping() {
    if (!primaryCategory.value) return;

    saving.value = true;
    error.value = '';

    try {
        const payload = {
            primary_category_id: getPrimaryExternalId(),
            primary_category_name: categoryDetails.value
                ? categoryDetails.value.category.path.map((p) => p.name).join(' > ')
                : primaryCategory.value.name,
            secondary_category_id: getSecondaryExternalId(),
            secondary_category_name: secondaryCategory.value?.name ?? null,
        };

        const response = await axios.post(
            `/categories/${props.categoryId}/platform-mappings/${props.marketplaceId}`,
            payload,
        );

        emit('saved', response.data);
        closeModal();
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { message?: string } } };
        error.value = axiosError.response?.data?.message ?? 'Failed to save mapping.';
    } finally {
        saving.value = false;
    }
}

function closeModal() {
    emit('update:open', false);
    // Reset state
    step.value = 'primary';
    primaryCategory.value = null;
    secondaryCategory.value = null;
    categoryDetails.value = null;
    error.value = '';
}

watch(() => props.open, (isOpen) => {
    if (!isOpen) {
        closeModal();
    }
});
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-w-2xl max-h-[85vh] flex flex-col">
            <DialogHeader>
                <DialogTitle>
                    Map "{{ categoryName }}" to {{ platformLabel }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="step === 'primary'">
                        Select the primary {{ platformLabel }} category for your products.
                    </template>
                    <template v-else-if="step === 'secondary'">
                        Optionally select a secondary {{ platformLabel }} category.
                    </template>
                    <template v-else>
                        Review your category mapping before saving.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <!-- Step indicator -->
            <div class="flex items-center gap-2 text-xs">
                <span
                    class="px-2 py-0.5 rounded-full"
                    :class="step === 'primary' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'"
                >
                    1. Primary
                </span>
                <span v-if="supportsSecondaryCategory"
                    class="px-2 py-0.5 rounded-full"
                    :class="step === 'secondary' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'"
                >
                    2. Secondary
                </span>
                <span
                    class="px-2 py-0.5 rounded-full"
                    :class="step === 'preview' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'"
                >
                    {{ supportsSecondaryCategory ? '3' : '2' }}. Review
                </span>
            </div>

            <!-- Content area -->
            <div class="flex-1 overflow-hidden py-2">
                <!-- Primary category selection -->
                <div v-if="step === 'primary'">
                    <PlatformCategoryBrowser
                        :platform="platform"
                        :selected-category-id="selectedPrimaryCategoryId"
                        @select="selectPrimaryCategory"
                    />

                    <div v-if="primaryCategory" class="mt-3 p-3 rounded-md bg-muted/50">
                        <Label class="text-xs text-muted-foreground">Selected primary category</Label>
                        <p class="text-sm font-medium mt-1">
                            {{ categoryDetails?.category.path.map((p) => p.name).join(' > ') || primaryCategory.name }}
                        </p>
                        <p v-if="categoryDetails" class="text-xs text-muted-foreground mt-1">
                            {{ categoryDetails.item_specifics_count }} item specifics available
                        </p>
                    </div>
                </div>

                <!-- Secondary category selection (eBay only) -->
                <div v-else-if="step === 'secondary'">
                    <PlatformCategoryBrowser
                        :platform="platform"
                        @select="selectSecondaryCategory"
                    />

                    <div v-if="secondaryCategory" class="mt-3 p-3 rounded-md bg-muted/50">
                        <Label class="text-xs text-muted-foreground">Selected secondary category</Label>
                        <p class="text-sm font-medium mt-1">{{ secondaryCategory.name }}</p>
                    </div>
                </div>

                <!-- Preview -->
                <div v-else-if="step === 'preview'" class="space-y-4">
                    <div class="p-4 rounded-md border dark:border-gray-700">
                        <div class="space-y-3">
                            <div>
                                <Label class="text-xs text-muted-foreground">Local Category</Label>
                                <p class="text-sm font-medium">{{ categoryName }}</p>
                            </div>
                            <div>
                                <Label class="text-xs text-muted-foreground">Primary {{ platformLabel }} Category</Label>
                                <p class="text-sm font-medium">
                                    {{ categoryDetails?.category.path.map((p) => p.name).join(' > ') || primaryCategory?.name }}
                                </p>
                            </div>
                            <div v-if="secondaryCategory">
                                <Label class="text-xs text-muted-foreground">Secondary {{ platformLabel }} Category</Label>
                                <p class="text-sm font-medium">{{ secondaryCategory.name }}</p>
                            </div>
                            <div v-if="categoryDetails">
                                <Label class="text-xs text-muted-foreground">Item Specifics</Label>
                                <p class="text-sm text-muted-foreground">
                                    {{ categoryDetails.item_specifics_count }} item specifics will be available for mapping.
                                    <span v-if="categoryDetails.item_specifics.filter(s => s.is_required).length > 0" class="text-amber-600 dark:text-amber-400">
                                        {{ categoryDetails.item_specifics.filter(s => s.is_required).length }} required.
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="error" class="p-3 rounded-md bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-400">
                        {{ error }}
                    </div>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="closeModal">Cancel</Button>

                <template v-if="step === 'primary'">
                    <Button
                        v-if="supportsSecondaryCategory && primaryCategory"
                        variant="outline"
                        @click="goToSecondary"
                    >
                        Add Secondary Category
                    </Button>
                    <Button
                        :disabled="!primaryCategory"
                        @click="goToPreview"
                    >
                        {{ supportsSecondaryCategory ? 'Skip Secondary' : 'Review' }}
                    </Button>
                </template>

                <template v-else-if="step === 'secondary'">
                    <Button variant="outline" @click="goToPreview">
                        Skip
                    </Button>
                </template>

                <template v-else-if="step === 'preview'">
                    <Button @click="step = 'primary'" variant="outline">
                        Back
                    </Button>
                    <Button :disabled="saving || !primaryCategory" @click="saveMapping">
                        {{ saving ? 'Saving...' : 'Save Mapping' }}
                    </Button>
                </template>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
