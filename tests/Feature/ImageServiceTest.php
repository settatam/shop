<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Image;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Image\ImageService;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageServiceTest extends TestCase
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

    public function test_can_upload_image_for_product(): void
    {
        Storage::fake('do_spaces');

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $file = UploadedFile::fake()->image('test-image.jpg', 800, 600);

        $imageService = app(ImageService::class);
        $image = $imageService->create(
            file: $file,
            imageable: $product,
            store: $this->store,
            folder: 'products',
            altText: 'Test Image'
        );

        $this->assertInstanceOf(Image::class, $image);
        $this->assertEquals($product->id, $image->imageable_id);
        $this->assertEquals(Product::class, $image->imageable_type);
        $this->assertEquals($this->store->id, $image->store_id);
        $this->assertEquals('Test Image', $image->alt_text);
        $this->assertStringContainsString('test-store/products/', $image->path);
        $this->assertNotNull($image->url);
        $this->assertNotNull($image->thumbnail_url);
    }

    public function test_can_upload_multiple_images(): void
    {
        Storage::fake('do_spaces');

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $files = [
            UploadedFile::fake()->image('image1.jpg', 800, 600),
            UploadedFile::fake()->image('image2.jpg', 800, 600),
            UploadedFile::fake()->image('image3.jpg', 800, 600),
        ];

        $imageService = app(ImageService::class);
        $images = $imageService->uploadMultiple(
            files: $files,
            imageable: $product,
            store: $this->store,
            folder: 'products',
            setFirstAsPrimary: true
        );

        $this->assertCount(3, $images);
        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);
        $this->assertFalse($images[2]->is_primary);

        // Sort order should be sequential
        $this->assertEquals(0, $images[0]->sort_order);
        $this->assertEquals(1, $images[1]->sort_order);
        $this->assertEquals(2, $images[2]->sort_order);
    }

    public function test_can_delete_image(): void
    {
        Storage::fake('do_spaces');

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $file = UploadedFile::fake()->image('test-image.jpg', 800, 600);

        $imageService = app(ImageService::class);
        $image = $imageService->create(
            file: $file,
            imageable: $product,
            store: $this->store,
            folder: 'products'
        );

        $imageId = $image->id;
        $path = $image->path;

        $imageService->delete($image);

        $this->assertDatabaseMissing('images', ['id' => $imageId]);
        Storage::disk('do_spaces')->assertMissing($path);
    }

    public function test_product_has_images_relationship(): void
    {
        Storage::fake('do_spaces');

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $file = UploadedFile::fake()->image('test-image.jpg', 800, 600);

        $imageService = app(ImageService::class);
        $imageService->create(
            file: $file,
            imageable: $product,
            store: $this->store,
            folder: 'products',
            isPrimary: true
        );

        $product->refresh();

        $this->assertCount(1, $product->images);
        $this->assertInstanceOf(Image::class, $product->images->first());
    }

    public function test_can_set_primary_image(): void
    {
        Storage::fake('do_spaces');

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $imageService = app(ImageService::class);

        $image1 = $imageService->create(
            file: UploadedFile::fake()->image('image1.jpg'),
            imageable: $product,
            store: $this->store,
            folder: 'products',
            isPrimary: true
        );

        $image2 = $imageService->create(
            file: UploadedFile::fake()->image('image2.jpg'),
            imageable: $product,
            store: $this->store,
            folder: 'products'
        );

        $this->assertTrue($image1->fresh()->is_primary);
        $this->assertFalse($image2->fresh()->is_primary);

        $product->setPrimaryImage($image2);

        $this->assertFalse($image1->fresh()->is_primary);
        $this->assertTrue($image2->fresh()->is_primary);
    }

    public function test_images_organized_by_store_slug(): void
    {
        Storage::fake('do_spaces');

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $file = UploadedFile::fake()->image('test-image.jpg', 800, 600);

        $imageService = app(ImageService::class);
        $image = $imageService->create(
            file: $file,
            imageable: $product,
            store: $this->store,
            folder: 'products'
        );

        // Path should start with store slug
        $this->assertStringStartsWith('test-store/', $image->path);
    }

    public function test_can_upload_image_for_customer(): void
    {
        Storage::fake('do_spaces');

        $customer = Customer::factory()->create(['store_id' => $this->store->id]);

        $file = UploadedFile::fake()->image('customer-photo.jpg', 400, 400);

        $imageService = app(ImageService::class);
        $image = $imageService->create(
            file: $file,
            imageable: $customer,
            store: $this->store,
            folder: 'customers'
        );

        $this->assertEquals($customer->id, $image->imageable_id);
        $this->assertEquals(Customer::class, $image->imageable_type);
        $this->assertStringContainsString('/customers/', $image->path);
    }

    public function test_stores_image_metadata(): void
    {
        Storage::fake('do_spaces');

        $product = Product::factory()->create(['store_id' => $this->store->id]);

        $file = UploadedFile::fake()->image('test-image.jpg', 1024, 768);

        $imageService = app(ImageService::class);
        $image = $imageService->create(
            file: $file,
            imageable: $product,
            store: $this->store,
            folder: 'products'
        );

        $this->assertEquals('image/jpeg', $image->mime_type);
        $this->assertNotNull($image->size);
        $this->assertEquals(1024, $image->width);
        $this->assertEquals(768, $image->height);
        $this->assertEquals('do_spaces', $image->disk);
    }
}
