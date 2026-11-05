<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{UserController, DesignationController, StaffProfileController, AuditLogController};
Route::get('/', fn()=>redirect()->route(auth()->check()?'dashboard':'login'))->name('home');
Route::middleware('guest')->group(function(){
 Route::get('/register',[\App\Http\Controllers\MemberRegistrationController::class,'create'])->name('register');
 Route::post('/register',[\App\Http\Controllers\MemberRegistrationController::class,'store'])->middleware('throttle:5,1')->name('register.store');
});
Route::middleware('auth')->group(function () {
 Route::get('/dashboard',[\App\Http\Controllers\DashboardController::class,'index'])->name('dashboard');
 Route::middleware('can:member-portal')->group(function () {
  Route::get('/member',[\App\Http\Controllers\MemberHomeController::class,'index'])->name('member.home');
  Route::get('/member/catalog',[\App\Http\Controllers\BookController::class,'catalog'])->name('member.catalog');
  Route::get('/member/catalog/{book}',[\App\Http\Controllers\BookController::class,'memberShow'])->name('member.books.show');
  Route::post('/member/requests/{issueRequest}/withdraw',[\App\Http\Controllers\BookIssueRequestController::class,'withdraw'])->name('member.requests.withdraw');
  Route::get('/member/requests',[\App\Http\Controllers\BookIssueRequestController::class,'mine'])->name('member.requests');
  Route::post('/member/catalog/{book}/request',[\App\Http\Controllers\BookIssueRequestController::class,'store'])->name('member.books.request');
 });
 Route::get('/catalog/{kind}/{entry}',[\App\Http\Controllers\BookController::class,'entity'])->whereIn('kind',['authors','publishers','categories'])->whereNumber('entry')->name('catalog.entity');
 Route::get('/books/{book}/cover',[\App\Http\Controllers\BookController::class,'cover'])->name('books.cover');
 Route::get('/catalog-options/{kind}',[\App\Http\Controllers\BookController::class,'options'])->middleware(['can:view-catalog','throttle:120,1'])->name('catalog.options');
 Route::middleware('can:manage-library')->group(function(){
  Route::get('/loans/create',[\App\Http\Controllers\LoanController::class,'create'])->name('loans.create');
  Route::post('/loans',[\App\Http\Controllers\LoanController::class,'store'])->name('loans.store');
  Route::get('/loan-options/{kind}',[\App\Http\Controllers\LoanController::class,'options'])->whereIn('kind',['books','members'])->middleware('throttle:120,1')->name('loans.options');
 });
 Route::middleware('can:manage-library')->group(function(){
  Route::get('/issue-register',[\App\Http\Controllers\IssueRegisterController::class,'index'])->name('issue-register.index');
  Route::get('/issue-register/export',[\App\Http\Controllers\IssueRegisterController::class,'export'])->name('issue-register.export');
  Route::post('/loans/{loan}/{action}',[\App\Http\Controllers\IssueRegisterController::class,'action'])->whereIn('action',['reminder','renew','fine','clear-fine'])->name('issue-register.action');
 });
 Route::get('/issue-requests',[\App\Http\Controllers\BookIssueRequestController::class,'index'])->middleware('can:manage-library')->name('issue-requests.index');
 Route::post('/issue-requests/{issueRequest}/review',[\App\Http\Controllers\BookIssueRequestController::class,'review'])->middleware('can:manage-library')->name('issue-requests.review');
 Route::post('/loans/{loan}/return',[\App\Http\Controllers\BookIssueRequestController::class,'returned'])->middleware('can:manage-library')->name('loans.return');
 Route::prefix('catalog-import/{kind}')->where(['kind'=>'books|authors|publishers|categories'])->middleware('can:manage-library')->group(function(){
  Route::get('/',[\App\Http\Controllers\CatalogImportController::class,'index'])->name('catalog-import.index');
  Route::get('/sample',[\App\Http\Controllers\CatalogImportController::class,'sample'])->name('catalog-import.sample');
  Route::post('/',[\App\Http\Controllers\CatalogImportController::class,'store'])->middleware('throttle:10,1')->name('catalog-import.store');
 });
 Route::resource('books',\App\Http\Controllers\BookController::class)->middleware('can:manage-library');
 foreach(['authors','publishers','categories'] as $kind){
  Route::prefix($kind)->name($kind.'.')->middleware('can:manage-library')->group(function()use($kind){
   $controller=\App\Http\Controllers\CatalogEntryController::class;
   Route::get('/',[$controller,'index'])->defaults('kind',$kind)->name('index');
   Route::get('/create',[$controller,'create'])->defaults('kind',$kind)->name('create');
   Route::post('/',[$controller,'store'])->defaults('kind',$kind)->name('store');
   Route::get('/{entry}/edit',[$controller,'edit'])->whereNumber('entry')->defaults('kind',$kind)->name('edit');
   Route::put('/{entry}',[$controller,'update'])->whereNumber('entry')->defaults('kind',$kind)->name('update');
   Route::delete('/{entry}',[$controller,'destroy'])->whereNumber('entry')->defaults('kind',$kind)->middleware('can:delete-library')->name('destroy');
  });
 }
 Route::resource('units', \App\Http\Controllers\UnitController::class)->except('show')->middleware('can:manage-system');
 Route::get('/profile', [StaffProfileController::class,'edit'])->name('staff-profile.edit');
 Route::put('/profile', [StaffProfileController::class,'update'])->name('staff-profile.update');
 Route::get('/profile/password', [StaffProfileController::class,'password'])->name('staff-profile.password');
 Route::put('/profile/password', [StaffProfileController::class,'updatePassword'])->name('staff-profile.update-password');
 Route::middleware('can:manage-users')->group(function () {
  Route::get('/users/registration-requests',[\App\Http\Controllers\MemberRegistrationController::class,'index'])->name('users.registrations');
  Route::post('/users/registration-requests/{registration}/approve',[\App\Http\Controllers\MemberRegistrationController::class,'approve'])->name('users.registrations.approve');
  Route::get('/users/{user}/delete-confirm', [UserController::class,'confirmDelete'])->middleware('can:delete-library')->name('users.confirm-delete');
  Route::resource('users',UserController::class)->except('destroy');
  Route::delete('/users/{user}',[UserController::class,'destroy'])->middleware('can:delete-library')->name('users.destroy');
 });
 Route::middleware('can:manage-library')->group(function () {
  Route::resource('designations',DesignationController::class)->except(['show','destroy']);
  Route::delete('/designations/{designation}',[DesignationController::class,'destroy'])->middleware('can:delete-library')->name('designations.destroy');
 });
 Route::get('/audit-logs',[AuditLogController::class,'index'])->middleware('can:view-logs')->name('audit-logs.index');
 Route::get('/borrowing-settings',[\App\Http\Controllers\BorrowingSettingsController::class,'edit'])->middleware('can:manage-system')->name('borrowing-settings.edit');
 Route::put('/borrowing-settings',[\App\Http\Controllers\BorrowingSettingsController::class,'update'])->middleware('can:manage-system')->name('borrowing-settings.update');
 Route::get('/roles',fn()=>view('library.roles'))->middleware('can:manage-system')->name('roles.index');
});
