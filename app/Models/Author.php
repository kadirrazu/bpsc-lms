<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class Author extends NamedCatalogEntry {
 public function books(): BelongsToMany {return $this->belongsToMany(Book::class,'book_authors','author_id','book_id');}
}
