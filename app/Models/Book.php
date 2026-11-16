<?php
namespace App\Models;
use Illuminate\Database\Eloquent\{Model,SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo,BelongsToMany,HasMany};
use Illuminate\Validation\ValidationException;
class Book extends Model {
 use SoftDeletes;
 public const PRINTED_CURRENCIES = ['BDT'=>'৳ (BDT)', 'USD'=>'$ (USD)', 'EUR'=>'€ (EUR)', 'INR'=>'₹ (INR)', 'CNY'=>'¥ (CNY)'];
 public function isSoftCopy(): bool {return $this->format === 'soft';}
 public function copies(): HasMany {return $this->hasMany(BookCopy::class);}
 public function digitalExtension(): string
 {
  return str_ends_with((string) $this->pdf_path, '.epub') ? 'epub' : 'pdf';
 }
 public function availableCopies(): HasMany
 {
  return $this->copies()->where('is_active', true)
   ->whereDoesntHave('loans', fn ($loans) => $loans->whereNull('returned_at'));
 }
 protected $attributes=['number_of_copies'=>1,'total_issue_count'=>0,'format'=>'hard','printed_currency'=>'BDT','download_count'=>0];
 protected $fillable=['accession_number','title_bn','title_en','publisher_id','call_number','shelf_number','publication_year','number_of_copies','cover_path','price','collection_method','format','printed_price','printed_currency','pdf_path'];
 protected function casts(): array {return ['price'=>'decimal:2','printed_price'=>'decimal:2','download_count'=>'integer','publication_year'=>'integer','number_of_copies'=>'integer','total_issue_count'=>'integer'];}
 public function authors(): BelongsToMany {return $this->belongsToMany(Author::class,'book_authors')->orderBy('title_bn');}
 public function publisher(): BelongsTo {return $this->belongsTo(Publisher::class);}
 public function categories(): BelongsToMany {return $this->belongsToMany(Category::class,'book_categories')->orderBy('title_bn');}
 public function loans(): HasMany {return $this->hasMany(BookLoan::class);}
 public function activeLoanQuantity(): int {return (int)$this->loans()->whereNull('returned_at')->sum('quantity');}
 protected static function booted(): void {
  static::deleting(function(self $book){if($book->loans()->whereNull('returned_at')->exists())throw ValidationException::withMessages(['book'=>'This book is currently issued and cannot be deleted. Return all issued copies first.']);});
 }
}
