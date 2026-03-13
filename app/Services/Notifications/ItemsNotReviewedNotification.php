<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\Store;
use App\Services\Notifications\Concerns\RendersReportHtml;
use App\Services\Reports\Email\ItemsNotReviewedReport;
use Carbon\Carbon;

class ItemsNotReviewedNotification
{
    use RendersReportHtml;

    /**
     * Send the items not reviewed report for a store.
     */
    public function send(Store $store, Carbon $reportDate): void
    {
        $report = new ItemsNotReviewedReport($store, $reportDate);
        $data = $report->getData();
        $structure = $report->getStructure()->toArray();

        $reportHtml = $this->renderReportHtml($structure, $data);

        $manager = new NotificationManager($store);
        $manager->trigger(Activity::REPORTS_ITEMS_NOT_REVIEWED, [
            'date' => $data['date'],
            'report_html' => $reportHtml,
        ]);
    }
}
