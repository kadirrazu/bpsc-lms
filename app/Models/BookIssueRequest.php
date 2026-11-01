<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookIssueRequest extends Model {
 protected $guarded=['id'];
 protected function casts(): array {return ['reviewed_at'=>'datetime'];}
 public function book(){return $this->belongsTo(Book::class)->withTrashed();}
 public function user(){return $this->belongsTo(User::class)->withTrashed();}
 public function loan(){return $this->belongsTo(BookLoan::class);}
}
