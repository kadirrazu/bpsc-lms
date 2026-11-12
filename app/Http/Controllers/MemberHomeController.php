<?php

namespace App\Http\Controllers;

use App\Models\LoanReminder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Schema};

class MemberHomeController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('member-portal');
        $member = $request->user();
        $loans = $member->loans()->whereNull('returned_at')
            ->with('book:id,title_bn,accession_number,deleted_at')
            ->latest('issued_at')->latest('id')->get();
        $reminders = Schema::hasTable('loan_reminders')
            ? LoanReminder::with('loan.book')->where('user_id', $member->id)
                ->whereHas('loan', fn ($query) => $query->where('user_id', $member->id)->whereNull('returned_at'))
                ->latest('sent_at')->limit(10)->get()
            : collect();

        return view('member.home', compact('loans', 'reminders'));
    }
}
