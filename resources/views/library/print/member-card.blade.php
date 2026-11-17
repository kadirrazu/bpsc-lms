@if ($side === 'front')
    @php
        $emailLength = mb_strlen($member->email);
        $compact = $emailLength > 42 || mb_strlen($member->name) > 40;
        $emailFontSize = $emailLength > 114 ? 4.5 : ($emailLength > 42 ? 5.5 : 7);
        $emailLines = $emailLength > 42 ? mb_str_split($member->email, $emailLength > 114 ? 45 : 38) : [$member->email];
    @endphp
    <table style="border-collapse:collapse;width:100%;color:#19314a;">
        <tr>
            <td colspan="2" style="background:#e9f2f6;text-align:center;padding:{{ $compact ? '1.2' : '2' }}mm 1mm;line-height:1.25;">
                <div style="font-size:7.4pt;font-weight:bold;">Bangladesh Public Service Commission</div>
                <div style="font-size:10.5pt;font-weight:bold;color:#175d6b;">Library Membership Card</div>
            </td>
        </tr>
        <tr>
            <td style="width:74%;vertical-align:middle;padding:2mm 1mm 1.2mm 3mm;font-size:7pt;line-height:{{ $compact ? '1.16' : '1.32' }};">
                <div style="font-size:{{ $compact ? '8' : '9' }}pt;font-weight:bold;">{{ \Illuminate\Support\Str::limit($member->name, 61) }}</div>
                <div>{{ \Illuminate\Support\Str::limit($member->designation?->name ?? 'Not assigned', 64) }}</div>
                <div>{{ \Illuminate\Support\Str::limit(\App\Support\Ui::unit($member->unit), 64) }}</div>
                <div>Mobile: {{ $member->mobile_number ?: 'Not recorded' }}</div>
                <div style="font-size:{{ $emailFontSize }}pt;line-height:1.1;">Email: @foreach ($emailLines as $line){{ $line }}@unless ($loop->last)<br>@endunless @endforeach</div>
                <div style="font-size:8pt;color:#175d6b;font-weight:bold;">Membership No. {{ $member->membership_number }}</div>
                @if (! $member->is_active)<div style="font-weight:bold;color:#8b2525;">Inactive membership</div>@endif
            </td>
            <td style="padding-right:2mm;text-align:center;vertical-align:middle;">
                <img style="width:18mm;height:18mm;" src="data:image/png;base64,{{ $qr }}" alt="Membership number QR code">
            </td>
        </tr>
    </table>
    <table style="width:100%;border-collapse:collapse;color:#19314a;font-size:6.7pt;">
        <tr>
            <td style="width:50%;text-align:center;padding:{{ $compact ? '1.5' : '3' }}mm 2mm 1mm;">__________________<br>Librarian signature</td>
            <td style="width:50%;text-align:center;padding:{{ $compact ? '1.5' : '3' }}mm 2mm 1mm;">__________________<br>Member signature</td>
        </tr>
        <tr>
            <td colspan="2" style="text-align:center;background:#e9f2f6;padding:.8mm;color:#175d6b;font-weight:bold;">BPSC Library</td>
        </tr>
    </table>
@else
    <table style="width:100%;border-collapse:collapse;text-align:center;color:#19314a;">
        <tr>
            <td style="height:50mm;vertical-align:middle;padding:0 4mm;line-height:1.45;">
                <div style="font-size:9pt;font-weight:bold;color:#234d64;">Bangladesh Public Service Commission</div>
                <div style="font-size:15pt;font-weight:bold;color:#175d6b;">Library Membership Card</div>
            </td>
        </tr>
    </table>
@endif
