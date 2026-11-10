<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p { margin: 0 0 12px; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #d4d4d8; padding: 4px 6px; text-align: left; }
        th { background: #f4f4f5; font-size: 10px; }
        .num { text-align: right; }
        h2 { font-size: 13px; margin: 14px 0 6px; }
    </style>
</head>
<body>
    <h1>{{ $report['title'] }}</h1>
    <p>
        {{ $from ?? '' }} – {{ $to ?? '' }}
        · Generated {{ $generatedAt->format('d M Y H:i') }}
    </p>
    @foreach ($tables as $table)
        @if (!empty($table['title']))
            <h2>{{ $table['title'] }}</h2>
        @endif
        <table>
            <thead>
                <tr>
                    @foreach ($table['columns'] as $col)
                        <th>{{ $col }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($table['rows'] as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ max(1, count($table['columns'])) }}">No rows for this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach
</body>
</html>
