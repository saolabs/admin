# Danh sách, form và thùng rác: `->menu()->list()->form()`

Mốc 28/10/2026, chưa phát hành tag. Module chỉ lo nghiệp vụ và dữ liệu (Model, Repository, Service của core); giao diện admin được **khai báo bằng mảng** từ `ModuleServiceProvider` của module. Gói dựng list, tìm kiếm/sắp xếp/phân trang, thêm/sửa, xóa mềm, thùng rác, khôi phục và thao tác nhiều dòng từ các mảng đó. Không còn class `Page`/`Resource` trong project, không quét thư mục, không sửa config khi thêm tính năng. Kế hoạch và các quyết định: [ADMIN_REGISTRY_PLAN.md](ADMIN_REGISTRY_PLAN.md).

Một khai báo field phục vụ ba việc: giao diện (`type`), validate ở server và trình duyệt (`rules`, cú pháp Laravel qua Validator của core) và thông báo lỗi (`messages`, khóa bản dịch). Mọi mảng được kiểm khi boot: khóa lạ, thiếu `title`/`ability`, service không phải `ModuleService`, callable không tồn tại đều ném lỗi trước request đầu tiên.

## Thêm một CRUD vào admin

Ba lớp controller: gói cung cấp lớp nền theo context; project luôn có `App\Http\Controllers\AdminController` (dòng `extends` chọn gói phục vụ context admin, `admin:install` tự trỏ sang gói này, `admin:doctor` kiểm); controller của module chỉ kế thừa lớp của project.

```php
// app/Modules/Shop/Http/Controllers/Admin/ProductController.php
class ProductController extends \App\Http\Controllers\AdminController {}

// app/Modules/Shop/ModuleServiceProvider.php
public function define(): void   // core ≥ 2.6; trước đó hook này tên routes()
{
    System::context('admin')->module('products')->controller(ProductController::class)
        ->menu(['title' => 'shop.products.title', 'ability' => 'shop.products.view', 'icon' => 'ri-store-line', 'order' => 20])
        ->list([
            'service' => ProductService::class,
            'columns' => ['name' => ['label' => 'shop.products.name'], 'price' => ['label' => 'shop.products.price', 'type' => 'number', 'decimals' => 2]],
            'search' => ['name'],
            'sort' => ['name', 'price'],
        ])
        ->form([
            'service' => ProductService::class,
            'messages' => ['required' => 'shop.errors.required'],
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'shop.products.name', 'rules' => ['required', 'max:120', 'unique:products,name,{id}'], 'messages' => ['unique' => 'shop.errors.name_taken']],
                'category_id' => ['type' => 'select', 'label' => 'shop.products.category', 'options' => [ProductService::class, 'categoryOptions']],
                'price' => ['type' => 'number', 'label' => 'shop.products.price', 'rules' => ['required', 'numeric', 'min:0']],
            ],
        ])
        ->routes(fn ($m) => ProductController::routes($m));   // routes() = group()
}
```

`module('products')` đặt cả slug lẫn prefix. `menu()/list()/form()` là phương thức của module trên route builder (gói đăng ký bằng `Router::macro()`), gọi trước `routes()`; tên route của module (`as`) mặc định theo slug. Khóa = tên route bỏ context: `admin.products` → `products`. `menu()` tự trỏ `route` vào `{tên route}.index` (đặt `route`/`url` để trỏ khác).

Module lồng nhau theo cây module, khóa có dấu chấm và nhóm menu suy từ cha:

```php
// app/Modules/Products/Categories/ModuleServiceProvider.php
System::context('admin')->module('products')->menu(['title' => 'shop.catalog', 'icon' => 'ri-store-line', 'order' => 20])   // có menu con → thành nhóm
    ->sub('categories')->controller(CategoryController::class)
    ->menu(['title' => 'shop.categories.title', 'ability' => 'shop.categories.view'])->list([...])->form([...])
    ->routes(fn ($m) => CategoryController::routes($m));   // /admin/products/categories, admin.products.categories.*, khóa products.categories
```

