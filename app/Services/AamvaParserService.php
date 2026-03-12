<?php

namespace App\Services;

use Carbon\Carbon;

class AamvaParserService
{
    /**
     * Parse an AAMVA PDF417 barcode string into structured customer data.
     *
     * @return array{
     *     first_name: ?string,
     *     last_name: ?string,
     *     middle_name: ?string,
     *     suffix: ?string,
     *     address: ?string,
     *     city: ?string,
     *     state: ?string,
     *     zip: ?string,
     *     id_number: ?string,
     *     date_of_birth: ?string,
     *     id_expiration_date: ?string,
     *     sex: ?string,
     *     id_issuing_state: ?string,
     * }
     */
    public function parse(string $rawData): array
    {
        $fields = $this->extractFields($rawData);

        $firstName = $fields['DAC'] ?? $fields['DCT'] ?? null;
        $lastName = $fields['DCS'] ?? null;
        $middleName = $fields['DAD'] ?? null;

        if (! $firstName && ! $lastName && isset($fields['DAA'])) {
            $nameParts = $this->parseFullName($fields['DAA']);
            $lastName = $nameParts['last'];
            $firstName = $nameParts['first'];
            $middleName = $nameParts['middle'];
        }

        $sex = null;
        if (isset($fields['DBC'])) {
            $sex = match ($fields['DBC']) {
                '1' => 'M',
                '2' => 'F',
                default => null,
            };
        }

        return [
            'first_name' => $firstName ? $this->formatName($firstName) : null,
            'last_name' => $lastName ? $this->formatName($lastName) : null,
            'middle_name' => $middleName ? $this->formatName($middleName) : null,
            'suffix' => isset($fields['DAY']) ? $this->formatName($fields['DAY']) : null,
            'address' => $fields['DAG'] ?? null,
            'city' => isset($fields['DAI']) ? $this->formatName($fields['DAI']) : null,
            'state' => $fields['DAJ'] ?? null,
            'zip' => isset($fields['DAK']) ? $this->formatZip($fields['DAK']) : null,
            'id_number' => $fields['DAQ'] ?? null,
            'date_of_birth' => $this->parseDate($fields['DBB'] ?? null),
            'id_expiration_date' => $this->parseDate($fields['DBA'] ?? null),
            'sex' => $sex,
            'id_issuing_state' => $fields['DAJ'] ?? null,
        ];
    }

    /**
     * Determine if a string looks like an AAMVA PDF417 barcode.
     */
    public function isAamvaBarcode(string $data): bool
    {
        if (strlen($data) < 50) {
            return false;
        }

        if (str_contains($data, 'ANSI ') || str_contains($data, 'AAMVA')) {
            return true;
        }

        $fieldCodes = ['DAQ', 'DCS', 'DAC', 'DAA', 'DAG', 'DAI', 'DAJ', 'DBB'];
        $matchCount = 0;
        foreach ($fieldCodes as $code) {
            if (str_contains($data, $code)) {
                $matchCount++;
            }
        }

        return $matchCount >= 3;
    }

    private const FIELD_CODES = [
        'DAA', 'DAB', 'DAC', 'DAD', 'DAG', 'DAH', 'DAI', 'DAJ', 'DAK', 'DAL',
        'DAM', 'DAN', 'DAO', 'DAQ', 'DAR', 'DAS', 'DAT', 'DAU', 'DAW', 'DAX',
        'DAY', 'DAZ',
        'DBA', 'DBB', 'DBC', 'DBD',
        'DCA', 'DCB', 'DCD', 'DCE', 'DCF', 'DCG', 'DCH', 'DCI', 'DCJ', 'DCK',
        'DCL', 'DCM', 'DCN', 'DCO', 'DCP', 'DCQ', 'DCR', 'DCS', 'DCT', 'DCU',
        'DDA', 'DDB', 'DDC', 'DDD', 'DDE', 'DDF', 'DDG', 'DDH', 'DDI', 'DDJ',
        'DDK', 'DDL', 'DDM', 'DDN',
        'ZMZ',
    ];

    /**
     * Extract field code => value pairs from raw AAMVA data.
     *
     * @return array<string, string>
     */
    private function extractFields(string $rawData): array
    {
        $rawData = str_replace(["\r\n", "\r"], "\n", $rawData);

        $fields = [];
        $codesPattern = implode('|', self::FIELD_CODES);

        preg_match_all('/('.$codesPattern.')([^\n]*?)(?='.$codesPattern.'|\n|$)/s', $rawData, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $code = $match[1];
            $value = trim($match[2]);

            if ($value !== '' && ! isset($fields[$code])) {
                $fields[$code] = $value;
            }
        }

        return $fields;
    }

    /**
     * Parse the DAA full name field (format: LAST,FIRST,MIDDLE).
     *
     * @return array{last: ?string, first: ?string, middle: ?string}
     */
    private function parseFullName(string $fullName): array
    {
        $parts = explode(',', $fullName);

        return [
            'last' => trim($parts[0] ?? ''),
            'first' => trim($parts[1] ?? ''),
            'middle' => trim($parts[2] ?? '') ?: null,
        ];
    }

    /**
     * Parse a date string from AAMVA format (MMDDYYYY or MM/DD/YYYY or YYYYMMDD).
     */
    private function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        $date = trim(str_replace(['/', '-'], '', $date));

        if (strlen($date) !== 8) {
            return null;
        }

        try {
            if ((int) substr($date, 4) > 1900) {
                return Carbon::createFromFormat('mdY', $date)?->format('Y-m-d');
            }

            return Carbon::createFromFormat('Ymd', $date)?->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Format a name string from ALL CAPS to Title Case.
     */
    private function formatName(string $name): string
    {
        return mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Truncate zip code to 5 digits (remove +4 suffix).
     */
    private function formatZip(string $zip): string
    {
        $zip = trim($zip);

        if (strlen($zip) > 5) {
            return substr($zip, 0, 5);
        }

        return $zip;
    }
}
