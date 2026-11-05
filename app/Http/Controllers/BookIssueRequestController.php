<?php
namespace App\Http\Controllers;
use App\Models\{Book,BookLoan,BookIssueRequest,User,Unit};
use App\Enums\UserRole;
use App\Services\Library\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate,Schema,Log};
use Illuminate\Validation\ValidationException;
class BookIssueRequestController extends Controller {
 public function mine(Request $r){
  Gate::authorize('member-portal');
  $filters=$r->validate(['q'=>'nullable|string|max:150','request_status'=>'nullable|in:pending,approved,rejected,withdrawn','loan_status'=>'nullable|in:on_loan,returned','page'=>'nullable|integer|min:1','loans_page'=>'nullable|integer|min:1']);
  $search=trim($filters['q']??'');$requestStatus=$filters['request_status']??'';$loanStatus=$filters['loan_status']??'';
  $requestQuery=BookIssueRequest::with(['book','loan'])->where('user_id',$r->user()->id);
  $loanQuery=BookLoan::with('book')->where('user_id',$r->user()->id);
  if($requestStatus!=='')$requestQuery->where('status',$requestStatus);
  if($loanStatus==='on_loan')$loanQuery->whereNull('returned_at');elseif($loanStatus==='returned')$loanQuery->whereNotNull('returned_at');
  if($search!=='')foreach([$requestQuery,$loanQuery] as $query)$this->searchMemberBooks($query,$search);
  $requests=$requestQuery->latest('id')->paginate(20)->withQueryString();$loans=$loanQuery->latest('id')->paginate(20,['*'],'loans_page')->withQueryString();
  return view('member.requests',compact('requests','loans','search','requestStatus','loanStatus'));
 }
 private function searchMemberBooks($query,string $search): void {
  $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
  $query->whereHas('book',function($book)use($pattern){$book->where(function($b)use($pattern){
   foreach(['title_bn','title_en','accession_number','call_number'] as $field)$b->orWhereRaw("$field LIKE ? ESCAPE '!'",[$pattern]);
   foreach(['authors','publisher','categories'] as $relation)$b->orWhereHas($relation,fn($entry)=>$entry->where(fn($names)=>$names->whereRaw("title_bn LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("title_en LIKE ? ESCAPE '!'",[$pattern])));
  });});
 }
 public function store(Request $r,Book $book){
  Gate::authorize('member-portal');
  DB::transaction(function()use($r,$book){
   $current=Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
   $borrower=User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
   $active=(int)BookLoan::where('user_id',$borrower->id)->whereNull('returned_at')->sum('quantity');
   $pending=BookIssueRequest::where('user_id',$borrower->id)->where('status','pending')->count();
   if($active+$pending>=app(\App\Services\Library\BorrowingPolicy::class)->limit())throw ValidationException::withMessages(['request'=>'You may borrow at most '.app(\App\Services\Library\BorrowingPolicy::class)->limit().' books at a time for '.app(\App\Services\Library\BorrowingPolicy::class)->days().' days. You currently have '.$active.' on loan and '.$pending.' pending requests. Return a book or withdraw a pending request before requesting another.']);
   if(BookIssueRequest::where('book_id',$current->id)->where('user_id',$r->user()->id)->where('status','pending')->exists())throw ValidationException::withMessages(['request'=>'You already have a pending request for this book.']);
   if(BookLoan::where('book_id',$current->id)->where('user_id',$r->user()->id)->whereNull('returned_at')->exists())throw ValidationException::withMessages(['request'=>'This book is already issued to you.']);
   if($current->activeLoanQuantity()>=$current->number_of_copies)throw ValidationException::withMessages(['request'=>'No copies are currently available.']);
   $entry=BookIssueRequest::create(['book_id'=>$current->id,'user_id'=>$r->user()->id]);app(Audit::class)->record('book.issue_requested',[],$entry);
  });return redirect()->route('member.requests')->with('success','Issue request submitted for review.');
 }
 public function withdraw(Request $r,BookIssueRequest $issueRequest){
  Gate::authorize('member-portal');abort_unless((int)$issueRequest->user_id===(int)$r->user()->id,403);
  if(!Schema::hasColumn('book_issue_requests','withdrawn_at')){
   Log::warning('Book request withdrawal unavailable: run the pending 2026_11_05_000008_add_issue_request_withdrawal migration.');
   throw ValidationException::withMessages(['request'=>'Withdrawal is temporarily unavailable. Please contact the librarian.']);
  }
  DB::transaction(function()use($r,$issueRequest){
   // Same book -> request lock order as approval. The first committed decision wins.
   Book::withTrashed()->whereKey($issueRequest->book_id)->lockForUpdate()->first();
   $entry=BookIssueRequest::whereKey($issueRequest->id)->lockForUpdate()->firstOrFail();
   abort_unless((int)$entry->user_id===(int)$r->user()->id,403);
   if($entry->status!=='pending' || $entry->loan_id!==null)throw ValidationException::withMessages(['request'=>'Only a pending request can be withdrawn. This request may already have been reviewed.']);
   $withdrawnAt=now();
   $entry->update(['status'=>'withdrawn','withdrawn_at'=>$withdrawnAt]);
   app(Audit::class)->record('book.issue_request_withdrawn',['book_id'=>$entry->book_id,'member_id'=>$entry->user_id,'withdrawn_at'=>$withdrawnAt->toIso8601String()],$entry);
  });return redirect()->route('member.requests')->with('success','Your issue request has been withdrawn.');
 }
 public function index(Request $r){
  Gate::authorize('manage-library');
  $filters=$r->validate(['q'=>'nullable|string|max:150','status'=>'nullable|in:pending,approved,on_loan,returned,rejected,withdrawn','page'=>'nullable|integer|min:1']);
  $search=trim($filters['q']??'');$status=$filters['status']??'';
  $query=BookIssueRequest::with(['book.authors','user.designation','loan']);
  if(in_array($status,['on_loan','returned'],true)){
   $query->where('status','approved')->whereHas('loan',fn($q)=>$status==='returned'?$q->whereNotNull('returned_at'):$q->whereNull('returned_at'));
  }elseif($status!=='')$query->where('status',$status);
  if($search!==''){
   $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
   $query->where(function($q)use($pattern){
    $q->whereHas('book',function($book)use($pattern){$book->where(function($b)use($pattern){foreach(['title_bn','title_en','accession_number','call_number'] as $field)$b->orWhereRaw("$field LIKE ? ESCAPE '!'",[$pattern]);});})
      ->orWhereHas('user',function($user)use($pattern){$user->where(function($u)use($pattern){
       foreach(['name','email','mobile_number','unit'] as $field)$u->orWhereRaw("$field LIKE ? ESCAPE '!'",[$pattern]);
       $u->orWhereHas('designation',fn($d)=>$d->whereRaw("name LIKE ? ESCAPE '!'",[$pattern]));
       $u->orWhereIn('unit',Unit::select('code')->whereRaw("name LIKE ? ESCAPE '!'",[$pattern]));
      });});
   });
  }
  $requests=$query->latest('id')->paginate(20)->withQueryString();
  return view('library.issue-requests',compact('requests','search','status'));
 }
 public function review(Request $r,BookIssueRequest $issueRequest){
  Gate::authorize('manage-library');$data=$r->validate(['decision'=>'required|in:approve,reject','confirmation_token'=>'nullable|string|max:64']);
  if($data['decision']==='approve'){
   $result=app(\App\Services\Library\LoanIssuance::class)->issue($r,(int)$issueRequest->book_id,(int)$issueRequest->user_id,$issueRequest->id);
   if($result['confirmation'])return view('loans.confirm-limit',$result);
   return redirect()->route('issue-requests.index')->with('success','Book issued. Due: '.\App\Support\Ui::date($result['loan']->due_at));
  }
  DB::transaction(function()use($r,$issueRequest){
   Book::whereKey($issueRequest->book_id)->lockForUpdate()->first();
   $entry=BookIssueRequest::whereKey($issueRequest->id)->lockForUpdate()->firstOrFail();
   if($entry->status!=='pending')throw ValidationException::withMessages(['request'=>'This request has already been reviewed.']);
   $entry->update(['status'=>'rejected','reviewed_by'=>$r->user()->id,'reviewed_at'=>now()]);app(Audit::class)->record('book.issue_request_rejected',[],$entry);
  });return back()->with('success','Issue request rejected.');
 }
 public function returned(BookLoan $loan){
  Gate::authorize('manage-library');
  DB::transaction(function()use($loan){Book::whereKey($loan->book_id)->lockForUpdate()->firstOrFail();$current=BookLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();if($current->returned_at)throw ValidationException::withMessages(['loan'=>'This loan has already been returned.']);$current->update(['returned_at'=>now()]);});return back()->with('success','Book marked as returned.');
 }
}
