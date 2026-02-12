<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanGiaCardRequest;
use App\Models\Category;
use App\Models\Certification;
use App\Models\Gemstone;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\GiaCardScannerService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GiaCardScannerController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected GiaCardScannerService $scannerService,
    ) {}

    /**
     * Scan a GIA card image and extract data.
     */
    public function scan(ScanGiaCardRequest $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 400);
        }

        // Scan the image with Textract
        $extractedData = $this->scannerService->scanImage($request->file('image'));

        // Store the scanned image
        $imagePath = $this->scannerService->storeScannedImage(
            $request->file('image'),
            $store->id
        );

        // Remove raw_data from response to reduce payload size
        $responseData = $extractedData;
        unset($responseData['raw_data']);

        // Check if certificate already exists
        $existingCert = null;
        if (! empty($extractedData['certificate_number'])) {
            $existingCert = Certification::where('store_id', $store->id)
                ->where('certificate_number', $extractedData['certificate_number'])
                ->first();
        }

        return response()->json([
            'extracted_data' => $responseData,
            'image_path' => $imagePath,
            'existing_certification' => $existingCert,
            'duplicate_warning' => $existingCert !== null,
        ]);
    }

    /**
     * Create a new product from scanned GIA data.
     */
    public function createProduct(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 400);
        }

        $validated = $request->validate([
            'certification_data' => ['required', 'array'],
            'certification_data.certificate_number' => ['required', 'string'],
            'certification_data.issue_date' => ['nullable', 'string'],
            'certification_data.shape' => ['nullable', 'string'],
            'certification_data.carat_weight' => ['nullable', 'numeric'],
            'certification_data.color_grade' => ['nullable', 'string'],
            'certification_data.clarity_grade' => ['nullable', 'string'],
            'certification_data.cut_grade' => ['nullable', 'string'],
            'certification_data.polish' => ['nullable', 'string'],
            'certification_data.symmetry' => ['nullable', 'string'],
            'certification_data.fluorescence' => ['nullable', 'string'],
            'certification_data.measurements' => ['nullable', 'array'],
            'certification_data.inscription' => ['nullable', 'string'],
            'certification_data.comments' => ['nullable', 'string'],
            'image_path' => ['nullable', 'string'],
            'product' => ['required', 'array'],
            'product.title' => ['required', 'string', 'max:255'],
            'product.description' => ['nullable', 'string'],
            'product.category_id' => ['nullable', 'exists:categories,id'],
            'product.brand_id' => ['nullable', 'exists:brands,id'],
            'variant' => ['required', 'array'],
            'variant.sku' => ['required', 'string', 'max:255'],
            'variant.price' => ['required', 'numeric', 'min:0'],
            'variant.cost' => ['nullable', 'numeric', 'min:0'],
            'variant.quantity' => ['required', 'integer', 'min:0'],
            'variant.warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['nullable', 'string'],
        ]);

        $result = DB::transaction(function () use ($validated, $store) {
            // Create certification
            $certData = $validated['certification_data'];
            $certification = Certification::create([
                'store_id' => $store->id,
                'lab' => 'GIA',
                'certificate_number' => $certData['certificate_number'],
                'issue_date' => $certData['issue_date'] ?? null,
                'shape' => $certData['shape'] ?? null,
                'carat_weight' => $certData['carat_weight'] ?? null,
                'color_grade' => $certData['color_grade'] ?? null,
                'clarity_grade' => $certData['clarity_grade'] ?? null,
                'cut_grade' => $certData['cut_grade'] ?? null,
                'polish' => $certData['polish'] ?? null,
                'symmetry' => $certData['symmetry'] ?? null,
                'fluorescence' => $certData['fluorescence'] ?? null,
                'measurements' => $certData['measurements'] ?? null,
                'inscription' => $certData['inscription'] ?? null,
                'comments' => $certData['comments'] ?? null,
                'scan_image_path' => $validated['image_path'] ?? null,
            ]);

            // Generate unique handle
            $baseHandle = Str::slug($validated['product']['title']);
            $handle = $baseHandle;
            $counter = 1;
            while (Product::where('store_id', $store->id)->where('handle', $handle)->exists()) {
                $handle = $baseHandle.'-'.$counter;
                $counter++;
            }

            // Create product
            $product = Product::create([
                'store_id' => $store->id,
                'title' => $validated['product']['title'],
                'description' => $validated['product']['description'] ?? null,
                'handle' => $handle,
                'category_id' => $validated['product']['category_id'] ?? null,
                'brand_id' => $validated['product']['brand_id'] ?? null,
                'status' => Product::STATUS_ACTIVE,
                'is_published' => false,
                'is_draft' => false,
                'has_variants' => false,
                'track_quantity' => true,
            ]);

            // Create variant
            $variant = $product->variants()->create([
                'sku' => $validated['variant']['sku'],
                'price' => $validated['variant']['price'],
                'cost' => $validated['variant']['cost'] ?? null,
                'quantity' => $validated['variant']['quantity'],
                'is_active' => true,
            ]);

            // Create gemstone linked to certification
            Gemstone::create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'type' => 'diamond',
                'shape' => $certification->shape,
                'carat_weight' => $certification->carat_weight,
                'color_grade' => $certification->color_grade,
                'clarity_grade' => $certification->clarity_grade,
                'cut_grade' => $certification->cut_grade,
                'fluorescence' => $certification->fluorescence,
                'certification_id' => $certification->id,
                'length_mm' => $certification->measurements['length'] ?? null,
                'width_mm' => $certification->measurements['width'] ?? null,
                'depth_mm' => $certification->measurements['depth'] ?? null,
            ]);

            // Create inventory if warehouse specified and quantity > 0
            if (! empty($validated['variant']['warehouse_id']) && $validated['variant']['quantity'] > 0) {
                Inventory::create([
                    'store_id' => $store->id,
                    'product_variant_id' => $variant->id,
                    'warehouse_id' => $validated['variant']['warehouse_id'],
                    'quantity' => $validated['variant']['quantity'],
                    'unit_cost' => $validated['variant']['cost'] ?? null,
                ]);
            }

            // Save template attribute values if provided
            if (! empty($validated['attributes'])) {
                foreach ($validated['attributes'] as $fieldId => $value) {
                    if ($value !== null && $value !== '') {
                        $product->setTemplateAttributeValue((int) $fieldId, $value);
                    }
                }
            }

            return [
                'product' => $product->load(['variants', 'images']),
                'certification' => $certification,
            ];
        });

        return response()->json([
            'success' => true,
            'product' => $result['product'],
            'certification' => $result['certification'],
            'redirect_url' => route('products.show', $result['product']),
        ]);
    }

    /**
     * Add scanned GIA data to an existing product.
     */
    public function addToProduct(Request $request, Product $product): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json(['error' => 'No store selected'], 400);
        }

        if ($product->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'certification_data' => ['required', 'array'],
            'certification_data.certificate_number' => ['required', 'string'],
            'certification_data.issue_date' => ['nullable', 'string'],
            'certification_data.shape' => ['nullable', 'string'],
            'certification_data.carat_weight' => ['nullable', 'numeric'],
            'certification_data.color_grade' => ['nullable', 'string'],
            'certification_data.clarity_grade' => ['nullable', 'string'],
            'certification_data.cut_grade' => ['nullable', 'string'],
            'certification_data.polish' => ['nullable', 'string'],
            'certification_data.symmetry' => ['nullable', 'string'],
            'certification_data.fluorescence' => ['nullable', 'string'],
            'certification_data.measurements' => ['nullable', 'array'],
            'certification_data.inscription' => ['nullable', 'string'],
            'certification_data.comments' => ['nullable', 'string'],
            'image_path' => ['nullable', 'string'],
        ]);

        $result = DB::transaction(function () use ($validated, $store, $product) {
            // Create certification
            $certData = $validated['certification_data'];
            $certification = Certification::create([
                'store_id' => $store->id,
                'lab' => 'GIA',
                'certificate_number' => $certData['certificate_number'],
                'issue_date' => $certData['issue_date'] ?? null,
                'shape' => $certData['shape'] ?? null,
                'carat_weight' => $certData['carat_weight'] ?? null,
                'color_grade' => $certData['color_grade'] ?? null,
                'clarity_grade' => $certData['clarity_grade'] ?? null,
                'cut_grade' => $certData['cut_grade'] ?? null,
                'polish' => $certData['polish'] ?? null,
                'symmetry' => $certData['symmetry'] ?? null,
                'fluorescence' => $certData['fluorescence'] ?? null,
                'measurements' => $certData['measurements'] ?? null,
                'inscription' => $certData['inscription'] ?? null,
                'comments' => $certData['comments'] ?? null,
                'scan_image_path' => $validated['image_path'] ?? null,
            ]);

            // Create gemstone linked to product and certification
            Gemstone::create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'type' => 'diamond',
                'shape' => $certification->shape,
                'carat_weight' => $certification->carat_weight,
                'color_grade' => $certification->color_grade,
                'clarity_grade' => $certification->clarity_grade,
                'cut_grade' => $certification->cut_grade,
                'fluorescence' => $certification->fluorescence,
                'certification_id' => $certification->id,
                'length_mm' => $certification->measurements['length'] ?? null,
                'width_mm' => $certification->measurements['width'] ?? null,
                'depth_mm' => $certification->measurements['depth'] ?? null,
            ]);

            return $certification;
        });

        return response()->json([
            'success' => true,
            'certification' => $result,
        ]);
    }

    /**
     * Search for existing products to add certification to.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json([]);
        }

        $search = $request->get('q', '');

        $products = Product::where('store_id', $store->id)
            ->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhereHas('variants', fn ($q) => $q->where('sku', 'like', "%{$search}%"));
            })
            ->with(['primaryImage', 'variants:id,product_id,sku,price'])
            ->limit(10)
            ->get(['id', 'title']);

        return response()->json($products);
    }

    /**
     * Get template fields for a category with GIA field mapping.
     */
    public function getCategoryTemplateFields(Category $category): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $category->store_id !== $store->id) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        // Get the effective template for this category (may inherit from parent)
        $template = $category->getEffectiveTemplate();

        if (! $template) {
            return response()->json([
                'template' => null,
                'fields' => [],
                'gia_mapping' => GiaCardScannerService::getGiaToCanonicalMapping(),
            ]);
        }

        // Load template fields with options
        $template->load('fields.options');

        $fields = $template->fields->map(function ($field) {
            return [
                'id' => $field->id,
                'name' => $field->name,
                'canonical_name' => $field->canonical_name,
                'label' => $field->label,
                'type' => $field->type,
                'placeholder' => $field->placeholder,
                'help_text' => $field->help_text,
                'default_value' => $field->default_value,
                'is_required' => $field->is_required,
                'width_class' => $field->width_class,
                'group_name' => $field->group_name,
                'group_position' => $field->group_position,
                'options' => $field->options->map(fn ($opt) => [
                    'label' => $opt->label,
                    'value' => $opt->value,
                ])->toArray(),
            ];
        })->toArray();

        return response()->json([
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
            ],
            'fields' => $fields,
            'gia_mapping' => GiaCardScannerService::getGiaToCanonicalMapping(),
        ]);
    }
}
