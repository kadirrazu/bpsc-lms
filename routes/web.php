<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{UserController, DesignationController, StaffProfileController, AuditLogController};
Route::get('/', fn()=>redirect()->route(auth()->check()?'dashboard':'login'))->name('home');
Route::middleware('auth')->group(function () {
 Route::get('/dashboard', fn()=>auth()->user()->role === \App\Enums\UserRole::Member ? redirect()->route('member.home') : view('library.dashboard'))->name('dashboard');
 Route::middleware('can:member-portal')->group(function () {
  Route::view('/member','member.home')->name('member.home');
  Route::view('/member/catalog','member.catalog')->name('member.catalog');
  Route::view('/member/requests','member.requests')->name('member.requests');
 });
 Route::resource('units', \App\Http\Controllers\UnitController::class)->except('show')->middleware('can:manage-system');
 Route::get('/profile', [StaffProfileController::class,'edit'])->name('staff-profile.edit');
 Route::put('/profile', [StaffProfileController::class,'update'])->name('staff-profile.update');
 Route::get('/profile/password', [StaffProfileController::class,'password'])->name('staff-profile.password');
 Route::put('/profile/password', [StaffProfileController::class,'updatePassword'])->name('staff-profile.update-password');
 Route::middleware('can:manage-library')->group(function () {
  Route::get('/users/{user}/delete-confirm', [UserController::class,'confirmDelete'])->middleware('can:delete-library')->name('users.confirm-delete');
  Route::resource('users',UserController::class)->except('destroy');
  Route::delete('/users/{user}',[UserController::class,'destroy'])->middleware('can:delete-library')->name('users.destroy');
  Route::resource('designations',DesignationController::class)->except(['show','destroy']);
  Route::delete('/designations/{designation}',[DesignationController::class,'destroy'])->middleware('can:delete-library')->name('designations.destroy');
 });
 Route::get('/audit-logs',[AuditLogController::class,'index'])->middleware('can:view-logs')->name('audit-logs.index');
 Route::get('/roles',fn()=>view('library.roles'))->middleware('can:manage-system')->name('roles.index');
});
