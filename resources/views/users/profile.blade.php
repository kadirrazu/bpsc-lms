@extends('layouts.app')
@section('title',__('My Profile'))
@section('content')
<h1 class="mb-3">{{ __('My Profile') }}</h1>
@if($user->role === \App\Enums\UserRole::Member)
<div class="card card-body"><h2>{{ $user->name }}</h2><p>{{ $user->email }}</p><p>{{ $user->designation?->name }} · {{ \App\Support\Ui::unit($user->unit) }}</p><p>{{ $user->role->label() }}</p></div>
@else
<form class="card card-body" method="post" action="{{ route('staff-profile.update') }}">@csrf @method('PUT')
<div class="row g-3"><div class="col-md-6"><label class="form-label" for="name"><i class="bi bi-person" aria-hidden="true"></i> {{ __('Name') }}</label><input class="form-control" id="name" name="name" value="{{ old('name',$user->name) }}" maxlength="255" required></div><div class="col-md-6"><label class="form-label" for="email"><i class="bi bi-envelope" aria-hidden="true"></i> {{ __('Email (Sign In)') }}</label><input class="form-control" type="email" id="email" name="email" value="{{ old('email',$user->email) }}" maxlength="255" required></div><div class="col-md-6"><label class="form-label" for="designation_id"><i class="bi bi-person-badge" aria-hidden="true"></i> {{ __('Designation') }}</label><select class="form-select" id="designation_id" name="designation_id" required><option value="">{{ __('Select designation') }}</option>@foreach($designations as $designation)<option value="{{ $designation->id }}" @selected(old('designation_id',$user->designation_id)==$designation->id)>{{ __($designation->name) }}</option>@endforeach</select></div><div class="col-md-6"><label class="form-label"><i class="bi bi-info-circle" aria-hidden="true"></i> {{ __('Unit') }}</label><div class="form-control-plaintext">{{ \App\Support\Ui::unit($user->unit) }}</div><small class="form-hint">{{ __('Unit is assigned by an administrator.') }}</small></div><div class="col-md-6"><label class="form-label"><i class="bi bi-info-circle" aria-hidden="true"></i> {{ __('Role') }}</label><div class="form-control-plaintext">{{ $user->role->label() }}</div></div></div>
<div class="mt-3"><button class="btn btn-primary"><i class="bi bi-person-check" aria-hidden="true"></i> {{ __('Save Profile') }}</button> <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> {{ __('Back') }}</a></div></form>
@endif
@endsection
