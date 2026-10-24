<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('meta:title', 'Saola - Advanced SPA Framework')</title>
    <meta name="description" content="@yield('meta:description', 'Saola is an advanced SPA framework that seamlessly integrates Laravel backend with reactive frontend capabilities.')">
    <meta name="keywords" content="@yield('meta:keywords', 'Laravel, SPA, PHP, JavaScript, Framework, Reactive, Saola')">
    <meta name="author" content="@yield('meta:author', 'SaoLabs')">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="@yield('meta:og:type', 'website')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="@yield('meta:og:title', $__env->yieldContent('meta:title', 'Saola - Advanced SPA Framework'))">
    <meta property="og:description" content="@yield('meta:og:description', $__env->yieldContent('meta:description', 'Saola is an advanced SPA framework that seamlessly integrates Laravel backend with reactive frontend capabilities.'))">
    <meta property="og:site_name" content="Saola Ecosystem">
    @hasSection('meta:og:image')
    <meta property="og:image" content="@yield('meta:og:image')">
    @endif

    <!-- Twitter Card -->
    <meta name="twitter:card" content="@yield('meta:twitter:card', 'summary_large_image')">
    <meta name="twitter:url" content="{{ url()->current() }}">
    <meta name="twitter:title" content="@yield('meta:twitter:title', $__env->yieldContent('meta:title', 'Saola - Advanced SPA Framework'))">
    <meta name="twitter:description" content="@yield('meta:twitter:description', $__env->yieldContent('meta:description', 'Saola is an advanced SPA framework that seamlessly integrates Laravel backend with reactive frontend capabilities.'))">

    <!-- Canonical Link -->
    <link rel="canonical" href="@yield('meta:canonical', url()->current())">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="{{ $__env->yieldContent('favicon', asset('favicon.svg')) }}">
    @include($__partial__.'head')

    {{-- Saola assets (CSS + JS), dev-aware: Vite HMR khi `npm run dev`, static khi build --}}
    @include($__system__.'partials.assets')

    {{-- CSS do view khai báo (`<link rel=stylesheet>` trong .sao → `@addCssLink`).
         View con của `@extends` chạy TRƯỚC layout nên tới đây đã đăng ký xong;
         phần đăng ký muộn hơn (chính layout, block render sau <head>) được
         `_system.partials.scripts` in nốt ở cuối <body>. --}}
    {!! $__helper->renderHeadAssets('css') !!}

    @yield('styles')
</head>
<body>
    <!-- body top -->

    <!-- begin application content -->
    <div id="app-root" data-server-rendered="true">
