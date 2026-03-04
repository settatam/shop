<?php

namespace Tests\Browser;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class BuyTransactionTest extends DuskTestCase
{
    use DatabaseMigrations;
    use DuskSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDuskEnvironment();
    }

    // ─── Wizard Navigation Helpers ───────────────────────────────────────

    /**
     * Step 1: Employee is auto-selected, click Continue.
     */
    private function completeEmployeeStep(Browser $browser): void
    {
        $browser->waitForText('Select Employee')
            ->assertSee($this->ownerStoreUser->first_name)
            ->press('Continue');
    }

    /**
     * Step 2: Switch to Create New mode and fill customer fields.
     */
    private function createCustomer(Browser $browser, array $fields): void
    {
        $browser->waitForText('Customer Information')
            ->press('Create New')
            ->waitFor('#first_name')
            ->type('#first_name', $fields['first_name'])
            ->type('#last_name', $fields['last_name']);

        if (isset($fields['email'])) {
            $browser->type('#email', $fields['email']);
        }
        if (isset($fields['phone'])) {
            $browser->type('#phone', $fields['phone']);
        }
        if (isset($fields['company_name'])) {
            $browser->type('#company_name', $fields['company_name']);
        }
        if (isset($fields['address'])) {
            $browser->type('#address', $fields['address']);
        }
        if (isset($fields['city'])) {
            $browser->type('#city', $fields['city']);
        }
        if (isset($fields['state'])) {
            $browser->type('#state', $fields['state']);
        }
        if (isset($fields['zip'])) {
            $browser->type('#zip', $fields['zip']);
        }

        $browser->press('Continue');
    }

    /**
     * Step 3: Open modal, fill item fields, save, then set offer & continue.
     */
    private function addItemAndContinue(Browser $browser, array $item): void
    {
        $browser->waitForText('Add Items')
            ->press('Add Item')
            ->waitFor('[role="dialog"] #title')
            ->type('#title', $item['title'])
            ->type('#buy_price', $item['buy_price']);

        if (isset($item['description'])) {
            $browser->type('#description', $item['description']);
        }
        if (isset($item['precious_metal'])) {
            $browser->select('#precious_metal', $item['precious_metal']);
        }
        if (isset($item['dwt'])) {
            $browser->type('#dwt', $item['dwt']);
        }
        if (isset($item['price'])) {
            $browser->type('#price', $item['price']);
        }

        // Click the modal's save button (within the dialog)
        $browser->within('[role="dialog"]', function (Browser $modal) {
            $modal->press('Add Item');
        });

        $browser->pause(500)
            ->assertSee($item['title']);

        // Set offer amount to match total buy price, then continue
        $offerAmount = $item['offer_amount'] ?? $item['buy_price'];
        $browser->clear('#offer_amount')
            ->type('#offer_amount', $offerAmount)
            ->press('Continue');
    }

    /**
     * Step 4: Select a payment method by its label text.
     */
    private function selectPaymentMethod(Browser $browser, string $label): void
    {
        $escapedLabel = addslashes($label);
        $browser->script("
            document.querySelectorAll('[role=\"radio\"]').forEach(function(el) {
                if (el.textContent.trim().includes('{$escapedLabel}')) {
                    el.click();
                }
            });
        ");
        $browser->pause(300);
    }

    /**
     * Fill a payment detail input by its placeholder attribute.
     */
    private function fillPaymentField(Browser $browser, string $placeholder, string $value): void
    {
        $escapedPlaceholder = addslashes($placeholder);
        $escapedValue = addslashes($value);
        $browser->script("
            var el = document.querySelector('input[placeholder=\"{$escapedPlaceholder}\"], textarea[placeholder=\"{$escapedPlaceholder}\"]');
            if (el) {
                var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                setter.call(el, '{$escapedValue}');
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        ");
        $browser->pause(200);
    }

    /**
     * Submit the transaction and wait for redirect.
     */
    private function submitAndVerify(Browser $browser): void
    {
        $browser->press('Create Transaction')
            ->waitForText('Payment Processed', 15);
    }

    /**
     * Generate a tiny test JPEG image and return its path.
     */
    private function createTestImage(): string
    {
        $path = sys_get_temp_dir().'/dusk_test_id_photo.jpg';
        $image = imagecreatetruecolor(200, 200);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 200, 200, $white);
        $text = imagecolorallocate($image, 0, 0, 0);
        imagestring($image, 5, 50, 90, 'TEST ID', $text);
        imagejpeg($image, $path, 80);
        imagedestroy($image);

        return $path;
    }

    // ─── Tests: Customer Creation ────────────────────────────────────────

    public function test_can_create_buy_with_new_customer(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)
                ->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);

            // Create customer with full details
            $this->createCustomer($browser, [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@example.com',
                'phone' => '555-123-4567',
                'company_name' => 'Smith Jewelers',
                'address' => '123 Main St',
                'city' => 'Philadelphia',
                'state' => 'PA',
                'zip' => '19103',
            ]);

            $this->addItemAndContinue($browser, [
                'title' => 'Silver Bracelet',
                'buy_price' => '75',
            ]);

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'Cash');
            $this->submitAndVerify($browser);

            // Verify customer was created with all fields
            $this->assertDatabaseHas('customers', [
                'store_id' => $this->store->id,
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@example.com',
            ]);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => Transaction::PAYMENT_CASH,
            ]);
        });
    }

    public function test_can_create_buy_with_customer_id_photo(): void
    {
        $testImagePath = $this->createTestImage();

        $this->browse(function (Browser $browser) use ($testImagePath) {
            $browser->loginAs($this->owner)
                ->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);

            // Create customer and upload ID photo
            $browser->waitForText('Customer Information')
                ->press('Create New')
                ->waitFor('#first_name')
                ->type('#first_name', 'Photo')
                ->type('#last_name', 'Customer');

            // Attach ID photo - the file input is hidden inside a label
            $browser->attach('input[type="file"][accept="image/*"]', $testImagePath)
                ->pause(1000);

            // Verify photo preview appears (the img element)
            $browser->assertPresent('img[alt="Customer ID"]')
                ->press('Continue');

            $this->addItemAndContinue($browser, [
                'title' => 'Gold Chain',
                'buy_price' => '200',
            ]);

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'Cash');
            $this->submitAndVerify($browser);

            $this->assertDatabaseHas('customers', [
                'store_id' => $this->store->id,
                'first_name' => 'Photo',
                'last_name' => 'Customer',
            ]);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
            ]);
        });

        @unlink($testImagePath);
    }

    // ─── Tests: Item Entry ───────────────────────────────────────────────

    public function test_can_add_item_with_metal_and_weight(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)
                ->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);
            $this->createCustomer($browser, [
                'first_name' => 'Metal',
                'last_name' => 'Buyer',
            ]);

            // Add item with precious metal and DWT
            $browser->waitForText('Add Items')
                ->press('Add Item')
                ->waitFor('[role="dialog"] #title')
                ->type('#title', '14K Gold Necklace')
                ->type('#buy_price', '350');

            // Select precious metal
            $browser->select('#precious_metal', 'gold_14k')
                ->pause(300);

            // Enter weight in DWT
            $browser->type('#dwt', '5.25')
                ->pause(1000);

            // The spot price section should show calculating or a value
            $browser->assertSeeIn('[role="dialog"]', 'Metal Type');

            // Set estimated value
            $browser->type('#price', '500');

            // Save the item
            $browser->within('[role="dialog"]', function (Browser $modal) {
                $modal->press('Add Item');
            });

            $browser->pause(500)
                ->assertSee('14K Gold Necklace')
                ->assertSee('14K Gold');

            // Set offer amount and continue
            $browser->clear('#offer_amount')
                ->type('#offer_amount', '350')
                ->press('Continue');

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'Cash');
            $this->submitAndVerify($browser);

            // Verify item was saved with metal and weight data
            $this->assertDatabaseHas('transaction_items', [
                'title' => '14K Gold Necklace',
                'precious_metal' => TransactionItem::METAL_GOLD_14K,
                'dwt' => 5.25,
                'buy_price' => 350,
                'price' => 500,
            ]);
        });
    }

    public function test_can_add_multiple_items(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)
                ->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);
            $this->createCustomer($browser, [
                'first_name' => 'Multi',
                'last_name' => 'Items',
            ]);

            $browser->waitForText('Add Items');

            // Add first item
            $browser->press('Add Item')
                ->waitFor('[role="dialog"] #title')
                ->type('#title', 'Diamond Ring')
                ->type('#buy_price', '500')
                ->within('[role="dialog"]', function (Browser $modal) {
                    $modal->press('Add Item');
                })
                ->pause(500)
                ->assertSee('Diamond Ring');

            // Add second item
            $browser->press('Add Item')
                ->waitFor('[role="dialog"] #title')
                ->type('#title', 'Pearl Earrings')
                ->type('#buy_price', '150')
                ->within('[role="dialog"]', function (Browser $modal) {
                    $modal->press('Add Item');
                })
                ->pause(500)
                ->assertSee('Pearl Earrings');

            // Add third item with metal/weight
            $browser->press('Add Item')
                ->waitFor('[role="dialog"] #title')
                ->type('#title', 'Platinum Band')
                ->type('#buy_price', '350')
                ->select('#precious_metal', 'platinum')
                ->type('#dwt', '3.50')
                ->within('[role="dialog"]', function (Browser $modal) {
                    $modal->press('Add Item');
                })
                ->pause(500)
                ->assertSee('Platinum Band');

            // Total buy price should be 500 + 150 + 350 = 1000
            $browser->clear('#offer_amount')
                ->type('#offer_amount', '1000')
                ->press('Continue');

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'Cash');
            $this->submitAndVerify($browser);

            // Verify all items exist
            $this->assertDatabaseHas('transaction_items', ['title' => 'Diamond Ring', 'buy_price' => 500]);
            $this->assertDatabaseHas('transaction_items', ['title' => 'Pearl Earrings', 'buy_price' => 150]);
            $this->assertDatabaseHas('transaction_items', [
                'title' => 'Platinum Band',
                'buy_price' => 350,
                'precious_metal' => TransactionItem::METAL_PLATINUM,
                'dwt' => 3.50,
            ]);
        });
    }

    // ─── Tests: Payment Methods ──────────────────────────────────────────

    public function test_can_pay_with_cash(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);
            $this->createCustomer($browser, ['first_name' => 'Cash', 'last_name' => 'Payer']);
            $this->addItemAndContinue($browser, ['title' => 'Gold Coin', 'buy_price' => '100']);

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'Cash');
            $this->submitAndVerify($browser);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => Transaction::PAYMENT_CASH,
            ]);
        });
    }

    public function test_can_pay_with_check(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);
            $this->createCustomer($browser, ['first_name' => 'Check', 'last_name' => 'Payer']);
            $this->addItemAndContinue($browser, ['title' => 'Silver Spoon', 'buy_price' => '50']);

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'Check');
            $browser->pause(500);

            // Fill check-specific fields
            $this->fillPaymentField($browser, 'Check #', '12345');
            $this->fillPaymentField($browser, 'Street Address', '456 Oak Ave');
            $this->fillPaymentField($browser, 'City', 'New York');
            $this->fillPaymentField($browser, 'State', 'NY');
            $this->fillPaymentField($browser, 'ZIP', '10001');

            $this->submitAndVerify($browser);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => Transaction::PAYMENT_CHECK,
            ]);
        });
    }

    public function test_can_pay_with_paypal(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);
            $this->createCustomer($browser, ['first_name' => 'PayPal', 'last_name' => 'Payer']);
            $this->addItemAndContinue($browser, ['title' => 'Vintage Watch', 'buy_price' => '300']);

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'PayPal');
            $browser->pause(500);

            // Fill PayPal email
            $this->fillPaymentField($browser, 'customer@email.com', 'seller@paypal.com');

            $this->submitAndVerify($browser);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => Transaction::PAYMENT_PAYPAL,
            ]);
        });
    }

    public function test_can_pay_with_venmo(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);
            $this->createCustomer($browser, ['first_name' => 'Venmo', 'last_name' => 'Payer']);
            $this->addItemAndContinue($browser, ['title' => 'Ruby Pendant', 'buy_price' => '225']);

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'Venmo');
            $browser->pause(500);

            // Fill Venmo handle
            $this->fillPaymentField($browser, 'username', 'seller_venmo');

            $this->submitAndVerify($browser);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => Transaction::PAYMENT_VENMO,
            ]);
        });
    }

    public function test_can_pay_with_ach(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);
            $this->createCustomer($browser, ['first_name' => 'ACH', 'last_name' => 'Payer']);
            $this->addItemAndContinue($browser, ['title' => 'Sapphire Ring', 'buy_price' => '800']);

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'ACH Transfer');
            $browser->pause(500);

            // Fill bank details
            $this->fillPaymentField($browser, 'Bank Name', 'First National Bank');
            $this->fillPaymentField($browser, 'Account Holder Name', 'ACH Payer');
            $this->fillPaymentField($browser, 'Routing Number', '021000021');
            $this->fillPaymentField($browser, 'Account Number', '123456789');

            $this->submitAndVerify($browser);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => Transaction::PAYMENT_ACH,
            ]);
        });
    }

    public function test_can_pay_with_wire_transfer(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);
            $this->createCustomer($browser, ['first_name' => 'Wire', 'last_name' => 'Payer']);
            $this->addItemAndContinue($browser, ['title' => 'Emerald Brooch', 'buy_price' => '1200']);

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'Wire Transfer');
            $browser->pause(500);

            // Fill bank details (same fields as ACH)
            $this->fillPaymentField($browser, 'Bank Name', 'Chase Bank');
            $this->fillPaymentField($browser, 'Account Holder Name', 'Wire Payer');
            $this->fillPaymentField($browser, 'Routing Number', '021000021');
            $this->fillPaymentField($browser, 'Account Number', '987654321');

            $this->submitAndVerify($browser);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => Transaction::PAYMENT_WIRE_TRANSFER,
            ]);
        });
    }

    public function test_can_pay_with_store_credit(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->owner)->visit('/transactions/buy');

            $this->completeEmployeeStep($browser);
            $this->createCustomer($browser, ['first_name' => 'Credit', 'last_name' => 'Payer']);
            $this->addItemAndContinue($browser, ['title' => 'Antique Pin', 'buy_price' => '45']);

            $browser->waitForText('Payment Details');
            $this->selectPaymentMethod($browser, 'Store Credit');
            $this->submitAndVerify($browser);

            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'payment_method' => Transaction::PAYMENT_STORE_CREDIT,
            ]);
        });
    }

    // ─── Tests: Full End-to-End ──────────────────────────────────────────

    public function test_full_buy_wizard_end_to_end(): void
    {
        $testImagePath = $this->createTestImage();

        $this->browse(function (Browser $browser) use ($testImagePath) {
            $browser->loginAs($this->owner)
                ->visit('/transactions/buy');

            // Step 1: Employee selection
            $this->completeEmployeeStep($browser);

            // Step 2: Create a full customer with ID photo
            $browser->waitForText('Customer Information')
                ->press('Create New')
                ->waitFor('#first_name')
                ->type('#first_name', 'Robert')
                ->type('#last_name', 'Johnson')
                ->type('#email', 'robert.j@example.com')
                ->type('#phone', '215-555-0199')
                ->type('#company_name', 'Johnson Estate Sales')
                ->type('#address', '789 Elm Street')
                ->type('#city', 'Philadelphia')
                ->type('#state', 'PA')
                ->type('#zip', '19107');

            // Upload ID photo
            $browser->attach('input[type="file"][accept="image/*"]', $testImagePath)
                ->pause(1000)
                ->assertPresent('img[alt="Customer ID"]')
                ->press('Continue');

            // Step 3: Add multiple items
            $browser->waitForText('Add Items');

            // Item 1: 14K Gold Ring with metal + weight
            $browser->press('Add Item')
                ->waitFor('[role="dialog"] #title')
                ->type('#title', '14K Gold Wedding Band')
                ->type('#description', 'Vintage wedding band, excellent condition')
                ->select('#precious_metal', 'gold_14k')
                ->type('#dwt', '4.50')
                ->type('#price', '400')
                ->type('#buy_price', '250')
                ->within('[role="dialog"]', function (Browser $modal) {
                    $modal->press('Add Item');
                })
                ->pause(500)
                ->assertSee('14K Gold Wedding Band');

            // Item 2: Sterling Silver Necklace
            $browser->press('Add Item')
                ->waitFor('[role="dialog"] #title')
                ->type('#title', 'Sterling Silver Chain')
                ->select('#precious_metal', 'silver')
                ->type('#dwt', '12.00')
                ->type('#buy_price', '150')
                ->within('[role="dialog"]', function (Browser $modal) {
                    $modal->press('Add Item');
                })
                ->pause(500)
                ->assertSee('Sterling Silver Chain');

            // Item 3: Non-metal item
            $browser->press('Add Item')
                ->waitFor('[role="dialog"] #title')
                ->type('#title', 'Vintage Rolex Datejust')
                ->type('#description', '1985 Rolex Datejust, running, no box or papers')
                ->type('#price', '5000')
                ->type('#buy_price', '3000')
                ->within('[role="dialog"]', function (Browser $modal) {
                    $modal->press('Add Item');
                })
                ->pause(500)
                ->assertSee('Vintage Rolex Datejust');

            // Total: 250 + 150 + 3000 = 3400
            $browser->clear('#offer_amount')
                ->type('#offer_amount', '3400')
                ->press('Continue');

            // Step 4: Payment with cash
            $browser->waitForText('Payment Details')
                ->assertSee('$3400.00');

            $this->selectPaymentMethod($browser, 'Cash');
            $this->submitAndVerify($browser);

            // ─── Database Assertions ─────────────────────────────

            // Verify customer
            $this->assertDatabaseHas('customers', [
                'store_id' => $this->store->id,
                'first_name' => 'Robert',
                'last_name' => 'Johnson',
                'email' => 'robert.j@example.com',
            ]);

            // Verify transaction
            $this->assertDatabaseHas('transactions', [
                'store_id' => $this->store->id,
                'status' => Transaction::STATUS_PAYMENT_PROCESSED,
                'type' => Transaction::TYPE_IN_STORE,
                'payment_method' => Transaction::PAYMENT_CASH,
            ]);

            // Verify items
            $this->assertDatabaseHas('transaction_items', [
                'title' => '14K Gold Wedding Band',
                'precious_metal' => TransactionItem::METAL_GOLD_14K,
                'dwt' => 4.50,
                'buy_price' => 250,
                'price' => 400,
            ]);

            $this->assertDatabaseHas('transaction_items', [
                'title' => 'Sterling Silver Chain',
                'precious_metal' => TransactionItem::METAL_SILVER,
                'dwt' => 12.00,
                'buy_price' => 150,
            ]);

            $this->assertDatabaseHas('transaction_items', [
                'title' => 'Vintage Rolex Datejust',
                'buy_price' => 3000,
                'price' => 5000,
            ]);
        });

        @unlink($testImagePath);
    }
}
