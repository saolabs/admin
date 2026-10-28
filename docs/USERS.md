# Người dùng trong admin

Từ 28/10/2026 gói không còn màn hình user riêng (`UserResource`, `users.*` trong config đã gỡ). Người dùng là một module của project như mọi module khác: model, repository, service của project; list/form khai bằng `Admin::list()`/`Admin::form()` trong provider của module. Thêm cột cho user = thêm một field vào mảng, không kế thừa class nào của gói. Xem [RESOURCES.md](RESOURCES.md).

Khung tối thiểu (rút từ Gehihi, `app/Modules/Users/ModuleServiceProvider.php`):

```php
Admin::menu('users', ['title' => 'admin.users.title', 'route' => 'admin.users.index', 'ability' => 'app.users.view', 'icon' => 'users', 'order' => 30]);
Admin::list('users', ['service' => UserService::class, 'columns' => ['name' => ['label' => 'admin.users.name'], 'email' => ['label' => 'admin.users.email']], 'search' => ['name', 'email'], 'sort' => ['name', 'email']]);
Admin::form('users', [
    'service' => UserService::class,
    'prepare' => [UserService::class, 'prepareAdmin'],   // trim, lowercase email trước "unique"
    'save' => [UserService::class, 'saveFromAdmin'],     // reset xác minh email, xoay remember token, gán vai trò
    'fields' => [
        'name' => ['type' => 'text', 'label' => 'admin.users.name', 'rules' => ['required', 'string', 'max:100']],
        'email' => ['type' => 'email', 'label' => 'admin.users.email', 'rules' => ['required', 'email', 'max:200', 'unique:users,email,{id}']],
        'password' => ['type' => 'password', 'label' => 'admin.resource.password', 'writeOnly' => true, 'optionalOnUpdate' => true, 'rules' => ['required', 'string', 'min:12', 'max:200', 'confirmed']],
        'password_confirmation' => ['type' => 'password', 'label' => 'admin.resource.password_confirmation', 'writeOnly' => true, 'persist' => false, 'optionalOnUpdate' => true, 'rules' => ['required', 'string', 'max:200']],
    ],
]);
```

Quyền theo bản ghi (không tự xóa mình, nhân sự không sửa chủ) đặt trong Policy của model User; Policy thắng ability suy ra. Hash mật khẩu, xác minh email, phiên đăng nhập vẫn thuộc model/service của project. Nhãn `admin.users.*` và `admin.resource.password*` có sẵn trong bản dịch của gói.
