<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\Store;
use Illuminate\Database\Seeder;

class ProductTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $store = Store::first();

        if (! $store) {
            $this->command->error('No store found. Please create a store first.');

            return;
        }

        $this->createJewelryTemplate($store);
        $this->createHandbagTemplate($store);

        $this->command->info('Product templates created successfully.');
    }

    protected function createJewelryTemplate(Store $store): void
    {
        // Create Jewelry template
        $template = ProductTemplate::updateOrCreate(
            ['store_id' => $store->id, 'name' => 'Jewelry'],
            [
                'description' => 'Template for jewelry items including rings, necklaces, bracelets, and earrings.',
                'is_active' => true,
            ]
        );

        // Metal Type (select)
        $metalType = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'metal_type'],
            [
                'label' => 'Metal Type',
                'type' => 'select',
                'placeholder' => 'Select metal type',
                'is_required' => true,
                'is_filterable' => true,
                'is_searchable' => true,
                'show_in_listing' => true,
                'sort_order' => 1,
                'width_class' => 'half',
            ]
        );
        $this->createOptions($metalType, [
            'gold' => 'Gold',
            'silver' => 'Silver',
            'platinum' => 'Platinum',
            'palladium' => 'Palladium',
            'titanium' => 'Titanium',
            'stainless_steel' => 'Stainless Steel',
        ]);

        // Metal Purity (select)
        $metalPurity = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'metal_purity'],
            [
                'label' => 'Metal Purity',
                'type' => 'select',
                'placeholder' => 'Select purity',
                'is_required' => false,
                'is_filterable' => true,
                'sort_order' => 2,
                'width_class' => 'half',
            ]
        );
        $this->createOptions($metalPurity, [
            '24k' => '24K (99.9%)',
            '22k' => '22K (91.7%)',
            '18k' => '18K (75%)',
            '14k' => '14K (58.3%)',
            '10k' => '10K (41.7%)',
            '925' => '925 Sterling',
            '950' => '950 Platinum',
        ]);

        // Metal Weight + Unit (grouped field set)
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'metal_weight'],
            [
                'label' => 'Metal Weight',
                'type' => 'number',
                'placeholder' => '0.00',
                'help_text' => 'Weight of the metal component',
                'is_required' => false,
                'sort_order' => 3,
                'group_name' => 'metal_weight',
                'group_position' => 1,
                'width_class' => 'half',
            ]
        );

        $weightUnit = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'metal_weight_unit'],
            [
                'label' => 'Unit',
                'type' => 'select',
                'is_required' => false,
                'sort_order' => 4,
                'group_name' => 'metal_weight',
                'group_position' => 2,
                'width_class' => 'quarter',
            ]
        );
        $this->createOptions($weightUnit, [
            'g' => 'grams',
            'oz' => 'oz',
            'dwt' => 'dwt',
        ]);

        // Ring Size (for rings)
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'ring_size'],
            [
                'label' => 'Ring Size',
                'type' => 'text',
                'placeholder' => 'e.g. 7, 7.5',
                'help_text' => 'US ring size (leave blank if not a ring)',
                'is_required' => false,
                'is_filterable' => true,
                'sort_order' => 5,
                'width_class' => 'half',
            ]
        );

        // Chain Length + Unit (grouped)
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'chain_length'],
            [
                'label' => 'Chain Length',
                'type' => 'number',
                'placeholder' => '0',
                'help_text' => 'For necklaces and chains',
                'is_required' => false,
                'is_filterable' => true,
                'sort_order' => 6,
                'group_name' => 'chain_length',
                'group_position' => 1,
                'width_class' => 'half',
            ]
        );

        $chainUnit = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'chain_length_unit'],
            [
                'label' => 'Unit',
                'type' => 'select',
                'is_required' => false,
                'sort_order' => 7,
                'group_name' => 'chain_length',
                'group_position' => 2,
                'width_class' => 'quarter',
            ]
        );
        $this->createOptions($chainUnit, [
            'in' => 'inches',
            'cm' => 'cm',
        ]);

        // Gemstone Type
        $gemstone = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'gemstone_type'],
            [
                'label' => 'Gemstone Type',
                'type' => 'select',
                'placeholder' => 'Select gemstone',
                'is_required' => false,
                'is_filterable' => true,
                'is_searchable' => true,
                'sort_order' => 8,
                'width_class' => 'half',
            ]
        );
        $this->createOptions($gemstone, [
            'diamond' => 'Diamond',
            'ruby' => 'Ruby',
            'sapphire' => 'Sapphire',
            'emerald' => 'Emerald',
            'pearl' => 'Pearl',
            'opal' => 'Opal',
            'amethyst' => 'Amethyst',
            'topaz' => 'Topaz',
            'none' => 'No Gemstone',
        ]);

        // Total Carat Weight
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'total_carat_weight'],
            [
                'label' => 'Total Carat Weight',
                'type' => 'number',
                'placeholder' => '0.00',
                'help_text' => 'Combined carat weight of all stones',
                'is_required' => false,
                'is_filterable' => true,
                'sort_order' => 9,
                'width_class' => 'half',
            ]
        );

        // Certificate Number
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'certificate_number'],
            [
                'label' => 'Certificate Number',
                'type' => 'text',
                'placeholder' => 'GIA, AGS, etc.',
                'help_text' => 'Grading certificate number if available',
                'is_required' => false,
                'sort_order' => 10,
                'width_class' => 'half',
            ]
        );

        // Create or update Jewelry category with this template
        $category = Category::updateOrCreate(
            ['store_id' => $store->id, 'slug' => 'jewelry'],
            [
                'name' => 'Jewelry',
                'template_id' => $template->id,
            ]
        );

        $this->command->info("Created Jewelry template with ID: {$template->id}");
        $this->command->info("Assigned to category: {$category->name} (ID: {$category->id})");
    }

    protected function createHandbagTemplate(Store $store): void
    {
        // Create Handbag template
        $template = ProductTemplate::updateOrCreate(
            ['store_id' => $store->id, 'name' => 'Handbags & Accessories'],
            [
                'description' => 'Template for handbags, purses, wallets, and leather goods.',
                'is_active' => true,
            ]
        );

        // Material (select)
        $material = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'material'],
            [
                'label' => 'Material',
                'type' => 'select',
                'placeholder' => 'Select material',
                'is_required' => true,
                'is_filterable' => true,
                'is_searchable' => true,
                'show_in_listing' => true,
                'sort_order' => 1,
                'width_class' => 'half',
            ]
        );
        $this->createOptions($material, [
            'leather' => 'Genuine Leather',
            'exotic_leather' => 'Exotic Leather',
            'canvas' => 'Canvas',
            'nylon' => 'Nylon',
            'suede' => 'Suede',
            'vegan_leather' => 'Vegan Leather',
            'fabric' => 'Fabric',
        ]);

        // Color
        $color = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'color'],
            [
                'label' => 'Color',
                'type' => 'text',
                'placeholder' => 'e.g. Black, Navy, Cognac',
                'is_required' => false,
                'is_filterable' => true,
                'is_searchable' => true,
                'sort_order' => 2,
                'width_class' => 'half',
            ]
        );

        // Dimensions - Length + Unit (grouped)
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'bag_length'],
            [
                'label' => 'Length',
                'type' => 'number',
                'placeholder' => '0',
                'is_required' => false,
                'sort_order' => 3,
                'group_name' => 'dimensions',
                'group_position' => 1,
                'width_class' => 'quarter',
            ]
        );

        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'bag_width'],
            [
                'label' => 'Width',
                'type' => 'number',
                'placeholder' => '0',
                'is_required' => false,
                'sort_order' => 4,
                'group_name' => 'dimensions',
                'group_position' => 2,
                'width_class' => 'quarter',
            ]
        );

        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'bag_height'],
            [
                'label' => 'Height',
                'type' => 'number',
                'placeholder' => '0',
                'is_required' => false,
                'sort_order' => 5,
                'group_name' => 'dimensions',
                'group_position' => 3,
                'width_class' => 'quarter',
            ]
        );

        $dimUnit = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'dimension_unit'],
            [
                'label' => 'Unit',
                'type' => 'select',
                'is_required' => false,
                'sort_order' => 6,
                'group_name' => 'dimensions',
                'group_position' => 4,
                'width_class' => 'quarter',
            ]
        );
        $this->createOptions($dimUnit, [
            'in' => 'inches',
            'cm' => 'cm',
        ]);

        // Closure Type
        $closure = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'closure_type'],
            [
                'label' => 'Closure Type',
                'type' => 'select',
                'placeholder' => 'Select closure',
                'is_required' => false,
                'is_filterable' => true,
                'sort_order' => 7,
                'width_class' => 'half',
            ]
        );
        $this->createOptions($closure, [
            'zipper' => 'Zipper',
            'magnetic' => 'Magnetic Snap',
            'turnlock' => 'Turn Lock',
            'flap' => 'Flap Closure',
            'drawstring' => 'Drawstring',
            'open' => 'Open Top',
        ]);

        // Strap Drop + Unit (grouped)
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'strap_drop'],
            [
                'label' => 'Strap Drop',
                'type' => 'number',
                'placeholder' => '0',
                'help_text' => 'Length from top of bag to top of strap',
                'is_required' => false,
                'sort_order' => 8,
                'group_name' => 'strap_drop',
                'group_position' => 1,
                'width_class' => 'half',
            ]
        );

        $strapUnit = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'strap_drop_unit'],
            [
                'label' => 'Unit',
                'type' => 'select',
                'is_required' => false,
                'sort_order' => 9,
                'group_name' => 'strap_drop',
                'group_position' => 2,
                'width_class' => 'quarter',
            ]
        );
        $this->createOptions($strapUnit, [
            'in' => 'inches',
            'cm' => 'cm',
        ]);

        // Hardware Color
        $hardware = ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'hardware_color'],
            [
                'label' => 'Hardware Color',
                'type' => 'select',
                'placeholder' => 'Select hardware',
                'is_required' => false,
                'is_filterable' => true,
                'sort_order' => 10,
                'width_class' => 'half',
            ]
        );
        $this->createOptions($hardware, [
            'gold' => 'Gold',
            'silver' => 'Silver',
            'rose_gold' => 'Rose Gold',
            'gunmetal' => 'Gunmetal',
            'brass' => 'Brass',
        ]);

        // Interior Lining
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'interior_lining'],
            [
                'label' => 'Interior Lining',
                'type' => 'text',
                'placeholder' => 'e.g. Microfiber, Cotton, Suede',
                'is_required' => false,
                'sort_order' => 11,
                'width_class' => 'half',
            ]
        );

        // Number of Compartments
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'compartments'],
            [
                'label' => 'Number of Compartments',
                'type' => 'number',
                'placeholder' => '0',
                'is_required' => false,
                'sort_order' => 12,
                'width_class' => 'half',
            ]
        );

        // Authenticity Code
        ProductTemplateField::updateOrCreate(
            ['product_template_id' => $template->id, 'name' => 'authenticity_code'],
            [
                'label' => 'Authenticity Code',
                'type' => 'text',
                'placeholder' => 'Serial or date code',
                'help_text' => 'For designer items',
                'is_required' => false,
                'sort_order' => 13,
                'width_class' => 'half',
            ]
        );

        // Create or update Handbags category with this template
        $category = Category::updateOrCreate(
            ['store_id' => $store->id, 'slug' => 'handbags'],
            [
                'name' => 'Handbags',
                'template_id' => $template->id,
            ]
        );

        $this->command->info("Created Handbags template with ID: {$template->id}");
        $this->command->info("Assigned to category: {$category->name} (ID: {$category->id})");
    }

    protected function createOptions(ProductTemplateField $field, array $options): void
    {
        $field->options()->delete();

        $sortOrder = 0;
        foreach ($options as $value => $label) {
            $field->options()->create([
                'value' => $value,
                'label' => $label,
                'sort_order' => $sortOrder++,
            ]);
        }
    }
}
