<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PrinterSetting;
use App\Services\NetworkPrintService;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrinterSettingsController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected NetworkPrintService $networkPrintService,
    ) {}

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
                'ip_address' => $setting->ip_address,
                'port' => $setting->port,
                'top_offset' => $setting->top_offset,
                'left_offset' => $setting->left_offset,
                'right_offset' => $setting->right_offset,
                'text_size' => $setting->text_size,
                'barcode_height' => $setting->barcode_height,
                'line_height' => $setting->line_height,
                'label_width' => $setting->label_width,
                'label_height' => $setting->label_height,
                'is_default' => $setting->is_default,
                'network_print_enabled' => $setting->isNetworkPrintingEnabled(),
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

        // Convert empty ip_address to null
        if ($request->input('ip_address') === '') {
            $request->merge(['ip_address' => null]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:printer_settings,name,NULL,id,store_id,'.$store->id,
            'printer_type' => 'required|string|in:zebra,godex,other',
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer|min:1|max:65535',
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
            'port' => $validated['port'] ?? 9100,
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

        // Convert empty ip_address to null
        if ($request->input('ip_address') === '') {
            $request->merge(['ip_address' => null]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:printer_settings,name,'.$printerSetting->id.',id,store_id,'.$store->id,
            'printer_type' => 'required|string|in:zebra,godex,other',
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer|min:1|max:65535',
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

        $printerSetting->update([
            ...$validated,
            'port' => $validated['port'] ?? 9100,
        ]);

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

    /**
     * Send ZPL to printer via network (for iPad/mobile devices).
     */
    public function networkPrint(Request $request, PrinterSetting $printerSetting): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $printerSetting->store_id !== $store->id) {
            return response()->json(['error' => 'Printer not found'], 404);
        }

        if (! $printerSetting->isNetworkPrintingEnabled()) {
            return response()->json([
                'error' => 'Network printing is not configured for this printer. Please add an IP address in printer settings.',
            ], 400);
        }

        $validated = $request->validate([
            'zpl' => 'required|string',
        ]);

        try {
            $this->networkPrintService->print($printerSetting, $validated['zpl']);

            return response()->json(['success' => true, 'message' => 'Print job sent successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Test network printer connection.
     */
    public function testConnection(PrinterSetting $printerSetting): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $printerSetting->store_id !== $store->id) {
            return response()->json(['error' => 'Printer not found'], 404);
        }

        if (! $printerSetting->isNetworkPrintingEnabled()) {
            return response()->json([
                'error' => 'Network printing is not configured for this printer.',
            ], 400);
        }

        $reachable = $this->networkPrintService->isPrinterReachable(
            $printerSetting->ip_address,
            $printerSetting->port
        );

        if ($reachable) {
            return response()->json([
                'success' => true,
                'message' => 'Printer is reachable at '.$printerSetting->ip_address.':'.$printerSetting->port,
            ]);
        }

        return response()->json([
            'error' => 'Could not connect to printer at '.$printerSetting->ip_address.':'.$printerSetting->port,
        ], 400);
    }

    /**
     * Print a test label to verify network configuration.
     */
    public function testPrint(PrinterSetting $printerSetting): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $printerSetting->store_id !== $store->id) {
            return response()->json(['error' => 'Printer not found'], 404);
        }

        if (! $printerSetting->isNetworkPrintingEnabled()) {
            return response()->json([
                'error' => 'Network printing is not configured for this printer.',
            ], 400);
        }

        // Generate a test label ZPL
        $testZpl = "^XA\n";
        $testZpl .= "^PW{$printerSetting->label_width}\n";
        $testZpl .= "^LL{$printerSetting->label_height}\n";
        $testZpl .= "^FO{$printerSetting->left_offset},{$printerSetting->top_offset}";
        $testZpl .= '^FB'.($printerSetting->label_width - $printerSetting->left_offset).',1,0,C,0';
        $testZpl .= "^A0N,{$printerSetting->text_size},{$printerSetting->text_size}";
        $testZpl .= "^FDTEST PRINT^FS\n";
        $testZpl .= '^FO'.floor($printerSetting->label_width / 4).','.($printerSetting->top_offset + 30);
        $testZpl .= "^BY2,2,{$printerSetting->barcode_height}^BCN,,Y,N,N^FD123456789^FS\n";
        $testZpl .= "^XZ\n";

        try {
            $this->networkPrintService->print($printerSetting, $testZpl);

            return response()->json(['success' => true, 'message' => 'Test label sent to printer']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
