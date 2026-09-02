<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zahlungsbeleg {{ $documentNumber }}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #17181c; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef0f3; }
        .toolbar { display: flex; justify-content: flex-end; gap: 10px; width: min(820px, calc(100% - 32px)); margin: 20px auto; }
        button { padding: 11px 18px; border: 0; border-radius: 8px; background: #ff6b00; color: #fff; font-weight: 700; cursor: pointer; }
        .receipt { width: min(820px, calc(100% - 32px)); min-height: 1040px; margin: 0 auto 32px; padding: 56px; background: #fff; box-shadow: 0 16px 50px rgba(0,0,0,.1); }
        header { display: flex; justify-content: space-between; gap: 30px; padding-bottom: 28px; border-bottom: 3px solid #ff6b00; }
        .brand { color: #ff6b00; font-size: 13px; font-weight: 900; letter-spacing: .18em; }
        h1 { margin: 12px 0 3px; font-size: 36px; }
        .number { color: #666; }
        .operator { max-width: 300px; text-align: right; white-space: pre-line; line-height: 1.5; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 40px; margin: 42px 0; }
        .field { padding-bottom: 12px; border-bottom: 1px solid #ddd; }
        .field span { display: block; margin-bottom: 7px; color: #777; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        .amount { margin: 40px 0; padding: 28px; border-radius: 14px; background: #fff4eb; }
        .amount span { display: block; color: #9b4a11; font-size: 12px; font-weight: 800; text-transform: uppercase; }
        .amount strong { display: block; margin-top: 8px; font-size: 38px; }
        .note { padding: 20px; border: 1px solid #ddd; border-radius: 10px; line-height: 1.6; white-space: pre-wrap; }
        footer { margin-top: 55px; padding-top: 20px; border-top: 1px solid #ddd; color: #6b6d72; font-size: 11px; line-height: 1.6; }
        @media (max-width: 650px) { .receipt { padding: 30px 22px; } header, .grid { grid-template-columns: 1fr; display: grid; } .operator { text-align: left; } }
        @media print { body { background: #fff; } .toolbar { display: none; } .receipt { width: 100%; min-height: 0; margin: 0; padding: 12mm 14mm; box-shadow: none; } }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Drucken / als PDF speichern</button></div>
    <main class="receipt">
        <header>
            <div>
                <div class="brand">LOOKDO</div>
                <h1>Zahlungsbeleg</h1>
                <div class="number">{{ $documentNumber }}</div>
            </div>
            <div class="operator"><strong>{{ $operator['name'] ?: 'LOOKDO' }}</strong>
{{ $operator['address'] }}
{{ $operator['email'] }}@if($operator['phone']) · {{ $operator['phone'] }}@endif
@if($operator['vat_id'])USt-IdNr.: {{ $operator['vat_id'] }}@endif</div>
        </header>

        <section class="grid">
            <div class="field"><span>Kunde</span><strong>{{ $subscription->tenant->name }}</strong><br>{{ $subscription->tenant->users->first()?->email }}</div>
            <div class="field"><span>Zahlungsdatum</span><strong>{{ $payment->paid_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</strong></div>
            <div class="field"><span>Leistung</span><strong>LOOKDO {{ data_get($subscription->plan->name, 'de', $subscription->plan->code) }}</strong><br>Abonnement</div>
            <div class="field"><span>Zahlungsart</span><strong>{{ match($payment->payment_method) { 'cash' => 'Barzahlung', 'bank_transfer' => 'Überweisung', 'card' => 'Kartenzahlung', 'other' => 'Sonstige Zahlung', default => 'Online-Zahlung' } }}</strong></div>
            @if($payment->reference)<div class="field"><span>Referenz</span><strong>{{ $payment->reference }}</strong></div>@endif
            <div class="field"><span>Status</span><strong>{{ $payment->status === 'paid' ? 'Bezahlt' : ucfirst($payment->status) }}</strong></div>
        </section>

        <section class="amount"><span>Erhaltener Betrag</span><strong>{{ number_format((float) $payment->amount, 2, ',', '.') }} {{ strtoupper($payment->currency) }}</strong></section>

        @if($payment->note)<section class="note"><strong>Vermerk</strong><br>{{ $payment->note }}</section>@endif

        <footer>
            Dieser Beleg bestätigt den erfassten Zahlungseingang. Er ist keine automatisch erzeugte Rechnung oder Steuerrechnung.
            @if($payment->recordedBy) Erfasst von {{ $payment->recordedBy->name }} am {{ $payment->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}. @endif
        </footer>
    </main>
</body>
</html>
