<?php

namespace Tests\Feature;

use App\Mail\DynamicReportMail;
use App\Models\Role;
use App\Models\ScheduledReport;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScheduledReportTest extends TestCase
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
        app(StoreContext::class)->setCurrentStore($this->store);

        $this->actingAs($this->user);
    }

    public function test_can_view_scheduled_reports_page(): void
    {
        $response = $this->get('/settings/notifications/scheduled-reports');

        $response->assertOk();
    }

    public function test_can_create_scheduled_report(): void
    {
        $response = $this->postJson('/settings/notifications/scheduled-reports', [
            'report_type' => 'daily_sales',
            'name' => 'My Daily Sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'schedule_days' => [1, 2, 3, 4, 5], // Weekdays
            'is_enabled' => true,
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('scheduled_reports', [
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'name' => 'My Daily Sales',
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => true,
        ]);
    }

    public function test_can_create_daily_report_without_schedule_days(): void
    {
        $response = $this->postJson('/settings/notifications/scheduled-reports', [
            'report_type' => 'daily_buy',
            'recipients' => ['test@example.com'],
            'schedule_time' => '09:00',
            'timezone' => 'America/New_York',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $report = ScheduledReport::first();
        $this->assertNull($report->schedule_days);
        $this->assertTrue($report->shouldRunToday()); // Should run every day
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->postJson('/settings/notifications/scheduled-reports', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['report_type', 'recipients', 'schedule_time', 'timezone']);
    }

    public function test_validates_report_type_exists(): void
    {
        $response = $this->postJson('/settings/notifications/scheduled-reports', [
            'report_type' => 'nonexistent_report',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
        ]);

        $response->assertUnprocessable()
            ->assertJson(['success' => false, 'error' => 'Invalid report type']);
    }

    public function test_can_update_scheduled_report(): void
    {
        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'recipients' => ['old@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => true,
        ]);

        $response = $this->putJson("/settings/notifications/scheduled-reports/{$report->id}", [
            'recipients' => ['new@example.com', 'another@example.com'],
            'schedule_time' => '10:00',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $report->refresh();
        $this->assertEquals(['new@example.com', 'another@example.com'], $report->recipients);
        $this->assertEquals('10:00', $report->schedule_time);
    }

    public function test_cannot_update_other_store_report(): void
    {
        $otherStore = Store::factory()->create();
        $report = ScheduledReport::create([
            'store_id' => $otherStore->id,
            'report_type' => 'daily_sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => true,
        ]);

        $response = $this->putJson("/settings/notifications/scheduled-reports/{$report->id}", [
            'schedule_time' => '10:00',
        ]);

        $response->assertForbidden();
    }

    public function test_can_delete_scheduled_report(): void
    {
        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => true,
        ]);

        $response = $this->deleteJson("/settings/notifications/scheduled-reports/{$report->id}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('scheduled_reports', ['id' => $report->id]);
    }

    public function test_can_toggle_scheduled_report(): void
    {
        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => true,
        ]);

        $response = $this->postJson("/settings/notifications/scheduled-reports/{$report->id}/toggle");

        $response->assertOk()
            ->assertJson(['success' => true, 'is_enabled' => false]);

        $report->refresh();
        $this->assertFalse($report->is_enabled);

        // Toggle back on
        $response = $this->postJson("/settings/notifications/scheduled-reports/{$report->id}/toggle");
        $response->assertJson(['is_enabled' => true]);
    }

    public function test_can_send_test_report(): void
    {
        Mail::fake();

        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => true,
        ]);

        $response = $this->postJson("/settings/notifications/scheduled-reports/{$report->id}/test");

        $response->assertOk()
            ->assertJson(['success' => true]);

        Mail::assertSent(DynamicReportMail::class, function ($mail) {
            return $mail->hasTo('test@example.com');
        });
    }

    public function test_should_run_today_respects_schedule_days(): void
    {
        // Create a report that only runs on Mondays (day 1)
        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'schedule_days' => [1], // Monday only
            'is_enabled' => true,
        ]);

        // The result depends on what day it is today
        $today = now('America/New_York')->dayOfWeek;

        if ($today === 1) {
            $this->assertTrue($report->shouldRunToday());
        } else {
            $this->assertFalse($report->shouldRunToday());
        }
    }

    public function test_disabled_report_should_not_run(): void
    {
        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => false,
        ]);

        $this->assertFalse($report->shouldRunToday());
    }

    public function test_display_name_uses_custom_name(): void
    {
        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'name' => 'My Custom Report',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => true,
        ]);

        $this->assertEquals('My Custom Report', $report->display_name);
    }

    public function test_display_name_falls_back_to_report_type(): void
    {
        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => true,
        ]);

        $this->assertEquals('Daily Sales Report', $report->display_name);
    }

    public function test_schedule_description_for_daily(): void
    {
        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'is_enabled' => true,
        ]);

        $this->assertStringContainsString('Daily', $report->schedule_description);
        $this->assertStringContainsString('8:00 AM', $report->schedule_description);
    }

    public function test_schedule_description_for_specific_days(): void
    {
        $report = ScheduledReport::create([
            'store_id' => $this->store->id,
            'report_type' => 'daily_sales',
            'recipients' => ['test@example.com'],
            'schedule_time' => '08:00',
            'timezone' => 'America/New_York',
            'schedule_days' => [1, 2, 3, 4, 5], // Weekdays
            'is_enabled' => true,
        ]);

        $description = $report->schedule_description;
        $this->assertStringContainsString('Mon', $description);
        $this->assertStringContainsString('Fri', $description);
        $this->assertStringNotContainsString('Sun', $description);
    }
}
