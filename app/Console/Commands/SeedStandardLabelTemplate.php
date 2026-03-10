<?php

namespace App\Console\Commands;

use App\Models\LabelTemplate;
use App\Models\LabelTemplateElement;
use App\Models\Store;
use Illuminate\Console\Command;

class SeedStandardLabelTemplate extends Command
{
    protected $signature = 'labels:seed-standard {store_id}';

    protected $description = 'Create a Standard label template that mirrors the default hardcoded barcode layout';

    public function handle(): int
    {
        $store = Store::find($this->argument('store_id'));

        if (! $store) {
            $this->error('Store not found.');

            return self::FAILURE;
        }

        // Check if one already exists
        $existing = LabelTemplate::where('store_id', $store->id)
            ->where('type', LabelTemplate::TYPE_PRODUCT)
            ->where('name', 'Test 2')
            ->first();

        if ($existing) {
            $this->warn('A "Standard" product label template already exists for this store.');

            return self::FAILURE;
        }

        // Canvas matches default printer settings: 406 dots wide x 203 dots tall
        // (2" x 1" at 203 DPI)
        $template = LabelTemplate::create([
            'store_id' => $store->id,
            'name' => 'Test 2',
            'type' => LabelTemplate::TYPE_PRODUCT,
            'canvas_width' => 406,
            'canvas_height' => 203,
            'is_default' => false,
        ]);

        // Element 1: Barcode value as text at top (centered)
        // Matches: ^FO0,30^FB406,1,0,C,0^A0N,20,20^FD{barcode}^FS
        LabelTemplateElement::create([
            'label_template_id' => $template->id,
            'element_type' => LabelTemplateElement::TYPE_TEXT_FIELD,
            'x' => 0,
            'y' => 30,
            'width' => 406,
            'height' => 25,
            'content' => 'variant.barcode',
            'styles' => [
                'fontSize' => 20,
                'alignment' => 'center',
            ],
            'sort_order' => 0,
        ]);

        // Element 2: Barcode (Code 128, centered)
        // Matches: ^FO53,55^BY2,2,50^BCN,,N,N,N^FD{barcode}^FS
        LabelTemplateElement::create([
            'label_template_id' => $template->id,
            'element_type' => LabelTemplateElement::TYPE_BARCODE,
            'x' => 53,
            'y' => 55,
            'width' => 350,
            'height' => 70,
            'content' => 'variant.barcode',
            'styles' => [
                'barcodeHeight' => 50,
                'moduleWidth' => 2,
                'showText' => false,
            ],
            'sort_order' => 1,
        ]);

        // Element 3: Attribute line at bottom (centered)
        // Uses product.attribute_line which auto-resolves from category barcode_attributes
        // or uses barcode_label_text override if set
        // Matches: ^FO0,130^FB406,1,0,C,0^A0N,18,18^FD{attributeLine}^FS
        LabelTemplateElement::create([
            'label_template_id' => $template->id,
            'element_type' => LabelTemplateElement::TYPE_TEXT_FIELD,
            'x' => 0,
            'y' => 130,
            'width' => 406,
            'height' => 25,
            'content' => 'product.attribute_line',
            'styles' => [
                'fontSize' => 18,
                'alignment' => 'center',
                'maxChars' => 50,
            ],
            'sort_order' => 2,
        ]);

        $this->info("Standard label template created (ID: {$template->id}) for store \"{$store->name}\".");
        $this->info('Elements: barcode text (top) → barcode (center) → attribute line (bottom)');

        return self::SUCCESS;
    }
}
