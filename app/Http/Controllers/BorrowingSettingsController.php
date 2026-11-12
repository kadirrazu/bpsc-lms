<?php

namespace App\Http\Controllers;

use App\Services\Library\Audit;
use App\Services\Library\BorrowingPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BorrowingSettingsController extends Controller
{
    public function edit(BorrowingPolicy $policy)
    {
        Gate::authorize('manage-administration');

        return view('library.borrowing-settings', ['settings' => $policy->values()]);
    }

    public function update(Request $request, BorrowingPolicy $policy)
    {
        Gate::authorize('manage-administration');
        $data = $request->validate([
            'loan_limit' => 'required|integer|min:1|max:100',
            'loan_days' => 'required|integer|min:1|max:365',
            'renewal_limit' => 'sometimes|required|integer|min:0|max:100',
            'renewal_days' => 'sometimes|required|integer|min:1|max:365',
        ]);

        DB::transaction(function () use ($request, $data) {
            $row = DB::table('borrowing_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless($row, 503, 'Borrowing settings are not installed.');
            $before = [
                'loan_limit' => (int) $row->loan_limit, 'loan_days' => (int) $row->loan_days,
                'renewal_limit' => (int) $row->renewal_limit, 'renewal_days' => (int) $row->renewal_days,
            ];
            $after = array_map('intval', $data) + $before;
            DB::table('borrowing_settings')->where('id', 1)->update($after + [
                'revision' => (int) $row->revision + 1,
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            app(Audit::class)->record('library.borrowing_policy_updated', ['before' => $before, 'after' => $after]);
        });

        $policy->refresh();

        return back()->with('success', 'Borrowing settings updated.');
    }
}
