<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\EbayCategory;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user with a store that needs onboarding (step = 1)
        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'step' => 1,
        ]);
        $this->user->update(['current_store_id' => $this->store->id]);

        // Set up store context
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_onboarding_page_is_accessible_for_users_needing_onboarding(): void
    {
        // Create some eBay categories
        EbayCategory::create([
            'name' => 'Jewelry & Watches',
            'level' => 1,
            'ebay_category_id' => 281,
        ]);

        $response = $this->actingAs($this->user)->get('/onboarding');

        $response->assertStatus(200);
        $response->assertInertia(function ($page) {
            $page->component('onboarding/Index')
                ->has('store')
                ->has('productCategories');
        });
    }

    public function test_users_who_completed_onboarding_are_redirected_to_dashboard(): void
    {
        $this->store->update(['step' => 2]);

        $response = $this->actingAs($this->user)->get('/onboarding');

        $response->assertRedirect('/dashboard');
    }

    public function test_onboarding_middleware_redirects_incomplete_stores_to_onboarding(): void
    {
        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertRedirect(route('onboarding.index'));
    }

    public function test_onboarding_middleware_allows_completed_stores_to_dashboard(): void
    {
        $this->store->update(['step' => 2]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_can_complete_onboarding_with_selected_categories(): void
    {
        // Create parent eBay categories (Level 1)
        $jewelryCategory = EbayCategory::create([
            'name' => 'Jewelry & Watches',
            'level' => 1,
            'ebay_category_id' => 281,
        ]);

        // Create child eBay categories (Level 2)
        EbayCategory::create([
            'name' => 'Fine Jewelry',
            'level' => 2,
            'parent_id' => $jewelryCategory->id,
            'ebay_category_id' => 10968,
        ]);
        EbayCategory::create([
            'name' => 'Fashion Jewelry',
            'level' => 2,
            'parent_id' => $jewelryCategory->id,
            'ebay_category_id' => 10969,
        ]);

        $response = $this->actingAs($this->user)->post('/onboarding', [
            'ebay_category_ids' => [$jewelryCategory->id],
        ]);

        $response->assertRedirect('/dashboard');

        // Verify store step is updated
        $this->store->refresh();
        $this->assertEquals(2, $this->store->step);

        // Verify eBay categories are attached to store
        $this->assertTrue($this->store->ebayCategories()->where('ebay_categories.id', $jewelryCategory->id)->exists());

        // Verify local categories were created (1 parent + 2 children)
        $categories = Category::withoutGlobalScopes()->where('store_id', $this->store->id)->get();
        $this->assertCount(3, $categories);

        // Verify parent category exists
        $parentCategory = $categories->whereNull('parent_id')->first();
        $this->assertNotNull($parentCategory);
        $this->assertEquals('Jewelry & Watches', $parentCategory->name);

        // Verify child categories exist
        $childCategories = $categories->where('parent_id', $parentCategory->id);
        $this->assertCount(2, $childCategories);
    }

    public function test_can_complete_onboarding_with_multiple_categories(): void
    {
        // Create multiple parent eBay categories
        $jewelryCategory = EbayCategory::create([
            'name' => 'Jewelry & Watches',
            'level' => 1,
            'ebay_category_id' => 281,
        ]);
        EbayCategory::create([
            'name' => 'Rings',
            'level' => 2,
            'parent_id' => $jewelryCategory->id,
            'ebay_category_id' => 10970,
        ]);

        $sportsCategory = EbayCategory::create([
            'name' => 'Sports Mem, Cards & Fan Shop',
            'level' => 1,
            'ebay_category_id' => 64482,
        ]);
        EbayCategory::create([
            'name' => 'Sports Trading Cards',
            'level' => 2,
            'parent_id' => $sportsCategory->id,
            'ebay_category_id' => 212,
        ]);

        $response = $this->actingAs($this->user)->post('/onboarding', [
            'ebay_category_ids' => [$jewelryCategory->id, $sportsCategory->id],
        ]);

        $response->assertRedirect('/dashboard');

        // Verify both eBay categories are attached
        $this->assertCount(2, $this->store->fresh()->ebayCategories);

        // Verify 4 local categories created (2 parents + 2 children)
        $categories = Category::withoutGlobalScopes()->where('store_id', $this->store->id)->get();
        $this->assertCount(4, $categories);

        // Verify both parent categories exist
        $parentCategories = $categories->whereNull('parent_id');
        $this->assertCount(2, $parentCategories);
    }

    public function test_can_complete_onboarding_with_address(): void
    {
        $jewelryCategory = EbayCategory::create([
            'name' => 'Jewelry & Watches',
            'level' => 1,
            'ebay_category_id' => 281,
        ]);

        $response = $this->actingAs($this->user)->post('/onboarding', [
            'ebay_category_ids' => [$jewelryCategory->id],
            'address_line1' => '123 Main Street',
            'address_line2' => 'Suite 100',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94102',
            'country' => 'US',
        ]);

        $response->assertRedirect('/dashboard');

        // Verify store address is updated
        $this->store->refresh();
        $this->assertEquals('123 Main Street', $this->store->address);
        $this->assertEquals('Suite 100', $this->store->address2);
        $this->assertEquals('San Francisco', $this->store->city);
        $this->assertEquals('CA', $this->store->state);
        $this->assertEquals('94102', $this->store->zip);
    }

    public function test_default_warehouse_is_created_during_onboarding(): void
    {
        $jewelryCategory = EbayCategory::create([
            'name' => 'Jewelry & Watches',
            'level' => 1,
            'ebay_category_id' => 281,
        ]);

        $response = $this->actingAs($this->user)->post('/onboarding', [
            'ebay_category_ids' => [$jewelryCategory->id],
            'address_line1' => '123 Main Street',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94102',
            'country' => 'US',
        ]);

        $response->assertRedirect('/dashboard');

        // Verify warehouse was created
        $warehouse = Warehouse::where('store_id', $this->store->id)->first();
        $this->assertNotNull($warehouse);
        $this->assertEquals('Main Warehouse', $warehouse->name);
        $this->assertEquals('MAIN', $warehouse->code);
        $this->assertTrue($warehouse->is_default);
        $this->assertEquals('123 Main Street', $warehouse->address_line1);
        $this->assertEquals('San Francisco', $warehouse->city);
    }

    public function test_default_lead_sources_are_created_during_onboarding(): void
    {
        $this->actingAs($this->user)->post('/onboarding', [
            'skip_categories' => true,
        ]);

        // Verify lead sources were created
        $leadSources = \App\Models\LeadSource::where('store_id', $this->store->id)->get();
        $this->assertCount(7, $leadSources);
        $this->assertTrue($leadSources->pluck('slug')->contains('walk-in'));
        $this->assertTrue($leadSources->pluck('slug')->contains('referral'));
        $this->assertTrue($leadSources->pluck('slug')->contains('social-media'));
    }

    public function test_default_notification_templates_are_created_during_onboarding(): void
    {
        $this->actingAs($this->user)->post('/onboarding', [
            'skip_categories' => true,
        ]);

        // Verify notification templates were created
        $templates = \App\Models\NotificationTemplate::where('store_id', $this->store->id)->get();
        $this->assertGreaterThan(0, $templates->count());
    }

    public function test_existing_warehouse_is_not_duplicated(): void
    {
        // Pre-create a warehouse
        Warehouse::create([
            'store_id' => $this->store->id,
            'name' => 'Existing Warehouse',
            'code' => 'EXIST',
            'is_default' => true,
        ]);

        $jewelryCategory = EbayCategory::create([
            'name' => 'Jewelry & Watches',
            'level' => 1,
            'ebay_category_id' => 281,
        ]);

        $response = $this->actingAs($this->user)->post('/onboarding', [
            'ebay_category_ids' => [$jewelryCategory->id],
        ]);

        $response->assertRedirect('/dashboard');

        // Verify only one warehouse exists
        $warehouses = Warehouse::where('store_id', $this->store->id)->get();
        $this->assertCount(1, $warehouses);
        $this->assertEquals('Existing Warehouse', $warehouses->first()->name);
    }

    public function test_can_skip_categories_during_onboarding(): void
    {
        $response = $this->actingAs($this->user)->post('/onboarding', [
            'ebay_category_ids' => [],
            'skip_categories' => true,
        ]);

        $response->assertRedirect('/dashboard');

        // Verify store is marked complete but no categories created
        $this->store->refresh();
        $this->assertFalse($this->store->needsOnboarding());
        $this->assertEquals(0, Category::where('store_id', $this->store->id)->count());
    }

    public function test_can_complete_onboarding_without_categories(): void
    {
        $response = $this->actingAs($this->user)->post('/onboarding', [
            'ebay_category_ids' => [],
        ]);

        // Should still complete successfully (categories are optional now)
        $response->assertRedirect('/dashboard');

        $this->store->refresh();
        $this->assertFalse($this->store->needsOnboarding());
    }

    public function test_ebay_category_ids_must_exist(): void
    {
        $response = $this->actingAs($this->user)->post('/onboarding', [
            'ebay_category_ids' => [9999],
        ]);

        $response->assertSessionHasErrors('ebay_category_ids.0');
    }

    public function test_can_fetch_ebay_category_children(): void
    {
        $parentCategory = EbayCategory::create([
            'name' => 'Jewelry & Watches',
            'level' => 1,
            'ebay_category_id' => 281,
        ]);
        EbayCategory::create([
            'name' => 'Fine Jewelry',
            'level' => 2,
            'parent_id' => $parentCategory->id,
            'ebay_category_id' => 10968,
        ]);
        EbayCategory::create([
            'name' => 'Fashion Jewelry',
            'level' => 2,
            'parent_id' => $parentCategory->id,
            'ebay_category_id' => 10969,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/onboarding/ebay-categories/{$parentCategory->id}/children");

        $response->assertStatus(200);
        $response->assertJson([
            'parent' => [
                'id' => $parentCategory->id,
                'name' => 'Jewelry & Watches',
            ],
        ]);
        $response->assertJsonCount(2, 'children');
    }

    public function test_register_redirects_to_onboarding(): void
    {
        // This tests the RegisterResponse override
        $response = $this->post('/register', [
            'name' => 'Test User',
            'store_name' => 'Test Store',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('onboarding.index'));
    }
}
