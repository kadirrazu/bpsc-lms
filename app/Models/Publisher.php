<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Publisher extends NamedCatalogEntry {
 public function books(): HasMany { return $this->hasMany(Book::class,'publisher_id'); }
}
