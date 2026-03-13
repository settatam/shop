<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\Store;
use App\Services\Notifications\Concerns\RendersReportHtml;
use App\Services\Reports\Email\LegacyBuyReport;
use Carbon\Carbon;

class DailyBuyReportNotification
{
    use RendersReportHtml;

    /**
     * Send the daily buy report for a store.
     */
    public function send(Store $store, Carbon $reportDate): void
    {
        $report = new LegacyBuyReport($store, $reportDate);
        $data = $report->getData();
        $structure = $report->getStructure()->toArray();

        $reportHtml = $this->renderReportHtml($structure, $data);

        $manager = new NotificationManager($store);
        $manager->trigger(Activity::REPORTS_DAILY_BUY, [
            'date' => $data['date'],
            'report_html' => $reportHtml,
        ]);
    }
}
