<?php

namespace Tests\Feature;

use App\Mail\DynamicReportMail;
use App\Models\Store;
use App\Services\StoreContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicReportMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_is_prefixed_with_store_short_name(): void
    {
        $store = Store::factory()->create(['short_name' => 'REB']);
        app(StoreContext::class)->setCurrentStore($store);

        $mailable = new DynamicReportMail(
            reportTitle: 'Cohort Analysis Data',
            description: 'Test',
            content: '',
            rowCount: 0,
            generatedAt: Carbon::now()
        );

        $mailable->assertHasSubject('REB - Report: Cohort Analysis Data');
    }

    public function test_custom_subject_is_prefixed_with_store_short_name(): void
    {
        $store = Store::factory()->create(['short_name' => 'REB']);
        app(StoreContext::class)->setCurrentStore($store);

        $mailable = (new DynamicReportMail(
            reportTitle: 'Cohort Analysis Data',
            description: 'Test',
            content: '',
            rowCount: 0,
            generatedAt: Carbon::now()
        ))->withSubject('Monthly Buys Report');

        $mailable->assertHasSubject('REB - Monthly Buys Report');
    }

    public function test_subject_does_not_double_prefix_store_short_name(): void
    {
        $store = Store::factory()->create(['short_name' => 'REB']);
        app(StoreContext::class)->setCurrentStore($store);

        $mailable = (new DynamicReportMail(
            reportTitle: 'Test',
            description: 'Test',
            content: '',
            rowCount: 0,
            generatedAt: Carbon::now()
        ))->withSubject('REB - Daily Sales Report');

        $mailable->assertHasSubject('REB - Daily Sales Report');
    }

    public function test_from_address_uses_store_email_settings(): void
    {
        $store = Store::factory()->create([
            'email_from_address' => 'reports@mystore.com',
            'email_from_name' => 'My Store Reports',
        ]);
        app(StoreContext::class)->setCurrentStore($store);

        $mailable = new DynamicReportMail(
            reportTitle: 'Test Report',
            description: 'Test',
            content: '',
            rowCount: 0,
            generatedAt: Carbon::now()
        );

        $mailable->assertFrom('reports@mystore.com', 'My Store Reports');
    }

    public function test_from_address_falls_back_to_config_when_store_has_no_email(): void
    {
        $store = Store::factory()->create([
            'name' => 'My Store',
            'email_from_address' => null,
            'email_from_name' => null,
        ]);
        app(StoreContext::class)->setCurrentStore($store);

        $mailable = new DynamicReportMail(
            reportTitle: 'Test Report',
            description: 'Test',
            content: '',
            rowCount: 0,
            generatedAt: Carbon::now()
        );

        $mailable->assertFrom(config('mail.from.address'));
    }

    public function test_reply_to_uses_store_setting(): void
    {
        $store = Store::factory()->create([
            'email_reply_to_address' => 'reply@mystore.com',
        ]);
        app(StoreContext::class)->setCurrentStore($store);

        $mailable = new DynamicReportMail(
            reportTitle: 'Test Report',
            description: 'Test',
            content: '',
            rowCount: 0,
            generatedAt: Carbon::now()
        );

        $mailable->assertHasReplyTo('reply@mystore.com');
    }
}
