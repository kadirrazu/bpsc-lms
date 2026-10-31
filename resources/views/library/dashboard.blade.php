@extends('layouts.app')
@section('title','Dashboard')
@section('content')
<section class="library-hero mb-4"><div class="library-kicker">Bangladesh Public Service Commission</div><h1>A home for knowledge and discovery</h1><p>Welcome to Library Management System, {{ auth()->user()->name }}.</p><span class="library-tag">Foundation release · {{ auth()->user()->role->label() }}</span></section>
<div class="row g-3 mb-4">
@can('manage-library')
<div class="col-md-4"><a class="card card-body library-link" href="{{ route('users.index') }}"><i class="bi bi-people"></i><h2>User Management</h2><p>Create accounts and keep user information up to date.</p><strong>Browse users →</strong></a></div>
<div class="col-md-4"><a class="card card-body library-link" href="{{ route('designations.index') }}"><i class="bi bi-person-badge"></i><h2>Designation Management</h2><p>Manage the designations assigned to library users.</p><strong>Browse designations →</strong></a></div>
@endcan
<div class="col-md-4"><a class="card card-body library-link" href="{{ route('staff-profile.edit') }}"><i class="bi bi-person-circle"></i><h2>My Profile</h2><p>View your account information.</p><strong>View profile →</strong></a></div>
</div>
<div class="card card-body"><h2>Coming in the next development phases</h2><p class="text-secondary mb-0">Book catalog and live search, issue and return workflows, member registration and approvals will be added in the next phases. These services are not available in this foundation release.</p></div>
@endsection
