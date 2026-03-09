import { ref } from 'vue';
import axios from 'axios';
import { isAamvaBarcode } from '@/lib/aamva';
import { parse } from '@/actions/App/Http/Controllers/Api/V1/CustomerIdScanController';

export interface IdScanParsedData {
    first_name: string | null;
    last_name: string | null;
    middle_name: string | null;
    suffix: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    zip: string | null;
    id_number: string | null;
    date_of_birth: string | null;
    id_expiration_date: string | null;
    sex: string | null;
    id_issuing_state: string | null;
}

export interface IdScanCustomer {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string | null;
    phone_number: string | null;
    address: string | null;
    address2: string | null;
    city: string | null;
    state: string | null;
    zip: string | null;
    id_number: string | null;
    is_active: boolean;
}

export interface IdScanResult {
    parsedData: IdScanParsedData;
    existingCustomer: IdScanCustomer | null;
}

export function useIdScanner() {
    const isProcessing = ref(false);
    const error = ref<string | null>(null);
    const lastScanResult = ref<IdScanResult | null>(null);

    async function processBarcode(barcode: string): Promise<IdScanResult | null> {
        if (!isAamvaBarcode(barcode)) {
            return null;
        }

        isProcessing.value = true;
        error.value = null;

        try {
            const response = await axios.post(parse.url(), { barcode });

            const result: IdScanResult = {
                parsedData: response.data.parsed_data,
                existingCustomer: response.data.existing_customer,
            };

            lastScanResult.value = result;
            return result;
        } catch (err: any) {
            error.value = err.response?.data?.message || 'Failed to process ID scan';
            return null;
        } finally {
            isProcessing.value = false;
        }
    }

    return {
        isProcessing,
        error,
        lastScanResult,
        processBarcode,
        isAamvaBarcode,
    };
}
