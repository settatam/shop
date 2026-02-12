<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\FeatureManager;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class StoreSettingsController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
        protected FeatureManager $featureManager,
    ) {}

    public function edit(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('settings/Store', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'logo' => $store->logo,
                'logo_url' => $store->logo ? Storage::disk('public')->url($store->logo) : null,
                'business_name' => $store->business_name,
                'account_email' => $store->account_email,
                'customer_email' => $store->customer_email,
                'phone' => $store->phone,
                'address' => $store->address,
                'address2' => $store->address2,
                'city' => $store->city,
                'state' => $store->state,
                'zip' => $store->zip,
                'store_domain' => $store->store_domain,
                'order_id_prefix' => $store->order_id_prefix,
                'order_id_suffix' => $store->order_id_suffix,
                'buy_id_prefix' => $store->buy_id_prefix,
                'buy_id_suffix' => $store->buy_id_suffix,
                'repair_id_prefix' => $store->repair_id_prefix,
                'repair_id_suffix' => $store->repair_id_suffix,
                'memo_id_prefix' => $store->memo_id_prefix,
                'memo_id_suffix' => $store->memo_id_suffix,
                'currency' => $store->currency_id ? $this->getCurrencyCode($store->currency_id) : 'USD',
                'timezone' => $store->timezone_id ? $this->getTimezoneValue($store->timezone_id) : 'America/New_York',
                'meta_title' => $store->meta_title,
                'meta_description' => $store->meta_description,
                'default_tax_rate' => $store->default_tax_rate ? (float) $store->default_tax_rate * 100 : null,
                'tax_id_number' => $store->tax_id_number,
                'edition' => $store->edition ?? config('editions.default', 'standard'),
                'metal_price_settings' => $store->getMetalPriceSettingsWithDefaults(),
            ],
            'currencies' => $this->getCurrencies(),
            'timezones' => $this->getTimezones(),
            'availableEditions' => $this->getAvailableEditionsForSelect(),
            'metalTypes' => $this->getMetalTypesForSettings(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'account_email' => ['nullable', 'email', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'zip' => ['nullable', 'string', 'max:20'],
            'store_domain' => ['nullable', 'string', 'max:255'],
            'order_id_prefix' => ['nullable', 'string', 'max:20'],
            'order_id_suffix' => ['nullable', 'string', 'max:20'],
            'buy_id_prefix' => ['nullable', 'string', 'max:20'],
            'buy_id_suffix' => ['nullable', 'string', 'max:20'],
            'repair_id_prefix' => ['nullable', 'string', 'max:20'],
            'repair_id_suffix' => ['nullable', 'string', 'max:20'],
            'memo_id_prefix' => ['nullable', 'string', 'max:20'],
            'memo_id_suffix' => ['nullable', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_id_number' => ['nullable', 'string', 'max:50'],
            'edition' => ['nullable', 'string', 'in:'.implode(',', array_keys($this->featureManager->getAvailableEditions()))],
            'metal_price_settings' => ['nullable', 'array'],
            'metal_price_settings.dwt_multipliers' => ['nullable', 'array'],
            'metal_price_settings.dwt_multipliers.*' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        // Map currency and timezone back to IDs (or store directly if no lookup tables exist)
        $updateData = [
            'name' => $validated['name'],
            'business_name' => $validated['business_name'] ?? null,
            'account_email' => $validated['account_email'] ?? null,
            'customer_email' => $validated['customer_email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'address2' => $validated['address2'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'zip' => $validated['zip'] ?? null,
            'store_domain' => $validated['store_domain'] ?? null,
            'order_id_prefix' => $validated['order_id_prefix'] ?? null,
            'order_id_suffix' => $validated['order_id_suffix'] ?? null,
            'buy_id_prefix' => $validated['buy_id_prefix'] ?? null,
            'buy_id_suffix' => $validated['buy_id_suffix'] ?? null,
            'repair_id_prefix' => $validated['repair_id_prefix'] ?? null,
            'repair_id_suffix' => $validated['repair_id_suffix'] ?? null,
            'memo_id_prefix' => $validated['memo_id_prefix'] ?? null,
            'memo_id_suffix' => $validated['memo_id_suffix'] ?? null,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'default_tax_rate' => isset($validated['default_tax_rate']) ? $validated['default_tax_rate'] / 100 : 0,
            'tax_id_number' => $validated['tax_id_number'] ?? null,
            'edition' => $validated['edition'] ?? $store->edition,
        ];

        // Handle metal price settings - store DWT multipliers as-is
        if (isset($validated['metal_price_settings']['dwt_multipliers'])) {
            $dwtMultipliers = [];
            foreach ($validated['metal_price_settings']['dwt_multipliers'] as $metal => $multiplier) {
                // Only store if a value was provided (not empty)
                if ($multiplier !== null && $multiplier !== '') {
                    $dwtMultipliers[$metal] = (float) $multiplier;
                }
            }

            if (! empty($dwtMultipliers)) {
                $existingSettings = $store->metal_price_settings ?? [];
                $existingSettings['dwt_multipliers'] = array_merge(
                    $existingSettings['dwt_multipliers'] ?? [],
                    $dwtMultipliers
                );
                $updateData['metal_price_settings'] = $existingSettings;
            }
        }

        $store->update($updateData);

        return back()->with('success', 'Store settings updated successfully.');
    }

    /**
     * Upload store logo.
     */
    public function uploadLogo(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,gif,svg,webp', 'max:2048'],
        ]);

        // Delete old logo if exists
        if ($store->logo) {
            Storage::disk('public')->delete($store->logo);
        }

        // Store new logo
        $path = $request->file('logo')->store("stores/{$store->id}/logos", 'public');

        $store->update(['logo' => $path]);

        return back()->with('success', 'Logo uploaded successfully.');
    }

    /**
     * Remove store logo.
     */
    public function removeLogo(): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        if ($store->logo) {
            Storage::disk('public')->delete($store->logo);
            $store->update(['logo' => null]);
        }

        return back()->with('success', 'Logo removed successfully.');
    }

    /**
     * Get available currencies.
     *
     * @return array<array{value: string, label: string}>
     */
    protected function getCurrencies(): array
    {
        return [
            ['value' => 'USD', 'label' => 'USD - US Dollar'],
            ['value' => 'EUR', 'label' => 'EUR - Euro'],
            ['value' => 'GBP', 'label' => 'GBP - British Pound'],
            ['value' => 'CAD', 'label' => 'CAD - Canadian Dollar'],
            ['value' => 'AUD', 'label' => 'AUD - Australian Dollar'],
            ['value' => 'JPY', 'label' => 'JPY - Japanese Yen'],
            ['value' => 'CHF', 'label' => 'CHF - Swiss Franc'],
            ['value' => 'CNY', 'label' => 'CNY - Chinese Yuan'],
            ['value' => 'INR', 'label' => 'INR - Indian Rupee'],
            ['value' => 'MXN', 'label' => 'MXN - Mexican Peso'],
            ['value' => 'BRL', 'label' => 'BRL - Brazilian Real'],
            ['value' => 'NZD', 'label' => 'NZD - New Zealand Dollar'],
            ['value' => 'SGD', 'label' => 'SGD - Singapore Dollar'],
            ['value' => 'HKD', 'label' => 'HKD - Hong Kong Dollar'],
        ];
    }

    /**
     * Get available timezones.
     *
     * @return array<array{value: string, label: string}>
     */
    protected function getTimezones(): array
    {
        return [
            ['value' => 'America/New_York', 'label' => 'Eastern Time (US & Canada)'],
            ['value' => 'America/Chicago', 'label' => 'Central Time (US & Canada)'],
            ['value' => 'America/Denver', 'label' => 'Mountain Time (US & Canada)'],
            ['value' => 'America/Los_Angeles', 'label' => 'Pacific Time (US & Canada)'],
            ['value' => 'America/Anchorage', 'label' => 'Alaska'],
            ['value' => 'Pacific/Honolulu', 'label' => 'Hawaii'],
            ['value' => 'America/Phoenix', 'label' => 'Arizona'],
            ['value' => 'UTC', 'label' => 'UTC'],
            ['value' => 'Europe/London', 'label' => 'London'],
            ['value' => 'Europe/Paris', 'label' => 'Paris'],
            ['value' => 'Europe/Berlin', 'label' => 'Berlin'],
            ['value' => 'Asia/Tokyo', 'label' => 'Tokyo'],
            ['value' => 'Asia/Shanghai', 'label' => 'Shanghai'],
            ['value' => 'Asia/Singapore', 'label' => 'Singapore'],
            ['value' => 'Asia/Dubai', 'label' => 'Dubai'],
            ['value' => 'Australia/Sydney', 'label' => 'Sydney'],
        ];
    }

    protected function getCurrencyCode(?int $currencyId): string
    {
        // For now, return USD as default since there's no currencies table
        return 'USD';
    }

    protected function getTimezoneValue(?int $timezoneId): string
    {
        // For now, return default timezone since there's no timezones table
        return 'America/New_York';
    }

    /**
     * Get available editions for select dropdown.
     *
     * @return array<array{value: string, label: string, description: string}>
     */
    protected function getAvailableEditionsForSelect(): array
    {
        $editions = $this->featureManager->getAvailableEditions();

        return array_map(
            fn (string $key, array $edition) => [
                'value' => $key,
                'label' => $edition['name'],
                'description' => $edition['description'],
            ],
            array_keys($editions),
            array_values($editions)
        );
    }

    /**
     * Get metal types for settings UI.
     *
     * @return array<array{value: string, label: string, group: string, default: float}>
     */
    protected function getMetalTypesForSettings(): array
    {
        return [
            // Gold karats
            ['value' => '10k', 'label' => '10K Gold', 'group' => 'Gold', 'default' => 0.0188],
            ['value' => '14k', 'label' => '14K Gold', 'group' => 'Gold', 'default' => 0.0261],
            ['value' => '16k', 'label' => '16K Gold', 'group' => 'Gold', 'default' => 0.0303],
            ['value' => '18k', 'label' => '18K Gold', 'group' => 'Gold', 'default' => 0.0342],
            ['value' => '20k', 'label' => '20K Gold', 'group' => 'Gold', 'default' => 0.0415],
            ['value' => '22k', 'label' => '22K Gold', 'group' => 'Gold', 'default' => 0.043],
            ['value' => '24k', 'label' => '24K Gold', 'group' => 'Gold', 'default' => 0.045],
            // Silver
            ['value' => 'sterling', 'label' => 'Sterling Silver', 'group' => 'Silver', 'default' => 0.04],
            // Other precious metals
            ['value' => 'platinum', 'label' => 'Platinum', 'group' => 'Other', 'default' => 0.04],
            ['value' => 'palladium', 'label' => 'Palladium', 'group' => 'Other', 'default' => 0.04],
        ];
    }
}
