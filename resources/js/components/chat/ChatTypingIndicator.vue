<script setup lang="ts">
defineProps<{
    status?: string;
}>();
</script>

<template>
    <div class="flex gap-3 px-4 py-3">
        <div class="size-8 shrink-0 rounded-full bg-secondary flex items-center justify-center">
            <span class="text-xs font-medium text-muted-foreground">AI</span>
        </div>

        <div class="bg-muted rounded-2xl rounded-bl-sm px-4 py-3">
            <div class="flex items-center gap-2">
                <div class="flex gap-1">
                    <span class="size-2 rounded-full bg-muted-foreground/50 animate-bounce [animation-delay:-0.3s]" />
                    <span class="size-2 rounded-full bg-muted-foreground/50 animate-bounce [animation-delay:-0.15s]" />
                    <span class="size-2 rounded-full bg-muted-foreground/50 animate-bounce" />
                </div>
                <span
                    v-if="status"
                    class="text-sm text-muted-foreground"
                >
                    {{ status }}
                </span>
            </div>
        </div>
    </div>
</template>
