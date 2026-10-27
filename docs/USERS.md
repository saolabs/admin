# Danh sách user của host

**Cập nhật 26/10/2026:** directory dưới đây giữ tương thích chế độ chỉ đọc của alpha.1. Để có thêm/sửa/xóa mềm/thùng rác/khôi phục, dùng [Resource dùng chung](RESOURCES.md) với User model, Policy và service của project. Gehihi đã tích hợp theo cách này, không viết view user riêng. Khi đăng ký Resource key `users`, để `users.enabled=false` để tránh trùng Page. Package không tự sửa bảng User; SoftDeletes/migration/password/session/role thuộc host.

Mốc 24/10/2026: đã có directory **chỉ đọc**, tìm kiếm/phân trang/sắp xếp qua renderer chung. Chưa có create/update, đổi password, trạng thái tài khoản, role assignment hoặc user detail. Không tạo User model/table thứ hai và không thay auth của host.

## Bật có chủ đích

`saola-admin.users.enabled` mặc định `false`. Khi bật, provider đăng ký Page `users`, dùng route sẵn có `/{prefix}/users`, view `admin.pages.resource`; không thêm một bộ HTML riêng. Không đăng ký thêm UsersPage trong panel.pages khi đã bật tùy chọn này.

Host cần cả ba lớp: panel guard/Gate, ability `view-admin-users` (có cấu hình) và model Policy `viewAny`. Read ability không tự cấp `viewAny`; `access-admin` cũng không cấp read ability. Host nối chúng về saola/permission/Policy hiện có. Không có role/grant được tạo tự động. Menu và request trực tiếp kiểm cùng điều kiện trên mỗi request.

```php
// config/saola-admin.php
'users' => [
    'enabled' => true,
    'model' => null,
    'read_ability' => 'view-admin-users',
    'query_scope' => App\Admin\VisibleUsers::class,
    'columns' => ['name' => 'name', 'email' => 'email'],
],
```

Model mặc định lấy từ `panel.guard` → auth guard → provider.model, không hardcode `App\Models\User`. `users.model` là override backend tường minh. Adapter Eloquent cần model triển khai Authenticatable, primary key dạng scalar (số/chuỗi UUID/ULID đã lưu), ba cột key/name/email khác nhau và tồn tại. Name/email có thể map sang display_name/login_email; không suy ra tên cột từ request, không tự thêm cột. Giá trị directory lấy từ raw column, không chạy accessor/cast trình bày của model, không serialize model/appends/relations. Giữ query global scopes nhưng thay select/order/eager loads bằng projection ba cột và thứ tự ổn định theo key.

## Thêm thông tin cho user (kế thừa, không sửa gói)

Gói có sẵn `Saola\Admin\Users\UserResource` (name, email, password, thùng rác). Project kế thừa, thêm cột riêng rồi đăng ký lớp con trong `panel.pages`; để `users.enabled=false`:

```php
class UserResource extends \Saola\Admin\Users\UserResource
{
    public function fields(): array { return [...parent::fields(), 'phone' => ['labelKey' => 'app.users.phone', 'type' => 'text', 'maxLength' => 30]]; }
    public function columns(): array { return [...parent::columns(), ['key' => 'phone', 'labelKey' => 'app.users.phone']]; }
    public function rules(Request $request, ?Model $record): array { return [...parent::rules($request, $record), 'phone' => ['nullable', 'string', 'max:30']]; }
}
```

Model lấy từ guard (hoặc `users.model`), phạm vi từ `users.query_scope`. Đổi độ dài mật khẩu bằng `passwordMin()`, quyền xem bằng `ability()`.

## Scope quyết định bản ghi và tổng số

`UserQueryScope` là class của host, bắt buộc cho Eloquent adapter khi bật directory. Không đoán tenant/team/ownership từ tên cột. Host khai scope trước tìm kiếm, count và pagination:

```php
namespace App\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Saola\Admin\Users\UserQueryScope;

final class VisibleUsers implements UserQueryScope
{
    public function apply(Builder $query, Request $request): Builder
    {
        $accountId = $request->user()->account_id;
        abort_unless(is_int($accountId) || is_string($accountId), 403);
        return $query->where('account_id', $accountId);
    }
}
```

