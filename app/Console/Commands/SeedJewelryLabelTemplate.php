<?php

namespace App\Console\Commands;

use App\Models\LabelTemplate;
use App\Models\LabelTemplateElement;
use App\Models\Store;
use Illuminate\Console\Command;

class SeedJewelryLabelTemplate extends Command
{
    protected $signature = 'labels:seed-jewelry {store_id}';

    protected $description = 'Create a Jewelry label template for TT306 rat-tail tags (1¾" x 7/16" at 300 DPI)';

    public function handle(): int
    {
        $store = Store::find($this->argument('store_id'));

        if (! $store) {
            $this->error('Store not found.');

            return self::FAILURE;
        }

        $existing = LabelTemplate::where('store_id', $store->id)
            ->where('type', LabelTemplate::TYPE_PRODUCT)
            ->where('name', 'Jewelry Tag')
            ->first();

        if ($existing) {
            $this->warn('A "Jewelry Tag" product label template already exists for this store.');

            return self::FAILURE;
        }

        // Canvas: 1¾" x 7/16" at 300 DPI = 525 x 131 dots
        $canvasW = 525;
        $canvasH = 131;

        $template = LabelTemplate::create([
            'store_id' => $store->id,
            'name' => 'Jewelry Tag',
            'type' => LabelTemplate::TYPE_PRODUCT,
            'canvas_width' => $canvasW,
            'canvas_height' => $canvasH,
            'is_default' => false,
        ]);

        // Layout: barcode left half, text right half
        $half = (int) ($canvasW / 2); // 262
        $rightX = $half;
        $rightW = $canvasW - $half; // 263

        // Element 1: Barcode — left half, full height
        LabelTemplateElement::create([
            'label_template_id' => $template->id,
            'element_type' => LabelTemplateElement::TYPE_BARCODE,
            'x' => 0,
            'y' => 0,
            'width' => $half,
            'height' => $canvasH,
            'content' => 'variant.barcode',
            'styles' => [
                'barcodeHeight' => 95,
                'moduleWidth' => 1,
                'showText' => true,
                'alignment' => 'left',
                'rotation' => 0,
            ],
            'sort_order' => 0,
        ]);

        // Right half: 5 stacked text lines, evenly spaced across 131 dots
        $lineHeight = (int) ($canvasH / 5); // 26
        $fontSize = 16;

        // Element 2: Attribute 1 (e.g., price_code)
        LabelTemplateElement::create([
            'label_template_id' => $template->id,
            'element_type' => LabelTemplateElement::TYPE_TEXT_FIELD,
            'x' => $rightX,
            'y' => 0,
            'width' => $rightW,
            'height' => $lineHeight,
            'content' => 'product.attribute_1',
            'styles' => ['fontSize' => $fontSize, 'alignment' => 'left'],
            'sort_order' => 1,
        ]);

        // Element 3: Attribute 2 (e.g., precious_metals)
        LabelTemplateElement::create([
            'label_template_id' => $template->id,
            'element_type' => LabelTemplateElement::TYPE_TEXT_FIELD,
            'x' => $rightX,
            'y' => $lineHeight,
            'width' => $rightW,
            'height' => $lineHeight,
            'content' => 'product.attribute_2',
            'styles' => ['fontSize' => $fontSize, 'alignment' => 'left'],
            'sort_order' => 2,
        ]);

        // Element 4: Attribute 3 (e.g., dwt)
        LabelTemplateElement::create([
            'label_template_id' => $template->id,
            'element_type' => LabelTemplateElement::TYPE_TEXT_FIELD,
            'x' => $rightX,
            'y' => $lineHeight * 2,
            'width' => $rightW,
            'height' => $lineHeight,
            'content' => 'product.attribute_3',
            'styles' => ['fontSize' => $fontSize, 'alignment' => 'left'],
            'sort_order' => 3,
        ]);

        // Element 5: Attribute 4 (e.g., total_stone_weight)
        LabelTemplateElement::create([
            'label_template_id' => $template->id,
            'element_type' => LabelTemplateElement::TYPE_TEXT_FIELD,
            'x' => $rightX,
            'y' => $lineHeight * 3,
            'width' => $rightW,
            'height' => $lineHeight,
            'content' => 'product.attribute_4',
            'styles' => ['fontSize' => $fontSize, 'alignment' => 'left'],
            'sort_order' => 4,
        ]);

        // Element 6: Price at bottom right
        LabelTemplateElement::create([
            'label_template_id' => $template->id,
            'element_type' => LabelTemplateElement::TYPE_TEXT_FIELD,
            'x' => $rightX,
            'y' => $lineHeight * 4,
            'width' => $rightW,
            'height' => $lineHeight,
            'content' => 'variant.price',
            'styles' => ['fontSize' => $fontSize, 'alignment' => 'left'],
            'sort_order' => 5,
        ]);

        $this->info("Jewelry Tag label template created (ID: {$template->id}) for store \"{$store->name}\".");
        $this->info('Layout: barcode (left) | attributes stacked vertically (right)');
        $this->info("Canvas: {$canvasW} x {$canvasH} dots (1¾\" x 7/16\" at 300 DPI)");

        return self::SUCCESS;
    }
}
