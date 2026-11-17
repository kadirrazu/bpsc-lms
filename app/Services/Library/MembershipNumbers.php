<?php

namespace App\Services\Library;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MembershipNumbers
{
    /** Allocate inside the same transaction as the member record. */
    public function next(): string
    {
        $prefix = now()->format('y');
        $sequence = DB::table('membership_sequences')->where('prefix', $prefix);
        // A no-op upsert takes the write lock even when this year's counter already exists.
        DB::table('membership_sequences')->upsert(['prefix' => $prefix, 'last_number' => 0], ['prefix'], ['prefix']);
        $number = (int) $sequence->lockForUpdate()->value('last_number') + 1;
        if ($number > 9999) {
            throw ValidationException::withMessages(['membership_number' => 'The membership number capacity for this year has been reached.']);
        }
        $sequence->update(['last_number' => $number]);

        return sprintf('%s%04d', $prefix, $number);
    }
}
