    </div>
    {{-- Cấu hình SPA.
         Chỉ phát những khoá client THẬT SỰ đọc (client/src/core/bootstrap/ssr.ts
         → readBootConfig): container, router.{mode,base,routes}, view.{contextViews,
         revision,systemData,ssrData}. Bản cũ còn phát api.*, env.*, mode,
         defaultRoute, view.superView — không nhánh nào đọc; csrf thì client lấy
         từ <meta name="csrf-token"> ở page.begin.

         MỘT danh sách route duy nhất. Trước đây phát cả `allRoutes` (đủ) lẫn
         `routes` (chỉ route có component) — trùng gần hết dữ liệu, và
         Router.configure() đăng ký cả hai nên mỗi route vào bảng hai lần.
         Route nào có view thì `component` khác null; client tự lọc.

         json_encode một phát, không PRETTY_PRINT: đây là payload, không phải
         code để đọc. --}}
    @php
        // Bundle nạp rời: server là chỗ DUY NHẤT biết theme nào đang bật, nên
        // client không đoán đường dẫn. Thứ tự mảng = thứ tự merge (sau đè trước).
        $__themeService = app(\Saola\Core\Services\ThemeService::class);
        $__activeTheme = $__themeService->active($__context__);
        $__bundleUrls = array_values(array_filter([
            $__activeTheme ? $__themeService->assets($__activeTheme, $__context__)['js'] : null,
        ]));
    @endphp
    {!! '<script>window.APP_CONFIGS='
    .json_encode([
        'container' => '#app-root',
        'router' => [
            'mode' => 'history',
            'base' => '/',
            'routes' => $__helper->exportSpaRoutes($__context__),
        ],
        'bundles' => $__bundleUrls,
        'i18n' => request()->attributes->get('saola.i18n'),
        'view' => [
            'contextViews' => viewContextManager()->getContextViews($__context__),
            'revision' => viewContextManager()->getContextViewRevision($__context__),
            'systemData' => viewContextManager()->exportContextState($__context__)['systemData'],
            'ssrData' => $__helper->exportApplicationViewData(),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
    .'</script>' !!}

    {{-- SSR hydrate boot — client (App.start → readSSRBoot) đọc để hydrate route đầu.
         viewId của layout chain client tự discover từ DOM. renderSSRBoot tự bỏ qua
         nếu thiếu view/viewId (→ CSR boot). Xem docs/HYDRATION.md §6. --}}
    {!! $__helper->renderSSRBoot($__VIEW_PATH__ ?? '', $__VIEW_ID__ ?? '') !!}

    @include($__system__.'partials.scripts')

</body>
</html>
