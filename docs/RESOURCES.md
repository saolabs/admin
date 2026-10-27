# Resource: danh sách, form và thùng rác dùng chung

Mốc 26/10/2026, source sau alpha.1, chưa phát hành tag mới. `Saola\Admin\Resources\Resource` là định nghĩa PHP để admin cung cấp list, tìm kiếm/sắp xếp/phân trang, thêm/sửa, xóa mềm, xem thùng rác, khôi phục và thao tác nhiều dòng. Module thông thường không cần thêm controller, route, JS hoặc `.sao` riêng.

Ý tưởng giữ sự tiện dụng của hệ cũ ai-services: cấu hình form/list một lần rồi dùng bộ màn hình chung. Thiết kế mới dùng model, scope và Policy của host; không đưa repository/controller kế thừa của hệ cũ vào package. `Page`/`ResourceDocument` chỉ đọc hiện có vẫn hoạt động. Settings, language exchange và media giữ contract riêng, không ép tất cả thành CRUD.

## Thêm tính năng: thêm file, không sửa config (28/10/2026)

Trang được **tự phát hiện**: mọi class `Page` (kể cả `Resource`) không trừu tượng trong `app/Modules/*/Resources` và `app/Modules/*/Pages` tự đăng ký. Không còn danh sách `panel.pages` hay `panel.icons` phải sửa (vẫn đọc nếu có, cho trang nằm ngoài quy ước; đổi thư mục quét bằng `panel.discover`).

| Việc | Cách làm |
| --- | --- |
| CRUD mới | `php artisan admin:make:resource Shop/Product` sinh `app/Modules/Shop/Resources/ProductResource.php`, trường lấy từ cột bảng của model; in ra khoá nhãn cần thêm. Sau đó `admin:lang:sync`. |
| Màn hình riêng | `php artisan admin:make:screen Shop/reports` sinh Page, controller và `resources/saola/admin/views/modules/reports/index.sao`; in ra dòng route cần thêm vào `ModuleServiceProvider` của module. |
| Mục menu | `navigation()` trên class: `['icon' => 'ri-store-line', 'order' => 20, 'hidden' => false]`. Nhóm con dùng `GroupedPage`. Menu sắp theo `order` (mặc định 100), nhóm theo con đầu tiên. |
| Quyền | `abilityPrefix()` = `'shop.products'` thì gói kiểm `shop.products.view/create/update/delete/restore`, không cần Policy. Model có Policy đăng ký thì Policy thắng (luật riêng như "nhân sự không sửa chủ"). |
| Màn hình riêng gắn route | Page implements `ModulePage` (`module()` = đoạn thứ hai của tên route `admin.{module}.*`); `HostScreenController` tự tìm trang, không cần `pageKeys()`. |

Stub có thể ghi đè ở `stubs/admin.resource.stub`, `stubs/admin.screen.{page,controller,view}.stub` của project. Tiền tố ability/nhãn mặc định đặt một lần cho cả project ở `saola-admin.generator` (`ability_prefix`, `lang_prefix`).

### Tùy biến khi cần: view của project, cùng chạy SSR, hydrate và CSR

Các view dưới đây nằm cạnh `modules/` (thư mục của `panel.host_modules`), vào chung bundle và cây Blade, nên theme `@include` được ở cả server lẫn client. Đổi theme không phải viết lại chúng nếu chỉ ghép component của theme (`__base__ + 'components.…'`).

| Muốn | Thêm file | Khai báo |
| --- | --- | --- |
| Loại input mới | `resources/saola/admin/views/inputs/{template}.sao` | `Input::make('color-hex', 'shop.color', ['type' => 'text'])`: `type` có sẵn giữ validate, `template` lạ trỏ tới file |
| Ô danh sách riêng | `resources/saola/admin/views/columns/{renderer}.sao` (nhận `value`, `row`, `column`) | `['key' => 'name', 'labelKey' => …, 'renderer' => 'person']`; `columnValue()` có thể trả map phẳng các giá trị scalar |
| Cả màn hình của một Resource | `resources/saola/admin/views/resources/{key}.sao` (nhận `resource`, có `resource['mode']` = list/create/edit) | Không cần: có file là dùng. Gọi lại `@include(__base__ + 'components.manager', {resource: resource})` để chỉ bọc thêm |

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

## Bắt đầu bằng một Resource của project

