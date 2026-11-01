<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BookLoan extends Model {
 protected $attributes=['quantity'=>1];
 protected $fillable=['book_id','user_id','quantity','issued_at','returned_at'];
 protected function casts(): array {return ['quantity'=>'integer','issued_at'=>'datetime','returned_at'=>'datetime'];}
 public function book(): BelongsTo {return $this->belongsTo(Book::class)->withTrashed();}
 public function user(): BelongsTo {return $this->belongsTo(User::class)->withTrashed();}
 // The shared book lock serializes issue writes with edits and deletion.
 public function save(array $options=[]): bool {
  if($this->exists && $this->isDirty('book_id'))throw ValidationException::withMessages(['book_id'=>'An issue record cannot be moved to another book.']);
  if($this->exists && $this->getRawOriginal('returned_at')!==null && $this->isDirty('returned_at') && !$this->returned_at)throw ValidationException::withMessages(['returned_at'=>'Create a new issue record when issuing a returned book again.']);
  return DB::transaction(function()use($options){
   $book=Book::whereKey($this->book_id)->lockForUpdate()->first();
   if(!$book)throw ValidationException::withMessages(['book_id'=>'This book is no longer available.']);
   if((int)$this->quantity<1)throw ValidationException::withMessages(['quantity'=>'Quantity must be at least one.']);
   if(!$this->returned_at){
    $issued=(int)$book->loans()->whereNull('returned_at')->when($this->exists,fn($q)=>$q->whereKeyNot($this->id))->sum('quantity');
    if($issued+(int)$this->quantity>$book->number_of_copies)throw ValidationException::withMessages(['quantity'=>'There are not enough available copies.']);
   }
   return parent::save($options);
  });
 }
}
