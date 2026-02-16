<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { CheckIcon, PlusIcon } from 'lucide-vue-next';
import type { Store } from '@/types';

interface Props {
    open: boolean;
    stores: Store[];
    currentStore?: Store;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const hasMultipleStores = computed(() => props.stores.length > 1);

function switchStore(store: Store) {
    if (store.id === props.currentStore?.id) {
        emit('update:open', false);
        return;
    }

    router.post(`/stores/${store.id}/switch`, {}, {
        preserveState: false,
        preserveScroll: false,
        onSuccess: () => {
            emit('update:open', false);
        },
    });
}

function handleClose() {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(val) => emit('update:open', val)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Switch Store</DialogTitle>
                <DialogDescription>
                    Select a store to manage. You have access to {{ stores.length }} store{{ stores.length === 1 ? '' : 's' }}.
                </DialogDescription>
            </DialogHeader>

            <div class="py-4">
                <div class="space-y-2">
                    <button
                        v-for="store in stores"
                        :key="store.id"
                        type="button"
                        class="w-full flex items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-accent"
                        :class="{
                            'border-primary bg-primary/5': store.id === currentStore?.id,
                            'border-border': store.id !== currentStore?.id,
                        }"
                        @click="switchStore(store)"
                    >
                        <div
                            class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-background"
                        >
                            <img
                                v-if="store.logo_url"
                                :src="store.logo_url"
                                :alt="store.name"
                                class="h-full w-full object-contain"
                            />
                            <span v-else class="text-sm font-semibold text-muted-foreground">
                                {{ store.initial }}
                            </span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-medium truncate">{{ store.name }}</div>
                            <div v-if="store.role" class="text-sm text-muted-foreground truncate">
                                {{ store.role.name }}
                            </div>
                        </div>
                        <CheckIcon
                            v-if="store.id === currentStore?.id"
                            class="size-5 text-primary shrink-0"
                        />
                    </button>
                </div>

                <!-- Empty state if no stores -->
                <div v-if="stores.length === 0" class="text-center py-8 text-muted-foreground">
                    <p>You don't have access to any stores yet.</p>
                </div>

                <!-- Info message for single store -->
                <div v-if="stores.length === 1" class="mt-4 rounded-lg bg-muted/50 p-3 text-sm text-muted-foreground">
                    You currently have access to one store. Contact your administrator if you need access to additional stores.
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t pt-4">
                <Button variant="outline" @click="handleClose">
                    Close
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
