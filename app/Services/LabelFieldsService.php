<?php

namespace App\Services;

class LabelFieldsService
{
    /**
     * Get all available fields for product labels.
     *
     * @return array<string, array<string, string>>
     */
    public static function getProductFields(): array
    {
        return [
            'Basic Information' => [
                'product.title' => 'Product Title',
                'variant.sku' => 'SKU',
                'variant.barcode' => 'Barcode',
                'variant.price' => 'Price',
                'variant.cost' => 'Cost',
                'variant.quantity' => 'Quantity',
                'product.weight' => 'Weight',
            ],
            'Identifiers' => [
                'product.upc' => 'UPC',
                'product.ean' => 'EAN',
                'product.jan' => 'JAN',
                'product.isbn' => 'ISBN',
                'product.mpn' => 'MPN',
            ],
            'Variant Options' => [
                'variant.option1' => 'Option 1 (Name: Value)',
                'variant.option2' => 'Option 2 (Name: Value)',
                'variant.option3' => 'Option 3 (Name: Value)',
                'variant.options_title' => 'All Options Combined',
            ],
            'Relationships' => [
                'product.category' => 'Category Name',
                'product.brand' => 'Brand Name',
            ],
            'Jewelry Fields' => [
                'product.metal_type' => 'Metal Type',
                'product.metal_purity' => 'Metal Purity',
                'product.metal_weight_grams' => 'Metal Weight (g)',
                'product.jewelry_type' => 'Jewelry Type',
                'product.ring_size' => 'Ring Size',
                'product.chain_length_inches' => 'Chain Length',
                'product.main_stone_type' => 'Main Stone',
                'product.total_carat_weight' => 'Total Carat Weight',
            ],
        ];
    }

    /**
     * Get all available fields for transaction labels.
     *
     * @return array<string, array<string, string>>
     */
    public static function getTransactionFields(): array
    {
        return [
            'Transaction Info' => [
                'transaction.transaction_number' => 'Transaction Number',
                'transaction.type' => 'Type (In-House/Mail-In)',
                'transaction.status' => 'Status',
                'transaction.bin_location' => 'Bin Location',
            ],
            'Customer' => [
                'customer.full_name' => 'Customer Name',
                'customer.phone' => 'Customer Phone',
                'customer.email' => 'Customer Email',
            ],
            'Financial' => [
                'transaction.final_offer' => 'Final Offer',
                'transaction.estimated_value' => 'Estimated Value',
                'transaction.preliminary_offer' => 'Preliminary Offer',
            ],
            'Dates' => [
                'transaction.created_at' => 'Created Date',
                'transaction.offer_accepted_at' => 'Offer Accepted Date',
            ],
        ];
    }

    /**
     * Get sample data for preview purposes.
     *
     * @return array<string, array<string, string|null>>
     */
    public static function getSampleData(string $type): array
    {
        if ($type === 'product') {
            return [
                'product' => [
                    'title' => '14K Gold Diamond Ring',
                    'weight' => '5.2g',
                    'upc' => '012345678901',
                    'ean' => '1234567890123',
                    'jan' => '4901234567890',
                    'isbn' => '978-3-16-148410-0',
                    'mpn' => 'GDR-14K-001',
                    'category' => 'Rings',
                    'brand' => 'Diamond Co.',
                    'metal_type' => 'Gold',
                    'metal_purity' => '14K',
                    'metal_weight_grams' => '3.5',
                    'jewelry_type' => 'Ring',
                    'ring_size' => '7',
                    'chain_length_inches' => null,
                    'main_stone_type' => 'Diamond',
                    'total_carat_weight' => '0.50',
                ],
                'variant' => [
                    'sku' => 'RING-14K-001',
                    'barcode' => '123456789012',
                    'price' => '$1,299.00',
                    'cost' => '$650.00',
                    'quantity' => '3',
                    'option1' => 'Size: 7',
                    'option2' => null,
                    'option3' => null,
                    'options_title' => 'Size: 7',
                ],
            ];
        }

        return [
            'transaction' => [
                'transaction_number' => 'TXN-20260117-ABC123',
                'type' => 'In-House Buy',
                'status' => 'Pending',
                'bin_location' => 'A-12',
                'final_offer' => '$500.00',
                'estimated_value' => '$750.00',
                'preliminary_offer' => '$450.00',
                'created_at' => 'Jan 17, 2026',
                'offer_accepted_at' => null,
            ],
            'customer' => [
                'full_name' => 'John Doe',
                'phone' => '(555) 123-4567',
                'email' => 'john@example.com',
            ],
        ];
    }

    /**
     * Get a flat list of all field keys for a type.
     *
     * @return array<string>
     */
    public static function getFieldKeys(string $type): array
    {
        $fields = $type === 'product'
            ? self::getProductFields()
            : self::getTransactionFields();

        $keys = [];
        foreach ($fields as $group) {
            $keys = array_merge($keys, array_keys($group));
        }

        return $keys;
    }
}
