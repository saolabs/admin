# Hợp đồng giao diện admin

Source hiện tại thuộc **gói saola/admin**. Giao diện nằm ở `resources/saola/admin/themes/{name}/{app,views,assets}`; controller/state dùng chung ở `app/`, gọi qua `App.Admin`. Xem [Themes](THEMES.md) cho manifest, view context, CSS và nâng cấp nguồn.

## Ranh giới package và project

Project cung cấp model, service, Policy, mảng list/form/Settings, nhãn và hook nghiệp vụ. CRUD thông thường chỉ cần một controller rỗng kế thừa `App\Http\Controllers\AdminController`; không cần `.sao`, JavaScript hoặc bản sao theme. Package cung cấp list/form/trash/bulk, media, locale management và settings editor. Các trang nghiệp vụ đặc thù dùng route/action/view riêng của module, shell và menu vẫn của gói.

`ResourceDocument` vẫn là DTO chỉ đọc. Mảng list/form (`AdminRegistry`) mang field/cột, scope, rules, quyền và hooks cho mutation; `Resources\Resource` chỉ còn là định nghĩa nội bộ của engine. SSR và page-data luôn project theo quyền trước khi render; giấu nút không thay kiểm tra backend. Không serialize toàn bộ model, relation, password hash hoặc token của host. Xem [Resource](RESOURCES.md).

## Theme và tài nguyên

Classic và Material dùng cùng props/state/action contract. Material dùng Bootstrap/Velzon Material đã được cung cấp; CSS vendor, icon và nguồn asset có inventory riêng. Các script demo, Popper/Bootstrap event handlers của template không được chạy trên view Saola. Dropdown, sidebar, media overlay và form chỉ có một bộ lifecycle/action trong `app/views`.

Mỗi theme có manifest và CSS entry riêng. Root bundle chỉ đăng ký service; không import mọi stylesheet. View context logic hiện là `admin.themes.classic` hoặc `admin.themes.material`; namespace được ánh xạ sang source trong `admin/themes/`. Đổi theme bằng document load mới để registry, SSR và CSS cùng phiên bản. Xem [Asset inventory](ASSET_INVENTORY.md).

Source publisher theo dõi ownership/hash, nâng cấp cả đường dẫn phẳng và `admin/{name}` cũ, giữ file host và dừng toàn bộ batch nếu file đã đổi chỗ có chỉnh sửa local. Không sửa output Blade/JS bằng tay. `admin:doctor` kiểm source, namespace, renderer, asset và các storage adapter đã cấu hình.

## Lớp JavaScript dùng chung

`AdminUI` tạo state instance cho từng view; không giữ draft hay actor trong singleton. ResourceManager, SettingsForm, LanguageForm và MediaLibraryForm quản lý dữ liệu/request. Controller trong `app/views` quản lý binding, sự kiện, dirty guard, focus và start/resume/pause/destroy. `.sao` của theme chỉ có markup, props/state và lời gọi binding.

Guard điều hướng, đổi ngôn ngữ và beforeunload được đăng ký/dispose theo lifecycle. Request có generation/abort để response cũ không đổi view đã pause. Không ghi đè navigation hook của project. Các request ghi có CSRF, revision và khả năng truy vết theo contract từng module.

## Shell và menu

Mỗi page có một heading qua block `heading`; block `content` chứa nội dung. Shell giữ active page theo AdminUI và cập nhật cả khi view cache resume. Sidebar hỗ trợ desktop thu gọn, flyout bằng hover/focus, Escape và mobile backdrop.

Dropdown tài khoản/ngôn ngữ có mũi tên riêng với sidebar, phản ánh `aria-expanded`, đóng khi click ngoài/đổi trang/Escape và trả focus về nút mở. Menu neo theo nút trigger bằng CSS của theme; không gắn class cần Popper transform. Tài khoản chỉ hiện tên và subtitle được host cấu hình rõ ràng; không chèn trạng thái “Signed in”. Menu có email và các route hợp lệ được host cung cấp; logout vẫn là POST/DELETE có CSRF.

## Form, phản hồi và input

Các vùng input dùng fieldset để nhóm/disable; lưới layout nằm trong div bên trong, tránh cơ chế layout đặc biệt của legend/fieldset. Label, mô tả và lỗi nối bằng ID/ARIA. Form settings và CRUD đưa focus tới field lỗi sau render, giữ draft khi 422/409/session/network lỗi; discard khôi phục cả native input, checkbox và textarea.

`feedback.ts` phân loại thông báo dùng chung: success cho thao tác đã xác nhận, info cho trạng thái tải/preview, warning cho guard/reload/conflict, error cho dữ liệu hoặc request lỗi. Theme chọn màu theo token; nội dung thông báo luôn được dịch qua text resolver.

CRUD có 26 input preset, bố cục section/tab và media picker. Tất cả dùng cùng controller input, không nhân bản chức năng theo theme. HTML editor hiện soạn source với toolbar, chưa phải WYSIWYG; relation select tìm trong tối đa 1000 options do host lọc scope, chưa phải remote relation editor. Xem [danh mục input và ví dụ](RESOURCES.md#input-template-và-bố-cục-dựng-sẵn).

## Media, Settings và ngôn ngữ

Media page/picker dùng cùng component. Search và submit là một input group; bộ lọc có chiều rộng hữu hạn, wrap trên màn hẹp. Trạng thái rỗng không hiện pagination giả, nút trang chỉ hiện khi có nhiều trang. Picker nằm trên header/sidebar, giữ focus, đóng bằng Escape và trả focus về control gọi. Upload/metadata/trash/selection vẫn kiểm quyền và revision; không có purge/crop/CDN tự động.

Settings chia Website/Hệ thống; các group dùng tab link có guard, locale nội dung độc lập locale UI. Chỉ giá trị chưa được override mới có gợi ý fallback. Khi discard/reload, native controls được tạo lại từ snapshot; dữ liệu kế thừa không tự tạo patch nếu chưa tương tác.

Trang ngôn ngữ có locale/defaults, form thêm locale, export/import preview/draft/publish và lịch sử. Chỉ phần cần dùng mới mở ra; toolbar không kéo giãn nút theo file input. Các permission manage/export/import/publish độc lập. Không tự công bố bản dịch khi upload hoặc preview.

## Mức kiểm chứng và phần tiếp tục

Kiểm chứng bằng PHP package, source publish thật → compiler → strict TypeScript, SSR/hydration/CSR, dirty guard và lifecycle. Gehihi kiểm tích hợp PostgreSQL test riêng, build và browser native cho các màn hiện tại. Các lần kiểm cụ thể được ghi trong tài liệu module/release; không coi test state là chứng nhận mọi layout responsive hoặc tranh chấp nhiều kết nối.

Role/grant UI, trình sửa dịch từng key/AI, remote relation picker, WYSIWYG và kiểm chứng concurrency DB nhiều connection vẫn chưa thuộc chức năng đã hoàn thiện. Source đang phát triển; chưa tạo tag hay phát hành thay alpha.1.