Module tổ tiên gần nhất có `menu()` (hoặc nhóm đăng ký bằng `Admin::group()`) làm nhóm; tầng không có `menu()` bị bỏ qua; sidebar lồng đúng một cấp. Nhóm dùng chung cho các module phẳng: `->menu([..., 'group' => 'ai'])` cùng `Admin::group('ai', …)` trong hook `menus()`. `Admin::menu/group/list/form(key, …)` vẫn là API nền cho route Laravel thường và mục menu không phải route.

`ProductController::routes($m)` khai 7 route chuẩn trỏ vào action của lớp nền: trang `index` `/`, `create` `/create`, `edit` `/{record}/edit` (SSR + SPA, view `@BASE:pages.resource`) và API chỉ ghi `api` `/_api` (POST tạo), `api.bulk` `/_api/bulk`, `api.item` `/_api/{record}` (PUT sửa), `api.state` `/_api/{record}/{trash|restore|purge}`. Action của gói tên `listPage/createPage/editPage/apiRecords/apiRecord/apiTransition/apiBulk`, để `index/create/edit/store/update/destroy` hoàn toàn thuộc về module. Module muốn xử lý khác thì override action hoặc tự khai route trỏ vào hàm của mình. Dùng route Laravel thường thì gọi `ProductController::routes()` trong `Route::prefix('admin/products')->name('admin.products.')->group(...)`.

Với builder System, helper còn khai permission enum: `index/edit` cần READ; `create/api` cần READ + CREATE; `api.item` cần READ + UPDATE. Form `edit` cũng dùng để xem record chỉ đọc. `api.bulk/api.state` cần READ tại route, rồi ResourceStore kiểm đúng DELETE/RESTORE/FORCE_DELETE theo operation và Policy của từng record. Không gộp DELETE + RESTORE làm permission route vì đó là phép AND. Host bật `saola.permission.route` trên context và khai ACCESS theo chính sách của mình; metadata không thay thế kiểm Policy ở controller/store. Route Laravel thường giữ kiểm Gate/Policy của resource, không có metadata System tự sinh.

Dữ liệu list/form đi qua chính URL trang: tải thẳng thì SSR render sẵn HTML kèm dữ liệu để hydrate, điều hướng SPA và lọc/sắp xếp/phân trang thì `@await` của view lấy JSON từ cùng URL đó; không có GET nào dưới `/_api`.

Khóa của request = khóa đăng ký dài nhất mà tên route nối tiếp (`AdminRegistry::routeKey()`: `admin.products.categories.api.item` → `products.categories` + `api.item`); đặt `protected $module = 'products';` trên controller để chỉ định khác. Ghi đi qua `create/update/moveToTrash/restoreFromTrash` của service, tức CRUD của core, nên sự kiện của repository vẫn phát.

## Tham chiếu khóa

| Hàm | Khóa |
| --- | --- |
| `Admin::menu(key, …)` | `title`, `ability` (bắt buộc); đúng một trong `route`/`url`; `icon`, `order` (mặc định 100), `group`, `hidden` |
| `Admin::group(key, …)` | `title`, `icon`, `order`. Nhiều module đăng ký cùng nhóm với cùng định nghĩa là hợp lệ |
| `Admin::list(key, …)` | `service`, `columns` (bắt buộc); `query` (callable thu hẹp phạm vi), `ability` (mặc định của menu), `search`, `sort`, `title`, `description` |
| cột | `label`; `type` (renderer: text, badge, image, link, boolean, number, date hoặc view của project), `decimals`, `value` (callable `($record, $request)`), `ability` |
| `Admin::form(key, …)` | `service`, `fields`; `save` (callable `($record|null, $values, $request)` trả model đã lưu), `prepare` (callable `($values, $request, $record|null)` chuẩn hóa trước validate), `messages`, `sections`, `layout` (`sections`/`tabs`) |
| field | `type`, `label`; `rules`, `messages`, `placeholder`, `help`, `options` (mảng hoặc callable `($request, $record)`), `default`, `section`, `span`, `readOnly`, `writeOnly`, `persist`, `ability`, `optionalOnUpdate`, `rows`, `maxItems`, `itemMaxLength`, `step`, `translatable` |

Callable luôn là `[Class::class, 'method']`, gọi trên instance lấy từ container; không nhận closure. Registry không bao giờ đổ nguyên ra JSON: trang list/form chỉ mang field người dùng được mở, khóa trình bày, và `rules` chỉ còn luật trình duyệt tự kiểm (`AdminRegistry::clientRules()`/`clientMessages()`).

