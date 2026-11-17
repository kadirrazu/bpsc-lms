<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\BookLoan;
use App\Models\IssueReceipt;
use App\Models\User;
use App\Services\Library\LibraryPdf;
use App\Support\Ui;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class LibraryPrintController extends Controller
{
    public function memberCard(Request $request, LibraryPdf $pdf)
    {
        Gate::authorize('member-portal');

        return $this->card($request->user(), $pdf);
    }

    public function managedCard(User $user, LibraryPdf $pdf)
    {
        Gate::authorize('manage-users');
        Gate::authorize('manage-user', $user);

        return $this->card($user, $pdf);
    }

    private function card(User $member, LibraryPdf $pdf)
    {
        abort_unless($member->role === UserRole::Member && $member->membership_number, 404);
        $member->load('designation');
        $qr = base64_encode((new Writer(new GDLibRenderer(174, 4)))->writeString($member->membership_number));
        $document = $pdf->make(['orientation' => 'P', 'margin_top' => 13, 'margin_bottom' => 12]);
        $document->SetTitle('Library Membership Card');
        foreach (['front', 'back'] as $side) {
            if ($side === 'back') {
                $document->AddPage();
            }
            $document->WriteHTML('<p style="text-align:center;color:#52647a;font-size:9pt">'
                .ucfirst($side).' · A4 · Print at Actual Size (100%)</p>');
            $document->WriteFixedPosHTML(
                view('library.print.member-card', compact('member', 'qr', 'side'))->render(),
                62.2, 27, 85.6, 53.98, 'hidden',
            );
            $document->SetDrawColor(125, 145, 162);
            $document->SetLineWidth(.18);
            $document->Rect(62.2, 27, 85.6, 53.98);
        }

        return $this->attachment($document, 'library-card-'.$member->membership_number.'.pdf');
    }

    public function issueReceipt(BookLoan $loan, LibraryPdf $pdf)
    {
        return $this->circulationReceipt($loan, $pdf, isReturn: false);
    }

    public function returnReceipt(BookLoan $loan, LibraryPdf $pdf)
    {
        return $this->circulationReceipt($loan, $pdf, isReturn: true);
    }

    private function circulationReceipt(BookLoan $loan, LibraryPdf $pdf, bool $isReturn)
    {
        Gate::authorize('manage-library');
        abort_if($isReturn && ! $loan->returned_at, 404);

        $receipt = $loan->issue_receipt_id ? IssueReceipt::findOrFail($loan->issue_receipt_id) : null;
        $loans = $receipt && ! $isReturn
            ? BookLoan::with('book')->where('issue_receipt_id', $receipt->id)->orderBy('id')->get()
            : collect([$loan->load('book', 'user')]);
        $issueReference = $receipt ? 'ISS-'.$receipt->id : 'LOAN-'.$loan->id;
        $reference = $isReturn ? 'RET-'.$loan->id : $issueReference;
        $title = $isReturn ? 'Return Receipt' : 'Issue Receipt';
        $memberName = $receipt?->member_name ?? $loan->user?->name ?? 'Unavailable member';
        $membershipNumber = $receipt?->membership_number ?? $loan->user?->membership_number;
        $issuedAt = $receipt?->issued_at ?? $loan->issued_at;
        $memberDesignation = $receipt?->member_designation ?? $loan->user?->designation?->name;
        $memberUnit = $receipt?->member_unit ?? Ui::unit($loan->user?->unit);
        $issuerId = $receipt?->issuer_user_id ?? $loan->issued_by;
        $issuerName = $receipt?->issuer_name ?? $loan->issuer_name ?? $loan->issuer?->name;
        $issuerDesignation = $receipt?->issuer_designation ?? $loan->issuer?->designation?->name;
        $printedAt = now();
        $document = $pdf->make([
            'orientation' => 'P', 'margin_top' => 11, 'margin_bottom' => 11,
            'margin_left' => 14, 'margin_right' => 14,
        ]);
        $document->SetTitle($title.' '.$reference);
        $document->WriteHTML(view('library.print.issue-receipt', compact(
            'loans', 'reference', 'memberName', 'membershipNumber', 'issuedAt', 'memberDesignation',
            'memberUnit', 'issuerId', 'issuerName', 'issuerDesignation', 'printedAt',
            'isReturn', 'title', 'loan', 'issueReference'
        ))->render());

        return $this->attachment($document, 'library-receipt-'.$reference.'.pdf');
    }

    private function attachment(Mpdf $pdf, string $filename)
    {
        return response($pdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
