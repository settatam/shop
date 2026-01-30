<?php

namespace App\Services\Gia;

use App\Models\Category;
use App\Models\Certification;
use App\Models\Gemstone;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductImage;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class GiaProductService
{
    protected string $disk;

    protected int $canvasWidth = 2400;

    protected int $canvasHeight = 2040;

    public function __construct(
        protected GiaApiService $giaApiService,
    ) {
        $this->disk = config('filesystems.disks.do_spaces.bucket')
            ? 'do_spaces'
            : 'public';
    }

    /**
     * Create or update a product from GIA data.
     *
     * @return array{product: Product|null, errors: array|null}
     */
    public function createFromGia(
        string $reportNumber,
        ?string $secondReportNumber,
        Category $category,
        Store $store,
        int $userId,
    ): array {
        $errors = [];

        // Fetch first GIA report
        $firstReport = $this->giaApiService->getReport($reportNumber);
        if ($firstReport['errors']) {
            return [
                'product' => null,
                'errors' => ['GIA Number '.$reportNumber.': '.$firstReport['errors'][0]['message']],
            ];
        }

        // Fetch second GIA report if provided (for diamond studs)
        $secondReport = null;
        if ($secondReportNumber) {
            $secondReport = $this->giaApiService->getReport($secondReportNumber);
            if ($secondReport['errors']) {
                $errors[] = 'GIA Number '.$secondReportNumber.': '.$secondReport['errors'][0]['message'];
            }
        }

        // Check if product already exists with this GIA number
        $existingProduct = $this->findExistingProduct($store, $reportNumber, $category);

        return DB::transaction(function () use (
            $firstReport,
            $secondReport,
            $category,
            $store,
            $userId,
            $reportNumber,
            $secondReportNumber,
            $existingProduct,
            $errors,
        ) {
            $template = $category->getEffectiveTemplate();
            $isEarrings = $template && $template->name === 'Earrings';

            if ($existingProduct) {
                // Update existing product
                $product = $this->updateProduct(
                    $existingProduct,
                    $firstReport['data'],
                    $secondReport ? $secondReport['data'] : null,
                    $category,
                    $template,
                    $store,
                    $reportNumber,
                    $secondReportNumber,
                    $isEarrings,
                );
            } else {
                // Create new product
                $product = $this->createProduct(
                    $firstReport['data'],
                    $secondReport ? $secondReport['data'] : null,
                    $category,
                    $template,
                    $store,
                    $userId,
                    $reportNumber,
                    $secondReportNumber,
                    $isEarrings,
                );
            }

            return [
                'product' => $product->load(['variants', 'attributeValues.field', 'category']),
                'errors' => ! empty($errors) ? $errors : null,
            ];
        });
    }

    /**
     * Find existing product by GIA report number.
     */
    protected function findExistingProduct(Store $store, string $reportNumber, Category $category): ?Product
    {
        $template = $category->getEffectiveTemplate();
        if (! $template) {
            return null;
        }

        $isEarrings = $template->name === 'Earrings';
        $giaFieldName = $isEarrings ? 'main_stone_gia_report_number' : 'gia_report_number';

        // Find field ID by name
        $field = $template->fields()->where('name', $giaFieldName)->first();
        if (! $field) {
            return null;
        }

        return Product::where('store_id', $store->id)
            ->whereHas('attributeValues', function ($query) use ($field, $reportNumber) {
                $query->where('product_template_field_id', $field->id)
                    ->where('value', $reportNumber);
            })
            ->first();
    }

    /**
     * Create a new product from GIA data.
     */
    protected function createProduct(
        array $report,
        ?array $secondReport,
        Category $category,
        $template,
        Store $store,
        int $userId,
        string $reportNumber,
        ?string $secondReportNumber,
        bool $isEarrings,
    ): Product {
        $results = $report['results'] ?? [];

        // Generate title
        $title = $this->generateTitle($results, $secondReport ? ($secondReport['results'] ?? []) : null, $isEarrings);

        // Generate unique handle
        $baseHandle = Str::slug($title ?: 'gia-diamond-'.$reportNumber);
        $handle = $baseHandle;
        $counter = 1;
        while (Product::where('store_id', $store->id)->where('handle', $handle)->exists()) {
            $handle = $baseHandle.'-'.$counter;
            $counter++;
        }

        // Create product
        $product = Product::create([
            'store_id' => $store->id,
            'title' => $title,
            'handle' => $handle,
            'category_id' => $category->id,
            'template_id' => $template?->id,
            'is_published' => false,
            'is_draft' => true,
            'has_variants' => false,
            'track_quantity' => true,
            'quantity' => 1,
            'country_of_origin' => 'US',
        ]);

        // Create variant
        $product->variants()->create([
            'sku' => $this->generateSku($category, $reportNumber),
            'price' => 0,
            'cost' => 0,
            'quantity' => 1,
            'is_active' => true,
        ]);

        // Create certification
        $certification = $this->createCertification($store, $report);

        // Create gemstone linked to certification
        $this->createGemstone($store, $product, $certification, $results);

        // Set template attribute values
        if ($template) {
            $this->setTemplateAttributes(
                $product,
                $template,
                $results,
                $reportNumber,
                $isEarrings,
            );

            // Handle second stone for studs
            if ($secondReport && $isEarrings) {
                $secondResults = $secondReport['results'] ?? [];
                $this->setSecondStoneAttributes(
                    $product,
                    $template,
                    $secondResults,
                    $secondReportNumber,
                );

                // Create certification for second stone
                $secondCertification = $this->createCertification($store, $secondReport);
                $this->createGemstone($store, $product, $secondCertification, $secondResults);

                // Calculate total carat weight
                $this->setTotalCaratWeight($product, $template, $results, $secondResults);
            }
        }

        // Create images from GIA PDF certificates
        $reports = [$report];
        if ($secondReport) {
            $reports[] = $secondReport;
        }
        $this->createImagesFromPdf($product, $store, $reports);

        return $product;
    }

    /**
     * Update an existing product with new GIA data.
     */
    protected function updateProduct(
        Product $product,
        array $report,
        ?array $secondReport,
        Category $category,
        $template,
        Store $store,
        string $reportNumber,
        ?string $secondReportNumber,
        bool $isEarrings,
    ): Product {
        $results = $report['results'] ?? [];

        // Update template attribute values
        if ($template) {
            $this->setTemplateAttributes(
                $product,
                $template,
                $results,
                $reportNumber,
                $isEarrings,
            );

            // Handle second stone for studs
            if ($secondReport && $isEarrings) {
                $secondResults = $secondReport['results'] ?? [];
                $this->setSecondStoneAttributes(
                    $product,
                    $template,
                    $secondResults,
                    $secondReportNumber,
                );

                // Calculate total carat weight
                $this->setTotalCaratWeight($product, $template, $results, $secondResults);
            }
        }

        // Regenerate title
        $title = $this->generateTitle($results, $secondReport ? ($secondReport['results'] ?? []) : null, $isEarrings);
        if ($title) {
            $product->title = $title;
            $product->save();
        }

        return $product;
    }

    /**
     * Create certification record.
     */
    protected function createCertification(Store $store, array $report): Certification
    {
        $results = $report['results'] ?? [];
        $measurements = GiaApiService::parseMeasurements($results['measurements'] ?? null);

        return Certification::create([
            'store_id' => $store->id,
            'lab' => 'GIA',
            'certificate_number' => $report['report_number'],
            'issue_date' => $report['report_date'] ?? null,
            'report_type' => $report['report_type'] ?? null,
            'shape' => $results['shape_and_cutting_style'] ?? null,
            'carat_weight' => data_get($results, 'data.weight.weight'),
            'color_grade' => data_get($results, 'data.color_grades.color_grade_code') ?? $results['color_grade'] ?? null,
            'clarity_grade' => data_get($results, 'data.clarity') ?? $results['clarity_grade'] ?? null,
            'cut_grade' => $results['cut_grade'] ?? null,
            'polish' => $results['polish'] ?? null,
            'symmetry' => $results['symmetry'] ?? null,
            'fluorescence' => $results['fluorescence'] ?? null,
            'measurements' => [
                'length' => $measurements['min_diameter'],
                'width' => $measurements['max_diameter'],
                'depth' => $measurements['depth'],
            ],
            'verification_url' => "https://www.gia.edu/report-check?reportno={$report['report_number']}",
            'raw_data' => $report,
        ]);
    }

    /**
     * Create gemstone record.
     */
    protected function createGemstone(Store $store, Product $product, Certification $certification, array $results): Gemstone
    {
        $measurements = GiaApiService::parseMeasurements($results['measurements'] ?? null);

        return Gemstone::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'type' => 'diamond',
            'shape' => $results['shape_and_cutting_style'] ?? null,
            'carat_weight' => data_get($results, 'data.weight.weight'),
            'color_grade' => data_get($results, 'data.color_grades.color_grade_code') ?? $results['color_grade'] ?? null,
            'clarity_grade' => data_get($results, 'data.clarity') ?? $results['clarity_grade'] ?? null,
            'cut_grade' => $results['cut_grade'] ?? null,
            'fluorescence' => $results['fluorescence'] ?? null,
            'certification_id' => $certification->id,
            'length_mm' => $measurements['min_diameter'],
            'width_mm' => $measurements['max_diameter'],
            'depth_mm' => $measurements['depth'],
        ]);
    }

    /**
     * Set template attribute values for main stone.
     */
    protected function setTemplateAttributes(
        Product $product,
        $template,
        array $results,
        string $reportNumber,
        bool $isEarrings,
    ): void {
        $fields = $template->fields;

        // GIA report number field
        $giaFieldName = $isEarrings ? 'main_stone_gia_report_number' : 'gia_report_number';
        $this->setAttributeByName($product, $fields, $giaFieldName, $reportNumber);

        // Certification type
        $certFieldName = $isEarrings ? 'main_stone_cert_type' : 'cert_type';
        $this->setAttributeByName($product, $fields, $certFieldName, 'GIA');

        // Stone type
        $this->setAttributeByName($product, $fields, 'main_stone_type', 'Natural Diamond');

        if (! $isEarrings) {
            $this->setAttributeByName($product, $fields, 'includes', 'Certificate');
        }

        // Map GIA fields to template fields using appropriate mapping
        $mapping = $isEarrings
            ? GiaApiService::getEarringsMainStoneMapping()
            : GiaApiService::getLooseStonesMapping();
        $mappedValues = GiaApiService::extractMappedValues($results, $mapping);

        foreach ($mappedValues as $fieldName => $value) {
            if ($value !== null) {
                $this->setAttributeByName($product, $fields, $fieldName, (string) $value);
            }
        }

        // Handle measurements - field names differ by template
        $measurements = GiaApiService::parseMeasurements($results['measurements'] ?? null);
        if ($isEarrings) {
            // Earrings template uses main_stone_ prefix for measurements
            if ($measurements['min_diameter']) {
                $this->setAttributeByName($product, $fields, 'main_stone_min_diameter_length', $measurements['min_diameter']);
            }
            if ($measurements['max_diameter']) {
                $this->setAttributeByName($product, $fields, 'main_stone_max_diameter_width', $measurements['max_diameter']);
            }
            if ($measurements['depth']) {
                $this->setAttributeByName($product, $fields, 'main_stone_depth', $measurements['depth']);
            }
        } else {
            // Loose Stones template
            if ($measurements['min_diameter']) {
                $this->setAttributeByName($product, $fields, 'min_diameter_length', $measurements['min_diameter']);
            }
            if ($measurements['max_diameter']) {
                $this->setAttributeByName($product, $fields, 'max_diameter_width', $measurements['max_diameter']);
            }
            if ($measurements['depth']) {
                $this->setAttributeByName($product, $fields, 'stone_depth', $measurements['depth']);
            }
        }

        // Weight range
        $weight = data_get($results, 'data.weight.weight');
        if ($weight) {
            $weightRange = GiaApiService::getWeightRangeLabel((float) $weight);
            if ($weightRange) {
                $this->setAttributeByName($product, $fields, 'main_stone_weight', $weightRange);
            }
        }
    }

    /**
     * Set second stone attributes for diamond studs (Earrings template).
     */
    protected function setSecondStoneAttributes(
        Product $product,
        $template,
        array $results,
        string $reportNumber,
    ): void {
        $fields = $template->fields;

        // Second GIA report number
        $this->setAttributeByName($product, $fields, 'second_gia_report_number', $reportNumber);
        $this->setAttributeByName($product, $fields, 'second_stone_cert_type', 'GIA');
        $this->setAttributeByName($product, $fields, 'second_stone_type', 'Natural Diamond');

        // Map second stone fields
        $mappedValues = GiaApiService::extractMappedValues($results, GiaApiService::getEarringsSecondStoneMapping());

        foreach ($mappedValues as $fieldName => $value) {
            if ($value !== null) {
                $this->setAttributeByName($product, $fields, $fieldName, (string) $value);
            }
        }

        // Handle second stone measurements
        $measurements = GiaApiService::parseMeasurements($results['measurements'] ?? null);
        if ($measurements['min_diameter']) {
            $this->setAttributeByName($product, $fields, 'second_min_diameter_length', $measurements['min_diameter']);
        }
        if ($measurements['max_diameter']) {
            $this->setAttributeByName($product, $fields, 'second_stone_max_diameter_width', $measurements['max_diameter']);
        }
        if ($measurements['depth']) {
            $this->setAttributeByName($product, $fields, 'second_stone_depth', $measurements['depth']);
        }
    }

    /**
     * Calculate and set total carat weight for studs.
     */
    protected function setTotalCaratWeight(Product $product, $template, array $firstResults, array $secondResults): void
    {
        $firstWeight = (float) data_get($firstResults, 'data.weight.weight', 0);
        $secondWeight = (float) data_get($secondResults, 'data.weight.weight', 0);

        $totalWeight = $firstWeight + $secondWeight;

        if ($totalWeight > 0) {
            $fields = $template->fields;
            $this->setAttributeByName($product, $fields, 'total_carat_weight', (string) $totalWeight);

            // Get weight range for total
            $weightRange = GiaApiService::getWeightRangeLabel($totalWeight);
            if ($weightRange) {
                $this->setAttributeByName($product, $fields, 'total_stone_weight', $weightRange);
            }
        }
    }

    /**
     * Set attribute value by field name.
     */
    protected function setAttributeByName(Product $product, $fields, string $fieldName, string $value): void
    {
        $field = $fields->firstWhere('name', $fieldName);
        if ($field) {
            ProductAttributeValue::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'product_template_field_id' => $field->id,
                ],
                ['value' => $value]
            );
        }
    }

    /**
     * Generate product title from GIA data.
     */
    protected function generateTitle(array $results, ?array $secondResults, bool $isEarrings): string
    {
        $weight = data_get($results, 'data.weight.weight');
        $shape = $results['shape_and_cutting_style'] ?? null;
        $color = data_get($results, 'data.color_grades.color_grade_code') ?? $results['color_grade'] ?? null;
        $clarity = data_get($results, 'data.clarity') ?? $results['clarity_grade'] ?? null;

        if ($isEarrings && $secondResults) {
            $secondWeight = data_get($secondResults, 'data.weight.weight', 0);
            $totalWeight = ((float) $weight) + ((float) $secondWeight);

            return sprintf(
                '%.2f CTW %s GIA Certified Diamond Studs',
                $totalWeight,
                $shape ?: 'Round'
            );
        }

        $parts = array_filter([
            $weight ? $weight.'ct' : null,
            $shape,
            $color,
            $clarity,
            'GIA Certified Diamond',
        ]);

        return implode(' ', $parts);
    }

    /**
     * Generate SKU for the product.
     */
    protected function generateSku(Category $category, string $reportNumber): string
    {
        $prefix = $category->getEffectiveSkuPrefix() ?? 'GIA';

        return $prefix.'-'.$reportNumber;
    }

    /**
     * Create product images from GIA PDF certificates.
     *
     * @param  array<array>  $reports  Array of GIA report data containing PDF links
     */
    public function createImagesFromPdf(Product $product, Store $store, array $reports): array
    {
        $images = [];
        $sortOrder = $product->publicImages()->count();

        foreach ($reports as $index => $report) {
            $pdfUrl = data_get($report, 'links.pdf');
            if (! $pdfUrl) {
                Log::warning('GIA report missing PDF link', [
                    'report_number' => data_get($report, 'report_number'),
                ]);

                continue;
            }

            try {
                $image = $this->convertPdfToImage($pdfUrl, $product, $store, $sortOrder);
                if ($image) {
                    $images[] = $image;
                    $sortOrder++;
                }
            } catch (\Exception $e) {
                Log::error('Failed to create image from GIA PDF', [
                    'report_number' => data_get($report, 'report_number'),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $images;
    }

    /**
     * Convert a PDF URL to an image and store it.
     */
    protected function convertPdfToImage(string $pdfUrl, Product $product, Store $store, int $sortOrder): ?ProductImage
    {
        // Download PDF to temp file
        $response = Http::timeout(30)->get($pdfUrl);
        if (! $response->successful()) {
            throw new \RuntimeException('Failed to download GIA PDF: '.$response->status());
        }

        $tempPdfPath = sys_get_temp_dir().'/gia_'.Str::random(16).'.pdf';
        file_put_contents($tempPdfPath, $response->body());

        try {
            // Convert PDF to image using Imagick
            $imagick = new Imagick;
            $imagick->setResolution(300, 300);
            $imagick->readImage($tempPdfPath.'[0]'); // Read first page only
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(90);

            // Get the converted image as blob
            $imageBlob = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();

            // Create canvas and center the image
            $manager = new ImageManager(new Driver);
            $canvas = $manager->create($this->canvasWidth, $this->canvasHeight)->fill('#ffffff');
            $giaImage = $manager->read($imageBlob);

            // Scale to fit within canvas while maintaining aspect ratio
            $giaImage->scaleDown($this->canvasWidth, $this->canvasHeight);

            // Center the image on the canvas
            $x = (int) (($this->canvasWidth - $giaImage->width()) / 2);
            $y = (int) (($this->canvasHeight - $giaImage->height()) / 2);
            $canvas->place($giaImage, 'top-left', $x, $y);

            // Generate paths
            $storeSlug = Str::slug($store->name);
            $filename = 'gia-'.Str::random(8).'.jpg';
            $imagePath = "{$storeSlug}/products/{$product->id}/{$filename}";
            $thumbnailPath = "{$storeSlug}/products/{$product->id}/thumbnails/{$filename}";

            // Upload main image
            Storage::disk($this->disk)->put($imagePath, $canvas->toJpeg(90)->toString(), [
                'visibility' => 'public',
                'ACL' => 'public-read',
            ]);

            // Generate and upload thumbnail
            $thumbnail = $canvas->scale(300, 300);
            Storage::disk($this->disk)->put($thumbnailPath, $thumbnail->toJpeg(85)->toString(), [
                'visibility' => 'public',
                'ACL' => 'public-read',
            ]);

            // Get URLs
            $url = $this->getFullUrl($imagePath);
            $thumbnailUrl = $this->getFullUrl($thumbnailPath);

            // Create ProductImage record
            return ProductImage::create([
                'product_id' => $product->id,
                'path' => $imagePath,
                'url' => $url,
                'thumbnail_url' => $thumbnailUrl,
                'alt_text' => 'GIA Certificate',
                'sort_order' => $sortOrder,
                'is_primary' => $sortOrder === 0,
                'is_internal' => false,
            ]);
        } catch (ImagickException $e) {
            throw new \RuntimeException('Imagick error: '.$e->getMessage());
        } finally {
            // Clean up temp file
            if (file_exists($tempPdfPath)) {
                unlink($tempPdfPath);
            }
        }
    }

    /**
     * Get the full URL for a storage path.
     */
    protected function getFullUrl(string $path): string
    {
        if ($this->disk === 'do_spaces') {
            $cdnUrl = rtrim(config('filesystems.disks.do_spaces.url'), '/');

            return "{$cdnUrl}/{$path}";
        }

        return Storage::disk($this->disk)->url($path);
    }
}
