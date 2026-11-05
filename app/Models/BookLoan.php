<?php
namespace App\Models;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BookLoan extends Model {
 protected $attributes=['quantity'=>1];
 protected $fillable=['book_id','user_id','quantity','issued_at','returned_at'];
 private ?User $overrideOperator=null;
 private ?int $confirmedActiveCount=null;
 protected function casts(): array {return ['quantity'=>'integer','issued_at'=>'datetime','returned_at'=>'datetime','due_at'=>'datetime','limit_override_at'=>'datetime','active_count_at_issue'=>'integer','authors_snapshot'=>'array','renewal_count'=>'integer','renewed_at'=>'datetime','is_fined'=>'boolean','fined_at'=>'datetime'];}
 public function book(): BelongsTo {return $this->belongsTo(Book::class)->withTrashed();}
 public function issuer(): BelongsTo {return $this->belongsTo(User::class,'issued_by')->withTrashed();}
 public function reminder(){return $this->hasOne(LoanReminder::class,'loan_id');}
 public function user(): BelongsTo {return $this->belongsTo(User::class)->withTrashed();}
 // Only trusted application code can authorize an override; these fields are not mass assignable.
 public function authorizeLimitOverride(User $operator,int $activeCount): static {
  if(!$operator->is_active || $operator->role!==UserRole::Librarian)throw ValidationException::withMessages(['loan'=>'Only a Librarian can confirm a borrowing-limit override.']);
  $this->overrideOperator=$operator;$this->confirmedActiveCount=$activeCount;return $this;
 }
 public function save(array $options=[]): bool {
  if($this->exists && $this->isDirty('book_id'))throw ValidationException::withMessages(['book_id'=>'An issue record cannot be moved to another book.']);
  if($this->exists && $this->getRawOriginal('returned_at')!==null && $this->isDirty('returned_at') && !$this->returned_at)throw ValidationException::withMessages(['returned_at'=>'Create a new issue record when issuing a returned book again.']);
  try {return DB::transaction(function()use($options){
   // All circulation writers lock book before borrower; the borrower lock serializes different books too.
   $book=($this->exists && $this->returned_at ? Book::withTrashed():Book::query())->whereKey($this->book_id)->lockForUpdate()->first();
   if(!$book)throw ValidationException::withMessages(['book_id'=>'This book is no longer available.']);
   if((int)$this->quantity<1)throw ValidationException::withMessages(['quantity'=>'Quantity must be at least one.']);
   if(!$this->exists){
    $this->accession_snapshot=$book->accession_number;$this->title_snapshot=$book->title_bn;$this->title_en_snapshot=$book->title_en;$this->attributes['authors_snapshot']=json_encode($book->authors()->pluck('title_bn')->all(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if($this->issued_by)$this->issuer_name=User::withTrashed()->find($this->issued_by)?->name;
   }
   if(!$this->exists || $this->isDirty('issued_at')){
    $this->issued_at=$this->issued_at??now();$this->due_at=$this->issued_at->copy()->addDays(app(\App\Services\Library\BorrowingPolicy::class)->days());
   }
   if(!$this->returned_at){
    $borrower=User::whereKey($this->user_id)->lockForUpdate()->first();
    if(!$borrower || !$borrower->is_active)throw ValidationException::withMessages(['user_id'=>'Select an active borrower.']);
    $issued=(int)$book->loans()->whereNull('returned_at')->when($this->exists,fn($q)=>$q->whereKeyNot($this->id))->sum('quantity');
    if($issued+(int)$this->quantity>$book->number_of_copies)throw ValidationException::withMessages(['quantity'=>'There are not enough available copies.']);
    if(!$this->exists || $this->isDirty(['user_id','quantity'])){
     $active=(int)self::where('user_id',$borrower->id)->whereNull('returned_at')->when($this->exists,fn($q)=>$q->whereKeyNot($this->id))->lockForUpdate()->get(['id','quantity'])->sum('quantity');
     if($active+(int)$this->quantity>app(\App\Services\Library\BorrowingPolicy::class)->limit()){
      if(!$this->overrideOperator || $this->confirmedActiveCount!==$active)throw ValidationException::withMessages(['loan'=>'The borrower already has '.$active.' books on loan. The limit is '.app(\App\Services\Library\BorrowingPolicy::class)->limit().'; a Librarian must explicitly confirm an override.']);
      $operator=User::find($this->overrideOperator->id);
      if(!$operator || !$operator->is_active || $operator->role!==UserRole::Librarian)throw ValidationException::withMessages(['loan'=>'A current Librarian is required to confirm this override.']);
      $this->limit_override_by=$operator->id;$this->limit_override_at=now();
     }
     $this->active_count_at_issue=$active;
    }
   }
   return parent::save($options);
  });}finally{$this->overrideOperator=null;$this->confirmedActiveCount=null;}
 }
}
