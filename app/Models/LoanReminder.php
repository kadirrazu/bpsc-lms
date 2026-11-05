<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LoanReminder extends Model {
 protected $guarded=['id'];
 protected function casts(): array {return ['sent_at'=>'datetime'];}
 public function loan(){return $this->belongsTo(BookLoan::class);}
}
