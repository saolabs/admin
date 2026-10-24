{{--
    Nạp asset của Saola. Mọi URL đi qua `BundleManifest` — một chỗ duy nhất
    chứa nhánh dev/build, thay vì chép logic manifest ra ba nơi.

    DEV   (`public/hot` tồn tại): load từ Vite dev server; Vite tự resolve bare
          specifier nên KHÔNG emit import map (emit vào là đá nhau với
          dep-optimizer).
    BUILD: <link> CSS trong head (tránh FOUC) + import map + modulepreload +
           <script type=module> entry.

    Import map PHẢI đứng trước mọi <script type="module">: nó là thứ giúp bundle
    theme (build độc lập, `@saolabs/client` external) dùng CHUNG instance runtime
    với app. Xem docs/EXTENSION_ARCHITECTURE.md §7.

    type=module ⇒ defer: chạy sau khi DOM parse + sau inline APP_CONFIGS (body)
    nên hydrate (App.start→readSSRBoot) claim được server DOM.
--}}
@php
    $__bundles = app(\Saola\Core\Services\BundleManifest::class);
    $__theme = app(\Saola\Core\Services\ThemeService::class);
    $__themeSlug = $__theme->active($__context__);
    $__themeAssets = $__themeSlug ? $__theme->assets($__themeSlug, $__context__) : ['js' => null, 'css' => null];
    $__cssUrl = $__bundles->cssUrl($__context__);
    $__entryUrl = $__bundles->entryUrl($__context__);
    $__importMap = $__bundles->importMap($__context__);
    // Ghép trong PHP: chuỗi "@vite/client" đặt thẳng trong template (kể cả bên
    // trong {{ }}) sẽ bị Blade compile như DIRECTIVE @vite và làm hỏng cả file.
    $__viteClientUrl = $__bundles->devUrl('') . '@' . 'vite/client';
@endphp
@if($__importMap)
    {{-- PHẢI đứng trước mọi <script type="module">. Cần ở CẢ dev: bundle theme là
         file tĩnh, không đi qua transform của Vite nên bare specifier trong nó
         tới thẳng trình duyệt. --}}
    {!! '<script type="importmap">' . json_encode(['imports' => $__importMap], JSON_UNESCAPED_SLASHES) . '</script>' !!}
@endif
@if($__bundles->isDev())
    <script type="module" src="{{ $__viteClientUrl }}"></script>
    {{-- <link>, KHÔNG phải <script type=module>: module chạy sau khi parse xong
         mới chèn <style>, nên trang vẽ trần trước (FOUC). Vite trả CSS thật cho
         request Accept: text/css, HMR vẫn thay <link> tại chỗ. --}}
    <link rel="stylesheet" href="{{ $__cssUrl }}">
    @if($__themeAssets['css'])
        <link rel="stylesheet" href="{{ $__themeAssets['css'] }}">
    @endif
@else
    @if($__cssUrl)
        <link rel="stylesheet" href="{{ $__cssUrl }}">
    @endif
    {{-- CSS của theme sau CSS của app: theme thắng bằng THỨ TỰ, không phải bằng
         đấu specificity. --}}
    @if($__themeAssets['css'])
        <link rel="stylesheet" href="{{ $__themeAssets['css'] }}">
    @endif
    {{-- Không có modulepreload thì entry phải chạy xong mới biết URL bundle
         theme → waterfall hai chặng. --}}
    <link rel="modulepreload" href="{{ $__entryUrl }}">
    @if($__themeAssets['js'])
        <link rel="modulepreload" href="{{ $__themeAssets['js'] }}">
    @endif
@endif
<script type="module" src="{{ $__entryUrl }}"></script>
