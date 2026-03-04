<?php

namespace Tests\Browser;

use App\Models\Customer;
use App\Models\Repair;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RepairTest extends DuskTestCase
{
    use DatabaseMigrations;
    use DuskSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDuskEnvironment();
    }

    public function test_can_create_repair(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)
                ->visit('/repairs/create')
                ->waitForText('Employee')

                // Step 1: Select Employee
                ->assertSee($this->ownerStoreUser->first_name)
                ->pause(300);

            // Click on the employee card to select them
            $browser->script("document.querySelectorAll('button')[1].click()");
            $browser->pause(300)
                ->press('Next')

                // Step 2: Customer - create new customer
                ->waitForText('Customer')
                ->press('Create New Customer')
                ->pause(500);

            // Fill customer form - inputs in grid order: first_name, last_name, company_name, email, phone
            $this->fillInputByIndex($browser, '.grid', 0, 'Jane');
            $browser->pause(200);
            $this->fillInputByIndex($browser, '.grid', 1, 'Doe');
            $browser->pause(200)
                ->press('Confirm Customer')
                ->pause(500)
                ->press('Next')

                // Step 3: Items - add repair item inline
                ->waitForText('Repair Items')
                ->pause(500);

            // Fill item title (first text input in the add item form)
            $this->fillInput($browser, 'input[placeholder*="Ring Repair"]', 'Watch Battery Replacement');
            $browser->pause(300);

            // Fill vendor cost and customer cost
            $this->fillInputByIndex($browser, '.border-dashed', 2, '25');
            $browser->pause(200);
            $this->fillInputByIndex($browser, '.border-dashed', 3, '50');
            $browser->pause(200)

                // Click "Add Item" button within the add item form
                ->press('Add Item')
                ->pause(500)

                // Verify item was added
                ->assertSee('Watch Battery Replacement')
                ->press('Next')

                // Step 4: Vendor - create new vendor
                ->waitForText('Vendor')
                ->press('Create New Vendor')
                ->pause(500);

            // Fill vendor name
            $this->fillInputByIndex($browser, '.grid', 0, 'Repair Vendor LLC');
            $browser->pause(300)
                ->press('Confirm Vendor')
                ->pause(500)
                ->press('Next')

                // Step 5: Review
                ->waitForText('Review')
                ->pause(500)
                ->press('Create Repair')
                ->pause(3000);

            // Verify repair was created
            $this->assertDatabaseHas('repairs', [
                'store_id' => $this->store->id,
                'status' => Repair::STATUS_PENDING,
            ]);

            $this->assertDatabaseHas('repair_items', [
                'title' => 'Watch Battery Replacement',
            ]);
        });
    }

    public function test_can_progress_repair_status(): void
    {
        $customer = Customer::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $vendor = Vendor::factory()->create([
            'store_id' => $this->store->id,
        ]);

        $repair = Repair::factory()->pending()->create([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'user_id' => $this->owner->id,
        ]);

        $this->browse(function (Browser $browser) use ($repair) {
            $browser->loginAs($this->owner)
                ->visit("/repairs/{$repair->id}")
                ->waitForText('Repair')
                ->assertSee('Pending')

                // Send to vendor
                ->press('Send to Vendor')
                ->pause(2000);

            $repair->refresh();
            $this->assertEquals(Repair::STATUS_SENT_TO_VENDOR, $repair->status);
        });
    }
}
