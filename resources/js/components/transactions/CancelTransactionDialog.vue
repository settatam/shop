<script setup lang="ts">
import { cancel } from '@/actions/App/Http/Controllers/Web/TransactionController';
import { Form } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

defineProps<{
    transaction: { id: number; transaction_number: string };
}>();
</script>

<template>
    <Dialog>
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="cancel.form(transaction)"
                class="space-y-6"
                #default="{ processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>Cancel transaction?</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to cancel
                        <strong class="text-foreground">{{ transaction.transaction_number }}</strong>?
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">
                            No, keep it
                        </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        {{ processing ? 'Cancelling...' : 'Yes, cancel' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
