<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureManagerTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected FeatureManager $featureManager;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $user->id]);
        $this->featureManager = app(FeatureManager::class);
    }

    public function test_standard_edition_has_default_features(): void
    {
        $this->store->update(['edition' => 'standard']);

        $features = $this->featureManager->getFeaturesForStore($this->store);

        $this->assertContains('dashboard', $features);
        $this->assertContains('products', $features);
        $this->assertContains('orders', $features);
        $this->assertNotContains('repairs', $features);
        $this->assertNotContains('memos', $features);
    }

    public function test_pawn_shop_edition_has_extended_features(): void
    {
        $this->store->update(['edition' => 'pawn_shop']);

        $features = $this->featureManager->getFeaturesForStore($this->store);

        $this->assertContains('repairs', $features);
        $this->assertContains('memos', $features);
        $this->assertContains('product_status_in_memo', $features);
        $this->assertContains('product_status_in_repair', $features);
    }

    public function test_store_has_feature_check(): void
    {
        $this->store->update(['edition' => 'pawn_shop']);

        $this->assertTrue($this->featureManager->storeHasFeature($this->store, 'repairs'));
        $this->assertTrue($this->featureManager->storeHasFeature($this->store, 'product_status_in_memo'));
    }

    public function test_get_field_requirements_returns_defaults(): void
    {
        $this->store->update(['edition' => 'standard']);

        $requirements = $this->featureManager->getFieldRequirements($this->store, 'products');

        $this->assertArrayHasKey('title', $requirements);
        $this->assertArrayHasKey('vendor_id', $requirements);
        $this->assertTrue($requirements['title']['required'] ?? false);
        $this->assertFalse($requirements['vendor_id']['required'] ?? true);
    }

    public function test_edition_can_override_field_requirements(): void
    {
        $this->store->update(['edition' => 'client_x']);

        $requirements = $this->featureManager->getFieldRequirements($this->store, 'products');

        // client_x edition makes vendor_id required
        $this->assertTrue($requirements['vendor_id']['required'] ?? false);
        $this->assertNotEmpty($requirements['vendor_id']['message'] ?? '');
    }

    public function test_is_field_required_helper(): void
    {
        $this->store->update(['edition' => 'standard']);
        $this->assertFalse($this->featureManager->isFieldRequired($this->store, 'products', 'vendor_id'));

        $this->store->update(['edition' => 'client_x']);
        $this->assertTrue($this->featureManager->isFieldRequired($this->store, 'products', 'vendor_id'));
    }

    public function test_get_validation_rules(): void
    {
        $this->store->update(['edition' => 'client_x']);

        $rules = $this->featureManager->getValidationRules($this->store, 'products');

        $this->assertArrayHasKey('title', $rules);
        $this->assertArrayHasKey('vendor_id', $rules);
        $this->assertEquals('required', $rules['vendor_id']);
    }
}
