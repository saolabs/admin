# Inventory tài nguyên frontend admin

Ngày kiểm: 24/10/2026. Phạm vi bản source `0.1.0-alpha.1`, contract 1.

| Tài nguyên trong resources/saola/admin | Nguồn | Phụ thuộc runtime/build |
| --- | --- | --- |
| assets/css/admin.css | CSS nguyên bản của package; token, sidebar/header/table/responsive | Vite xử lý import CSS và URL ảnh tương đối |
| assets/images/mark.svg | SVG hình học nguyên bản | Được CSS tham chiếu, Vite có thể inline hoặc hash |
| app/bundle.ts | Service AdminUI nguyên bản, import runtime host | @saolabs/client của host; không vendor thêm runtime |
| app/settings-form.ts | State draft/request nguyên bản, view sở hữu instance | Web fetch/AbortController; không giữ state actor trong singleton |
| views/**/*.sao | Shell/resource/settings/head nguyên bản | Compiler Saola + document host |
| Font, icon library, plugin/demo JS | Không đóng gói | Font hệ thống; không tải CDN |

`admin-html-template/html/material/pages-starter.html` được tham khảo cách chia sidebar/topbar/content. Checkout là bản mirror Velzon/Themesbrand; chưa có bằng chứng license phân phối lại CSS/font/plugin/ảnh template trong package. Vì vậy source hiện tại chỉ dùng phần viết mới trong bảng, không chép asset template. Nếu đưa asset template vào sau này phải cập nhật inventory và căn cứ quyền phân phối trước publish release.

Source phải đi cùng manifest; publish cây này sang đúng root admin, build ra public/static/saola/admin. Không đặt tài nguyên admin vào resources/css hoặc resources/js dùng chung. File ngôn ngữ backend là nguồn PHP theo locale và không thuộc asset pipeline.
