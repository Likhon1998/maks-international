<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }} · {{ $site->siteName() }}</title>
    <style>
        @page { margin: 10mm 12mm; size: A4 portrait; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            color: #0f172a;
            font-size: 10px;
            line-height: 1.35;
        }
        .inv-doc { width: 100%; }
        .inv-doc-band {
            height: 5px;
            background: #4f46e5;
            margin: 0 0 10px;
        }
        .inv-doc-head {
            display: table;
            width: 100%;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1.5px solid #0f172a;
        }
        .inv-doc-head-left, .inv-doc-head-right { display: table-cell; vertical-align: top; width: 50%; }
        .inv-doc-head-right { text-align: right; }
        .inv-doc-brand { font-size: 18px; font-weight: 700; color: #312e81; }
        .inv-doc-muted { color: #64748b; font-size: 9px; }
        .inv-doc-title { font-size: 13px; font-weight: 700; margin: 0 0 2px; text-transform: uppercase; letter-spacing: .04em; color: #312e81; }
        .inv-doc-number { font-size: 12px; font-weight: 700; margin-bottom: 3px; }
        .inv-doc-strong { font-size: 12px; font-weight: 700; }
        .inv-doc-label {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #64748b;
            margin-bottom: 2px;
        }
        .inv-doc-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            background: #e2e8f0;
            color: #334155;
        }
        .inv-doc-badge-paid { background: #dcfce7; color: #166534; }
        .inv-doc-badge-partial { background: #fef3c7; color: #92400e; }
        .inv-doc-badge-issued { background: #e0e7ff; color: #3730a3; }
        .inv-doc-info { width: 100%; margin-bottom: 8px; border-collapse: collapse; }
        .inv-doc-info-bill, .inv-doc-info-meta { vertical-align: top; width: 50%; }
        .inv-doc-info-meta { text-align: right; }
        .inv-doc-meta-table { margin-left: auto; border-collapse: collapse; }
        .inv-doc-meta-table td { padding: 1px 0 1px 10px; font-size: 9px; }
        .inv-doc-meta-table td:first-child { color: #64748b; font-weight: 700; text-align: right; padding-right: 6px; }
        .inv-doc-settle { width: 100%; border-collapse: separate; border-spacing: 4px 0; margin: 0 0 6px; }
        .inv-doc-settle td {
            width: 33%;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 5px 7px;
            vertical-align: top;
        }
        .inv-doc-settle span { display: block; font-size: 7px; font-weight: 700; text-transform: uppercase; color: #64748b; }
        .inv-doc-settle strong { font-size: 11px; font-weight: 700; }
        .inv-doc-settle .is-rest { background: #fff7ed; border-color: #fcd34d; }
        .inv-doc-settle .is-rest strong { color: #b45309; }
        .inv-doc-ack {
            margin: 0 0 8px;
            padding: 4px 7px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 4px;
            font-size: 9px;
            color: #92400e;
        }
        .inv-doc-lines { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .inv-doc-lines th, .inv-doc-lines td {
            border-bottom: 1px solid #e2e8f0;
            padding: 3px 4px;
            text-align: left;
            font-size: 9px;
        }
        .inv-doc-lines th {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: #64748b;
            background: #f1f5f9;
        }
        .inv-doc-lines .num, .inv-doc-pay .num, .inv-doc-totals .num { text-align: right; }
        .center { text-align: center; padding: 8px !important; }
        .inv-doc-bottom { width: 100%; border-collapse: collapse; margin-top: 2px; }
        .inv-doc-pay-col { width: 62%; vertical-align: top; padding-right: 8px; }
        .inv-doc-tot-col { width: 38%; vertical-align: top; }
        .inv-doc-pay { width: 100%; border-collapse: collapse; }
        .inv-doc-pay th, .inv-doc-pay td {
            border-bottom: 1px solid #e2e8f0;
            padding: 2px 3px;
            font-size: 8px;
            text-align: left;
        }
        .inv-doc-pay th {
            text-transform: uppercase;
            color: #64748b;
            background: #f8fafc;
            font-size: 7px;
        }
        .inv-doc-totals { width: 100%; border-collapse: collapse; background: transparent; border: 0; }
        .inv-doc-totals td { padding: 3px 0; font-size: 9px; }
        .inv-doc-totals .grand td {
            border-top: 1.5px solid #0f172a;
            font-size: 11px;
            font-weight: 700;
            padding-top: 6px;
        }
        .inv-doc-totals .bal td { color: #b45309; font-weight: 700; }
        .inv-doc-notes {
            margin-top: 6px;
            padding: 4px 6px;
            background: #f8fafc;
            border-radius: 3px;
            font-size: 8px;
            color: #475569;
        }
        .inv-doc-footer {
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px solid #e2e8f0;
            font-size: 8px;
            color: #64748b;
        }
    </style>
</head>
<body>
@include('admin.invoices.partials.document-body', [
    'invoice' => $invoice,
    'documentKind' => $documentKind ?? 'Sales Invoice',
])
</body>
</html>
