<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover"
    >

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>
        @yield('title', __('Authentication')) | {{ __('Library Management System') }}
    </title>

    @include('shared.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>

<body class="d-flex flex-column library-auth">
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="auth-brand product-name mb-2"><i class="bi bi-book" aria-hidden="true"></i> 
                {{ __('Library Management System') }}
            </div>

            <div class="auth-subtitle mb-4">
                <strong lang="en">Bangladesh Public Service Commission (BPSC)</strong>
            </div>

            <div class="d-flex justify-content-center mb-3"></div>
            @yield('content')
        </div>
    </div>

    @include('shared.footer')
    @stack('scripts')
</body>
</html>