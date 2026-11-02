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
    if (! app()->environment(['local', 'development'])) {
        $this->error('This command is available only in development (APP_ENV=local or development).');
        return 1;
    }
    $designations = Designation::where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name','id')->all();
    if (! $designations) {
        $this->error('No active designations found. Run php artisan db:seed --class=DesignationSeeder first.');
        return 1;
    }
    $selectedDesignation = $this->choice('Designation', array_values($designations), 0);
    $designationId = array_search($selectedDesignation, $designations, true);
    $name = $this->ask('Administrator name');
    $email = strtolower((string) $this->ask('Email address'));
    $password = $this->secret('Password (8+ characters, mixed case, number and symbol)');
    $confirm = $this->secret('Confirm password');
    $unitLabels = app(Catalog::class)->unitLabels('users');
    if (! $unitLabels) {
        $this->error('No active units found. Run php artisan db:seed --class=UnitSeeder first.');

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
    DB::transaction(function () use ($name, $email, $password, $designationId, $unit) {
        $designation = Designation::whereKey($designationId)->where('is_active',true)->lockForUpdate()->first();
        if (! $designation) throw \Illuminate\Validation\ValidationException::withMessages(['designation'=>'Selected designation is no longer active. Try again.']);
        app(Catalog::class)->lockUnit($unit, 'users');
        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'designation_id' => $designation->id, 'unit' => $unit, 'role' => UserRole::Admin, 'is_active' => true]);
    });
    $this->info('Administrator created. No default password was saved.');

    return 0;
})->purpose('Development only: create an administrator using interactive credentials');
Schedule::command('sanctum:prune-expired --hours=24')->daily();


Artisan::command('library:purge-cover-files', function () {
 $this->info('Removed '.app(\App\Services\Library\CoverStorage::class)->purge().' unused cover files.');
})->purpose('Retry pending book cover file cleanup');
Schedule::command('library:purge-cover-files')->everyMinute()->withoutOverlapping();

Artisan::command('library:benchmark-catalog {--query=} {--accession=} {--call=} {--match=exact} {--runs=7} {--json}', function () {
    $filters=['q'=>trim((string)$this->option('query')),'accession_number'=>trim((string)$this->option('accession')),'call_number'=>trim((string)$this->option('call')),'number_match'=>$this->option('match')];
    $validation=Validator::make($filters+['runs'=>$this->option('runs')],['q'=>'nullable|string|max:150','accession_number'=>'nullable|string|max:100','call_number'=>'nullable|string|max:100','number_match'=>'required|in:exact,prefix,partial','runs'=>'required|integer|min:1|max:20']);
    if($validation->fails()){$this->error($validation->errors()->first());return 1;}
    $search=app(\App\Services\Library\BookSearch::class);$runs=(int)$this->option('runs');$times=[];$matches=0;
    for($run=0;$run<$runs;$run++){
        $start=hrtime(true);$query=$search->query($filters);$matches=(clone $query)->count();
        (clone $query)->orderByDesc('books.id')->limit(20)->pluck('books.id');
        $times[]=(hrtime(true)-$start)/1e6;
    }
    sort($times);$middle=intdiv($runs,2);$median=$runs%2 ? $times[$middle] : ($times[$middle-1]+$times[$middle])/2;
    $result=['driver'=>DB::getDriverName(),'books'=>\App\Models\Book::count(),'matches'=>$matches,'runs'=>$runs,'median_ms'=>round($median,2),'max_ms'=>round(max($times),2),'scope'=>'SQL count plus first 20 IDs, without search-result cache, relation hydration, rendering or network'];
    if($this->option('json'))$this->line(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    else {$this->table(['Driver','Books','Matches','Median ms','Max ms'],[[$result['driver'],$result['books'],$matches,$result['median_ms'],$result['max_ms']]]);$this->line($result['scope']);}
    return 0;
})->purpose('Measure catalog queries against existing data without changing catalog records');
