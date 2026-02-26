<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Props {
    open: boolean;
    editData?: Record<string, unknown> | null;
}

const props = withDefaults(defineProps<Props>(), {
    editData: null,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    save: [data: Record<string, unknown>];
}>();

const saving = ref(false);
const errors = ref<Record<string, string>>({});

const selectClass = 'mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';

const form = ref({
    location_key: '',
    name: '',
    addressLine1: '',
    city: '',
    stateOrProvince: '',
    postalCode: '',
    country: 'US',
    locationType: 'WAREHOUSE',
    status: 'ENABLED',
});

const isEdit = ref(false);

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        errors.value = {};
        if (props.editData) {
            isEdit.value = true;
            const data = props.editData;
            form.value.location_key = (data.merchantLocationKey as string) ?? '';
            form.value.name = (data.name as string) ?? '';
            const address = (data.location as Record<string, unknown>)?.address as Record<string, unknown> ?? {};
            form.value.addressLine1 = (address.addressLine1 as string) ?? '';
            form.value.city = (address.city as string) ?? '';
            form.value.stateOrProvince = (address.stateOrProvince as string) ?? '';
            form.value.postalCode = (address.postalCode as string) ?? '';
            form.value.country = (address.country as string) ?? 'US';
            form.value.status = (data.merchantLocationStatus as string) ?? 'ENABLED';
        } else {
            isEdit.value = false;
            form.value = {
                location_key: '',
                name: '',
                addressLine1: '',
                city: '',
                stateOrProvince: '',
                postalCode: '',
                country: 'US',
                locationType: 'WAREHOUSE',
                status: 'ENABLED',
            };
        }
    }
});

function validate(): boolean {
    errors.value = {};
    if (!form.value.location_key && !isEdit.value) {
        errors.value.location_key = 'Location key is required.';
    }
    if (!form.value.name) {
        errors.value.name = 'Name is required.';
    }
    if (!form.value.city) {
        errors.value.city = 'City is required.';
    }
    if (!form.value.postalCode) {
        errors.value.postalCode = 'Postal code is required.';
    }
    if (!form.value.country) {
        errors.value.country = 'Country is required.';
    }
    return Object.keys(errors.value).length === 0;
}

function submit() {
    if (!validate()) return;

    saving.value = true;

    const data: Record<string, unknown> = {
        location_key: form.value.location_key,
        name: form.value.name,
        location: {
            address: {
                addressLine1: form.value.addressLine1,
                city: form.value.city,
                stateOrProvince: form.value.stateOrProvince,
                postalCode: form.value.postalCode,
                country: form.value.country,
            },
        },
        locationTypes: [form.value.locationType],
        merchantLocationStatus: form.value.status,
    };

    emit('save', data);
    saving.value = false;
}

function close() {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="close">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ isEdit ? 'Edit' : 'Create' }} Inventory Location</DialogTitle>
                <DialogDescription>
                    Configure the location details. This will be {{ isEdit ? 'updated' : 'created' }} on your eBay account.
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="submit" class="space-y-4">
                <div v-if="!isEdit">
                    <Label for="loc-key">Location Key</Label>
                    <Input
                        id="loc-key"
                        v-model="form.location_key"
                        class="mt-1"
                        placeholder="e.g. warehouse-01"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        A unique identifier (letters, numbers, hyphens, underscores)
                    </p>
                    <InputError :message="errors.location_key" />
                </div>

                <div>
                    <Label for="loc-name">Location Name</Label>
                    <Input id="loc-name" v-model="form.name" class="mt-1" placeholder="e.g. Main Warehouse" />
                    <InputError :message="errors.name" />
                </div>

                <div>
                    <Label for="loc-address">Street Address</Label>
                    <Input id="loc-address" v-model="form.addressLine1" class="mt-1" placeholder="123 Main St" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label for="loc-city">City</Label>
                        <Input id="loc-city" v-model="form.city" class="mt-1" />
                        <InputError :message="errors.city" />
                    </div>

                    <div>
                        <Label for="loc-state">State / Province</Label>
                        <Input id="loc-state" v-model="form.stateOrProvince" class="mt-1" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label for="loc-zip">Postal Code</Label>
                        <Input id="loc-zip" v-model="form.postalCode" class="mt-1" />
                        <InputError :message="errors.postalCode" />
                    </div>

                    <div>
                        <Label for="loc-country">Country</Label>
                        <select id="loc-country" v-model="form.country" :class="selectClass">
                            <option value="US">United States</option>
                            <option value="GB">United Kingdom</option>
                            <option value="CA">Canada</option>
                            <option value="AU">Australia</option>
                            <option value="DE">Germany</option>
                            <option value="FR">France</option>
                            <option value="IT">Italy</option>
                            <option value="ES">Spain</option>
                        </select>
                        <InputError :message="errors.country" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label for="loc-type">Location Type</Label>
                        <select id="loc-type" v-model="form.locationType" :class="selectClass">
                            <option value="WAREHOUSE">Warehouse</option>
                            <option value="STORE">Store</option>
                        </select>
                    </div>

                    <div>
                        <Label for="loc-status">Status</Label>
                        <select id="loc-status" v-model="form.status" :class="selectClass">
                            <option value="ENABLED">Enabled</option>
                            <option value="DISABLED">Disabled</option>
                        </select>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="close">Cancel</Button>
                    <Button type="submit" :disabled="saving">
                        {{ saving ? 'Saving...' : (isEdit ? 'Update' : 'Create') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
