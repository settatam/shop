<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 2, // Onboarding complete
        ]);

        StoreUser::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'is_owner' => true,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
    }

    public function test_store_settings_page_is_displayed(): void
    {
        $response = $this->actingAs($this->user)->get('/settings/store');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('settings/Store')
            ->has('store')
            ->has('currencies')
            ->has('timezones')
            ->has('availableEditions')
        );
    }

    public function test_store_settings_can_be_updated(): void
    {
        $response = $this->actingAs($this->user)->patch('/settings/store', [
            'name' => 'Updated Store Name',
            'business_name' => 'Updated Business LLC',
            'account_email' => 'updated@example.com',
            'customer_email' => 'support@example.com',
            'phone' => '555-123-4567',
            'address' => '123 New Street',
            'address2' => 'Suite 200',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10001',
            'order_id_prefix' => 'INV-',
            'order_id_suffix' => '-2024',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->store->refresh();

        $this->assertEquals('Updated Store Name', $this->store->name);
        $this->assertEquals('Updated Business LLC', $this->store->business_name);
        $this->assertEquals('updated@example.com', $this->store->account_email);
        $this->assertEquals('support@example.com', $this->store->customer_email);
        $this->assertEquals('555-123-4567', $this->store->phone);
        $this->assertEquals('123 New Street', $this->store->address);
        $this->assertEquals('Suite 200', $this->store->address2);
        $this->assertEquals('New York', $this->store->city);
        $this->assertEquals('NY', $this->store->state);
        $this->assertEquals('10001', $this->store->zip);
        $this->assertEquals('INV-', $this->store->order_id_prefix);
        $this->assertEquals('-2024', $this->store->order_id_suffix);
    }

    public function test_store_name_is_required(): void
    {
        $response = $this->actingAs($this->user)->patch('/settings/store', [
            'name' => '',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_email_fields_must_be_valid_emails(): void
    {
        $response = $this->actingAs($this->user)->patch('/settings/store', [
            'name' => 'Test Store',
            'account_email' => 'not-an-email',
            'customer_email' => 'also-not-an-email',
        ]);

        $response->assertSessionHasErrors(['account_email', 'customer_email']);
    }

    public function test_guest_cannot_access_store_settings(): void
    {
        $response = $this->get('/settings/store');

        $response->assertRedirect('/login');
    }

    public function test_store_logo_can_be_uploaded(): void
    {
        Storage::fake('do_spaces');

        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        $response = $this->actingAs($this->user)->post('/settings/store/logo', [
            'logo' => $file,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->store->refresh();
        $this->assertNotNull($this->store->logo);
        Storage::disk('do_spaces')->assertExists($this->store->logo);
    }

    public function test_store_logo_must_be_an_image(): void
    {
        Storage::fake('do_spaces');

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->user)->post('/settings/store/logo', [
            'logo' => $file,
        ]);

        $response->assertSessionHasErrors('logo');
    }

    public function test_store_logo_has_max_size(): void
    {
        Storage::fake('do_spaces');

        $file = UploadedFile::fake()->image('logo.png')->size(3000); // 3MB

        $response = $this->actingAs($this->user)->post('/settings/store/logo', [
            'logo' => $file,
        ]);

        $response->assertSessionHasErrors('logo');
    }

    public function test_store_logo_can_be_removed(): void
    {
        Storage::fake('do_spaces');

        // First upload a logo
        $file = UploadedFile::fake()->image('logo.png', 200, 200);
        $path = $file->store("stores/{$this->store->id}/logos", 'public');
        $this->store->update(['logo' => $path]);

        $response = $this->actingAs($this->user)->delete('/settings/store/logo');

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->store->refresh();
        $this->assertNull($this->store->logo);
        Storage::disk('do_spaces')->assertMissing($path);
    }

    public function test_uploading_new_logo_removes_old_logo(): void
    {
        Storage::fake('do_spaces');

        // First upload a logo
        $oldFile = UploadedFile::fake()->image('old-logo.png', 200, 200);
        $oldPath = $oldFile->store("stores/{$this->store->id}/logos", 'public');
        $this->store->update(['logo' => $oldPath]);

        // Upload a new logo
        $newFile = UploadedFile::fake()->image('new-logo.png', 200, 200);

        $response = $this->actingAs($this->user)->post('/settings/store/logo', [
            'logo' => $newFile,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->store->refresh();
        $this->assertNotNull($this->store->logo);
        $this->assertNotEquals($oldPath, $this->store->logo);
        Storage::disk('do_spaces')->assertMissing($oldPath);
        Storage::disk('do_spaces')->assertExists($this->store->logo);
    }

    public function test_store_edition_can_be_updated(): void
    {
        $response = $this->actingAs($this->user)->patch('/settings/store', [
            'name' => $this->store->name,
            'edition' => 'pawn_shop',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->store->refresh();
        $this->assertEquals('pawn_shop', $this->store->edition);
    }

    public function test_invalid_edition_is_rejected(): void
    {
        $response = $this->actingAs($this->user)->patch('/settings/store', [
            'name' => $this->store->name,
            'edition' => 'invalid_edition',
        ]);

        $response->assertSessionHasErrors('edition');
    }

    public function test_metal_price_settings_page_includes_metal_types(): void
    {
        $response = $this->actingAs($this->user)->get('/settings/store');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('settings/Store')
            ->has('metalTypes')
        );
    }

    public function test_metal_price_settings_can_be_updated(): void
    {
        $response = $this->actingAs($this->user)->patch('/settings/store', [
            'name' => $this->store->name,
            'metal_price_settings' => [
                'dwt_multipliers' => [
                    '14k' => 0.0261,
                    '18k' => 0.0342,
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->store->refresh();

        $settings = $this->store->metal_price_settings;
        $this->assertNotNull($settings);
        $this->assertArrayHasKey('dwt_multipliers', $settings);
        $this->assertEquals(0.0261, $settings['dwt_multipliers']['14k']);
        $this->assertEquals(0.0342, $settings['dwt_multipliers']['18k']);
    }

    public function test_metal_price_settings_stores_multiplier_directly(): void
    {
        $response = $this->actingAs($this->user)->patch('/settings/store', [
            'name' => $this->store->name,
            'metal_price_settings' => [
                'dwt_multipliers' => [
                    'sterling' => 0.04,
                ],
            ],
        ]);

        $response->assertRedirect();

        $this->store->refresh();

        // Verify the multiplier was stored as-is
        $this->assertEquals(0.04, $this->store->metal_price_settings['dwt_multipliers']['sterling']);

        // Verify the helper method returns correct value
        $this->assertEquals(0.04, $this->store->getDwtMultiplier('sterling'));
    }

    public function test_metal_price_settings_merges_with_existing(): void
    {
        // Set initial settings
        $this->store->update([
            'metal_price_settings' => [
                'dwt_multipliers' => [
                    '10k' => 0.0188,
                    '14k' => 0.025,
                ],
            ],
        ]);

        // Update only 14k
        $response = $this->actingAs($this->user)->patch('/settings/store', [
            'name' => $this->store->name,
            'metal_price_settings' => [
                'dwt_multipliers' => [
                    '14k' => 0.0261,
                ],
            ],
        ]);

        $response->assertRedirect();

        $this->store->refresh();

        // 10k should remain unchanged, 14k should be updated
        $this->assertEquals(0.0188, $this->store->metal_price_settings['dwt_multipliers']['10k']);
        $this->assertEquals(0.0261, $this->store->metal_price_settings['dwt_multipliers']['14k']);
    }

    public function test_get_dwt_multiplier_returns_null_when_not_set(): void
    {
        $this->store->update(['metal_price_settings' => null]);

        // Should return null when no multiplier is set (spot price used as-is)
        $this->assertNull($this->store->getDwtMultiplier('14k'));
    }

    public function test_get_metal_price_settings_with_defaults(): void
    {
        $this->store->update(['metal_price_settings' => null]);

        $settings = $this->store->getMetalPriceSettingsWithDefaults();

        $this->assertArrayHasKey('dwt_multipliers', $settings);
        // Should have default multipliers from MetalPrice::DEFAULT_DWT_MULTIPLIERS
        $this->assertEquals(0.0188, $settings['dwt_multipliers']['10k']);
        $this->assertEquals(0.0261, $settings['dwt_multipliers']['14k']);
    }
}
