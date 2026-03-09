/**
 * Determine if a barcode string looks like an AAMVA PDF417 driver's license barcode.
 * Used client-side to distinguish DL scans from product UPCs without a network call.
 */
export function isAamvaBarcode(data: string): boolean {
    if (data.length < 50) {
        return false;
    }

    if (data.includes('ANSI ') || data.includes('AAMVA')) {
        return true;
    }

    const fieldCodes = ['DAQ', 'DCS', 'DAC', 'DAA', 'DAG', 'DAI', 'DAJ', 'DBB'];
    let matchCount = 0;
    for (const code of fieldCodes) {
        if (data.includes(code)) {
            matchCount++;
        }
    }

    return matchCount >= 3;
}