- `rules`: luật Laravel. `required`, `nullable`, `max:`/`min:` còn được suy ra cho input; `{id}` thay bằng khóa của bản ghi đang sửa. Chỉ luật trình duyệt tự kiểm được (required, nullable, email, url, numeric, integer, boolean, date, confirmed, min/max/between/size/digits, in/not_in) đi kèm field trong JSON; `unique`/`exists` (lộ tên bảng, cột), `regex` (PCRE, định dạng nội bộ), Rule object và mọi luật khác chỉ chạy ở server.
- `messages`: `rule => khóa bản dịch`, ở form (mọi field) hoặc field (ghi đè). Nhãn field làm tên thuộc tính trong câu lỗi.
- `ability`: ability Gate cho cả đọc và ghi field. Field không đọc được không có trong list/form; client tự gửi field không ghi được bị từ chối 422.
- `persist: false`: giá trị không ghi vào model nhưng có trong request (`$request->input('values.x')`) cho hook `save`, ví dụ ma trận quyền, `password_confirmation`.
- `type` lạ (không phải template dựng sẵn) là view `inputs/{type}.sao` của project; dữ liệu validate như text.

Quyền thao tác: ability `….view` cho suy ra `….create/update/delete/restore`; model có Policy đăng ký thì Policy thắng (luật riêng như "nhân sự không sửa chủ").

## Tùy biến khi cần: view của project, cùng chạy SSR, hydrate và CSR

Các view dưới đây nằm cạnh `modules/` (thư mục của `panel.host_modules`), vào chung bundle và cây Blade, nên theme `@include` được ở cả server lẫn client. Đổi theme không phải viết lại chúng nếu chỉ ghép component của theme (`__base__ + 'components.…'`).

| Muốn | Thêm file | Khai báo |
| --- | --- | --- |
| Loại input mới | `resources/saola/admin/views/inputs/{type}.sao` | `'color' => ['type' => 'color-hex', 'label' => …]` |
| Ô danh sách riêng | `resources/saola/admin/views/columns/{type}.sao` (nhận `value`, `row`, `column`) | `'name' => ['label' => …, 'type' => 'person']`; `value` callable có thể trả map phẳng các giá trị scalar |
| Cả màn hình của một list/form | `resources/saola/admin/views/resources/{key}.sao` (nhận `resource`, có `resource['mode']` = list/create/edit) | Không cần: có file là dùng. Gọi lại `@include(__base__ + 'components.manager', {resource: resource})` để chỉ bọc thêm |
| Màn hình nghiệp vụ riêng | `resources/saola/admin/views/modules/{module}/{action}.sao` | Route của module trỏ vào action riêng, `$this->response($data)`; shell và menu tự có |

Input riêng phải gắn controller chung để form ghi nhận thay đổi, giống input của theme:

```blade
<script setup lang="ts">
    @vars(field: any = {}, error: string = '', locked: boolean = false, onChange: any = null, onMedia: any = null, mediaAvailable: boolean = false)
    App.Admin.controllers.input($view, () => ({field, error, locked, onChange, onMedia, mediaAvailable}));
</script>
<template>
    <input class="form-control" id="resource-field-{{ field['key'] }}" name="{{ field['key'] }}" value="{{ field['value'] }}" @disabled(locked || field['readOnly']) @input(input($event))>
</template>
```

Giá trị vẫn được escape; ô và input riêng không nhận HTML thô từ dữ liệu.

Muốn thùng rác, model dùng chế độ xóa mềm của core (`$deleteMode = 'soft'`) và bảng có `deleted_at`; không có thì chỉ list/create/edit. Chạy migration package có bảng `saola_resource_events` (audit, revision, receipt) trên connection mặc định.

## Quyền và phạm vi dữ liệu

| Hành vi | Điều kiện ngoài guard/Gate của panel và ability của list |
| --- | --- |
| List/count/search | Model Policy `viewAny`, phạm vi từ callable `query` của list |
| Xem form hiện có | Policy `view` trên record trong scope |
| Thêm | Policy `create` trên model class |
| Sửa | Policy `update` trên record trong scope |
| Xem thùng rác và tổng số | Policy `viewTrash` trên model class, có SoftDeletes |
| Xóa mềm | Policy `delete` trên record |
| Khôi phục | Policy `restore` trên record |
| Xóa vĩnh viễn | `supportsPurge(): true` do host khai báo và Policy `forceDelete`; mặc định tắt |

