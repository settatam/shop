<?php

namespace App\Services\Jewelry;

use App\Models\Certification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CertificationService
{
    public function createCertification(int $storeId, array $data): Certification
    {
        return Certification::create([
            'store_id' => $storeId,
            'lab' => $data['lab'],
            'certificate_number' => $data['certificate_number'],
            'issue_date' => $data['issue_date'] ?? null,
            'report_type' => $data['report_type'] ?? null,
            'shape' => $data['shape'] ?? null,
            'carat_weight' => $data['carat_weight'] ?? null,
            'color_grade' => $data['color_grade'] ?? null,
            'clarity_grade' => $data['clarity_grade'] ?? null,
            'cut_grade' => $data['cut_grade'] ?? null,
            'polish' => $data['polish'] ?? null,
            'symmetry' => $data['symmetry'] ?? null,
            'fluorescence' => $data['fluorescence'] ?? null,
            'measurements' => $data['measurements'] ?? null,
            'proportions' => $data['proportions'] ?? null,
            'inscription' => $data['inscription'] ?? null,
            'comments' => $data['comments'] ?? null,
            'verification_url' => $data['verification_url'] ?? null,
            'pdf_path' => $data['pdf_path'] ?? null,
            'raw_data' => $data['raw_data'] ?? null,
        ]);
    }

    public function lookupGIACertificate(string $certificateNumber): ?array
    {
        // GIA doesn't have a public API, but this is a placeholder
        // for potential integration with GIA Report Check or third-party services
        try {
            $response = Http::get("https://api.example.com/gia/lookup/{$certificateNumber}");

            if ($response->successful()) {
                return $this->parseGIAResponse($response->json());
            }
        } catch (\Throwable $e) {
            // Log error
        }

        return null;
    }

    public function importFromGIA(int $storeId, string $certificateNumber): ?Certification
    {
        $data = $this->lookupGIACertificate($certificateNumber);

        if (! $data) {
            return null;
        }

        // Check if certificate already exists
        $existing = Certification::where('certificate_number', $certificateNumber)->first();
        if ($existing) {
            return $existing;
        }

        return $this->createCertification($storeId, array_merge($data, [
            'lab' => 'GIA',
            'certificate_number' => $certificateNumber,
        ]));
    }

    public function verifyCertificate(Certification $certification): array
    {
        $verificationUrl = $certification->getVerificationUrl();

        if (! $verificationUrl) {
            return [
                'verified' => false,
                'message' => 'No verification URL available for this lab',
            ];
        }

        // This would typically involve scraping or API calls
        // For now, return placeholder
        return [
            'verified' => true,
            'message' => 'Certificate verification URL generated',
            'url' => $verificationUrl,
        ];
    }

    public function uploadCertificatePdf(Certification $certification, $file): Certification
    {
        $path = "certifications/{$certification->store_id}/{$certification->id}.pdf";
        Storage::disk('public')->put($path, file_get_contents($file));

        $certification->update(['pdf_path' => $path]);

        return $certification;
    }

    public function getSupportedLabs(): array
    {
        return [
            'GIA' => [
                'name' => 'Gemological Institute of America',
                'website' => 'https://www.gia.edu',
                'verification_url_pattern' => 'https://www.gia.edu/report-check?reportno={number}',
            ],
            'AGS' => [
                'name' => 'American Gem Society',
                'website' => 'https://www.americangemsociety.org',
                'verification_url_pattern' => 'https://www.agslab.com/report-check?reportno={number}',
            ],
            'IGI' => [
                'name' => 'International Gemological Institute',
                'website' => 'https://www.igi.org',
                'verification_url_pattern' => 'https://www.igi.org/verify.php?r={number}',
            ],
            'HRD' => [
                'name' => 'Hoge Raad voor Diamant',
                'website' => 'https://www.hrdantwerp.com',
                'verification_url_pattern' => null,
            ],
            'EGL' => [
                'name' => 'European Gemological Laboratory',
                'website' => 'https://www.egl.co.za',
                'verification_url_pattern' => null,
            ],
            'GCAL' => [
                'name' => 'Gem Certification & Assurance Lab',
                'website' => 'https://www.gcalusa.com',
                'verification_url_pattern' => 'https://www.gcalusa.com/certificate-search.html',
            ],
        ];
    }

    public function validateCertificateNumber(string $lab, string $number): bool
    {
        return match (strtoupper($lab)) {
            'GIA' => preg_match('/^\d{10}$/', $number) === 1,
            'AGS' => preg_match('/^\d{8,12}$/', $number) === 1,
            'IGI' => preg_match('/^[A-Z0-9]{8,15}$/', $number) === 1,
            default => strlen($number) >= 6,
        };
    }

    protected function parseGIAResponse(array $response): array
    {
        // Parse GIA API response into our certification format
        return [
            'report_type' => $response['report_type'] ?? null,
            'issue_date' => $response['date_of_issue'] ?? null,
            'shape' => $response['shape_cut'] ?? null,
            'carat_weight' => $response['carat_weight'] ?? null,
            'color_grade' => $response['color'] ?? null,
            'clarity_grade' => $response['clarity'] ?? null,
            'cut_grade' => $response['cut'] ?? null,
            'polish' => $response['polish'] ?? null,
            'symmetry' => $response['symmetry'] ?? null,
            'fluorescence' => $response['fluorescence'] ?? null,
            'measurements' => [
                'length' => $response['measurements']['length'] ?? null,
                'width' => $response['measurements']['width'] ?? null,
                'depth' => $response['measurements']['depth'] ?? null,
            ],
            'proportions' => [
                'depth_percent' => $response['depth_percent'] ?? null,
                'table_percent' => $response['table_percent'] ?? null,
                'crown_angle' => $response['crown_angle'] ?? null,
                'crown_height' => $response['crown_height'] ?? null,
                'pavilion_angle' => $response['pavilion_angle'] ?? null,
                'pavilion_depth' => $response['pavilion_depth'] ?? null,
                'star_length' => $response['star_length'] ?? null,
                'lower_half' => $response['lower_half'] ?? null,
                'girdle' => $response['girdle'] ?? null,
                'culet' => $response['culet'] ?? null,
            ],
            'inscription' => $response['inscription'] ?? null,
            'comments' => $response['comments'] ?? null,
            'raw_data' => $response,
        ];
    }
}
