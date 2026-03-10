import { ref } from 'vue';
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
        console.log('[useIdScanner] processBarcode called, barcode length:', barcode.length);
        console.log('[useIdScanner] barcode preview:', barcode.substring(0, 100));
        console.log('[useIdScanner] isAamvaBarcode:', isAamvaBarcode(barcode));

        if (!isAamvaBarcode(barcode)) {
            console.log('[useIdScanner] Not an AAMVA barcode, returning null');
            return null;
        }

        isProcessing.value = true;
        error.value = null;

        try {
            console.log('[useIdScanner] Posting to:', parse.url());
            const response = await axios.post(parse.url(), { barcode });

            const result: IdScanResult = {
                parsedData: response.data.parsed_data,
                existingCustomer: response.data.existing_customer,
            };

            lastScanResult.value = result;
            return result;
        } catch (err: any) {
            console.error('[useIdScanner] Error:', err);
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
