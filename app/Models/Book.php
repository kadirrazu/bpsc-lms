<?php
namespace App\Models;
use Illuminate\Database\Eloquent\{Model,SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo,BelongsToMany,HasMany};
use Illuminate\Validation\ValidationException;
class Book extends Model {
 use SoftDeletes;
 protected $attributes=['number_of_copies'=>1,'total_issue_count'=>0];
 protected $fillable=['accession_number','title_bn','title_en','publisher_id','call_number','shelf_number','publication_year','number_of_copies','cover_path'];
 protected function casts(): array {return ['publication_year'=>'integer','number_of_copies'=>'integer','total_issue_count'=>'integer'];}
 public function authors(): BelongsToMany {return $this->belongsToMany(Author::class,'book_authors')->orderBy('title_bn');}
 public function publisher(): BelongsTo {return $this->belongsTo(Publisher::class);}
 public function categories(): BelongsToMany {return $this->belongsToMany(Category::class,'book_categories')->orderBy('title_bn');}
 public function loans(): HasMany {return $this->hasMany(BookLoan::class);}
 public function activeLoanQuantity(): int {return (int)$this->loans()->whereNull('returned_at')->sum('quantity');}
 protected static function booted(): void {
  static::deleting(function(self $book){if($book->loans()->whereNull('returned_at')->exists())throw ValidationException::withMessages(['book'=>'This book is currently issued and cannot be deleted. Return all issued copies first.']);});
 }
}
