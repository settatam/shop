<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $reportTitle }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            margin: 0;
            padding: 0;
            background-color: #f3f4f6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 32px;
            margin-top: 20px;
        }
        .header {
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .header h1 {
            color: #111827;
            font-size: 24px;
            margin: 0 0 8px 0;
        }
        .description {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }
        .meta-info {
            display: flex;
            gap: 24px;
            margin-bottom: 24px;
            padding: 16px;
            background-color: #f9fafb;
            border-radius: 6px;
        }
        .meta-item {
            font-size: 14px;
        }
        .meta-label {
            color: #6b7280;
            display: block;
            margin-bottom: 2px;
        }
        .meta-value {
            color: #111827;
            font-weight: 600;
        }
        .section-heading {
            font-size: 18px;
            font-weight: 600;
            color: #374151;
            margin: 24px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        .section-heading:first-of-type {
            margin-top: 0;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .data-table thead tr {
            background-color: #f3f4f6;
        }
        .data-table th {
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
            font-weight: 600;
            color: #374151;
        }
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            color: #1f2937;
        }
        .data-table tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .data-table td.currency {
            font-family: 'SF Mono', Monaco, 'Courier New', monospace;
            text-align: right;
        }
        .data-table td.number {
            text-align: right;
        }
        .data-table td.percentage {
            text-align: right;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-success {
            background-color: #dcfce7;
            color: #166534;
        }
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .badge-info {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .no-data {
            text-align: center;
            padding: 48px 24px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>{{ $reportTitle }}</h1>
                @if($description)
                    <p class="description">{{ $description }}</p>
                @endif
            </div>

            <div class="meta-info">
                <div class="meta-item">
                    <span class="meta-label">Results</span>
                    <span class="meta-value">{{ number_format($rowCount) }} {{ Str::plural('row', $rowCount) }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Generated</span>
                    <span class="meta-value">{{ $generatedAt->format('M j, Y g:i A') }}</span>
                </div>
            </div>

            @if(is_array($content) && isset($content['tables']))
                {{-- Multiple tables format --}}
                @foreach($content['tables'] as $table)
                    @if(isset($table['heading']))
                        <h2 class="section-heading">{{ $table['heading'] }}</h2>
                    @endif

                    @if(isset($table['rows']) && count($table['rows']) > 0)
                        <table class="data-table">
                            @if(isset($table['columns']))
                                <thead>
                                    <tr>
                                        @foreach($table['columns'] as $column)
                                            <th>{{ is_array($column) ? ($column['label'] ?? $column['key'] ?? '') : $column }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                            @endif
                            <tbody>
                                @foreach($table['rows'] as $row)
                                    <tr>
                                        @foreach($table['columns'] as $column)
                                            @php
                                                $key = is_array($column) ? ($column['key'] ?? '') : $column;
                                                $type = is_array($column) ? ($column['type'] ?? 'text') : 'text';
                                                $value = is_array($row) ? ($row[$key] ?? '') : ($row->$key ?? '');
                                            @endphp
                                            <td class="{{ $type }}">
                                                @if($type === 'currency')
                                                    @php
                                                        $currencyValue = is_array($value) ? ($value['data'] ?? 0) : $value;
                                                    @endphp
                                                    ${{ number_format((float) $currencyValue, 2) }}
                                                @elseif($type === 'percentage')
                                                    @php
                                                        $percentValue = is_array($value) ? ($value['data'] ?? 0) : $value;
                                                    @endphp
                                                    {{ number_format((float) $percentValue, 1) }}%
                                                @elseif($type === 'number')
                                                    @php
                                                        $numValue = is_array($value) ? ($value['data'] ?? 0) : $value;
                                                    @endphp
                                                    {{ number_format((int) $numValue) }}
                                                @elseif($type === 'badge')
                                                    @php
                                                        $badgeText = is_array($value) ? ($value['data'] ?? '') : $value;
                                                        $badgeVariant = is_array($value) ? ($value['variant'] ?? ($column['variant'] ?? 'info')) : ($column['variant'] ?? 'info');
                                                    @endphp
                                                    <span class="badge badge-{{ $badgeVariant }}">{{ $badgeText }}</span>
                                                @elseif($type === 'link')
                                                    @php
                                                        $linkText = is_array($value) ? ($value['data'] ?? '') : $value;
                                                        $linkHref = is_array($value) ? ($value['href'] ?? ($row['url'] ?? '#')) : ($row['url'] ?? '#');
                                                    @endphp
                                                    <a href="{{ $linkHref }}">{{ $linkText }}</a>
                                                @else
                                                    {{ is_array($value) ? ($value['data'] ?? $value['formatted'] ?? '') : $value }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="no-data">
                            <p>No data found for this section.</p>
                        </div>
                    @endif
                @endforeach
            @elseif(is_array($content) && isset($content['html_table']))
                {!! $content['html_table'] !!}
            @elseif(is_array($content) && isset($content['headers']) && isset($content['rows']))
                @if(count($content['rows']) > 0)
                    <table class="data-table">
                        <thead>
                            <tr>
                                @foreach($content['headers'] as $header)
                                    <th>{{ ucwords(str_replace('_', ' ', $header)) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($content['rows'] as $row)
                                <tr>
                                    @foreach($row as $value)
                                        <td>{{ $value }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="no-data">
                        <p>No data found for this report.</p>
                    </div>
                @endif
            @elseif(is_string($content))
                <p>{{ $content }}</p>
            @else
                <div class="no-data">
                    <p>No data available.</p>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
