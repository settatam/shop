<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVideo;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use App\Services\Video\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductVideoTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected StoreUser $storeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->store = Store::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Store',
            'step' => 2,
        ]);

        $this->user->update(['current_store_id' => $this->store->id]);

        $role = Role::factory()->owner()->create(['store_id' => $this->store->id]);
        $this->storeUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->store->id,
            'role_id' => $role->id,
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
        $this->actingAs($this->user);
    }

    public function test_can_create_video_from_youtube_url(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $videoService = app(VideoService::class);
        $video = $videoService->createFromUrl(
            product: $product,
            url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            title: 'Test Video'
        );

        $this->assertInstanceOf(ProductVideo::class, $video);
        $this->assertEquals($product->id, $video->product_id);
        $this->assertEquals('Test Video', $video->title);
        $this->assertEquals(ProductVideo::TYPE_EXTERNAL, $video->type);
        $this->assertEquals(ProductVideo::PROVIDER_YOUTUBE, $video->provider);
    }

    public function test_can_create_video_from_vimeo_url(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $videoService = app(VideoService::class);
        $video = $videoService->createFromUrl(
            product: $product,
            url: 'https://vimeo.com/123456789',
            title: 'Vimeo Video'
        );

        $this->assertEquals(ProductVideo::PROVIDER_VIMEO, $video->provider);
    }

    public function test_can_upload_video_file(): void
    {
        Storage::fake('do_spaces');

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $file = UploadedFile::fake()->create('test-video.mp4', 1024, 'video/mp4');

        $videoService = app(VideoService::class);
        $video = $videoService->upload(
            file: $file,
            product: $product,
            store: $this->store,
            title: 'Uploaded Video'
        );

        $this->assertInstanceOf(ProductVideo::class, $video);
        $this->assertEquals('Uploaded Video', $video->title);
        $this->assertEquals(ProductVideo::TYPE_UPLOADED, $video->type);
        $this->assertNull($video->provider);
        $this->assertNotEmpty($video->url);
    }

    public function test_product_has_videos_relationship(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        ProductVideo::create([
            'product_id' => $product->id,
            'url' => 'https://www.youtube.com/watch?v=test',
            'title' => 'Video 1',
            'type' => ProductVideo::TYPE_EXTERNAL,
            'provider' => ProductVideo::PROVIDER_YOUTUBE,
            'sort_order' => 0,
        ]);

        ProductVideo::create([
            'product_id' => $product->id,
            'url' => 'https://www.youtube.com/watch?v=test2',
            'title' => 'Video 2',
            'type' => ProductVideo::TYPE_EXTERNAL,
            'provider' => ProductVideo::PROVIDER_YOUTUBE,
            'sort_order' => 1,
        ]);

        $product->refresh();

        $this->assertCount(2, $product->videos);
        $this->assertEquals('Video 1', $product->videos->first()->title);
    }

    public function test_videos_are_ordered_by_sort_order(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        ProductVideo::create([
            'product_id' => $product->id,
            'url' => 'https://www.youtube.com/watch?v=second',
            'title' => 'Second Video',
            'type' => ProductVideo::TYPE_EXTERNAL,
            'sort_order' => 1,
        ]);

        ProductVideo::create([
            'product_id' => $product->id,
            'url' => 'https://www.youtube.com/watch?v=first',
            'title' => 'First Video',
            'type' => ProductVideo::TYPE_EXTERNAL,
            'sort_order' => 0,
        ]);

        $product->refresh();

        $this->assertEquals('First Video', $product->videos->first()->title);
        $this->assertEquals('Second Video', $product->videos->last()->title);
    }

    public function test_youtube_embed_url_generation(): void
    {
        $video = new ProductVideo([
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'provider' => ProductVideo::PROVIDER_YOUTUBE,
        ]);

        $this->assertEquals('https://www.youtube.com/embed/dQw4w9WgXcQ', $video->getEmbedUrl());
    }

    public function test_vimeo_embed_url_generation(): void
    {
        $video = new ProductVideo([
            'url' => 'https://vimeo.com/123456789',
            'provider' => ProductVideo::PROVIDER_VIMEO,
        ]);

        $this->assertEquals('https://player.vimeo.com/video/123456789', $video->getEmbedUrl());
    }

    public function test_detect_youtube_provider(): void
    {
        $this->assertEquals(ProductVideo::PROVIDER_YOUTUBE, ProductVideo::detectProvider('https://www.youtube.com/watch?v=abc123'));
        $this->assertEquals(ProductVideo::PROVIDER_YOUTUBE, ProductVideo::detectProvider('https://youtu.be/abc123'));
    }

    public function test_detect_vimeo_provider(): void
    {
        $this->assertEquals(ProductVideo::PROVIDER_VIMEO, ProductVideo::detectProvider('https://vimeo.com/123456'));
    }

    public function test_detect_unknown_provider(): void
    {
        $this->assertNull(ProductVideo::detectProvider('https://example.com/video.mp4'));
    }

    public function test_can_upload_multiple_videos(): void
    {
        Storage::fake('do_spaces');

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $files = [
            UploadedFile::fake()->create('video1.mp4', 1024, 'video/mp4'),
            UploadedFile::fake()->create('video2.mp4', 1024, 'video/mp4'),
        ];

        $videoService = app(VideoService::class);
        $videos = $videoService->uploadMultiple(
            files: $files,
            product: $product,
            store: $this->store,
            titles: ['Video One', 'Video Two']
        );

        $this->assertCount(2, $videos);
        $this->assertEquals('Video One', $videos[0]->title);
        $this->assertEquals('Video Two', $videos[1]->title);
        $this->assertEquals(0, $videos[0]->sort_order);
        $this->assertEquals(1, $videos[1]->sort_order);
    }

    public function test_videos_cascade_delete_with_product(): void
    {
        $product = Product::factory()->create(['store_id' => $this->store->id]);

        ProductVideo::create([
            'product_id' => $product->id,
            'url' => 'https://www.youtube.com/watch?v=test',
            'title' => 'Test Video',
            'type' => ProductVideo::TYPE_EXTERNAL,
        ]);

        $this->assertDatabaseCount('product_videos', 1);

        $product->forceDelete();

        $this->assertDatabaseCount('product_videos', 0);
    }
}
