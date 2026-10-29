# Thư viện media dùng chung

Source ngày 25/10/2026, sau alpha.1, chưa phát hành. Media nằm trong `saola/admin`; `MediaLibrary`, `MediaScope` và renderer/picker có ranh giới riêng để module hoặc theme dùng chung, hoặc tách package sau này.

## Phạm vi đã triển khai

- Trang `/{prefix}/media`: danh sách dạng lưới, tìm tên/tiêu đề, lọc ảnh/video/audio/tài liệu, phân trang 24 tệp.
- Tải nhiều tệp qua nút hoặc kéo thả; tối đa 20 tệp một lượt ở client, tuần tự từng request.
- Chọn tệp xem thông tin, sửa tiêu đề/alt/chú thích. Alt/chú thích lưu Unicode, chuỗi rỗng và xuống dòng có chủ đích; không đổi tên vật lý hay URL khi sửa tiêu đề.
- Thùng rác/khôi phục. Không có xóa vĩnh viễn: tệp trong thùng rác biến mất khỏi picker nhưng bytes và liên kết đang dùng được giữ để tránh gãy ảnh ở chức năng khác.
- Một component `components.media-library` dùng làm trang quản lý hoặc hộp chọn. Chọn ảnh cho logo, logo nền tối, favicon, ảnh chia sẻ SEO đã tích hợp. Picker xác nhận lại tệp còn khả dụng trước khi trả kết quả.
- Nhãn/thông báo en/vi nằm trong `lang/{locale}/admin.php`, được sync vào DB và dùng chung cơ chế `text()`/dictionary của admin.

Đây là thư viện **tài nguyên công khai của website**, có nhãn rõ ngay trong UI. Không dùng cho hồ sơ riêng tư hoặc tài liệu cần ACL ở mỗi lượt tải xuống. Không phải trình duyệt filesystem: người dùng không nhập đường dẫn máy chủ, disk, giải nén hay sửa code.

## Dữ liệu và vị trí lưu

`saola_media`: UUID, scope, disk/path nội bộ, tên gốc, tiêu đề/alt/chú thích, MIME thực, nhóm loại, bytes/kích thước ảnh, revision, timestamps và deleted_at. Tên bảng/connection cấu hình bằng `saola-admin.media.table/connection`; không lặp `admin` vào tên bảng. Không có khóa ngoại tới model user do package không sở hữu user của host.

Mỗi tệp được lưu bằng UUID, mặc định trên Laravel disk `local` với visibility private, dưới `saola/admin/media/YYYY/MM/{uuid}.{ext}`. Tệp upload là dữ liệu runtime trong storage của host, không trộn vào source `resources/saola/admin` hoặc assets build. Có thể chọn disk khác qua `media.disk`; driver phải hỗ trợ put/readStream/exists. Không cần `storage:link`.

URL ổn định `/saola/media/{uuid}` (`saola.media.content`) do controller stream từ disk. Route công khai cho tài nguyên website, độc lập prefix admin và UI/content locale, vẫn hoạt động khi panel bị tắt. Chỉ trả nội dung theo MIME đã kiểm, `nosniff`, CSP sandbox; ảnh inline, tài liệu/video/audio được tải xuống. Không gửi disk/path/scope vào dữ liệu client. Cần giữ route và bản ghi khi đổi theme; nếu chuyển disk, host phải di chuyển bytes và cập nhật disk/path có chủ đích.

Metadata media hiện dùng chung ngôn ngữ. Alt cho từng lần sử dụng hoặc theo locale thuộc nội dung của module sử dụng; picker trả alt gợi ý, không ghi đè nội dung đã có.

### Link tĩnh trực tiếp từ public disk

Host có thể bật `media.public_disks = ['contents']`, chọn `media.disk = 'contents'` và khai Laravel disk có `root = public_path('static/contents')`, `url = '/static/contents'`. Upload trên disk đã opt-in dùng visibility public; projection `item.url` lấy từ filesystem thay cho route stream. Các bản ghi trên disk khác vẫn dùng URL route cũ. Không suy ra public từ tên disk và không nhận disk/path từ client.

`media.kind_directories` cho phép ánh xạ server-side `image`, `video`, `audio`, `document` sang thư mục tương ứng, ví dụ `image => 'media/images'`; nhóm không cấu hình dùng `media.directory`. Tên file vẫn là UUID và được giữ khi sửa metadata hoặc đưa vào thùng rác.

Web server/CDN của host chịu trách nhiệm MIME, cache, nosniff và phục vụ file trực tiếp. Khi chuyển bản ghi cũ, host sao chép/kiểm tra bytes rồi cập nhật disk/path và các URL đã lưu trong nội dung/settings. Package không tự di chuyển dữ liệu cũ hay sửa cấu hình web server. Bảo toàn thư mục runtime khi deploy với rsync/delete; dữ liệu upload không thuộc assets build.

## Tích hợp ở module hoặc theme

`AdminUI.createMediaLibrary(editor, imageOnly)` tạo instance theo view. Editor do `MediaController::editor($request)` project endpoint, CSRF, permission, accept, maxBytes và initial list. Không chia sẻ draft/selection/actor trong singleton.