```php
namespace App\Admin;

use App\Models\Category;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Saola\Admin\Resources\Resource;

final class CategoryResource extends Resource
{
    public function key(): string { return 'categories'; }
    public function titleKey(): string { return 'catalog.categories.title'; }
    public function ability(): string { return 'manage-categories'; }
    public function model(): string { return Category::class; }
    public function searchable(): array { return ['name']; }

    public function fields(): array
    {
        return ['name' => [
            'labelKey' => 'catalog.categories.name',
            'type' => 'text', 'required' => true, 'maxLength' => 100,
        ]];
    }

    public function query(Request $request): Builder
    {
        return Category::where('account_id', $request->user()->account_id);
    }

    public function newRecord(Request $request): Model
    {
        return new Category(['account_id' => $request->user()->account_id]);
    }

    public function rules(Request $request, ?Model $record): array
    {
        return ['name' => [
            ...($record ? ['sometimes'] : []), 'required', 'string', 'max:100',
            Rule::unique('categories')->where('account_id', $request->user()->account_id)->ignore($record?->getKey()),
        ]];
    }
}
```

Đặt file trong `app/Modules/{Module}/Resources/` để tự đăng ký (hoặc liệt kê trong `saola-admin.panel.pages` nếu nằm chỗ khác). Khai báo Gate `manage-categories`, đăng ký model Policy và nguồn `lang/{locale}/catalog.php` qua LanguageSources của host, cho phép nhãn cần dùng trong scope dictionary admin rồi sync. Tên file/catalog không buộc thêm namespace vendor; giá trị `titleKey`/`labelKey` là key lồng tùy ý. `.sao` dùng `__()`/`text()`, compiler hạ `__()` thành `text()`; không thay Laravel `__()`.

Host tạo migration/model `Category` của mình. Muốn thùng rác, model dùng Eloquent `SoftDeletes` và bảng có `deleted_at`. Không có SoftDeletes thì chỉ list/create/edit, không tự xóa cứng thay thế. Installer không sửa bảng hoặc gắn trait vào model của host. Chạy migration package có bảng `saola_resource_events` trên connection mặc định; tên bảng có cấu hình `resources.audit_table`.

## Quyền và phạm vi dữ liệu

| Hành vi | Điều kiện ngoài guard/Gate của panel và Resource ability |
| --- | --- |
| List/count/search | Model Policy `viewAny`, phạm vi từ `query(Request)` |
| Xem form hiện có | Policy `view` trên record trong scope |
| Thêm | Policy `create` trên model class |
| Sửa | Policy `update` trên record trong scope |
| Xem thùng rác và tổng số | Policy `viewTrash` trên model class, có SoftDeletes |
| Xóa mềm | Policy `delete` trên record |
| Khôi phục | Policy `restore` trên record |
| Xóa vĩnh viễn | `supportsPurge(): true` do host khai báo và Policy `forceDelete`; mặc định tắt |

`query()` phải giới hạn visibility trước count/search/pagination và mọi lookup ghi. Host không có tenant vẫn trả query tường minh. Không lọc quyền sau pagination. `viewAny` cho phép xem các cột đã khai báo trong scope; `view` quyết định quyền mở chi tiết, không dùng để lọc lại từng dòng list. Giữ global scopes của host, bỏ điều kiện SoftDeletes để package chọn active/trash; không dùng `onlyTrashed()` trong `query()`. Query giới hạn hàng, không joins/group/unions/limit/offset; không trả model khác.

Form/toolbar chỉ hiển thị thao tác được cấp quyền nhưng server vẫn kiểm lại mỗi request, kể cả bulk. Quyền theo trường dùng `readAbility`/`writeAbility` (Gate nhận model class khi list/create, nhận record khi edit/write). Trường không đọc được không xuất hiện ở list/search/sort/form; trường không ghi được bị từ chối khi client tự gửi. Muốn chỉ ghi, khai báo `writeOnly` rõ ràng.

Project quyết định tự xóa, quản trị viên cuối cùng, tenant, uniqueness và quan hệ liên quan. Không suy ra quyền từ tên cột `is_admin`, không tự tạo grants. Nếu phải bảo vệ bất biến khi nhiều request cùng chạy, host khóa dữ liệu liên quan trong `beforeMutation()` hoặc service của mình, cùng transaction.

## Fields và cột

`fields()` trả map `column_name => metadata`, không phải HTML. Các template dựng sẵn được liệt kê ở phần bên dưới. Metadata gồm `labelKey`, `required`, `optionalOnUpdate`, `nullable`, `default`, `readOnly`, `writeOnly`, `persist`, `maxLength`, `options` (giá trị => key nhãn) và hai ability theo trường. Key là identifier cột; không nhận tên bảng/cột/class/SQL từ request. Relation select, media picker và editor nội dung dùng contract input chung bên dưới.

Mặc định `columns()` lấy fields được lưu và không write-only; có thể override để chọn cột, thứ tự và `labelKey`. `sortable()` mặc định các cột list, `searchable()` mặc định rỗng. Scalar được escape ở view, không render HTML từ dữ liệu. Giá trị đọc là raw attribute, không serialize model/appends/relations. Default chỉ dùng ở form tạo mới; bất biến như account/owner vẫn phải gán tại `newRecord()`.

