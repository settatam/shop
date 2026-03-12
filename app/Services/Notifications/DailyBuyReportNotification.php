<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\Store;
use App\Services\Reports\Email\LegacyBuyReport;
use Carbon\Carbon;

class DailyBuyReportNotification
{
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

    /**
     * Render the report structure and data into HTML tables.
     */
    protected function renderReportHtml(array $structure, array $data): string
    {
        $html = '';

        foreach ($structure['tables'] ?? [] as $table) {
            $dataKey = $table['data_key'] ?? $table['name'];
            $rows = $data[$dataKey] ?? [];

            $html .= '<h4 style="margin-top: 25px; margin-bottom: 10px;">'
                .htmlspecialchars($table['heading'] ?? $table['name'])
                .'</h4>';

            if (empty($rows)) {
                $html .= '<p style="color: #666;">No data available.</p>';

                continue;
            }

            $html .= $this->renderTable($table['columns'] ?? [], $rows);
        }

        return $html;
    }

    /**
     * Render a single HTML table from columns and rows.
     */
    protected function renderTable(array $columns, array $rows): string
    {
        $html = '<table style="width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 12px;">';

        // Header
        $html .= '<thead><tr>';
        foreach ($columns as $col) {
            $align = in_array($col['type'] ?? 'text', ['currency', 'number', 'percentage']) ? 'right' : 'left';
            $html .= '<th style="padding: 8px 12px; text-align: '.$align.'; border: 1px solid #ddd; background: #f9f7f6; white-space: nowrap;">'
                .htmlspecialchars($col['label'] ?? $col['key'])
                .'</th>';
        }
        $html .= '</tr></thead>';

        // Body
        $html .= '<tbody>';
        foreach ($rows as $row) {
            $isTotal = $row['_is_total'] ?? false;
            $trStyle = $isTotal ? ' style="font-weight: bold; background: #f9f7f6;"' : '';
            $html .= '<tr'.$trStyle.'>';

            foreach ($columns as $col) {
                $key = $col['key'];
                $value = $row[$key] ?? '';
                $align = in_array($col['type'] ?? 'text', ['currency', 'number', 'percentage']) ? 'right' : 'left';

                $displayValue = $this->formatCellValue($value, $col['type'] ?? 'text');
                $html .= '<td style="padding: 8px 12px; text-align: '.$align.'; border: 1px solid #ddd; white-space: nowrap;">'
                    .$displayValue
                    .'</td>';
            }

            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Format a cell value for HTML display.
     */
    protected function formatCellValue(mixed $value, string $type): string
    {
        if (is_array($value)) {
            // Handle link type: {data, href}
            if (isset($value['href']) && $value['href']) {
                return '<a href="'.htmlspecialchars($value['href']).'" style="color: #2563eb; text-decoration: none;">'
                    .htmlspecialchars($value['data'] ?? '')
                    .'</a>';
            }

            // Handle badge type: {data, variant}
            if (isset($value['variant'])) {
                $colors = [
                    'success' => '#059669',
                    'info' => '#2563eb',
                    'warning' => '#d97706',
                    'danger' => '#dc2626',
                    'secondary' => '#6b7280',
                ];
                $color = $colors[$value['variant']] ?? '#6b7280';

                return '<span style="color: '.$color.';">'.htmlspecialchars($value['data'] ?? '').'</span>';
            }

            // Handle formatted value: {data, formatted}
            if (isset($value['formatted'])) {
                return htmlspecialchars($value['formatted']);
            }

            return htmlspecialchars($value['data'] ?? '');
        }

        return htmlspecialchars((string) $value);
    }
}