Include cùng renderer với `picker: true`, `imageOnly: true|false`, callback `onSelect(item)` và `onClose()`. Bao renderer trong `.sa-admin-media-overlay` ở view chủ. Callback nhận `{id, url, title, alt, caption, mime, kind, size, width, height, revision, trashed, ...}`. View chủ quyết định ghi vào trường nào và chỉ lưu khi người dùng bấm Lưu của chức năng đó. Picker không tự sửa Settings hay model nội dung.

Schema Settings khai báo ảnh bằng:

```php
'hero_url' => [
    'labelKey' => 'catalog.settings.hero', 'type' => 'url', 'default' => '',
    'relative' => true, 'media' => true, 'public' => true,
],
```

Đặt `media:true` chỉ hợp lệ với type URL. Renderer hiện ảnh, nút Chọn từ thư viện và Bỏ chọn. Lưu vẫn là URL string để giữ contract và dữ liệu alpha.1. Khi actor không có `view-media` hoặc media tắt, không mở picker; trường URL hiện hữu vẫn dùng được. URL bên ngoài hợp lệ qua API cũ vẫn được giữ. Module mới có thể lưu UUID rồi resolve qua `MediaLibrary::find($scope, $id)`; phải kiểm kind/trạng thái và Gate của module khi liên kết. Việc chọn trong browser không thay thế validation ở backend module.

Chưa có bảng đồ thị sử dụng tệp hoặc tự dò URL trong nội dung/theme. Vì vậy thùng rác giữ URL hoạt động; không tự dọn bytes theo tuổi. Không cần sao chép media vào theme để dùng lại.

## Quyền và phân vùng thư viện

Panel vẫn cần guard/`access-admin`. Thêm Gate độc lập `view-media`, `upload-media`, `update-media`, `delete-media`, đổi tên qua `media.abilities`. Xem không đồng nghĩa được upload/sửa/xóa; server kiểm lại từng thao tác, không tự cấp quyền khi cài.

Mặc định `GlobalMediaScope` là một thư viện chung của website. Host nhiều tenant bind `MediaScope` trong provider đăng ký sau admin:

```php
$this->app->bind(\Saola\Admin\Media\MediaScope::class, TenantMediaScope::class);
// TenantMediaScope::key(Request $request): string
// Trả key ổn định, không rỗng, tối đa 128 bytes từ tenant đã xác thực.
```

Scope được áp dụng vào list/count/upload/read/edit/trash/restore; UUID tenant khác trả 404. Route nội dung vẫn công khai theo mục đích tài nguyên website. Scope không biến tệp công khai thành tài liệu riêng tư.

## HTTP và an toàn dữ liệu

| Endpoint dưới `/{prefix}/_api/media` | Hành vi |
| --- | --- |
| GET `/` | q/kind/status/page, trả items/total/page/perPage |
| POST `/` | multipart `file`, trả item 201 |
| GET `/{uuid}` | xác nhận thông tin/trạng thái tệp trong scope |
| PUT `/{uuid}` | JSON `{revision, changes:{title,alt,caption}}` |
| POST `/{uuid}/trash` hoặc `/restore` | JSON `{revision}` |

Web session, CSRF cho mọi write; upload có throttle 30/phút. Edit/trash/restore dùng conditional revision update, stale trả 409. Tên vật lý/path/disk/scope không nhận từ body. JSON gốc bảo toàn rỗng/xuống dòng; field lạ/kiểu sai bị 422. Response admin private/no-store. Picker giữ draft khi lỗi, chặn rời trang/đổi UI locale khi dirty hoặc pending, hủy listener/request khi suspend; không áp response cũ hay tự retry write chưa xác nhận.

MIME được đọc từ bytes, đối chiếu extension allowlist; ảnh raster cần header kích thước hợp lệ. Mặc định nhận JPEG/PNG/GIF/WebP/ICO, PDF, MP3/OGG/WAV, MP4/WebM; loại thực tế còn phụ thuộc MIME detector máy chủ. Không nhận SVG/HTML/script/archive. Tối đa 20 MiB/tệp, 40 triệu pixel/ảnh, có thể giảm bằng config. Giới hạn PHP/nginx (`upload_max_filesize`, `post_max_size`, request body) cũng phải đủ; package không tự đổi cấu hình máy chủ. Dùng UUID tránh ghi đè tên gốc. Nếu ghi DB thất bại sau lưu storage, gỡ tệp mới.

Không có crop/resize, chuyển mã video/audio, biến thể thumbnail, tích hợp CDN riêng, giao diện quản lý folders, purge hoặc metadata đa ngôn ngữ trong bản đầu. Ảnh lưới dùng lazy load bản gốc. Những phần này có thể thêm sau qua adapter và jobs, giữ UUID/URL/picker contract.

## Cài/nâng cấp và kiểm tra

Review và chạy migration `2026_10_25_000005_create_media.php` trên connection đã cấu hình; cấp Gate trong host, `admin:publish --dry-run`/publish rồi build lại context admin. `admin:doctor` kiểm renderer, bảng và disk. Frontend thêm 3 authored files, tổng 18; tất cả vẫn nằm trong root admin.

Tests có upload/MIME/giới hạn, public stream, scope/count, quyền độc lập, CAS, trash/restore, JSON rỗng, SSR, strict TypeScript, hydrate picker thực → Settings CAS, multipart CSRF và response sau suspend. Workbench chỉ dùng SQLite/storage tạm; chưa chứng nhận tranh chấp đa connection MySQL/PostgreSQL hay driver S3 thực.