`rules()` thay rule mặc định theo từng field; validation luôn chạy server-side. Update gửi các trường đã đổi, nên custom rule thường dùng `sometimes` cùng `required`. `prepareValues()` chuẩn hóa trước validation. `save()` nhận đúng trường đã validate/allowlist, mặc định forceFill/save; chỉ khai fields mà host thực sự cho sửa. `persist:false` phục vụ xác nhận như password_confirmation, không ghi vào model. Password phải `writeOnly`; luôn trả giá trị rỗng, không có hash trong SSR/JSON. Để giữ nguyên mật khẩu khi sửa, dùng `optionalOnUpdate:true`, bỏ chuỗi rỗng và rule `sometimes`.

Host cần cấu hình TrimStrings/ConvertEmptyStringsToNull phù hợp với password lồng trong `values`, không cắt whitespace của mật khẩu. Không flash `values.password` hoặc `values.password_confirmation`. Hashing, đổi remember token, email verification và session revocation thuộc model/service host.

## Luồng và an toàn dữ liệu

Route được tạo dưới prefix đã chọn (`admin` mặc định, Gehihi dùng `control`): `/{key}`, `/{key}/create`, `/{key}/{id}/edit`; API dưới `/_api/resources/{key}`. Gói dùng web middleware/CSRF và guard của host, JSON version `schema:1`, response private/no-store. UUID, ULID và ID số dùng cùng contract; composite key chưa hỗ trợ. Form ghi dùng POST làm fallback để không đưa mật khẩu lên URL khi JS không chạy; CRUD cần runtime client, không có luồng submit HTML độc lập.

Update/trash/restore/purge dùng revision HMAC từ raw record và audit sequence, không chỉ timestamp. Dữ liệu đổi hoặc trải qua trash/restore làm request cũ trả 409. Form giữ draft/lỗi validation, chặn retry sau 409/thu hồi quyền, cần bỏ draft rồi tải lại. Create dùng UUID requestId và receipt theo actor/resource: retry cùng nội dung sau mất response không tạo trùng. Client không tự gửi lại request ghi. UI có guard khi rời trang/đổi ngôn ngữ, reset form và password khi quay lại lần tạo đã hoàn tất/hủy.

Bulk chỉ xóa mềm/khôi phục tối đa 50 record, khóa theo thứ tự ID, kiểm quyền/revision tất cả và ghi trong một transaction. Lỗi ở bất kỳ record/hook nào rollback cả dữ liệu và audit; không báo thành công một phần. Sau ghi client tải lại list/count/quyền với bộ lọc hiện tại. Nếu đã ghi thành công nhưng tải list lỗi, UI yêu cầu tải lại, không cho lặp thao tác trên danh sách cũ. Purge chỉ từng dòng, có xác nhận riêng; không có bulk purge.

Audit lưu actor, resource, record key, operation, tên trường đổi và create receipt HMAC, không lưu giá trị/password/token. Bảng audit đồng thời giữ sequence cho revision và receipt bền vững; không tùy tiện truncate/xóa receipt khi client còn có thể retry. Với dữ liệu đổi ngoài Resource, revision bắt raw attribute thay đổi; host chịu trách nhiệm đồng bộ các service/khóa nghiệp vụ ngoài package.

Store mặc định yêu cầu model và audit cùng connection mặc định để ghi nguyên tử. `store()` có thể trả class triển khai `ResourceStore` riêng cho service/persistence khác; định nghĩa Resource hiện vẫn dùng Eloquent model/schema. Không giữ request/query/draft trong singleton. Hooks chạy trong transaction, nên mail/job/webhook hoặc side effect ngoài DB phải dùng afterCommit/outbox tại host; rollback DB không thể thu hồi side effect đã gửi.

## Ví dụ đã tích hợp và mức kiểm chứng

Gehihi dùng `App\Admin\UserResource`, model User UUID thật, `VisibleUsers`, `UserPolicy` và migration `deleted_at` của host. UI list/thêm/sửa/thùng rác/khôi phục do package render toàn bộ. User mới luôn là tài khoản thường; không có trường nâng quyền. Policy chặn tự xóa, xóa admin và sửa danh tính admin khác; purge tắt. Khôi phục giữ UUID/workspace; cấp quyền quản trị vẫn là hành động riêng của Gehihi. Cấu hình `users.enabled=false` vì Resource đã cung cấp Page `users`; không đăng ký trùng directory chỉ đọc.

