<?php
namespace App\Services\Library;
use App\Models\{Book,BookLoan,BookIssueRequest,User};
use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class LoanIssuance {
 public function issue(Request $r,int $bookId,int $memberId,?int $requestId=null): array {
  return DB::transaction(function()use($r,$bookId,$memberId,$requestId){
   $book=Book::whereKey($bookId)->lockForUpdate()->first();
   $entry=$requestId?BookIssueRequest::whereKey($requestId)->lockForUpdate()->firstOrFail():null;
   if($entry && ($entry->status!=='pending' || (int)$entry->book_id!==$bookId || (int)$entry->user_id!==$memberId))throw ValidationException::withMessages(['request'=>'This request has already been reviewed or changed.']);
   $member=User::whereKey($memberId)->lockForUpdate()->first();
   if(!$book || !$member || !$member->is_active || $member->role!==UserRole::Member)throw ValidationException::withMessages(['loan'=>'Select an available book and an active Library Member.']);
   if(BookLoan::where('book_id',$bookId)->where('user_id',$memberId)->whereNull('returned_at')->exists())throw ValidationException::withMessages(['loan'=>'This book is already issued to this member.']);
   if($book->activeLoanQuantity()>=$book->number_of_copies)throw ValidationException::withMessages(['quantity'=>'There are not enough available copies.']);
   if(!$entry && BookIssueRequest::where('book_id',$bookId)->where('user_id',$memberId)->where('status','pending')->exists())throw ValidationException::withMessages(['loan'=>'A pending request already exists for this book and member. Approve that request instead.']);
   $active=(int)BookLoan::where('user_id',$memberId)->whereNull('returned_at')->sum('quantity');
   $context=['operator_id'=>$r->user()->id,'book_id'=>$bookId,'member_id'=>$memberId,'request_id'=>$requestId,'active'=>$active,'policy'=>app(BorrowingPolicy::class)->values()];
   $over=$active+1>app(BorrowingPolicy::class)->limit();
   if($over){
    if($r->user()->role!==UserRole::Librarian)throw ValidationException::withMessages(['loan'=>'This member already has '.$active.' books on loan. Return a book first; only a Librarian can confirm an exception.']);
    $saved=$r->session()->get('loan_limit_confirmation');$token=(string)$r->input('confirmation_token','');
    $confirmed=is_array($saved) && ($saved['context']??null)===$context && ($saved['expires']??0)>=time() && $token!=='' && hash_equals((string)$saved['token'],$token);
    if(!$confirmed){
     $token=bin2hex(random_bytes(32));$r->session()->put('loan_limit_confirmation',['context'=>$context,'token'=>$token,'expires'=>time()+600]);
     return ['confirmation'=>true,'book'=>$book,'member'=>$member,'active'=>$active,'token'=>$token,'requestId'=>$requestId];
    }
   }
   $loan=new BookLoan(['book_id'=>$bookId,'user_id'=>$memberId,'quantity'=>1,'issued_at'=>now()]);
   $loan->issued_by=$r->user()->id;
   if($over)$loan->authorizeLimitOverride($r->user(),$active);
   $loan->save();
   if(!$entry)$entry=new BookIssueRequest(['book_id'=>$bookId,'user_id'=>$memberId,'source'=>'staff_issue']);
   $entry->fill(['status'=>'approved','loan_id'=>$loan->id,'reviewed_by'=>$r->user()->id,'reviewed_at'=>now()])->save();
   app(Audit::class)->record('book.issue_request_approved',['loan_id'=>$loan->id,'source'=>$entry->source],$entry);
   if($over)app(Audit::class)->record('book.loan_limit_overridden',['loan_id'=>$loan->id,'member_id'=>$memberId,'previous_active_copies'=>$active,'new_active_copies'=>$active+1,'due_at'=>$loan->due_at->toIso8601String()],$book);
   $r->session()->forget('loan_limit_confirmation');
   return ['confirmation'=>false,'loan'=>$loan];
  });
 }
}
