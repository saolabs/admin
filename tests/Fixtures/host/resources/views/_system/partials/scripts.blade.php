{{-- Saola CSS+JS bundle giờ load qua _system/partials/assets (dev-aware) trong
     <head> của begin.blade. Partial này để dành cho script bổ sung cuối <body>
     (analytics, polyfill…) nếu cần. --}}

{{-- `<script src>` do view khai báo (`@addScriptSrc`) + phần CSS đăng ký sau khi
     <head> đã render. renderHeadAssets chỉ trả thứ CHƯA in nên không trùng thẻ
     với begin.blade. --}}
{!! $__helper->renderHeadAssets() !!}
