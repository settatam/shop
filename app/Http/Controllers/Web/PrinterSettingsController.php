<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PrinterSetting;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrinterSettingsController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $printerSettings = PrinterSetting::where('store_id', $store->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn ($setting) => [
                'id' => $setting->id,
                'name' => $setting->name,
                'printer_type' => $setting->printer_type,
                'top_offset' => $setting->top_offset,
                'left_offset' => $setting->left_offset,
                'right_offset' => $setting->right_offset,
                'text_size' => $setting->text_size,
                'barcode_height' => $setting->barcode_height,
                'line_height' => $setting->line_height,
                'label_width' => $setting->label_width,
                'label_height' => $setting->label_height,
                'is_default' => $setting->is_default,
            ]);

        return Inertia::render('settings/PrinterSettings', [
            'printerSettings' => $printerSettings,
            'printerTypes' => PrinterSetting::getTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:printer_settings,name,NULL,id,store_id,'.$store->id,
            'printer_type' => 'required|string|in:zebra,godex,other',
            'top_offset' => 'required|integer|min:0|max:500',
            'left_offset' => 'required|integer|min:0|max:500',
            'right_offset' => 'required|integer|min:0|max:500',
            'text_size' => 'required|integer|min:10|max:100',
            'barcode_height' => 'required|integer|min:20|max:200',
            'line_height' => 'required|integer|min:10|max:100',
            'label_width' => 'required|integer|min:100|max:1000',
            'label_height' => 'required|integer|min:50|max:1000',
            'is_default' => 'boolean',
        ]);

        $setting = PrinterSetting::create([
            ...$validated,
            'store_id' => $store->id,
        ]);

        // If this is set as default, unset other defaults
        if ($request->boolean('is_default')) {
            PrinterSetting::where('store_id', $store->id)
                ->where('id', '!=', $setting->id)
                ->update(['is_default' => false]);
        }

        // If this is the first printer setting, make it default
        if (PrinterSetting::where('store_id', $store->id)->count() === 1) {
            $setting->update(['is_default' => true]);
        }

        return redirect()->route('settings.printers.index')
            ->with('success', 'Printer setting created successfully.');
    }

    public function update(Request $request, PrinterSetting $printerSetting): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $printerSetting->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:printer_settings,name,'.$printerSetting->id.',id,store_id,'.$store->id,
            'printer_type' => 'required|string|in:zebra,godex,other',
            'top_offset' => 'required|integer|min:0|max:500',
            'left_offset' => 'required|integer|min:0|max:500',
            'right_offset' => 'required|integer|min:0|max:500',
            'text_size' => 'required|integer|min:10|max:100',
            'barcode_height' => 'required|integer|min:20|max:200',
            'line_height' => 'required|integer|min:10|max:100',
            'label_width' => 'required|integer|min:100|max:1000',
            'label_height' => 'required|integer|min:50|max:1000',
            'is_default' => 'boolean',
        ]);

        $printerSetting->update($validated);

        // If this is set as default, unset other defaults
        if ($request->boolean('is_default')) {
            PrinterSetting::where('store_id', $store->id)
                ->where('id', '!=', $printerSetting->id)
                ->update(['is_default' => false]);
        }

        return redirect()->route('settings.printers.index')
            ->with('success', 'Printer setting updated successfully.');
    }

    public function destroy(PrinterSetting $printerSetting): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $printerSetting->store_id !== $store->id) {
            abort(404);
        }

        $wasDefault = $printerSetting->is_default;
        $printerSetting->delete();

        // If deleted was default, make another one default
        if ($wasDefault) {
            $newDefault = PrinterSetting::where('store_id', $store->id)->first();
            if ($newDefault) {
                $newDefault->update(['is_default' => true]);
            }
        }

        return redirect()->route('settings.printers.index')
            ->with('success', 'Printer setting deleted successfully.');
    }

    public function makeDefault(PrinterSetting $printerSetting): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $printerSetting->store_id !== $store->id) {
            abort(404);
        }

        // Unset other defaults
        PrinterSetting::where('store_id', $store->id)
            ->where('id', '!=', $printerSetting->id)
            ->update(['is_default' => false]);

        $printerSetting->update(['is_default' => true]);

        return redirect()->route('settings.printers.index')
            ->with('success', $printerSetting->name.' is now the default printer setting.');
    }
}