Website không có tenant vẫn cần một class trả query tường minh sau khi host đã quyết định operator có quyền đọc cả danh sách. Model `viewAny` cho phép đọc directory trong phạm vi này, gồm các trường ID/name/email. Không tự gọi `view` từng row, không có field Policy tự động. Nếu từng operator chỉ được nhìn một tập hoặc không được đọc email thì host phải thay scope/adapter để lọc **trước** count/projection; không paginate toàn bộ rồi lọc row tại renderer. Không trả một model khác, không bỏ phạm vi chỉ vì đang search. Scope nên chỉ giới hạn visibility, không join/group/select/limit làm đổi ngữ nghĩa identity hoặc nhân bản row.

Backend khác Eloquent: bind `Users\UserDirectory` trong provider của host sau admin. Contract có `allows(Request)`, `document(Request, ListingQuery)` và `diagnostics()`; trả ResourceDocument đã scope/project, không raw model hoặc remote secret. Binding này thay adapter Eloquent và không bắt buộc config model/query_scope. Đừng giữ actor/query/response trong singleton; registry chỉ giữ class definition.

## List contract và frontend

| Query | Chấp nhận |
| --- | --- |
| q | Chuỗi tối đa 100 ký tự, tìm name/email; `%`, `_`, `!` là ký tự literal |
| sort | name, email, id; map server-side sang cột đã cấu hình |
| direction | asc hoặc desc |
| page | Integer 1–100000; trang vượt cuối trả rows rỗng |
| per_page | 10, 25 hoặc 50; mặc định 25 |
| locale | Locale giao diện theo contract panel hiện có |

Sai dạng/giá trị trả 422; request không chọn model/table/SQL column hoặc eager relation. Search dùng binding SQL và được nhóm trong scope. So khớp chữ hoa/thường dùng LOWER của DB; Unicode/collation phụ thuộc DB và còn cần kiểm trong matrix MySQL/Postgres. Thứ tự có tie-break primary key; pagination dạng offset không đảm bảo snapshot cố định nếu dữ liệu đổi giữa hai lần đọc.

ResourceDocument schema 1 thêm `listing` tùy chọn (null với Page cũ). ResourceListing dựng action/links từ prefix cố định và query đã validate; không phản chiếu host/header/query lạ. DTO chứa search/sort/page size/page/lastPage/total/previous/next và options có label key. Renderer chung chỉ hiện filter/pagination khi có listing, chỉ hiện Details khi có fields.

Form GET dùng được khi không có JS. Khi có JS, submit qua Router.navigate để giữ lifecycle/guard của host; filter mới reset page. Link phân trang giữ locale/filter/sort/page size. SSR, hydrate, CSR và đổi UI locale dùng cùng dữ liệu/nhãn từ DB. HTML/cell/search được escape; tổng số và metadata vẫn private/no-store như các page admin khác.

## Kiểm tra và bước tiếp theo

`admin:doctor` kiểm model/scope/cột trên connection của model khi users.enabled; chỉ đọc schema, không thêm bảng/cột/quyền. Doctor không chứng nhận scope đúng nghiệp vụ hoặc Policy đã đủ; host cần test visibility và quyền trực tiếp. Disabled directory không resolve model hoặc mở Page users.

Workbench `/control/users` dùng fixture giả tenant 7, 27 row nhìn thấy; có row tenant khác và row bị global scope ẩn để kiểm count/search. Test bảo đảm không chọn password, không gọi appends/accessors/default relations; có model khóa chuỗi/cột khác, Policy thay đổi, adapter thay thế, query sai/literal search, SSR/hydrate/đổi locale/filter/pagination và HTTP thật trên SQLite tạm. Chưa thay DB/dependency/auth của starter.

Bước P2 tiếp theo: form/action Resource và User adapter ghi với field allowlist, UserPolicy cho danh tính/mật khẩu, revision dưới transaction và audit. Gán role/grant thuộc P3 với GrantPolicy và bảo vệ operator cuối cùng; chưa sinh nút ghi trước khi các contract này có.
