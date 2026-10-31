<?php

use App\Enums\UserRole;
use App\Models\Designation;
use App\Models\User;
use App\Services\Library\Catalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('library:create-admin', function () {
    $name = $this->ask('Administrator name');
    $email = strtolower((string) $this->ask('Email address'));
    $password = $this->secret('Password (8+ characters, mixed case, number and symbol)');
    $confirm = $this->secret('Confirm password');
    $unitLabels = app(Catalog::class)->unitLabels('users');
    if (! $unitLabels) {
        $this->error('Run php artisan db:seed --class=DesignationSeeder first.');

        return 1;
    }
    $selectedUnit = $this->choice('Department', array_values($unitLabels), $unitLabels['IT Section'] ?? reset($unitLabels));
    $unit = array_search($selectedUnit, $unitLabels, true);
    $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm], [
        'name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email',
        'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    $designation = Designation::where('slug', 'system-manager')->first();
    if (! $designation) {
        $this->error('Run php artisan db:seed --class=DesignationSeeder first.');

        return 1;
    }
    DB::transaction(function () use ($name, $email, $password, $designation, $unit) {
        app(Catalog::class)->lockUnit($unit, 'users');
        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'designation_id' => $designation->id, 'unit' => $unit, 'role' => UserRole::Admin, 'is_active' => true]);
    });
    $this->info('Administrator created. No default password was saved.');

    return 0;
})->purpose('Create an administrator using interactive credentials');
Schedule::command('sanctum:prune-expired --hours=24')->daily();

