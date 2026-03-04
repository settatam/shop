<?php

namespace Tests\Browser;

use App\Models\Memo;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MemoTest extends DuskTestCase
{
    use DatabaseMigrations;
    use DuskSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDuskEnvironment();
    }

    public function test_can_create_memo(): void
    {
        // Create a product that can be added to the memo
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'title' => 'Diamond Necklace',
            'status' => Product::STATUS_ACTIVE,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 500.00,
        ]);

        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)
                ->visit('/memos/create')
                ->waitForText('Select Employee')

                // Step 1: Select Employee - should be auto-selected
                ->assertSee($this->ownerStoreUser->first_name)
                ->press('Continue')

                // Step 2: Vendor - create new vendor
                ->waitForText('Vendor');

            // Click "Create New" button for vendor
            $browser->press('Create New')
                ->pause(500);

            // Fill vendor name (first text input in the vendor create form grid)
            $this->fillInputByIndex($browser, '.grid', 0, 'Test Vendor Co');
            $browser->pause(300)
                ->press('Continue')

                // Step 3: Products - search and add product
                ->waitForText('Products')
                ->pause(500);

            // Use quick product creation instead of search (since search requires an API call)
            $browser->press('Quick Product')
                ->waitFor('#product_title')
                ->type('#product_title', 'Test Memo Product')
                ->type('#product_price', '500')
                ->press('Create Product')
                ->pause(1000)
                ->press('Continue')

                // Step 4: Review
                ->waitForText('Review')
                ->pause(500)
                ->press('Create Memo')
                ->pause(3000);

            // Verify memo was created
            $this->assertDatabaseHas('memos', [
                'store_id' => $this->store->id,
                'status' => Memo::STATUS_PENDING,
            ]);
        });
    }

    public function test_can_send_memo_to_vendor(): void
    {
        $vendor = Vendor::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $memo = Memo::factory()->pending()->create([
            'store_id' => $this->store->id,
            'vendor_id' => $vendor->id,
            'user_id' => $this->owner->id,
        ]);

        $this->browse(function (Browser $browser) use ($memo) {
            $browser->loginAs($this->owner)
                ->visit("/memos/{$memo->id}")
                ->waitForText('Memo')
                ->assertSee('Pending')
                ->press('Send to Vendor')
                ->pause(2000);

            $memo->refresh();
            $this->assertEquals(Memo::STATUS_SENT_TO_VENDOR, $memo->status);
        });
    }
}
