<style>
    body { color: #172e45; font-size: 9pt; line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }
    .banner td { background: #eaf2f6; padding: 2mm 3mm; border-bottom: 1px solid #b2c8d6; }
    .commission { font-size: 8pt; color: #345772; font-weight: bold; }
    .title { font-size: 15pt; font-weight: bold; }
    .copy-type { text-align: right; vertical-align: middle; font-size: 9pt; font-weight: bold; color: #175d6b; }
    .meta { margin: 2mm 0; }
    .identity { margin: 2mm 0 3mm; }
    .items th, .items td { border: 1px solid #bbccd9; padding: 1.5mm 2mm; }
    .items th { text-align: left; background: #eaf2f6; }
    .items .center { text-align: center; vertical-align: middle; }
    .totals { margin: 1.2mm 0 0; }
    .operator { margin-top: 2.5mm; }
    .operator-note { font-size: 8pt; color: #495e72; }
    .signatures td { vertical-align: top; padding: 11mm 2mm 1mm 0; font-size: 8pt; }
    .signatures .return-signature { padding-top: 13mm; }
    .signature-line { color: #536b80; line-height: 1.2; }
    .footer-space { height: 4mm; line-height: 4mm; }
    .footer td { border-top: 1px solid #bdcbd9; padding-top: 1.4mm; color: #495e72; font-size: 7.5pt; }
    .tear { border-top: 1px dashed #8194a6; margin: 6mm 0; }
</style>
@foreach (['Member copy', 'Library copy'] as $copy)
    @if (! $loop->first)
        @if ($loans->count() > 3)<pagebreak />@else<div class="tear">&nbsp;</div>@endif
    @endif
    <table class="banner">
        <tr>
            <td><div class="commission">Bangladesh Public Service Commission Library</div><div class="title">{{ $title }}</div></td>
            <td class="copy-type">{{ $copy }}</td>
        </tr>
    </table>
    <div class="meta">
        <strong>{{ $reference }}</strong>
        @if ($isReturn)
            &nbsp; | &nbsp; Issue reference {{ $issueReference }}
        @endif
        &nbsp; | &nbsp; Issued {{ \App\Support\Ui::date($issuedAt, 'd M Y, h:i A') }} (Bangladesh time)
        @if ($isReturn)
            <br><strong>Returned:</strong> {{ \App\Support\Ui::date($loan->returned_at, 'd M Y, h:i A') }} (Bangladesh time)
        @endif
    </div>
    <div class="identity">
        Issued To: {{ $membershipNumber ?? 'Not recorded' }} - <strong>{{ $memberName }}, {{ $memberDesignation ?? 'Not recorded' }}</strong>, {{ $memberUnit }}
    </div>
    <table class="items">
        <thead><tr><th class="center" style="width: 10%;">Sr. No</th><th>Book title</th><th class="center" style="width: 22%;">Accession</th><th class="center" style="width: 12%;">Quantity</th></tr></thead>
        <tbody>
        @foreach ($loans as $item)
            <tr>
                <td class="center">{{ $loop->iteration }}</td>
                <td>{{ $item->title_snapshot ?? $item->book?->title_bn ?? 'Unavailable book' }}</td>
                <td class="center">{{ $item->accession_snapshot ?? $item->book?->accession_number ?? 'Not recorded' }}</td>
                <td class="center">{{ $item->quantity }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="totals"><strong>Books:</strong> {{ $loans->count() }} &nbsp; | &nbsp; <strong>Copies:</strong> {{ $loans->sum('quantity') }}</div>
    <div class="operator">
        @if ($isReturn)
            Return Recorded By:
            @if ($loan->returned_by)
                {{ $loan->returned_by }} - <strong>{{ $loan->return_receiver_name }}, {{ $loan->return_receiver_designation }}</strong>
                <span class="operator-note">[On behalf of the library]</span>
            @else
                Not recorded
            @endif
        @else
            Issued By: {{ $issuerId ?? 'Not recorded' }} - <strong>{{ $issuerName ?? 'Not recorded' }}, {{ $issuerDesignation ?? 'Not recorded' }}</strong>
            <span class="operator-note">[On behalf of the library]</span>
        @endif
    </div>
    <table class="signatures">
        @unless ($isReturn)
            <tr>
                <td style="width: 54%;"><div class="signature-line">_______________________________</div>Member / authorized representative signature</td>
                <td><div class="signature-line">_______________________________</div>Received by (write name)</td>
            </tr>
        @endunless
        @if ($isReturn)
            <tr>
                <td style="width: 52%;">&nbsp;</td>
                <td><div class="signature-line">_______________________________</div>Return received by (Librarian / Library Staff signature)</td>
            </tr>
        @elseif ($loop->last)
            <tr>
                <td class="return-signature"><div class="signature-line">_______________________________</div>Return received by (Librarian / Library Staff signature)</td>
                <td class="return-signature"><div class="signature-line">_______________________________</div>Return date</td>
            </tr>
        @endif
    </table>
    <div class="footer-space">&nbsp;</div>
    <table class="footer"><tr>
        <td>Printed {{ \App\Support\Ui::date($printedAt, 'd M Y, h:i:s A') }} (Bangladesh time)</td>
        <td style="text-align:right;">Software Developed By: IT Section, BPSC</td>
    </tr></table>
@endforeach
