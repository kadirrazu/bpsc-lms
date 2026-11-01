<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
abstract class NamedCatalogEntry extends Model {
 protected $fillable=['title_bn','title_en'];
 public function scopeSearch(Builder $query,?string $term): void {
  if($term!==null && trim($term)!=='')$query->where(fn($q)=>$q->where('title_bn','like','%'.trim($term).'%')->orWhere('title_en','like','%'.trim($term).'%'));
 }
}
