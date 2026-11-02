@extends('layouts.app')
@section('title',$plural)
@section('content')
<div class="catalog-heading"><div><span class="eyebrow">CATALOG MANAGEMENT</span><h1>{{ $plural }}</h1><p class="text-secondary mb-0">Manage Bengali names with optional English names for search.</p></div><div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="{{ route('catalog-import.index',$kind) }}"><i class="bi bi-upload" aria-hidden="true"></i>Import XLSX</a><a class="btn btn-primary" href="{{ route($kind.'.create') }}"><i class="bi bi-plus-lg"></i>Add {{ $singular }}</a></div></div>
<form class="card card-body mb-4" method="get" data-live-search data-results="#catalog-results"><label class="form-label" for="q">Search {{ strtolower($plural) }}</label><div class="d-flex gap-2 flex-wrap flex-sm-nowrap"><input type="search" id="q" name="q" class="form-control" value="{{ request('q') }}" placeholder="Search Bengali or English names" maxlength="150" data-live-input><div class="filter-actions"><button class="btn btn-primary">Search</button><a class="btn btn-outline-secondary" href="{{ route($kind.'.index') }}">Clear</a></div></div><div class="live-status form-hint mt-2" role="status" aria-live="polite"></div></form>
<div id="catalog-results" aria-live="polite">@include('catalog-entries.table')</div>
@endsection
