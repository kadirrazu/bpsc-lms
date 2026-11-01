<?php
namespace App\Http\Controllers;
use App\Models\{MemberRegistration,User,Designation};
use App\Enums\UserRole;
use App\Services\Library\{Catalog,Audit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\{Rule,ValidationException};
use Illuminate\Validation\Rules\Password;
class MemberRegistrationController extends Controller {
 public function create(){return view('auth.register',['designations'=>Designation::where('is_active',true)->orderBy('sort_order')->orderBy('name')->get()]);}
 public function store(Request $r){
  $data=$r->validate(['name'=>'required|string|max:255','email'=>['required','email','max:255','unique:users,email','unique:member_registrations,email'],'designation_id'=>['required','integer',Rule::exists('designations','id')->where('is_active',true)],'unit'=>['required','string',Rule::exists('units','code')->where('is_active',true)],'mobile_number'=>['required','string','regex:/^[0-9]{11}$/'],'password'=>['required','confirmed','max:72',Password::min(8)->mixedCase()->numbers()->symbols()]]);
  DB::transaction(function()use($data){app(Catalog::class)->lockUnit($data['unit'],'users');$entry=MemberRegistration::create($data);app(Audit::class)->record('member.registration_requested',[], $entry);});
  return redirect()->route('login')->with('status','Registration request submitted. You can sign in after an Administrator or Librarian approves your account.');
 }
 public function index(){Gate::authorize('manage-users');return view('users.registrations',['registrations'=>MemberRegistration::with('designation')->where('status','pending')->latest('id')->paginate(20)]);}
 public function approve(Request $r,MemberRegistration $registration){
  Gate::authorize('manage-users');
  DB::transaction(function()use($r,$registration){
   $entry=MemberRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();
   if($entry->status!=='pending')throw ValidationException::withMessages(['registration'=>'This request has already been reviewed.']);
   if(User::withTrashed()->where('email',$entry->email)->exists())throw ValidationException::withMessages(['registration'=>'This email already belongs to an account. Resolve it in Users before approval.']);
   if(!Designation::whereKey($entry->designation_id)->where('is_active',true)->lockForUpdate()->first())throw ValidationException::withMessages(['registration'=>'This designation is no longer active. Restore it before approval.']);
   app(Catalog::class)->lockUnit($entry->unit,'users');
   $user=User::create(['name'=>$entry->name,'email'=>$entry->email,'designation_id'=>$entry->designation_id,'unit'=>$entry->unit,'mobile_number'=>$entry->mobile_number,'password'=>$entry->password,'role'=>UserRole::Member,'is_active'=>true]);
   $entry->update(['status'=>'approved','password'=>null,'reviewed_by'=>$r->user()->id,'reviewed_at'=>now()]);
   app(Audit::class)->record('member.registration_approved',['user_id'=>$user->id],$entry);
  });
  return back()->with('success','Registration approved. The member can now sign in.');
 }
}
