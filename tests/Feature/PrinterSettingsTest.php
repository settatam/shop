<?php

namespace Tests\Feature;

use App\Models\PrinterSetting;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterSettingsTest extends TestCase
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

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    public function test_can_list_printer_settings(): void
    {
        $this->actingAs($this->user);

        PrinterSetting::factory()->count(3)->create(['store_id' => $this->store->id]);

        $response = $this->get('/settings/printers');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('settings/PrinterSettings')
                ->has('printerSettings', 3)
            );
    }

    public function test_can_create_printer_setting(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/settings/printers', [
            'name' => 'Main Label Printer',
            'printer_type' => 'zebra',
            'top_offset' => 30,
            'left_offset' => 10,
            'right_offset' => 5,
            'text_size' => 20,
            'barcode_height' => 50,
            'line_height' => 25,
            'label_width' => 406,
            'label_height' => 203,
            'is_default' => true,
        ]);

        $response->assertRedirect('/settings/printers');

        $this->assertDatabaseHas('printer_settings', [
            'store_id' => $this->store->id,
            'name' => 'Main Label Printer',
            'printer_type' => 'zebra',
            'is_default' => true,
        ]);
    }

    public function test_first_printer_setting_is_automatically_default(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/settings/printers', [
            'name' => 'First Printer',
            'printer_type' => 'zebra',
            'top_offset' => 30,
            'left_offset' => 0,
            'right_offset' => 0,
            'text_size' => 20,
            'barcode_height' => 50,
            'line_height' => 25,
            'label_width' => 406,
            'label_height' => 203,
            'is_default' => false,
        ]);

        $response->assertRedirect('/settings/printers');

        $this->assertDatabaseHas('printer_settings', [
            'store_id' => $this->store->id,
            'name' => 'First Printer',
            'is_default' => true,
        ]);
    }

    public function test_can_update_printer_setting(): void
    {
        $this->actingAs($this->user);

        $setting = PrinterSetting::factory()->create(['store_id' => $this->store->id]);

        $response = $this->put("/settings/printers/{$setting->id}", [
            'name' => 'Updated Printer',
            'printer_type' => 'godex',
            'top_offset' => 40,
            'left_offset' => 15,
            'right_offset' => 10,
            'text_size' => 22,
            'barcode_height' => 60,
            'line_height' => 30,
            'label_width' => 500,
            'label_height' => 250,
            'is_default' => true,
        ]);

        $response->assertRedirect('/settings/printers');

        $this->assertDatabaseHas('printer_settings', [
            'id' => $setting->id,
            'name' => 'Updated Printer',
            'printer_type' => 'godex',
            'top_offset' => 40,
        ]);
    }

    public function test_can_delete_printer_setting(): void
    {
        $this->actingAs($this->user);

        $setting = PrinterSetting::factory()->create(['store_id' => $this->store->id]);

        $response = $this->delete("/settings/printers/{$setting->id}");

        $response->assertRedirect('/settings/printers');
        $this->assertDatabaseMissing('printer_settings', ['id' => $setting->id]);
    }

    public function test_can_make_printer_setting_default(): void
    {
        $this->actingAs($this->user);

        $setting1 = PrinterSetting::factory()->default()->create(['store_id' => $this->store->id]);
        $setting2 = PrinterSetting::factory()->create(['store_id' => $this->store->id]);

        $response = $this->post("/settings/printers/{$setting2->id}/make-default");

        $response->assertRedirect('/settings/printers');

        $setting1->refresh();
        $setting2->refresh();

        $this->assertFalse($setting1->is_default);
        $this->assertTrue($setting2->is_default);
    }

    public function test_cannot_access_other_store_printer_settings(): void
    {
        $this->actingAs($this->user);

        $otherStore = Store::factory()->create();
        $otherSetting = PrinterSetting::factory()->create(['store_id' => $otherStore->id]);

        $response = $this->put("/settings/printers/{$otherSetting->id}", [
            'name' => 'Hacked Printer',
            'printer_type' => 'zebra',
            'top_offset' => 30,
            'left_offset' => 0,
            'right_offset' => 0,
            'text_size' => 20,
            'barcode_height' => 50,
            'line_height' => 25,
            'label_width' => 406,
            'label_height' => 203,
        ]);

        $response->assertStatus(404);
    }

    public function test_name_must_be_unique_per_store(): void
    {
        $this->actingAs($this->user);

        PrinterSetting::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Main Printer',
        ]);

        $response = $this->post('/settings/printers', [
            'name' => 'Main Printer',
            'printer_type' => 'zebra',
            'top_offset' => 30,
            'left_offset' => 0,
            'right_offset' => 0,
            'text_size' => 20,
            'barcode_height' => 50,
            'line_height' => 25,
            'label_width' => 406,
            'label_height' => 203,
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_deleting_default_sets_another_as_default(): void
    {
        $this->actingAs($this->user);

        $setting1 = PrinterSetting::factory()->default()->create(['store_id' => $this->store->id]);
        $setting2 = PrinterSetting::factory()->create(['store_id' => $this->store->id]);

        $this->delete("/settings/printers/{$setting1->id}");

        $setting2->refresh();
        $this->assertTrue($setting2->is_default);
    }
}
