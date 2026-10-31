<?php

namespace App\Http\Controllers;

use App\Models\Designation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('users.profile', ['user' => $request->user(), 'designations' => Designation::where('is_active', true)->orderBy('sort_order')->get()]);
    }

    public function update(Request $request)
    {
        abort_if($request->user()->role === \App\Enums\UserRole::Member, 403);
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'designation_id' => ['required', 'integer', Rule::exists('designations', 'id')->where('is_active', true)],
            'preferred_locale' => 'sometimes|in:en', 'unit' => 'prohibited', 'role' => 'prohibited', 'is_active' => 'prohibited', 'user_id' => 'prohibited', 'id' => 'prohibited', 'deleted_at' => 'prohibited',
        ]);
        if ($data['email'] !== $user->email) {
            $user->email_verified_at = null;
        }
        $locale = $data['preferred_locale'] ?? null;
        unset($data['preferred_locale']);
        DB::transaction(function () use ($user, $data, $locale) {
            $user->fill($data);
            if ($locale) {
                $user->preferred_locale = $locale;
            } $user->save();
        });
        if ($request->is('api/*')) {
            return response()->json(['data' => $user]);
        }
        $request->session()->put('auth_version', $user->auth_version);

        return redirect()->route('staff-profile.edit')->with('success', __('Your profile has been updated.'));
    }

    public function password()
    {
        return view('users.password');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string|current_password', 'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()], 'user_id' => 'prohibited', 'id' => 'prohibited', 'preferred_locale' => 'sometimes|in:en', 'unit' => 'prohibited', 'role' => 'prohibited']);
        DB::transaction(fn () => $request->user()->forceFill(['password' => $data['password'], 'remember_token' => null])->save());
        if ($request->is('api/*')) {
            return response()->json(['message' => __('Password changed. Sign in again to obtain a new token.')]);
        }
        $request->session()->regenerate();
        $request->session()->put('auth_version', $request->user()->auth_version);

        return redirect()->route('staff-profile.password')->with('success', __('Your password has been updated.'));
    }
}
