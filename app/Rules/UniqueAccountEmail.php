<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueAccountEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && User::withTrashed()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreId, fn ($query) => $query->whereKeyNot($this->ignoreId))
            ->exists()) {
            $fail('This email already belongs to an account.');
        }
    }
}