Callable `query` phải giới hạn visibility trước count/search/pagination và mọi lookup ghi. Host không có tenant vẫn trả query tường minh. Không lọc quyền sau pagination. `viewAny` cho phép xem các cột đã khai báo trong scope; `view` quyết định quyền mở chi tiết, không dùng để lọc lại từng dòng list. Giữ global scopes của host, bỏ điều kiện SoftDeletes để package chọn active/trash; không dùng `onlyTrashed()` trong `query`. Query giới hạn hàng, không joins/group/unions/limit/offset; không trả model khác.

Form/toolbar chỉ hiển thị thao tác được cấp quyền nhưng server vẫn kiểm lại mỗi request, kể cả bulk. Quyền theo trường dùng `ability` (Gate nhận model class khi list/create, nhận record khi edit/write). Trường không đọc được không xuất hiện ở list/search/sort/form; trường không ghi được bị từ chối khi client tự gửi. Muốn chỉ ghi, khai báo `writeOnly` rõ ràng.

Project quyết định tự xóa, quản trị viên cuối cùng, tenant, uniqueness và quan hệ liên quan. Không suy ra quyền từ tên cột `is_admin`, không tự tạo grants. Nếu phải bảo vệ bất biến khi nhiều request cùng chạy, host khóa dữ liệu liên quan trong hook `save` (service của mình), cùng transaction.

## Fields và cột

Field là metadata, không phải HTML. Key là tên cột; không nhận tên bảng/cột/class/SQL từ request. Cột của list không khai trong form hiện ở form dưới dạng chỉ đọc. Scalar được escape ở view; giá trị đọc là raw attribute, không serialize model/appends/relations. `default` chỉ dùng ở form tạo mới; bất biến như account/owner gán trong service.

Validation luôn chạy ở server. Update chỉ gửi trường đã đổi, nên `required` chỉ áp khi field có mặt. `prepare` chuẩn hóa trước validate (trim, lowercase email để `unique` thấy đúng dạng lưu). `save` nhận đúng trường đã validate/allowlist; không khai thì gói gọi `create/update` của service. Password phải `writeOnly`; luôn trả rỗng, không có hash trong SSR/JSON. Để giữ nguyên mật khẩu khi sửa, dùng `optionalOnUpdate: true`.

Host cấu hình TrimStrings để không cắt password lồng trong `values` (ví dụ ngoại trừ `{prefix}/*/_api*`). Hashing, đổi remember token, email verification thuộc model/service host.

## Luồng và an toàn dữ liệu

Route do module khai (xem trên): `/{module}`, `/{module}/create`, `/{module}/{id}/edit`; API ghi dưới `/{module}/_api`. Gói dùng web middleware/CSRF và guard của host, JSON version `schema:1`, response private/no-store. UUID, ULID và ID số dùng cùng contract; composite key chưa hỗ trợ. Form ghi dùng POST làm fallback để không đưa mật khẩu lên URL khi JS không chạy; CRUD cần runtime client, không có luồng submit HTML độc lập.

Update/trash/restore/purge dùng revision HMAC từ raw record và audit sequence, không chỉ timestamp. Dữ liệu đổi hoặc trải qua trash/restore làm request cũ trả 409. Form giữ draft/lỗi validation, chặn retry sau 409/thu hồi quyền, cần bỏ draft rồi tải lại. Create dùng UUID requestId và receipt theo actor/resource: retry cùng nội dung sau mất response không tạo trùng. Client không tự gửi lại request ghi. UI có guard khi rời trang/đổi ngôn ngữ, reset form và password khi quay lại lần tạo đã hoàn tất/hủy.

Bulk chỉ xóa mềm/khôi phục tối đa 50 record, khóa theo thứ tự ID, kiểm quyền/revision tất cả và ghi trong một transaction. Lỗi ở bất kỳ record/hook nào rollback cả dữ liệu và audit; không báo thành công một phần. Sau ghi client tải lại list/count/quyền với bộ lọc hiện tại. Nếu đã ghi thành công nhưng tải list lỗi, UI yêu cầu tải lại, không cho lặp thao tác trên danh sách cũ. Purge chỉ từng dòng, có xác nhận riêng; không có bulk purge.

