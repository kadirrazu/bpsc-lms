<?php
namespace App\Http\Controllers;
use App\Models\{Book,BookLoan,LoanReminder};
use App\Enums\UserRole;
use App\Services\Library\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\ValidationException;
class IssueRegisterController extends Controller {
 private function query(Request $r){
  $data=$r->validate(['q'=>'nullable|string|max:150','status'=>'nullable|in:on_loan,returned','fine'=>'nullable|in:flagged,clear','page'=>'nullable|integer|min:1']);
  $q=BookLoan::with(['book.authors','user.designation','issuer','reminder']);$term=trim($data['q']??'');
  if(($data['status']??'')==='on_loan')$q->whereNull('returned_at');elseif(($data['status']??'')==='returned')$q->whereNotNull('returned_at');
  if(($data['fine']??'')!=='')$q->where('is_fined',$data['fine']==='flagged');
  if($term!==''){$pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';$q->where(function($q)use($pattern){
   foreach(['accession_snapshot','title_snapshot','title_en_snapshot','issuer_name','authors_snapshot'] as $field)$q->orWhereRaw("$field LIKE ? ESCAPE '!'",[$pattern]);
   $q->orWhereHas('book',fn($b)=>$b->where(fn($b)=>$b->whereRaw("title_bn LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("title_en LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("accession_number LIKE ? ESCAPE '!'",[$pattern])));
   $q->orWhereHas('book.authors',fn($a)=>$a->where(fn($n)=>$n->whereRaw("title_bn LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("title_en LIKE ? ESCAPE '!'",[$pattern])));
   $q->orWhereHas('user',fn($u)=>$u->where(fn($u)=>$u->whereRaw("name LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("email LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("mobile_number LIKE ? ESCAPE '!'",[$pattern])));
  });}
  return [$q,$data];
 }
 public function index(Request $r){Gate::authorize('manage-library');[$query,$filters]=$this->query($r);$loans=$query->latest('id')->paginate(20)->withQueryString();return view('loans.register',compact('loans','filters'));}
 public function export(Request $r){
  Gate::authorize('manage-library');[$query]=$this->query($r);app(Audit::class)->record('loan.register_exported',['status'=>$r->input('status'),'fine'=>$r->input('fine')]);
  return response()->streamDownload(function()use($query){
   $file=fopen('php://output','w');fwrite($file,"\xEF\xBB\xBF");
   fputcsv($file,['Loan ID','Accession Number','Title','Authors','Member','Email','Issue Date and Time','Due Date and Time','Return Date and Time','Issued By','Renewal Count','Fine Flag','Last Reminder'],',','"','');
   $safe=fn($value)=>preg_match('/^(?:\s*[=+@\-]|[\x00-\x1F])/u',(string)$value)?"'".$value:(string)$value;
   $query->chunkById(500,function($rows)use($file,$safe){foreach($rows as $loan)fputcsv($file,array_map($safe,[$loan->id,$loan->accession_snapshot??$loan->book?->accession_number,$loan->title_snapshot??$loan->book?->title_bn,implode(' | ',$loan->authors_snapshot??$loan->book?->authors->pluck('title_bn')->all()??[]),$loan->user?->name,$loan->user?->email,$loan->issued_at?->format('Y-m-d H:i:s'),$loan->due_at?->format('Y-m-d H:i:s'),$loan->returned_at?->format('Y-m-d H:i:s'),$loan->issuer_name??$loan->issuer?->name??'Not recorded',$loan->renewal_count,$loan->is_fined?'Yes':'No',$loan->reminder?->sent_at?->format('Y-m-d H:i:s')]),',','"','');});fclose($file);
  },'issue-register-'.now()->format('Ymd-His').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
 }
 public function action(Request $r,BookLoan $loan,string $action){
  Gate::authorize('manage-library');if($action==='renew' && !$r->has('days'))$r->merge(['days'=>app(\App\Services\Library\BorrowingPolicy::class)->days()]);$data=$r->validate(['confirmed'=>'required|accepted','days'=>'nullable|integer|min:1|max:365','expected_renewal_count'=>'nullable|integer|min:0']);
  if($action==='renew')$r->validate(['days'=>'required|integer|min:1|max:365','expected_renewal_count'=>'required|integer|min:0']);
  DB::transaction(function()use($r,$loan,$action){
   Book::withTrashed()->whereKey($loan->book_id)->lockForUpdate()->first();$current=BookLoan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
   if(in_array($action,['reminder','renew'],true) && $current->returned_at)throw ValidationException::withMessages(['loan'=>'This book has already been returned.']);
   if(in_array($action,['renew','fine'],true) && (!$current->due_at || !$current->due_at->isPast()))throw ValidationException::withMessages(['loan'=>'Renew and Fine are available only after the due date has passed.']);
   if($action==='reminder'){
    $member=$current->user;if(!$member || !$member->is_active || $member->role!==UserRole::Member)throw ValidationException::withMessages(['loan'=>'This member cannot receive a dashboard reminder.']);
    LoanReminder::updateOrCreate(['loan_id'=>$current->id],['user_id'=>$member->id,'sent_by'=>$r->user()->id,'sent_at'=>now()]);
    app(Audit::class)->record('book.return_reminder_sent',['loan_id'=>$current->id,'member_id'=>$member->id],$current->book);
   }elseif($action==='renew'){
    if((int)$r->input('expected_renewal_count')!==(int)$current->renewal_count)throw ValidationException::withMessages(['loan'=>'This loan was already renewed. Reload the register before renewing again.']);
    $days=(int)$r->input('days');$before=$current->due_at?->toIso8601String();$base=now();
    $current->due_at=$base->addDays($days);$current->renewal_count=(int)$current->renewal_count+1;$current->renewed_at=now();$current->save();
    app(Audit::class)->record('book.renewed',['loan_id'=>$current->id,'days'=>$days,'previous_due_at'=>$before,'new_due_at'=>$current->due_at->toIso8601String()],$current->book);
   }elseif(in_array($action,['fine','clear-fine'],true)){
    $flag=$action==='fine';if((bool)$current->is_fined!==$flag){$current->is_fined=$flag;$current->fined_at=$flag?now():null;$current->fined_by=$flag?$r->user()->id:null;$current->save();app(Audit::class)->record($flag?'book.fine_flagged':'book.fine_cleared',['loan_id'=>$current->id,'member_id'=>$current->user_id],$current->book);}
   }else abort(404);
  });
  return back()->with('success',match($action){'reminder'=>'Return reminder sent to the member dashboard.','renew'=>'Loan extended successfully.','fine'=>'Issue flagged for fine reporting.','clear-fine'=>'Fine flag cleared.'});
 }
}
