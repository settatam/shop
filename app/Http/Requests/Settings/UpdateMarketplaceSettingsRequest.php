<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMarketplaceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Common
            'price_markup' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
            'use_ai_details' => ['boolean'],

            // eBay
            'marketplace_id' => ['nullable', 'string', 'max:50'],
            'default_condition' => ['nullable', 'string', 'max:50'],
            'listing_type' => ['nullable', 'string', 'in:FIXED_PRICE,AUCTION'],
            'listing_duration_fixed' => ['nullable', 'string', 'max:20'],
            'listing_duration_auction' => ['nullable', 'string', 'max:20'],
            'return_policy_id' => ['nullable', 'string', 'max:100'],
            'payment_policy_id' => ['nullable', 'string', 'max:100'],
            'fulfillment_policy_id' => ['nullable', 'string', 'max:100'],
            'auction_markup' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
            'fixed_price_markup' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
            'best_offer_enabled' => ['boolean'],
            'location_key' => ['nullable', 'string', 'max:100'],
            'location_mappings' => ['nullable', 'array'],
            'location_mappings.*.warehouse_id' => ['required_with:location_mappings', 'integer'],
            'location_mappings.*.location_key' => ['required_with:location_mappings', 'string', 'max:100'],

            // Amazon
            'fulfillment_channel' => ['nullable', 'string', 'in:DEFAULT,AFN'],
            'language_tag' => ['nullable', 'string', 'in:en_US,en_GB,de_DE,fr_FR,it_IT,es_ES,ja_JP'],

            // Etsy
            'currency' => ['nullable', 'string', 'in:USD,GBP,EUR,CAD,AUD'],
            'who_made' => ['nullable', 'string', 'in:i_did,someone_else,collective'],
            'when_made' => ['nullable', 'string', 'in:made_to_order,2020_2026,2010_2019,2000_2009,before_2000,1990s,1980s,1970s,1960s'],
            'is_supply' => ['boolean'],
            'shipping_profile_id' => ['nullable', 'string', 'max:100'],
            'auto_renew' => ['boolean'],

            // Walmart
            'product_id_type' => ['nullable', 'string', 'in:UPC,GTIN,EAN,ISBN'],
            'fulfillment_type' => ['nullable', 'string', 'in:seller,wfs'],
            'shipping_method' => ['nullable', 'string', 'in:STANDARD,EXPEDITED,FREIGHT,VALUE'],
            'weight_unit' => ['nullable', 'string', 'in:LB,KG,OZ'],

            // Shopify
            'default_product_status' => ['nullable', 'string', 'in:active,draft'],
            'inventory_tracking' => ['nullable', 'string', 'in:shopify,not_managed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'listing_type.in' => 'Listing type must be either Fixed Price or Auction.',
            'auction_markup.min' => 'Auction markup cannot be less than -100%.',
            'fixed_price_markup.min' => 'Fixed price markup cannot be less than -100%.',
            'price_markup.min' => 'Price markup cannot be less than -100%.',
            'fulfillment_channel.in' => 'Fulfillment channel must be either Merchant (DEFAULT) or FBA (AFN).',
            'who_made.in' => 'Invalid "who made" selection.',
            'when_made.in' => 'Invalid "when made" selection.',
            'product_id_type.in' => 'Product ID type must be UPC, GTIN, EAN, or ISBN.',
            'fulfillment_type.in' => 'Fulfillment type must be either seller or WFS.',
            'default_product_status.in' => 'Product status must be either active or draft.',
        ];
    }
}
