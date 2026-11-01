<?php
namespace App\Http\Controllers;
use App\Models\{Book,BookLoan,BookIssueRequest,User};
use App\Enums\UserRole;
use App\Services\Library\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\ValidationException;
class BookIssueRequestController extends Controller {
 public function mine(Request $r){Gate::authorize('member-portal');return view('member.requests',['requests'=>BookIssueRequest::with(['book','loan'])->where('user_id',$r->user()->id)->latest('id')->paginate(20),'loans'=>BookLoan::with('book')->where('user_id',$r->user()->id)->latest('id')->paginate(20,['*'],'loans_page')]);}
 public function store(Request $r,Book $book){
  Gate::authorize('member-portal');
  DB::transaction(function()use($r,$book){
   $current=Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
   if(BookIssueRequest::where('book_id',$current->id)->where('user_id',$r->user()->id)->where('status','pending')->exists())throw ValidationException::withMessages(['request'=>'You already have a pending request for this book.']);
   if(BookLoan::where('book_id',$current->id)->where('user_id',$r->user()->id)->whereNull('returned_at')->exists())throw ValidationException::withMessages(['request'=>'This book is already issued to you.']);
   if($current->activeLoanQuantity()>=$current->number_of_copies)throw ValidationException::withMessages(['request'=>'No copies are currently available.']);
   $entry=BookIssueRequest::create(['book_id'=>$current->id,'user_id'=>$r->user()->id]);app(Audit::class)->record('book.issue_requested',[],$entry);
  });return redirect()->route('member.requests')->with('success','Issue request submitted for review.');
 }
 public function index(){Gate::authorize('manage-library');return view('library.issue-requests',['requests'=>BookIssueRequest::with(['book','user','loan'])->latest('id')->paginate(20)]);}
 public function review(Request $r,BookIssueRequest $issueRequest){
  Gate::authorize('manage-library');$data=$r->validate(['decision'=>'required|in:approve,reject']);
  DB::transaction(function()use($r,$issueRequest,$data){
   // Lock order is book, request, member; all circulation writes serialize on the book.
   $book=Book::whereKey($issueRequest->book_id)->lockForUpdate()->first();
   $entry=BookIssueRequest::whereKey($issueRequest->id)->lockForUpdate()->firstOrFail();
   if($entry->status!=='pending')throw ValidationException::withMessages(['request'=>'This request has already been reviewed.']);
   $status='rejected';$loan=null;
   if($data['decision']==='approve'){
    $user=User::whereKey($entry->user_id)->lockForUpdate()->first();
    if(!$book || !$user || !$user->is_active || $user->role!==UserRole::Member)throw ValidationException::withMessages(['request'=>'The book or member is no longer available.']);
    if(BookLoan::where('book_id',$book->id)->where('user_id',$user->id)->whereNull('returned_at')->exists())throw ValidationException::withMessages(['request'=>'This book is already issued to this member.']);
    $loan=BookLoan::create(['book_id'=>$book->id,'user_id'=>$user->id,'quantity'=>1,'issued_at'=>now()]);$status='approved';
   }
   $entry->update(['status'=>$status,'loan_id'=>$loan?->id,'reviewed_by'=>$r->user()->id,'reviewed_at'=>now()]);app(Audit::class)->record('book.issue_request_'.$status,['loan_id'=>$loan?->id],$entry);
  });return back()->with('success','Issue request reviewed.');
 }
 public function returned(BookLoan $loan){
  Gate::authorize('manage-library');
  DB::transaction(function()use($loan){Book::whereKey($loan->book_id)->lockForUpdate()->firstOrFail();$current=BookLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();if($current->returned_at)throw ValidationException::withMessages(['loan'=>'This loan has already been returned.']);$current->update(['returned_at'=>now()]);});return back()->with('success','Book marked as returned.');
 }
}
