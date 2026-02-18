<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Image;
use App\Models\ProductTemplate;
use App\Models\ProductTemplateField;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\AI\TemplateFieldPopulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TemplateFieldPopulatorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create(['user_id' => $this->user->id, 'step' => 2]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        // Set up a test API key
        config(['services.anthropic.api_key' => 'test-api-key']);
    }

    public function test_auto_populate_requires_category(): void
    {
        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => null,
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson("/transactions/{$transaction->id}/items/{$item->id}/auto-populate-fields");

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    public function test_auto_populate_returns_field_suggestions(): void
    {
        // Create a template with fields
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $brandField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'brand',
            'label' => 'Brand',
            'type' => 'text',
        ]);
        $modelField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'model',
            'label' => 'Model',
            'type' => 'text',
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'title' => 'Rolex Submariner Watch',
        ]);

        // Mock the Anthropic API response
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'identified' => true,
                        'confidence' => 'high',
                        'product_info' => [
                            'brand' => 'Rolex',
                            'model' => 'Submariner',
                            'reference_number' => '126610LN',
                        ],
                        'fields' => [
                            $brandField->id => 'Rolex',
                            $modelField->id => 'Submariner',
                        ],
                        'notes' => 'Identified as a Rolex Submariner based on dial and bezel design.',
                    ]),
                ]],
                'usage' => [
                    'input_tokens' => 500,
                    'output_tokens' => 200,
                ],
            ], 200),
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson("/transactions/{$transaction->id}/items/{$item->id}/auto-populate-fields");

        $response->assertOk();
        $response->assertJson([
            'identified' => true,
            'confidence' => 'high',
            'product_info' => [
                'brand' => 'Rolex',
                'model' => 'Submariner',
            ],
        ]);
        $response->assertJsonStructure([
            'identified',
            'confidence',
            'product_info',
            'fields',
        ]);

        // Verify fields were returned with correct IDs
        $this->assertEquals('Rolex', $response->json('fields.'.$brandField->id));
        $this->assertEquals('Submariner', $response->json('fields.'.$modelField->id));
    }

    public function test_auto_populate_handles_unidentified_product(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $conditionField = ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'condition',
            'label' => 'Condition',
            'type' => 'select',
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'title' => 'Unknown Item',
        ]);

        // Mock API response for unidentified product
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'identified' => false,
                        'confidence' => 'low',
                        'product_info' => [],
                        'fields' => [
                            $conditionField->id => 'used',
                        ],
                        'notes' => 'Could not identify specific product. Appears to be a used item.',
                    ]),
                ]],
                'usage' => [
                    'input_tokens' => 300,
                    'output_tokens' => 100,
                ],
            ], 200),
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson("/transactions/{$transaction->id}/items/{$item->id}/auto-populate-fields");

        $response->assertOk();
        $response->assertJson([
            'identified' => false,
            'confidence' => 'low',
        ]);
    }

    public function test_auto_populate_service_includes_images(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        ProductTemplateField::factory()->create([
            'product_template_id' => $template->id,
            'name' => 'brand',
            'label' => 'Brand',
            'type' => 'text',
        ]);

        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'title' => 'Luxury Watch',
        ]);

        // Add an image to the item
        Image::create([
            'store_id' => $this->store->id,
            'imageable_type' => TransactionItem::class,
            'imageable_id' => $item->id,
            'path' => 'test/watch.jpg',
            'url' => 'https://example.com/watch.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'identified' => true,
                        'confidence' => 'high',
                        'product_info' => ['brand' => 'Omega'],
                        'fields' => [],
                    ]),
                ]],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 100],
            ], 200),
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson("/transactions/{$transaction->id}/items/{$item->id}/auto-populate-fields");

        $response->assertOk();

        // Verify the request included the image
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'] ?? [];

            // Check if any content item is an image
            foreach ($content as $item) {
                if (($item['type'] ?? '') === 'image') {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_auto_populate_handles_api_error(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
        ]);

        // Mock API error
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson("/transactions/{$transaction->id}/items/{$item->id}/auto-populate-fields");

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    public function test_auto_populate_logs_ai_usage(): void
    {
        $template = ProductTemplate::factory()->create(['store_id' => $this->store->id]);
        $category = Category::factory()->create([
            'store_id' => $this->store->id,
            'template_id' => $template->id,
        ]);

        $transaction = Transaction::factory()->paymentProcessed()->create(['store_id' => $this->store->id]);
        $item = TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'identified' => true,
                        'confidence' => 'medium',
                        'product_info' => [],
                        'fields' => [],
                    ]),
                ]],
                'usage' => [
                    'input_tokens' => 500,
                    'output_tokens' => 150,
                ],
            ], 200),
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson("/transactions/{$transaction->id}/items/{$item->id}/auto-populate-fields");

        $response->assertOk();

        // Verify AI usage was logged
        $this->assertDatabaseHas('ai_usage_logs', [
            'store_id' => $this->store->id,
            'provider' => 'anthropic',
            'feature' => 'template_field_population',
            'input_tokens' => 500,
            'output_tokens' => 150,
        ]);
    }
}
