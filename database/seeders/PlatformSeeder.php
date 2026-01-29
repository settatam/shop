<?php

namespace Database\Seeders;

use App\Models\Platform;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $platforms = [
            [
                'name' => 'eBay',
                'slug' => 'ebay',
                'description' => 'eBay is one of the world\'s largest online marketplaces for buying and selling goods.',
                'api_base_url' => 'https://api.ebay.com',
                'is_active' => true,
                'settings' => [
                    'requires_item_specifics' => true,
                    'max_title_length' => 80,
                    'max_description_length' => 500000,
                    'supports_variations' => true,
                    'condition_required' => true,
                ],
            ],
            [
                'name' => 'Amazon',
                'slug' => 'amazon',
                'description' => 'Amazon is the world\'s largest e-commerce platform.',
                'api_base_url' => 'https://sellingpartnerapi.amazon.com',
                'is_active' => true,
                'settings' => [
                    'requires_upc' => true,
                    'max_title_length' => 200,
                    'requires_brand' => true,
                    'supports_variations' => true,
                    'requires_category' => true,
                ],
            ],
            [
                'name' => 'Etsy',
                'slug' => 'etsy',
                'description' => 'Etsy is a global marketplace for unique and creative goods, focusing on handmade, vintage, and craft supplies.',
                'api_base_url' => 'https://openapi.etsy.com/v3',
                'is_active' => true,
                'settings' => [
                    'max_title_length' => 140,
                    'max_tags' => 13,
                    'max_images' => 10,
                    'supports_variations' => true,
                    'requires_shipping_profile' => true,
                ],
            ],
            [
                'name' => 'Shopify',
                'slug' => 'shopify',
                'description' => 'Shopify is a leading e-commerce platform for creating online stores.',
                'api_base_url' => 'https://{store}.myshopify.com/admin/api',
                'is_active' => true,
                'settings' => [
                    'supports_metafields' => true,
                    'supports_variants' => true,
                    'supports_collections' => true,
                    'max_variants' => 100,
                    'max_images' => 250,
                ],
            ],
            [
                'name' => 'Google Shopping',
                'slug' => 'google_shopping',
                'description' => 'Google Shopping allows products to be displayed in Google search results and Shopping tab.',
                'api_base_url' => 'https://shoppingcontent.googleapis.com/content/v2.1',
                'is_active' => true,
                'settings' => [
                    'requires_gtin' => true,
                    'requires_brand' => true,
                    'max_title_length' => 150,
                    'max_description_length' => 5000,
                    'requires_shipping' => true,
                ],
            ],
            [
                'name' => 'WooCommerce',
                'slug' => 'woocommerce',
                'description' => 'WooCommerce is an open-source e-commerce plugin for WordPress.',
                'api_base_url' => null,
                'is_active' => true,
                'settings' => [
                    'supports_variations' => true,
                    'supports_attributes' => true,
                    'supports_categories' => true,
                    'supports_tags' => true,
                ],
            ],
            [
                'name' => 'Facebook/Meta',
                'slug' => 'facebook',
                'description' => 'Facebook Marketplace and Instagram Shopping allow selling products through Meta platforms.',
                'api_base_url' => 'https://graph.facebook.com',
                'is_active' => true,
                'settings' => [
                    'max_title_length' => 150,
                    'max_description_length' => 5000,
                    'requires_condition' => true,
                    'supports_variations' => true,
                ],
            ],
            [
                'name' => 'Poshmark',
                'slug' => 'poshmark',
                'description' => 'Poshmark is a social commerce marketplace for buying and selling fashion.',
                'api_base_url' => null,
                'is_active' => true,
                'settings' => [
                    'max_title_length' => 80,
                    'max_photos' => 16,
                    'requires_size' => true,
                    'requires_brand' => true,
                    'fashion_focused' => true,
                ],
            ],
        ];

        foreach ($platforms as $platform) {
            Platform::updateOrCreate(
                ['slug' => $platform['slug']],
                $platform
            );
        }

        $this->command->info('Platforms seeded successfully.');
    }
}
