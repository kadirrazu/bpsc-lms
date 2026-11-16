<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookCopy extends Model
{
    public $timestamps = false;

    protected $fillable = ['accession_number', 'is_active'];

    public function loans(): HasMany
    {
        return $this->hasMany(BookLoan::class);
    }
}
