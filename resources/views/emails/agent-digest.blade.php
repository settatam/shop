<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Agent Digest</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 { color: #1a1a1a; font-size: 24px; margin-bottom: 20px; }
        h2 { color: #444; font-size: 18px; margin-top: 30px; margin-bottom: 15px; }
        .stats { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px; }
        .stat-box {
            background: #f5f5f5;
            padding: 15px 20px;
            border-radius: 8px;
            min-width: 120px;
        }
        .stat-value { font-size: 28px; font-weight: bold; color: #1a1a1a; }
        .stat-label { font-size: 12px; color: #666; text-transform: uppercase; }
        .highlight {
            background: #fff9e6;
            border-left: 4px solid #f5a623;
            padding: 12px 16px;
            margin-bottom: 10px;
        }
        .agent-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .agent-name { font-weight: 600; }
        .success { color: #22c55e; }
        .failed { color: #ef4444; }
        .pending { color: #f59e0b; }
        .btn {
            display: inline-block;
            background: #3b82f6;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <h1>Agent Digest - {{ $store->name }}</h1>

    <p>Here's a summary of your AI agents' activity for the {{ $digest['period'] }} period.</p>

    <div class="stats">
        <div class="stat-box">
            <div class="stat-value">{{ $digest['runs_total'] }}</div>
            <div class="stat-label">Total Runs</div>
        </div>
        <div class="stat-box">
            <div class="stat-value success">{{ $digest['runs_successful'] }}</div>
            <div class="stat-label">Successful</div>
        </div>
        <div class="stat-box">
            <div class="stat-value failed">{{ $digest['runs_failed'] }}</div>
            <div class="stat-label">Failed</div>
        </div>
        <div class="stat-box">
            <div class="stat-value pending">{{ $digest['actions_pending'] }}</div>
            <div class="stat-label">Pending Approval</div>
        </div>
    </div>

    <h2>Actions Summary</h2>
    <ul>
        <li><strong>{{ $digest['actions_executed'] }}</strong> actions executed automatically</li>
        <li><strong>{{ $digest['actions_pending'] }}</strong> actions awaiting your approval</li>
        <li><strong>{{ $digest['actions_rejected'] }}</strong> actions rejected</li>
    </ul>

    @if(count($digest['highlights']) > 0)
    <h2>Highlights</h2>
    @foreach($digest['highlights'] as $highlight)
    <div class="highlight">
        {{ $highlight['message'] }}
    </div>
    @endforeach
    @endif

    @if(count($digest['agent_summaries']) > 0)
    <h2>Agent Performance</h2>
    @foreach($digest['agent_summaries'] as $summary)
    <div class="agent-summary">
        <span class="agent-name">{{ $summary['agent_name'] }}</span>
        <br>
        <small>
            {{ $summary['total_runs'] }} runs
            (<span class="success">{{ $summary['successful'] }} successful</span>,
            <span class="failed">{{ $summary['failed'] }} failed</span>)
        </small>
    </div>
    @endforeach
    @endif

    @if($digest['actions_pending'] > 0)
    <a href="{{ config('app.url') }}/agents/actions" class="btn">
        Review Pending Actions ({{ $digest['actions_pending'] }})
    </a>
    @endif

    <div class="footer">
        <p>This digest was generated at {{ now()->format('M j, Y g:i A') }}</p>
        <p>You're receiving this because you're the owner of {{ $store->name }} on Shopmata.</p>
    </div>
</body>
</html>
