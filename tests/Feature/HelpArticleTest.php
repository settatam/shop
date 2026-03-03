<?php

namespace Tests\Feature;

use App\Models\HelpArticle;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpArticleTest extends TestCase
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
            'step' => 2,
        ]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);
        app(StoreContext::class)->setCurrentStore($this->store);
    }

    // ── Help Center (Public) ─────────────────────────────────

    public function test_can_view_help_center_index(): void
    {
        HelpArticle::factory()->create([
            'title' => 'Getting Started Guide',
            'category' => 'Getting Started',
            'is_published' => true,
        ]);

        HelpArticle::factory()->create([
            'title' => 'Draft Article',
            'category' => 'Products',
            'is_published' => false,
        ]);

        $response = $this->actingAs($this->user)->get('/help');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('help/Index')
            ->has('articles', 1)
            ->where('articles.0.title', 'Getting Started Guide')
        );
    }

    public function test_can_view_help_article(): void
    {
        $article = HelpArticle::factory()->create([
            'title' => 'How to Create a Buy',
            'slug' => 'how-to-create-a-buy',
            'category' => 'Buys',
            'content' => '<p>Step by step guide...</p>',
            'is_published' => true,
        ]);

        $response = $this->actingAs($this->user)->get('/help/how-to-create-a-buy');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('help/Show')
            ->where('article.title', 'How to Create a Buy')
            ->where('article.content', '<p>Step by step guide...</p>')
        );
    }

    public function test_cannot_view_unpublished_help_article(): void
    {
        HelpArticle::factory()->create([
            'slug' => 'draft-article',
            'is_published' => false,
        ]);

        $response = $this->actingAs($this->user)->get('/help/draft-article');

        $response->assertStatus(404);
    }

    public function test_help_article_returns_prev_next_navigation(): void
    {
        HelpArticle::factory()->create([
            'title' => 'First Article',
            'slug' => 'first-article',
            'category' => 'Buys',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        HelpArticle::factory()->create([
            'title' => 'Second Article',
            'slug' => 'second-article',
            'category' => 'Buys',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        HelpArticle::factory()->create([
            'title' => 'Third Article',
            'slug' => 'third-article',
            'category' => 'Buys',
            'sort_order' => 2,
            'is_published' => true,
        ]);

        $response = $this->actingAs($this->user)->get('/help/second-article');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('previous.slug', 'first-article')
            ->where('next.slug', 'third-article')
        );
    }

    // ── Admin CRUD ───────────────────────────────────────────

    public function test_can_view_help_articles_admin(): void
    {
        HelpArticle::factory()->count(3)->create();

        $response = $this->actingAs($this->user)->get('/settings/help-articles');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('settings/HelpArticles')
            ->has('articles', 3)
            ->has('categories')
        );
    }

    public function test_can_create_help_article(): void
    {
        $response = $this->actingAs($this->user)->post('/settings/help-articles', [
            'category' => 'Products',
            'title' => 'How to Add a Product',
            'content' => '<p>Here is how you add a product.</p>',
            'excerpt' => 'Learn to add products',
            'is_published' => true,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('help_articles', [
            'category' => 'Products',
            'title' => 'How to Add a Product',
            'slug' => 'how-to-add-a-product',
            'is_published' => true,
        ]);
    }

    public function test_slug_is_auto_generated_from_title(): void
    {
        $this->actingAs($this->user)->post('/settings/help-articles', [
            'category' => 'Getting Started',
            'title' => 'Welcome to the App!',
            'content' => '<p>Welcome.</p>',
            'is_published' => true,
        ]);

        $this->assertDatabaseHas('help_articles', [
            'slug' => 'welcome-to-the-app',
        ]);
    }

    public function test_duplicate_slug_gets_suffix(): void
    {
        HelpArticle::factory()->create([
            'title' => 'Test Article',
            'slug' => 'test-article',
        ]);

        $this->actingAs($this->user)->post('/settings/help-articles', [
            'category' => 'Getting Started',
            'title' => 'Test Article',
            'content' => '<p>Duplicate.</p>',
            'is_published' => true,
        ]);

        $this->assertDatabaseHas('help_articles', [
            'slug' => 'test-article-1',
        ]);
    }

    public function test_can_update_help_article(): void
    {
        $article = HelpArticle::factory()->create([
            'title' => 'Old Title',
            'category' => 'Products',
        ]);

        $response = $this->actingAs($this->user)->put("/settings/help-articles/{$article->id}", [
            'category' => 'Buys',
            'title' => 'Updated Title',
            'content' => '<p>Updated content.</p>',
            'excerpt' => 'Updated excerpt',
            'is_published' => false,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('help_articles', [
            'id' => $article->id,
            'category' => 'Buys',
            'title' => 'Updated Title',
            'is_published' => false,
        ]);
    }

    public function test_can_delete_help_article(): void
    {
        $article = HelpArticle::factory()->create();

        $response = $this->actingAs($this->user)->delete("/settings/help-articles/{$article->id}");

        $response->assertRedirect();

        $this->assertDatabaseMissing('help_articles', [
            'id' => $article->id,
        ]);
    }

    public function test_create_requires_title_and_content(): void
    {
        $response = $this->actingAs($this->user)->post('/settings/help-articles', [
            'category' => 'Products',
            'title' => '',
            'content' => '',
        ]);

        $response->assertSessionHasErrors(['title', 'content']);
    }

    public function test_create_requires_valid_category(): void
    {
        $response = $this->actingAs($this->user)->post('/settings/help-articles', [
            'category' => 'Invalid Category',
            'title' => 'Test',
            'content' => '<p>Test</p>',
        ]);

        $response->assertSessionHasErrors(['category']);
    }

    // ── Model ────────────────────────────────────────────────

    public function test_to_searchable_text_strips_html(): void
    {
        $article = HelpArticle::factory()->create([
            'content' => '<h2>Title</h2><p>This is <strong>bold</strong> text.</p>',
        ]);

        $this->assertEquals('TitleThis is bold text.', $article->toSearchableText());
    }

    public function test_published_scope_filters_correctly(): void
    {
        HelpArticle::factory()->create(['is_published' => true]);
        HelpArticle::factory()->create(['is_published' => true]);
        HelpArticle::factory()->create(['is_published' => false]);

        $this->assertCount(2, HelpArticle::published()->get());
    }

    // ── Auth ─────────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_help_center(): void
    {
        $response = $this->get('/help');
        $response->assertRedirect('/login');
    }

    public function test_unauthenticated_cannot_access_admin(): void
    {
        $response = $this->get('/settings/help-articles');
        $response->assertRedirect('/login');
    }
}
