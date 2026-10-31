@extends('layouts.member')
@section('title','Library Catalog')
@section('content')
<div class="mb-4"><span class="eyebrow">MEMBER SERVICES</span><h1>Library Catalog</h1></div><div class="card card-body empty-state"><i class="bi bi-journals"></i><span class="badge bg-blue-lt mb-3">Coming soon</span><h2>Library Catalog</h2><p>The book catalog is being prepared. Live search, filters and book request options will be added with the catalog module.</p><a class="btn btn-outline-primary" href="{{ route('member.home') }}">Back to Member Home</a></div>
@endsection