Audit lưu actor, resource, record key, operation, tên trường đổi và create receipt HMAC, không lưu giá trị/password/token. Bảng audit đồng thời giữ sequence cho revision và receipt bền vững; không tùy tiện truncate/xóa receipt khi client còn có thể retry. Với dữ liệu đổi ngoài form admin, revision bắt raw attribute thay đổi; host chịu trách nhiệm đồng bộ các service/khóa nghiệp vụ ngoài package.

Store mặc định yêu cầu model và audit cùng connection mặc định để ghi nguyên tử; model lấy từ repository của service. Không giữ request/query/draft trong singleton. Hooks chạy trong transaction, nên mail/job/webhook hoặc side effect ngoài DB phải dùng afterCommit/outbox tại host; rollback DB không thể thu hồi side effect đã gửi.

## Ví dụ đã tích hợp và mức kiểm chứng

Gehihi đăng ký `users` và `roles` trong `app/Modules/Users/ModuleServiceProvider.php`: list/form bằng mảng, hook `prepareAdmin`/`saveFromAdmin`/`roleOptions` trên `UserService`, `grantsMatrix`/`saveFromAdmin` trên `RoleService`, `UserPolicy`/`RolePolicy` của host. UI list/thêm/sửa/thùng rác/khôi phục do gói render toàn bộ. Policy chặn tự xóa, xóa chủ và sửa danh tính người khác; purge tắt.

Kiểm chứng: PHP package trên SQLite (`RegistryCrudTest`: sự kiện CRUD của core, rules/messages/options/ability, `prepare`, route module, `admin:install`), integration Gehihi trên PostgreSQL, SSR và SPA trên trình duyệt. Chưa chứng nhận tranh chấp đồng thời nhiều connection hoặc MySQL.

## List UI theo ai-services — 26/10/2026

List dùng card, bộ lọc ngang (từ khóa, tìm theo trường, sắp xếp, chiều, số kết quả), nút icon có tên truy cập/tooltip và bảng kẻ dòng. Bấm tiêu đề cột đổi sắp xếp, đặt lại trang 1 và giữ bộ lọc. Màn hẹp giữ tìm kiếm trực tiếp; nút Bộ lọc mở các lựa chọn bổ sung. Footer chứa thao tác hàng loạt theo quyền, khoảng kết quả và các trang đánh số có dấu lược. Chỉ hiện checklist khi có dòng được phép chọn; không suy ra quyền từ việc hiển thị nút.

`search_by` chỉ nhận field trong `searchable()` sau lọc quyền đọc; bỏ trống tìm trên tất cả field đó. Server project `sortUrl`, `listing.searchOptions/pages/from/to` và `listUrl`. Link mở form giữ context đã validate, quay lại sau lưu/hủy dùng context đó; không nhận URL chuyển hướng tùy ý từ request. Trang vượt cuối danh sách được đưa về trang cuối còn dữ liệu, kể cả sau trash/restore.

Gehihi chỉ khai cột họ tên/email; UUID giữ trong giao thức thao tác. Form dùng hai cột trên desktop, một cột trên mobile, phân biệt thêm/sửa/xem, có dấu bắt buộc và gợi ý giữ mật khẩu khi sửa. Lỗi validation có tổng hợp và liên kết ARIA tới field; HTTP 401/419 khóa ghi và giữ draft. Integer từ input được chuẩn hóa trước so sánh dirty; select số dùng cùng kiểu chuỗi với option. Client khóa lần ghi sau khi đã nhận xác nhận thành công.


## Input template và bố cục dựng sẵn

`type` của field là template trình bày; kiểu dữ liệu/validate suy ra từ nó. Ví dụ `switch` dùng boolean, `radio` và `relation` dùng select, `checkboxes` dùng multiselect, `range` dùng number. Project không đưa HTML/CSS/JS vào cấu hình.

