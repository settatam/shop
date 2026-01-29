<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

const delegatedProps = computed(() => {
    const { class: _, ...rest } = props;
    return rest;
});
</script>

<template>
    <tfoot
        :class="cn('border-t bg-muted/50 font-medium [&>tr]:last:border-b-0', props.class)"
        v-bind="delegatedProps"
    >
        <slot />
    </tfoot>
</template>
