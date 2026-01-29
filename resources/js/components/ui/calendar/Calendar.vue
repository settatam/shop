<script setup lang="ts">
import { cn } from "@/lib/utils"
import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
    CalendarHeader,
    CalendarHeading,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
    type CalendarRootEmits,
    type CalendarRootProps,
} from "reka-ui"
import { type HTMLAttributes, computed } from "vue"
import { ChevronLeft, ChevronRight } from "lucide-vue-next"

const props = defineProps<CalendarRootProps & { class?: HTMLAttributes["class"] }>()

const emits = defineEmits<CalendarRootEmits>()

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props
    return delegated
})
</script>

<template>
    <CalendarRoot
        v-slot="{ grid, weekDays }"
        :class="cn('p-3', props.class)"
        v-bind="delegatedProps"
        @update:model-value="
            (value) => {
                emits('update:modelValue', value)
            }
        "
    >
        <CalendarHeader class="relative flex w-full items-center justify-between pt-1">
            <CalendarPrev
                class="inline-flex size-7 items-center justify-center rounded-md border border-input bg-transparent hover:bg-accent hover:text-accent-foreground"
            >
                <ChevronLeft class="size-4" />
            </CalendarPrev>
            <CalendarHeading class="text-sm font-medium" />
            <CalendarNext
                class="inline-flex size-7 items-center justify-center rounded-md border border-input bg-transparent hover:bg-accent hover:text-accent-foreground"
            >
                <ChevronRight class="size-4" />
            </CalendarNext>
        </CalendarHeader>

        <div class="mt-4 flex flex-col gap-y-4 sm:flex-row sm:gap-x-4 sm:gap-y-0">
            <CalendarGrid v-for="month in grid" :key="month.value.toString()">
                <CalendarGridHead>
                    <CalendarGridRow class="flex">
                        <CalendarHeadCell
                            v-for="day in weekDays"
                            :key="day"
                            class="w-8 rounded-md text-[0.8rem] font-normal text-muted-foreground"
                        >
                            {{ day }}
                        </CalendarHeadCell>
                    </CalendarGridRow>
                </CalendarGridHead>
                <CalendarGridBody>
                    <CalendarGridRow
                        v-for="(weekDates, index) in month.rows"
                        :key="`weekDate-${index}`"
                        class="mt-2 flex w-full"
                    >
                        <CalendarCell
                            v-for="weekDate in weekDates"
                            :key="weekDate.toString()"
                            :date="weekDate"
                            class="relative flex size-8 items-center justify-center p-0 text-center text-sm focus-within:relative focus-within:z-20 [&:has([data-selected])]:rounded-md [&:has([data-selected])]:bg-accent [&:has([data-selected][data-outside-month])]:bg-accent/50"
                        >
                            <CalendarCellTrigger
                                :day="weekDate"
                                :month="month.value"
                                class="inline-flex size-8 items-center justify-center rounded-md p-0 text-sm font-normal transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 aria-selected:opacity-100 data-[selected]:bg-primary data-[selected]:text-primary-foreground data-[selected]:hover:bg-primary data-[selected]:hover:text-primary-foreground data-[selected]:focus:bg-primary data-[selected]:focus:text-primary-foreground data-[disabled]:text-muted-foreground data-[disabled]:opacity-50 data-[unavailable]:text-muted-foreground data-[unavailable]:line-through data-[outside-month]:text-muted-foreground data-[outside-month]:opacity-50 data-[outside-month]:pointer-events-none data-[today]:bg-accent data-[today]:text-accent-foreground"
                            />
                        </CalendarCell>
                    </CalendarGridRow>
                </CalendarGridBody>
            </CalendarGrid>
        </div>
    </CalendarRoot>
</template>
