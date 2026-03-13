<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\Store;
use App\Services\Notifications\Concerns\RendersReportHtml;
use App\Services\Reports\Email\DailyMemoReport;
use Carbon\Carbon;

class DailyMemoReportNotification
{
    use RendersReportHtml;

    /**
     * Send the daily memo report for a store.
     */
    public function send(Store $store, Carbon $reportDate): void
    {
        $report = new DailyMemoReport($store, $reportDate);
        $data = $report->getData();
        $structure = $report->getStructure()->toArray();

        $reportHtml = $this->renderReportHtml($structure, $data);

        $manager = new NotificationManager($store);
        $manager->trigger(Activity::REPORTS_DAILY_MEMO, [
            'date' => $data['date'],
            'report_html' => $reportHtml,
        ]);
    }
}
