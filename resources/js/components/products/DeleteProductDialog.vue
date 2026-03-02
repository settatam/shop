<script setup lang="ts">
import { destroy } from '@/actions/App/Http/Controllers/Web/ProductController';
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
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';

defineProps<{
    product: { id: number; title: string };
}>();
</script>

<template>
    <Dialog>
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="destroy.form(product)"
                class="space-y-6"
                #default="{ processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>Delete product?</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete
                        <strong class="text-foreground">{{ product.title }}</strong>?
                        This action can only be undone by an administrator.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="deletion_reason">Reason for deletion (optional)</Label>
                    <Textarea
                        id="deletion_reason"
                        name="deletion_reason"
                        placeholder="Why is this product being deleted?"
                        class="min-h-[100px]"
                    />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        {{ processing ? 'Deleting...' : 'Delete product' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
