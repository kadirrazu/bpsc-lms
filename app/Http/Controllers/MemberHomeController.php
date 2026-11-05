<?php
namespace App\Http\Controllers;
use App\Models\LoanReminder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate,Schema};
class MemberHomeController extends Controller {
 public function index(Request $r){Gate::authorize('member-portal');$reminders=Schema::hasTable('loan_reminders')?LoanReminder::with('loan.book')->where('user_id',$r->user()->id)->whereHas('loan',fn($q)=>$q->where('user_id',$r->user()->id)->whereNull('returned_at'))->latest('sent_at')->limit(10)->get():collect();return view('member.home',compact('reminders'));}
}
