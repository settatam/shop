<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreMarketplace;
use App\Services\Platforms\Shopify\ShopifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedStoreProducts extends Command
{
    protected $signature = 'store:seed-products
                            {store : Store ID or shop domain}
                            {--count=50 : Number of products to create}
                            {--push-to=  : StoreMarketplace ID to push products to (e.g. Shopify connection)}';

    protected $description = 'Seed a store with realistic jewelry & watch products for testing';

    /** @var array<string, list<array{title: string, description: string, variants: list<array{name: string, sku_suffix: string, price: float, cost: float, qty: int, option1_name?: string, option1_value?: string}>}>> */
    protected array $catalog = [];

    public function handle(ShopifyService $shopifyService): int
    {
        $store = $this->resolveStore();

        if (! $store) {
            $this->error('Store not found.');

            return self::FAILURE;
        }

        $count = (int) $this->option('count');
        $pushToMarketplaceId = $this->option('push-to');

        $marketplace = null;
        if ($pushToMarketplaceId) {
            $marketplace = StoreMarketplace::where('id', $pushToMarketplaceId)
                ->where('store_id', $store->id)
                ->first();

            if (! $marketplace) {
                $this->error("StoreMarketplace ID {$pushToMarketplaceId} not found for this store.");

                return self::FAILURE;
            }

            $label = $marketplace->shop_domain ?? $marketplace->name;
            $this->info("Will push to: {$marketplace->platform->value} — {$label} (ID: {$marketplace->id})");
        }

        $this->info("Seeding {$count} jewelry products for store: {$store->name} (ID: {$store->id})");

        $this->buildCatalog();
        $categories = $this->createCategories($store);
        $brands = $this->createBrands($store);

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $products = [];
        $created = 0;

        while ($created < $count) {
            foreach ($this->catalog as $categoryName => $items) {
                if ($created >= $count) {
                    break;
                }

                foreach ($items as $item) {
                    if ($created >= $count) {
                        break;
                    }

                    $category = $categories[$categoryName] ?? null;
                    $brand = $brands[array_rand($brands)];

                    $product = Product::create([
                        'store_id' => $store->id,
                        'title' => $item['title'],
                        'handle' => Str::slug($item['title']).'-'.Str::random(4),
                        'description' => $item['description'],
                        'category_id' => $category?->id,
                        'brand_id' => $brand->id,
                        'status' => Product::STATUS_ACTIVE,
                        'is_published' => true,
                        'track_quantity' => true,
                        'quantity' => collect($item['variants'])->sum('qty'),
                    ]);

                    foreach ($item['variants'] as $v) {
                        ProductVariant::create([
                            'product_id' => $product->id,
                            'sku' => strtoupper(Str::random(3)).'-'.$v['sku_suffix'].'-'.str_pad((string) ($created + 1), 3, '0', STR_PAD_LEFT),
                            'price' => $v['price'],
                            'cost' => $v['cost'],
                            'quantity' => $v['qty'],
                            'status' => 'active',
                            'is_active' => true,
                            'weight' => round(rand(5, 200) / 10, 1),
                            'weight_unit' => 'g',
                            'option1_name' => $v['option1_name'] ?? null,
                            'option1_value' => $v['option1_value'] ?? null,
                        ]);
                    }

                    $products[] = $product;
                    $created++;
                    $bar->advance();
                }
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$created} products with variants in the local database.");

        if ($marketplace) {
            $this->pushToMarketplace($products, $marketplace, $shopifyService);
        }

        return self::SUCCESS;
    }

    protected function resolveStore(): ?Store
    {
        $identifier = $this->argument('store');

        if (is_numeric($identifier)) {
            return Store::find((int) $identifier);
        }

        $marketplace = StoreMarketplace::where('shop_domain', $identifier)->first();

        return $marketplace?->store;
    }

    /**
     * @param  list<Product>  $products
     */
    protected function pushToMarketplace(array $products, StoreMarketplace $marketplace, ShopifyService $shopifyService): void
    {
        $total = count($products);
        $label = $marketplace->shop_domain ?? $marketplace->name;
        $this->info("Pushing {$total} products to {$marketplace->platform->value} ({$label})...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $pushed = 0;
        $failed = 0;

        foreach ($products as $product) {
            try {
                $shopifyService->pushProduct($product, $marketplace);
                $pushed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("  Failed to push '{$product->title}': {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Pushed {$pushed} products. {$failed} failed.");
    }

    /**
     * @return array<string, Category>
     */
    protected function createCategories(Store $store): array
    {
        $names = ['Rings', 'Necklaces', 'Bracelets', 'Earrings', 'Watches', 'Pendants', 'Brooches', 'Chains'];
        $categories = [];

        foreach ($names as $name) {
            $categories[$name] = Category::firstOrCreate(
                ['store_id' => $store->id, 'slug' => Str::slug($name)],
                ['name' => $name, 'level' => 0, 'sort_order' => 0]
            );
        }

        return $categories;
    }

    /**
     * @return array<int, Brand>
     */
    protected function createBrands(Store $store): array
    {
        $names = [
            'Tiffany & Co.', 'Cartier', 'David Yurman', 'Rolex', 'Omega',
            'TAG Heuer', 'Pandora', 'Swarovski', 'Kay Jewelers', 'Zales',
            'Blue Nile', 'James Allen', 'Chopard', 'Bulgari', 'Van Cleef & Arpels',
        ];

        $brands = [];

        foreach ($names as $name) {
            $brands[] = Brand::firstOrCreate(
                ['store_id' => $store->id, 'slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => 0]
            );
        }

        return $brands;
    }

    protected function buildCatalog(): void
    {
        $this->catalog = [
            'Rings' => [
                [
                    'title' => '14K Gold Diamond Solitaire Engagement Ring',
                    'description' => 'Classic solitaire engagement ring featuring a brilliant round-cut diamond set in polished 14K gold. The timeless four-prong setting allows maximum light to enter the stone for exceptional brilliance.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'RING', 'price' => 899.99, 'cost' => 450.00, 'qty' => 4],
                    ],
                ],
                [
                    'title' => '18K White Gold Sapphire Halo Ring',
                    'description' => 'Stunning halo ring with a vivid blue Ceylon sapphire surrounded by a pavé of round brilliant diamonds. Set in lustrous 18K white gold with a delicate split shank.',
                    'variants' => [
                        ['name' => 'Size 6', 'sku_suffix' => 'SAPH', 'price' => 1249.99, 'cost' => 625.00, 'qty' => 2, 'option1_name' => 'Size', 'option1_value' => '6'],
                        ['name' => 'Size 7', 'sku_suffix' => 'SAPH', 'price' => 1249.99, 'cost' => 625.00, 'qty' => 3, 'option1_name' => 'Size', 'option1_value' => '7'],
                    ],
                ],
                [
                    'title' => 'Sterling Silver Stackable Band Set',
                    'description' => 'Set of three stackable bands in .925 sterling silver. Includes one plain polished band, one twisted rope band, and one CZ-accented band. Mix, match, and stack to create your signature look.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'STAK', 'price' => 79.99, 'cost' => 28.00, 'qty' => 15],
                    ],
                ],
                [
                    'title' => 'Rose Gold Morganite Cocktail Ring',
                    'description' => 'Eye-catching cocktail ring with a cushion-cut morganite center stone in warm rose gold. Diamond accents along the shoulders add sparkle to this statement piece.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'MORG', 'price' => 549.99, 'cost' => 275.00, 'qty' => 3],
                    ],
                ],
                [
                    'title' => 'Platinum Diamond Eternity Band',
                    'description' => 'Luxurious eternity band featuring 2.0 carats of round brilliant diamonds channel-set in platinum. Each diamond is hand-selected for exceptional color and clarity.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'ETER', 'price' => 3499.99, 'cost' => 1750.00, 'qty' => 1],
                    ],
                ],
                [
                    'title' => '10K Gold Emerald and Diamond Ring',
                    'description' => 'Elegant ring featuring a natural emerald flanked by two round diamonds. Set in warm 10K yellow gold with a comfort-fit band.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'EMER', 'price' => 399.99, 'cost' => 180.00, 'qty' => 5],
                    ],
                ],
                [
                    'title' => 'Tungsten Carbide Men\'s Wedding Band',
                    'description' => 'Durable tungsten carbide ring with a brushed matte finish and polished beveled edges. Comfort-fit interior. Scratch-resistant for everyday wear.',
                    'variants' => [
                        ['name' => 'Size 10', 'sku_suffix' => 'TUNG', 'price' => 129.99, 'cost' => 35.00, 'qty' => 8, 'option1_name' => 'Size', 'option1_value' => '10'],
                        ['name' => 'Size 11', 'sku_suffix' => 'TUNG', 'price' => 129.99, 'cost' => 35.00, 'qty' => 6, 'option1_name' => 'Size', 'option1_value' => '11'],
                    ],
                ],
            ],
            'Necklaces' => [
                [
                    'title' => '14K Gold Cuban Link Chain 22"',
                    'description' => 'Bold and classic 14K yellow gold Cuban link chain. 5mm width, 22 inches long with a secure lobster claw clasp. Solid construction with a satisfying weight.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'CUBN', 'price' => 1899.99, 'cost' => 1100.00, 'qty' => 2],
                    ],
                ],
                [
                    'title' => 'Diamond Tennis Necklace 3.0 CTW',
                    'description' => 'Breathtaking tennis necklace featuring 3.0 carats total weight of round brilliant diamonds individually set in four-prong 14K white gold settings. 16 inches with safety clasp.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'TENN', 'price' => 4999.99, 'cost' => 2500.00, 'qty' => 1],
                    ],
                ],
                [
                    'title' => 'Pearl Strand Necklace - Freshwater',
                    'description' => 'Classic strand of 7-8mm cultured freshwater pearls with high luster. Hand-knotted on silk thread with a 14K gold filigree clasp. 18 inches.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'PERL', 'price' => 299.99, 'cost' => 120.00, 'qty' => 6],
                    ],
                ],
                [
                    'title' => 'Sterling Silver Lariat Y-Necklace',
                    'description' => 'Modern lariat-style necklace in polished sterling silver with a CZ-studded drop pendant. Adjustable length from 16 to 20 inches. Everyday elegance.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'LART', 'price' => 89.99, 'cost' => 30.00, 'qty' => 10],
                    ],
                ],
                [
                    'title' => '18K Gold Diamond Cross Pendant',
                    'description' => 'Timeless cross pendant set with round brilliant diamonds in 18K yellow gold. Comes on an 18-inch cable chain. Total diamond weight 0.25 CTW.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'CROS', 'price' => 649.99, 'cost' => 300.00, 'qty' => 4],
                    ],
                ],
                [
                    'title' => 'Layered Gold-Filled Choker Set',
                    'description' => 'Three-piece layered choker set in 14K gold-fill. Includes a 14-inch herringbone chain, 16-inch paperclip chain, and 18-inch satellite chain. Wear together or separately.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'CHOK', 'price' => 159.99, 'cost' => 55.00, 'qty' => 8],
                    ],
                ],
            ],
            'Bracelets' => [
                [
                    'title' => '14K Gold Figaro Bracelet 8"',
                    'description' => 'Classic Figaro chain bracelet in 14K yellow gold. 6mm width, 8 inches with a secure lobster claw clasp. The alternating link pattern creates a timeless look.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'FIGR', 'price' => 799.99, 'cost' => 420.00, 'qty' => 3],
                    ],
                ],
                [
                    'title' => 'Diamond Tennis Bracelet 2.0 CTW',
                    'description' => 'Stunning tennis bracelet with 2.0 carats total weight of round diamonds set in 14K white gold. Features a hidden safety clasp and figure-eight safety. 7 inches.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'TENB', 'price' => 2999.99, 'cost' => 1500.00, 'qty' => 1],
                    ],
                ],
                [
                    'title' => 'Sterling Silver Charm Bracelet',
                    'description' => 'Classic cable-link charm bracelet in .925 sterling silver. Includes a heart lock toggle clasp. Compatible with standard European-style charms.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'CHRM', 'price' => 69.99, 'cost' => 22.00, 'qty' => 12],
                    ],
                ],
                [
                    'title' => 'Rose Gold Bangle with Diamonds',
                    'description' => 'Elegant hinged bangle in 14K rose gold with a row of channel-set diamonds across the top. 0.50 CTW. Fits wrist sizes 6.5 to 7.5 inches.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'BANG', 'price' => 1199.99, 'cost' => 600.00, 'qty' => 2],
                    ],
                ],
                [
                    'title' => 'Men\'s Leather & Steel ID Bracelet',
                    'description' => 'Rugged braided leather bracelet with a polished stainless steel ID plate. Magnetic clasp for easy on/off. Available in brown or black.',
                    'variants' => [
                        ['name' => 'Brown', 'sku_suffix' => 'LTHR', 'price' => 49.99, 'cost' => 15.00, 'qty' => 10, 'option1_name' => 'Color', 'option1_value' => 'Brown'],
                        ['name' => 'Black', 'sku_suffix' => 'LTHR', 'price' => 49.99, 'cost' => 15.00, 'qty' => 10, 'option1_name' => 'Color', 'option1_value' => 'Black'],
                    ],
                ],
            ],
            'Earrings' => [
                [
                    'title' => 'Diamond Stud Earrings 1.0 CTW',
                    'description' => 'Classic round brilliant diamond studs totaling 1.0 carat in 14K white gold four-prong settings with secure screw-back posts. I-J color, I1-I2 clarity.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'STUD', 'price' => 1499.99, 'cost' => 750.00, 'qty' => 3],
                    ],
                ],
                [
                    'title' => 'Pearl Drop Earrings - Tahitian',
                    'description' => 'Gorgeous Tahitian pearl drop earrings with a natural dark overtone. 9-10mm pearls suspended from 14K white gold hooks with a single diamond accent.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'TPRL', 'price' => 599.99, 'cost' => 280.00, 'qty' => 2],
                    ],
                ],
                [
                    'title' => 'Gold Huggie Hoop Earrings',
                    'description' => 'Petite huggie hoop earrings in 14K yellow gold with a hinged snap closure. 12mm diameter. Perfect for everyday wear or stacking with other earrings.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'HUGG', 'price' => 199.99, 'cost' => 80.00, 'qty' => 8],
                    ],
                ],
                [
                    'title' => 'Sterling Silver CZ Chandelier Earrings',
                    'description' => 'Statement chandelier earrings with cascading cubic zirconia stones set in rhodium-plated sterling silver. 2.5 inches long with lever-back closures.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'CHND', 'price' => 119.99, 'cost' => 40.00, 'qty' => 5],
                    ],
                ],
                [
                    'title' => 'Sapphire and Diamond Halo Studs',
                    'description' => 'Blue sapphire studs surrounded by a halo of round diamonds. Set in 14K white gold with push-back posts. Total sapphire weight 1.20 CTW.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'SAHP', 'price' => 849.99, 'cost' => 420.00, 'qty' => 3],
                    ],
                ],
            ],
            'Watches' => [
                [
                    'title' => 'Rolex Submariner Date 41mm',
                    'description' => 'Pre-owned Rolex Submariner Date reference 126610LN. Oystersteel case and bracelet with black ceramic Cerachrom bezel. Automatic movement. Excellent condition with box and papers.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'RLEX', 'price' => 12999.99, 'cost' => 9500.00, 'qty' => 1],
                    ],
                ],
                [
                    'title' => 'Omega Speedmaster Moonwatch',
                    'description' => 'Pre-owned Omega Speedmaster Professional reference 310.30.42.50.01.001. The legendary Moonwatch with manual-wind caliber 3861. Hesalite crystal, steel bracelet. Very good condition.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'OMGA', 'price' => 5499.99, 'cost' => 3800.00, 'qty' => 1],
                    ],
                ],
                [
                    'title' => 'TAG Heuer Carrera Chronograph',
                    'description' => 'Pre-owned TAG Heuer Carrera Chronograph with blue sunray dial. 44mm steel case, automatic Heuer-02 movement. Leather strap with deployment buckle. Great condition.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'TAGC', 'price' => 3299.99, 'cost' => 2200.00, 'qty' => 1],
                    ],
                ],
                [
                    'title' => 'Seiko Presage Cocktail Time',
                    'description' => 'Brand new Seiko Presage "Cocktail Time" with ice blue textured dial inspired by the Skywalk cocktail. 40.5mm case, automatic 4R35 movement, exhibition case back.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'SEIK', 'price' => 425.00, 'cost' => 250.00, 'qty' => 3],
                    ],
                ],
                [
                    'title' => 'Citizen Eco-Drive Promaster Diver',
                    'description' => 'Citizen Promaster Diver with Eco-Drive solar movement — never needs a battery. 44mm stainless steel case, 200m water resistance, luminous hands and markers. ISO 6425 certified.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'CTZN', 'price' => 299.99, 'cost' => 160.00, 'qty' => 5],
                    ],
                ],
                [
                    'title' => 'Casio G-Shock GA-2100',
                    'description' => 'The iconic "CasiOak" — Casio G-Shock GA-2100 with carbon core guard structure. Thin octagonal bezel, analog-digital display, 200m water resistance. Matte black.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'GSHK', 'price' => 99.99, 'cost' => 55.00, 'qty' => 10],
                    ],
                ],
                [
                    'title' => 'Vintage Omega Seamaster De Ville',
                    'description' => 'Vintage 1960s Omega Seamaster De Ville with silver dial. 34mm gold-plated case with original crown. Manual-wind caliber 552. Recently serviced. Beautiful patina.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'VOMG', 'price' => 1299.99, 'cost' => 700.00, 'qty' => 1],
                    ],
                ],
            ],
            'Pendants' => [
                [
                    'title' => 'Diamond Solitaire Pendant 0.50 CT',
                    'description' => 'Elegant solitaire pendant with a 0.50 carat round brilliant diamond in a classic four-prong 14K white gold setting. Comes on an 18-inch cable chain.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'SOLP', 'price' => 999.99, 'cost' => 500.00, 'qty' => 3],
                    ],
                ],
                [
                    'title' => 'Birthstone Heart Pendant - Amethyst',
                    'description' => 'Heart-shaped amethyst pendant in 10K yellow gold with a small diamond accent. February birthstone. Comes on a 16-inch gold-filled chain.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'BSTN', 'price' => 149.99, 'cost' => 55.00, 'qty' => 7],
                    ],
                ],
                [
                    'title' => 'Opal and Diamond Halo Pendant',
                    'description' => 'Australian white opal pendant with mesmerizing play-of-color surrounded by a diamond halo. Set in 14K rose gold. Opal weight approximately 1.5 carats.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'OPAL', 'price' => 749.99, 'cost' => 350.00, 'qty' => 2],
                    ],
                ],
                [
                    'title' => 'Men\'s Gold Dog Tag Pendant',
                    'description' => 'Classic dog tag pendant in 14K yellow gold with a high-polish finish. Engravable. Comes on a 24-inch box chain. Weighs approximately 8 grams.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'DTAG', 'price' => 599.99, 'cost' => 320.00, 'qty' => 4],
                    ],
                ],
            ],
            'Chains' => [
                [
                    'title' => '14K Gold Rope Chain 24"',
                    'description' => 'Classic rope chain in solid 14K yellow gold. 3mm width, 24 inches long with a spring ring clasp. Versatile for pendants or worn alone.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'ROPE', 'price' => 1099.99, 'cost' => 650.00, 'qty' => 3],
                    ],
                ],
                [
                    'title' => 'Sterling Silver Box Chain 20"',
                    'description' => 'Sleek box chain in .925 sterling silver. 1.5mm width, 20 inches long. Anti-tarnish rhodium plated. Perfect for pendants or layering.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'BOXC', 'price' => 39.99, 'cost' => 12.00, 'qty' => 20],
                    ],
                ],
                [
                    'title' => '18K Gold Franco Chain 22"',
                    'description' => 'Premium Franco chain in 18K yellow gold. 4mm width with a V-shaped link pattern for superior strength. Secure box clasp with safety latch. 22 inches.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'FRNC', 'price' => 2799.99, 'cost' => 1700.00, 'qty' => 1],
                    ],
                ],
            ],
            'Brooches' => [
                [
                    'title' => 'Vintage Crystal Butterfly Brooch',
                    'description' => 'Vintage-inspired butterfly brooch with multicolor Swarovski crystals set in gold-tone metal. 2 inches wide. A charming accent for blazers, scarves, or hats.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'BTRF', 'price' => 59.99, 'cost' => 18.00, 'qty' => 8],
                    ],
                ],
                [
                    'title' => 'Art Deco Diamond and Onyx Brooch',
                    'description' => 'Striking Art Deco brooch with geometric design in 14K white gold, black onyx, and diamond accents. Estate piece from the 1930s. Excellent vintage condition.',
                    'variants' => [
                        ['name' => 'Default', 'sku_suffix' => 'DECO', 'price' => 1899.99, 'cost' => 950.00, 'qty' => 1],
                    ],
                ],
            ],
        ];
    }
}
