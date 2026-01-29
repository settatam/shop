<script setup lang="ts">
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover"
import {
    CalendarDate,
    type DateValue,
    getLocalTimeZone,
    parseDate,
    today,
} from "@internationalized/date"
import { CalendarIcon, XIcon } from "lucide-vue-next"
import { computed, ref, watch, type HTMLAttributes } from "vue"
import dayjs from "dayjs"

interface Props {
    modelValue?: string
    placeholder?: string
    disabled?: boolean
    clearable?: boolean
    class?: HTMLAttributes["class"]
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: "Select date",
    disabled: false,
    clearable: true,
})

const emit = defineEmits<{
    "update:modelValue": [value: string | undefined]
}>()

const open = ref(false)

// Convert string date to CalendarDate for the calendar
const calendarValue = computed<DateValue | undefined>({
    get() {
        if (!props.modelValue) return undefined
        try {
            return parseDate(props.modelValue)
        } catch {
            return undefined
        }
    },
    set(value) {
        if (!value) {
            emit("update:modelValue", undefined)
        } else {
            // Convert CalendarDate to YYYY-MM-DD string
            const dateString = `${value.year}-${String(value.month).padStart(2, "0")}-${String(value.day).padStart(2, "0")}`
            emit("update:modelValue", dateString)
        }
    },
})

// Format the display value
const displayValue = computed(() => {
    if (!props.modelValue) return props.placeholder
    return dayjs(props.modelValue).format("MMM D, YYYY")
})

function handleSelect(value: DateValue | undefined) {
    calendarValue.value = value
    open.value = false
}

function handleClear(e: MouseEvent) {
    e.stopPropagation()
    emit("update:modelValue", undefined)
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                :disabled="disabled"
                :class="
                    cn(
                        'w-[200px] justify-start text-left font-normal',
                        !modelValue && 'text-muted-foreground',
                        props.class
                    )
                "
            >
                <CalendarIcon class="mr-2 size-4" />
                <span class="flex-1 truncate">{{ displayValue }}</span>
                <XIcon
                    v-if="clearable && modelValue"
                    class="ml-1 size-4 shrink-0 opacity-50 hover:opacity-100"
                    @click="handleClear"
                />
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                :model-value="calendarValue"
                initial-focus
                @update:model-value="handleSelect"
            />
        </PopoverContent>
    </Popover>
</template>
