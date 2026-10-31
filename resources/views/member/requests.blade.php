@extends('layouts.member')
@section('title','My Requests')
@section('content')
<div class="mb-4"><span class="eyebrow">MEMBER SERVICES</span><h1>My Requests</h1></div><div class="card card-body empty-state"><i class="bi bi-bookmark-check"></i><span class="badge bg-blue-lt mb-3">Coming soon</span><h2>My Requests</h2><p>Book issue requests are not available yet. Your requests, issued books and return status will appear here once the circulation module is enabled.</p><a class="btn btn-outline-primary" href="{{ route('member.home') }}">Back to Member Home</a></div>
@endsection
