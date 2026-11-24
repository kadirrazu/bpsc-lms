<?php
namespace App\Models;
use App\Services\Library\BorrowingPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BookLoan extends Model {
 protected $attributes=['quantity'=>1];
 protected $fillable=['book_id','user_id','quantity','issued_at','returned_at'];
 protected function casts(): array {return ['quantity'=>'integer','issued_at'=>'datetime','returned_at'=>'datetime','due_at'=>'datetime','limit_override_at'=>'datetime','active_count_at_issue'=>'integer','exceptional_limit'=>'integer','exceptional_total_after'=>'integer','authors_snapshot'=>'array','renewal_count'=>'integer','renewed_at'=>'datetime','is_fined'=>'boolean','fined_at'=>'datetime'];}
 public ?string $requestedAccession = null;
 public ?User $exceptionalOperator = null;
 public function book(): BelongsTo {return $this->belongsTo(Book::class)->withTrashed();}
 public function issuer(): BelongsTo {return $this->belongsTo(User::class,'issued_by')->withTrashed();}
 public function reminder(){return $this->hasOne(LoanReminder::class,'loan_id');}
 public function user(): BelongsTo {return $this->belongsTo(User::class)->withTrashed();}
 public function save(array $options=[]): bool {
  if($this->exists && $this->isDirty('book_id'))throw ValidationException::withMessages(['book_id'=>'An issue record cannot be moved to another book.']);
  if($this->exists && $this->getRawOriginal('returned_at')!==null && $this->isDirty('returned_at') && !$this->returned_at)throw ValidationException::withMessages(['returned_at'=>'Create a new issue record when issuing a returned book again.']);
  return DB::transaction(function()use($options){
   // All circulation writers lock book before borrower; the borrower lock serializes different books too.
   $book=($this->exists && $this->returned_at ? Book::withTrashed():Book::query())->whereKey($this->book_id)->lockForUpdate()->first();
   if(!$book)throw ValidationException::withMessages(['book_id'=>'This book is no longer available.']);
   if ($book->isSoftCopy()) {
    throw ValidationException::withMessages(['loan'=>'Soft copies are downloaded, not issued.']);
   }
   $newBorrowing = !$this->exists || $this->isDirty(['user_id','quantity']);
   $exceptional = ! $this->exists && $this->exceptionalOperator !== null;
   if ($exceptional) {
    abort_unless($this->exceptionalOperator->is_active && $this->exceptionalOperator->role === \App\Enums\UserRole::Librarian, 403);
    if (blank($this->requestedAccession) || blank($this->exceptional_reason)) {
     throw ValidationException::withMessages(['exceptional_reason' => 'Select a copy and enter the reason for this exceptional issue.']);
    }
    $this->limit_override_by = $this->exceptionalOperator->id;
    $this->limit_override_at = now();
   }
   if($newBorrowing && !in_array($this->getAttributes()['quantity'] ?? null, [1, '1'], true)){
    throw ValidationException::withMessages(['quantity'=>'Each loan must issue exactly one copy of a book.']);
   }
   if(!$this->exists){
    $registered = $book->copies()->where('is_active', true);
    $fullyRegistered = $registered->count() === $book->number_of_copies;
    if ($this->requestedAccession === null && $fullyRegistered && ! $this->returned_at
     && $book->loans()->whereNull('returned_at')->whereNull('book_copy_id')->exists()) {
     throw ValidationException::withMessages(['loan' => 'Enter the accession on the physical copy being issued. An older open loan has no copy recorded.']);
    }
    $copy = $this->requestedAccession !== null || $fullyRegistered
     ? $book->availableCopies()
      ->when($this->requestedAccession !== null, fn ($query) => $query->where('accession_number', $this->requestedAccession))
      ->orderBy('id')->first()
     : null;
    if ($this->requestedAccession !== null && ! $copy) {
     throw ValidationException::withMessages(['loan' => "Copy {$this->requestedAccession} is not available for this title."]);
    }
    $this->book_copy_id = $copy?->id;
    $this->accession_snapshot = $copy?->accession_number ?? $book->accession_number;$this->title_snapshot=$book->title_bn;$this->title_en_snapshot=$book->title_en;$this->attributes['authors_snapshot']=json_encode($book->authors()->pluck('title_bn')->all(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
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
    if($newBorrowing){
     $active = self::where('user_id',$borrower->id)->whereNull('returned_at')
      ->when($this->exists,fn($q)=>$q->whereKeyNot($this->id))->lockForUpdate()->get(['book_id', 'quantity']);
     if(! $exceptional && $active->contains('book_id', $book->id)){
      throw ValidationException::withMessages(['loan'=>'This book is already on loan to this member.']);
     }
     $limit = app(BorrowingPolicy::class)->limit();
     if(! $exceptional && $active->sum('quantity') >= $limit){
      throw ValidationException::withMessages(['loan'=>"The member has reached the configured borrowing limit of $limit books."]);
     }
     $this->active_count_at_issue = $active->sum('quantity');
    }
   }
   return parent::save($options);
  });
 }
}
