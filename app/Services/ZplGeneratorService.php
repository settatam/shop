<?php

namespace App\Services;

use App\Models\LabelTemplate;
use App\Models\LabelTemplateElement;

class ZplGeneratorService
{
    /**
     * Generate ZPL for a single label.
     */
    public function generate(LabelTemplate $template, array $data): string
    {
        $zpl = $this->startLabel($template);

        foreach ($template->elements as $element) {
            $zpl .= $this->renderElement($element, $data);
        }

        $zpl .= $this->endLabel();

        return $zpl;
    }

    /**
     * Generate ZPL for multiple labels (batch print).
     *
     * @param  array<array>  $items
     */
    public function generateBatch(LabelTemplate $template, array $items): string
    {
        $zpl = '';

        foreach ($items as $item) {
            $zpl .= $this->generate($template, $item);
        }

        return $zpl;
    }

    /**
     * Start a new label.
     */
    protected function startLabel(LabelTemplate $template): string
    {
        return "^XA\n^LH0,0\n^LS0\n^PW{$template->canvas_width}\n^LL{$template->canvas_height}\n";
    }

    /**
     * End the current label.
     */
    protected function endLabel(): string
    {
        return "^XZ\n";
    }

    /**
     * Render a single element to ZPL.
     */
    protected function renderElement(LabelTemplateElement $element, array $data): string
    {
        return match ($element->element_type) {
            LabelTemplateElement::TYPE_TEXT_FIELD => $this->renderTextField($element, $data),
            LabelTemplateElement::TYPE_BARCODE => $this->renderBarcode($element, $data),
            LabelTemplateElement::TYPE_STATIC_TEXT => $this->renderStaticText($element),
            LabelTemplateElement::TYPE_LINE => $this->renderLine($element),
            default => '',
        };
    }

    /**
     * Render a dynamic text field.
     * Uses ^FB (Field Block) for text wrapping and alignment.
     */
    protected function renderTextField(LabelTemplateElement $element, array $data): string
    {
        $value = $this->getFieldValue($element->content, $data);

        if ($value === null || $value === '') {
            return '';
        }

        $styles = $element->styles ?? [];
        $fontSize = $styles['fontSize'] ?? 20;
        $alignment = $this->mapAlignment($styles['alignment'] ?? 'left');
        $maxChars = $styles['maxChars'] ?? null;

        if ($maxChars && strlen($value) > $maxChars) {
            $value = substr($value, 0, $maxChars);
        }

        // Escape special ZPL characters
        $value = $this->escapeZplText($value);

        $rotation = $this->mapRotation($styles['rotation'] ?? 0);

        // ^FO = Field Origin (x,y)
        // ^FB = Field Block (width, max lines, line spacing, alignment, hanging indent)
        // ^A0 = Scalable font with orientation
        // ^FD = Field Data
        // ^FS = Field Separator
        $fbWidth = in_array($rotation, ['R', 'I']) ? $element->height : $element->width;

        return "^FO{$element->x},{$element->y}^FB{$fbWidth},1,0,{$alignment},0^A0{$rotation},{$fontSize},{$fontSize}^FD{$value}^FS\n";
    }

