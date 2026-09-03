<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rechnung {{ $invoice->invoice_number }}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #17181c; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef0f3; }
        .toolbar { display: flex; justify-content: flex-end; width: min(820px, calc(100% - 32px)); margin: 20px auto; }
        button { padding: 11px 18px; border: 0; border-radius: 8px; background: #ff6b00; color: #fff; font-weight: 700; cursor: pointer; }
        .invoice { width: min(820px, calc(100% - 32px)); min-height: 1040px; margin: 0 auto 32px; padding: 56px; background: #fff; box-shadow: 0 16px 50px rgba(0,0,0,.1); }
        header { display: flex; justify-content: space-between; gap: 30px; padding-bottom: 28px; border-bottom: 3px solid #ff6b00; }
        .brand { color: #ff6b00; font-size: 13px; font-weight: 900; letter-spacing: .18em; }
        h1 { margin: 12px 0 3px; font-size: 36px; }
        .number,.muted { color: #666; }
        .operator { max-width: 310px; text-align: right; white-space: pre-line; line-height: 1.5; }
        .addresses { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; margin: 42px 0; }
        .addresses div { white-space: pre-line; line-height: 1.55; }
        .addresses span,.meta span { display: block; margin-bottom: 7px; color: #777; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        .meta { display: grid; grid-template-columns: repeat(3,1fr); gap: 18px; margin-bottom: 35px; }
        .meta div { padding-bottom: 12px; border-bottom: 1px solid #ddd; }
        table { width: 100%; border-collapse: collapse; margin: 25px 0 35px; }
        th,td { padding: 14px 10px; border-bottom: 1px solid #ddd; text-align: right; }
        th:first-child,td:first-child { text-align: left; }
        tfoot th,tfoot td { font-size: 16px; }
        tfoot tr:last-child { background: #fff4eb; color: #8d3d08; }
        .status { display: inline-block; margin-top: 12px; padding: 7px 11px; border-radius: 20px; background: #f1f2f4; font-size: 12px; font-weight: 700; }
        .status.paid { background: #e3f5ea; color: #146b39; }
        .status.void { background: #f5e3e3; color: #8b2020; }
        .note { margin-top: 28px; padding: 18px; border: 1px solid #ddd; border-radius: 10px; line-height: 1.6; white-space: pre-wrap; }
        footer { margin-top: 55px; padding-top: 20px; border-top: 1px solid #ddd; color: #6b6d72; font-size: 11px; line-height: 1.6; }
        @media (max-width:650px) { .invoice { padding: 30px 22px; } header,.addresses,.meta { grid-template-columns:1fr;display:grid; } .operator { text-align:left; } }
        @media print { body { background:#fff; } .toolbar { display:none; } .invoice { width:100%;min-height:0;margin:0;padding:12mm 14mm;box-shadow:none; } }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Drucken / als PDF speichern</button></div>
    <main class="invoice">
        <header>
            <div><div class="brand">LOOKDO</div><h1>Rechnung</h1><div class="number">{{ $invoice->invoice_number }}</div><span class="status {{ $invoice->status }}">{{ match($invoice->status) { 'paid' => 'Bezahlt', 'void' => 'Storniert', 'overdue' => 'Überfällig', default => 'Offen' } }}</span></div>
            <div class="operator"><strong>{{ $operator['name'] ?: 'LOOKDO' }}</strong>
{{ $operator['address'] }}
{{ $operator['email'] }}@if($operator['phone']) · {{ $operator['phone'] }}@endif
@if($operator['vat_id'])USt-IdNr.: {{ $operator['vat_id'] }}@endif
@if($operator['register']){{ $operator['register'] }}@endif</div>
        </header>

        <section class="addresses">
            <div><span>Rechnung an</span><strong>{{ $invoice->recipient_name }}</strong>
{{ $invoice->recipient_address }}</div>
            <div><span>Kontakt</span>{{ $invoice->tenant->users->first()?->name }}
{{ $invoice->tenant->users->first()?->email }}</div>
        </section>

        <section class="meta">
            <div><span>Rechnungsdatum</span><strong>{{ $invoice->issue_date->format('d.m.Y') }}</strong></div>
            <div><span>Fällig am</span><strong>{{ $invoice->due_date?->format('d.m.Y') ?: 'sofort' }}</strong></div>
            <div><span>Leistungszeitraum</span><strong>{{ $invoice->period_start?->format('d.m.Y') ?: '—' }} – {{ $invoice->period_end?->format('d.m.Y') ?: '—' }}</strong></div>
        </section>

        <table>
            <thead><tr><th>Leistung</th><th>Netto</th><th>USt.</th><th>Gesamt</th></tr></thead>
            <tbody><tr><td><strong>{{ $invoice->description }}</strong><br><span class="muted">LOOKDO {{ data_get($invoice->subscription?->plan?->name, 'de', $invoice->subscription?->plan?->code) }}</span></td><td>{{ number_format((float) $invoice->amount_net, 2, ',', '.') }} {{ $invoice->currency }}</td><td>{{ number_format((float) $invoice->tax_rate, 2, ',', '.') }} %<br>{{ number_format((float) $invoice->tax_amount, 2, ',', '.') }} {{ $invoice->currency }}</td><td>{{ number_format((float) $invoice->amount_total, 2, ',', '.') }} {{ $invoice->currency }}</td></tr></tbody>
            <tfoot><tr><th colspan="3">Rechnungsbetrag</th><td><strong>{{ number_format((float) $invoice->amount_total, 2, ',', '.') }} {{ $invoice->currency }}</strong></td></tr></tfoot>
        </table>

        @if($invoice->notes)<section class="note">{{ $invoice->notes }}</section>@endif
        <footer>Bitte geben Sie bei der Zahlung die Rechnungsnummer {{ $invoice->invoice_number }} an. Dieses Dokument wurde durch LOOKDO erstellt.</footer>
    </main>
</body>
</html>