| Template | Trường hợp dùng |
| --- | --- |
| text, email, url, tel, password | Văn bản một dòng, email, URL, điện thoại, bí mật chỉ ghi |
| textarea | Mô tả nhiều dòng, có rows và maxLength |
| integer, number, range | Số nguyên, số thực, thanh trượt; min/max/step |
| date, datetime, time | Ngày, ngày giờ cục bộ và giờ; định dạng ISO từ input native |
| color | Màu dạng #rrggbb |
| boolean, switch | Checkbox hoặc công tắc |
| select, radio | Chọn một từ options |
| multiselect, checkboxes | Chọn nhiều từ options; giá trị mảng chuỗi |
| relation | Select có ô tìm trong options do host cung cấp theo scope |
| tags | Các thẻ phân tách bằng dấu phẩy; mảng chuỗi, maxItems/itemMaxLength |
| image, media | Chọn ảnh/tệp bằng media picker chung hoặc nhập URL; ảnh có preview |
| markdown | Soạn Markdown với công cụ chèn đậm/nghiêng/tiêu đề/list/link/code |
| richtext | Soạn mã HTML với toolbar chèn định dạng; server chỉ giữ HTML cơ bản an toàn |
| json | Soạn và format JSON, báo lỗi, giữ nguyên draft lỗi; tối đa 32 cấp |

`richtext` hiện là **editor mã nguồn HTML**, chưa phải WYSIWYG. Nội dung không được chạy trong admin. Server bỏ script/style/embed, thuộc tính sự kiện và URL không hợp lệ; chỉ giữ đoạn văn, heading, list, quote, code và liên kết an toàn. Package yêu cầu PHP ext-dom. Markdown lưu source, không tự render HTML trong list. Number dùng số thực thông thường; dữ liệu cần số học tiền tệ chính xác nên lưu số nguyên đơn vị nhỏ nhất hoặc xử lý decimal bằng service host.

`multiselect`, `checkboxes`, `tags` cần cast Eloquent `array`/`json` khi persist; `json` còn hỗ trợ cast `object`. Các lựa chọn nhiều lưu mảng; JSON được decode trước lưu và encode đẹp khi mở editor. Date là `Y-m-d`, datetime là `Y-m-dTH:i` không mang timezone, time là `H:i`; host quyết định chuyển timezone khi cần. Update gửi field đã đổi; ô optional chưa nhập khi tạo được bỏ qua để giữ default của model. Input dựng sẵn không tự đồng bộ pivot/relation nhiều-nhiều: host thực hiện qua service/custom store; select quan hệ đơn lưu foreign key đã khai báo.

`options` nhận map giá trị → key bản dịch. Với dữ liệu từ DB, khai `options => [Service::class, 'method']`, hàm nhận `($request, $record)` và trả map giá trị → `['label' => tên thực]` (với `matrix`: đặc tả ma trận). Host phải lọc tenant/quyền và có thể loại chính record đang sửa. Cùng tập options được đọc lại khi validate ghi; không nhận foreign key ngoài scope dù tự sửa request. Giới hạn 1000 options; tìm trên client cho tập nhỏ, chưa phải AJAX relation picker. Không đặt tên class/query hoặc endpoint từ client trong schema.

Bố cục dùng `sections` của form (map key → `['labelKey' => …]`), `section` trên field và `layout` = `sections` hoặc `tabs`. Mặc định có section `main` và bố cục section. `span: full|half`, `rows`, `placeholder`, `help`, `step`, `maxItems`, `itemMaxLength` và `min:`/`max:` trong `rules` điều chỉnh input. Section không còn field sau lọc quyền được bỏ. Chuyển tab giữ draft; lỗi server mở tab chứa field lỗi. Bỏ thay đổi khôi phục cả mảng, input native và editor.

List thêm renderer `image`, `link`, `boolean`, `number`, `date` bên cạnh text/badge. URL được kiểm tra trước xuất; cell không render HTML tùy ý. Number hỗ trợ `decimals` từ 0 đến 6; date hiển thị Y-m-d. Cột tính toán dùng `value => [Service::class, 'method']`; chỉ cột thật mới đưa vào `sort`.

Hai theme dùng `components.input` và controller `app/views/input.ts` giống nhau; CRUD dùng `app/views/manager.ts` để quản lý draft, section và picker. Template chỉ chứa markup và binding. Tất cả nằm trong gói admin, source theme ở `resources/saola/admin/themes/{name}`.
