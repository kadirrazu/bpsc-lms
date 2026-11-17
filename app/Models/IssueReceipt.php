<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IssueReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'member_name', 'membership_number', 'issued_at', 'member_designation', 'member_unit',
        'issuer_user_id', 'issuer_name', 'issuer_designation',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }
}