Kiểm chứng hiện có: PHP package trên SQLite (scope/field permissions/CAS/idempotency/rollback/purge opt-in), integration Gehihi trên PostgreSQL riêng, compiler/typecheck/build thật và client CSR/cache/dirty guards. Chưa chứng nhận tranh chấp đồng thời nhiều connection, MySQL hoặc mọi model/workflow. Đây là nền CRUD; role assignment và nghiệp vụ chuyên sâu tiếp tục phát triển theo Policy/service host.

## List UI theo ai-services — 26/10/2026

List dùng card, bộ lọc ngang (từ khóa, tìm theo trường, sắp xếp, chiều, số kết quả), nút icon có tên truy cập/tooltip và bảng kẻ dòng. Bấm tiêu đề cột đổi sắp xếp, đặt lại trang 1 và giữ bộ lọc. Màn hẹp giữ tìm kiếm trực tiếp; nút Bộ lọc mở các lựa chọn bổ sung. Footer chứa thao tác hàng loạt theo quyền, khoảng kết quả và các trang đánh số có dấu lược. Chỉ hiện checklist khi có dòng được phép chọn; không suy ra quyền từ việc hiển thị nút.

`search_by` chỉ nhận field trong `searchable()` sau lọc quyền đọc; bỏ trống tìm trên tất cả field đó. Server project `sortUrl`, `listing.searchOptions/pages/from/to` và `listUrl`. Link mở form giữ context đã validate, quay lại sau lưu/hủy dùng context đó; không nhận URL chuyển hướng tùy ý từ request. Trang vượt cuối danh sách được đưa về trang cuối còn dữ liệu, kể cả sau trash/restore.

Gehihi chỉ khai cột họ tên/email; UUID giữ trong giao thức thao tác. Form dùng hai cột trên desktop, một cột trên mobile, phân biệt thêm/sửa/xem, có dấu bắt buộc và gợi ý giữ mật khẩu khi sửa. Lỗi validation có tổng hợp và liên kết ARIA tới field; HTTP 401/419 khóa ghi và giữ draft. Integer từ input được chuẩn hóa trước so sánh dirty; select số dùng cùng kiểu chuỗi với option. Client khóa lần ghi sau khi đã nhận xác nhận thành công.


## Input template và bố cục dựng sẵn

Khai báo map `fields()` như trước hoặc dùng `Input::make(template, labelKey, attributes)`. Cách khai báo mảng cũ tương thích. `type` là kiểu dữ liệu/validation; `template` là cách trình bày. Ví dụ `switch` dùng boolean, `radio` và `relation` dùng select, `checkboxes` dùng multiselect, `range` dùng number. Schema kiểm tra template phải tương thích type; project không đưa HTML/CSS/JS vào cấu hình.

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

`options` nhận map giá trị → key bản dịch. Với dữ liệu từ model, override `fieldOptions(key, Request, ?Model)` và trả map giá trị → `['label' => tên thực]`. Host phải lọc tenant/quyền và có thể loại chính record đang sửa. Cùng tập options được đọc lại khi validate ghi; không nhận foreign key ngoài scope dù tự sửa request. Giới hạn 1000 options; tìm trên client cho tập nhỏ, chưa phải AJAX relation picker. Không đặt tên class/query hoặc endpoint từ client trong schema.

Bố cục dùng `sections()` (map key → labelKey/descriptionKey), `section` trên field và `formLayout()` trả `sections` hoặc `tabs`. Mặc định có section `main` và bố cục section. `span: full|half`, `rows`, `placeholderKey`, `helpKey`, `min/max/step`, `maxLength`, `maxItems`, `itemMaxLength` điều chỉnh input. Section không còn field sau lọc quyền được bỏ. Chuyển tab giữ draft; lỗi server mở tab chứa field lỗi. Bỏ thay đổi khôi phục cả mảng, input native và editor.

List thêm renderer `image`, `link`, `boolean`, `number`, `date` bên cạnh text/badge. URL được kiểm tra trước xuất; cell không render HTML tùy ý. Number hỗ trợ `decimals` từ 0 đến 6; date hiển thị Y-m-d. Override `columnValue(key, Model, Request)` cho giá trị tính toán; vẫn cần khai field/column, quyền đọc và `sortable()` tường minh cho cột thật. Field tính toán nên `readOnly:true, persist:false`.

Ví dụ hoàn chỉnh: [examples/CatalogResource.php](../examples/CatalogResource.php). Model, migration, Policy, dịch thuật và scope vẫn do project cung cấp. Ví dụ không tạo bảng hoặc đăng ký module tự động.

Hai theme dùng `components.input` và controller `app/views/input.ts` giống nhau; CRUD dùng `app/views/manager.ts` để quản lý draft, section và picker. Template chỉ chứa markup và binding. Tất cả nằm trong gói admin, source theme ở `resources/saola/admin/themes/{name}`.
