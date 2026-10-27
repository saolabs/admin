# Inventory tài nguyên frontend admin

Source sau alpha.1, contract 1. Cấu trúc theme và nguồn thuộc gói `saola/admin`; xem [THEMES](THEMES.md).

| Thành phần trong resources/saola/admin | Nguồn và vai trò |
| --- | --- |
| app/ | Bootstrap/bridge giữ tích hợp host; không import skin |
| app/ | AdminUI (`App.Admin`) và state/request/guard dùng chung cho form, CRUD, ngôn ngữ và media |
| themes/classic/app, views, assets | Giao diện nguyên bản trước khi tách theme, được giữ đầy đủ |
| themes/material/app, views | Template Material chuyển thành `.sao`, dùng các class Bootstrap/Velzon có sẵn và contract state chung |
| themes/material/assets/vendor/material/css | CSS Bootstrap 5.3.6, Velzon Material và icon library từ distribution admin-material được cung cấp |
| themes/material/assets/vendor/material/fonts | Font icon được các stylesheet vendor tham chiếu, đóng gói local |
| themes/material/assets/vendor/material/images | Ảnh local còn được stylesheet vendor tham chiếu |
| themes/material/assets/vendor/material/SOURCE.json | Hash upstream/packaged và các biến đổi chuẩn bị asset |
| themes/*/theme.manifest.json | Tên theme, contract, view context và các entry trong theme |
| build/themes.mjs | Helper lấy CSS input riêng của từng theme từ manifest |

CSS vendor được giữ nguyên hệ component. Bỏ Google Fonts network imports, source-map comments, các font hkgrotesk và URL ảnh trang trí bị thiếu trong bản mirror; font hệ thống làm fallback. Không thêm ảnh giả thay thế. Dùng font icon đóng gói local; không chạy app.js/layout.js/demo plugin của template vì chúng quản lý document và sessionStorage bên ngoài lifecycle Saola. Không đưa ảnh avatar/cờ hay dữ liệu demo vào host.

CSS adapter Material chỉ bố trí widget đặc thù và nối trạng thái Saola vào class của distribution; không import CSS Classic. Build hai CSS entry riêng và chọn theo theme. Cập nhật source ở package rồi publish; toàn bộ frontend vẫn dưới root resources/saola/admin. Lang backend và media do người dùng upload vẫn là dữ liệu độc lập, không thuộc cây asset theme.

Distribution Material là tài nguyên bên thứ ba do người dùng cung cấp, không phải phần CSS/SVG nguyên bản theo license của code package. SOURCE.json giữ provenance; việc kiểm thử Composer archive cục bộ không phải một lần phát hành công khai.