    /**
     * Render a CODE128 barcode.
     */
    protected function renderBarcode(LabelTemplateElement $element, array $data): string
    {
        $value = $this->getFieldValue($element->content, $data);

        if ($value === null || $value === '') {
            return '';
        }

        $styles = $element->styles ?? [];
        $barcodeHeight = $styles['barcodeHeight'] ?? 50;
        $showText = ($styles['showText'] ?? true) ? 'Y' : 'N';
        $moduleWidth = $styles['moduleWidth'] ?? 2;
        $alignment = $styles['alignment'] ?? 'left';
        $rotation = $this->mapRotation($styles['rotation'] ?? 0);

        // Estimate Code 128 barcode width in dots:
        // Each data char = 11 modules, start = 11, check = 11, stop = 13
        $charCount = strlen($value);
        $estimatedWidth = (($charCount + 2) * 11 + 13) * $moduleWidth;

        // Calculate offset based on alignment within the element's bounding box
        // For rotated barcodes the "length" axis changes
        $x = $element->x;
        $y = $element->y;
        $isVertical = in_array($rotation, ['R', 'I']);

        if ($isVertical) {
            // Rotated: barcode length runs along Y axis, bar height along X
            $availableSpace = $element->height;
            if ($alignment === 'center' && $estimatedWidth < $availableSpace) {
                $y = $element->y + (int) (($availableSpace - $estimatedWidth) / 2);
            } elseif ($alignment === 'right' && $estimatedWidth < $availableSpace) {
                $y = $element->y + $availableSpace - $estimatedWidth;
            }
        } else {
            // Normal: barcode length runs along X axis
            if ($alignment === 'center' && $estimatedWidth < $element->width) {
                $x = $element->x + (int) (($element->width - $estimatedWidth) / 2);
            } elseif ($alignment === 'right' && $estimatedWidth < $element->width) {
                $x = $element->x + $element->width - $estimatedWidth;
            }
        }

        // ^FO = Field Origin (x,y)
        // ^BY = Bar Code Field Default (module width, wide/narrow ratio, bar height)
        // ^BC = Code 128 Barcode (orientation, height, print interpretation line, print above, UCC check digit)
        // ^FD = Field Data
        // ^FS = Field Separator
        return "^FO{$x},{$y}^BY{$moduleWidth},2,{$barcodeHeight}^BC{$rotation},,{$showText},N,N^FD{$value}^FS\n";
    }

    /**
     * Render static text.
     */
    protected function renderStaticText(LabelTemplateElement $element): string
    {
        $value = $element->content;

        if ($value === null || $value === '') {
            return '';
        }

        $styles = $element->styles ?? [];
        $fontSize = $styles['fontSize'] ?? 20;
        $alignment = $this->mapAlignment($styles['alignment'] ?? 'left');

        $value = $this->escapeZplText($value);

        $rotation = $this->mapRotation($styles['rotation'] ?? 0);
        $fbWidth = in_array($rotation, ['R', 'I']) ? $element->height : $element->width;

        return "^FO{$element->x},{$element->y}^FB{$fbWidth},1,0,{$alignment},0^A0{$rotation},{$fontSize},{$fontSize}^FD{$value}^FS\n";
    }

    /**
     * Render a horizontal line.
     */
    protected function renderLine(LabelTemplateElement $element): string
    {
        $styles = $element->styles ?? [];
        $thickness = $styles['thickness'] ?? 2;

        // ^FO = Field Origin (x,y)
        // ^GB = Graphic Box (width, height, thickness, color, rounding)
        // ^FS = Field Separator
        return "^FO{$element->x},{$element->y}^GB{$element->width},{$thickness},{$thickness}^FS\n";
    }

    /**
     * Get the value of a field from the data array.
     * Supports dot notation like 'product.title' or 'variant.sku'.
     */
    protected function getFieldValue(?string $fieldKey, array $data): ?string
    {
        if ($fieldKey === null) {
            return null;
        }

        $parts = explode('.', $fieldKey);

        if (count($parts) !== 2) {
            return null;
        }

        [$group, $field] = $parts;

        return $data[$group][$field] ?? null;
    }

    /**
     * Map alignment string to ZPL alignment code.
     */
    protected function mapAlignment(string $alignment): string
    {
        return match ($alignment) {
            'center' => 'C',
            'right' => 'R',
            default => 'L',
        };
    }

    /**
     * Map rotation degrees to ZPL orientation letter.
     */
    protected function mapRotation(int $rotation): string
    {
        return match ($rotation) {
            90 => 'R',
            180 => 'B',
            270 => 'I',
            default => 'N',
        };
    }

    /**
     * Escape special characters for ZPL.
     */
    protected function escapeZplText(string $text): string
    {
        // Replace special ZPL characters with their escape sequences
        // ^ = _5E, ~ = _7E, \ = _5C
        $text = str_replace('\\', '_5C', $text);
        $text = str_replace('^', '_5E', $text);
        $text = str_replace('~', '_7E', $text);

        return $text;
    }
}
