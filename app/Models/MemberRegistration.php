<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MemberRegistration extends Model {
 protected $guarded=['id'];
 protected $hidden=['password'];
 protected function casts(): array {return ['password'=>'hashed','reviewed_at'=>'datetime'];}
 public function designation(){return $this->belongsTo(Designation::class);}
}
