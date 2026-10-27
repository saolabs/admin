# Material UI components

Nguồn: `resources/saola/admin/themes/material/views/components/ui/`. Namespace: `admin.material.components.ui.*`.

UI dùng markup, class, CSS, font và icon từ **Velzon Material** trong `admin-material`. Không thêm bộ CSS thiết kế mới; không chạy template `app.js` hoặc Bootstrap DOM plugins cùng Saola. Bản ghi nguồn/SHA-256: `themes/material/assets/ui.sources.json`. Bốn ảnh card mẫu được sao chép nguyên bản. Classic giữ renderer hiện có, không tự nạp CSS Material.

## Cách dùng

```sao
@include(__base__ + 'components.ui.button', {
    data: {label: text('admin.settings.save'), action: 'save', color: 'success', variant: 'outline', icon: 'ri-save-line', disabled: busy},
    on$action: (id) => save()
})
```

`__base__` trỏ tới theme Material của context; có thể dùng tên view đầy đủ khi ở context khác. Label là nội dung đã dịch, không phải translation key. Text được escape ở SSR và CSR; không truyền HTML để render nội dung.

```ts
import type {UiButton, UiCard, UiPropsMap} from '@sao/admin/app/contracts/ui';
const save: UiButton = {label: 'Save', color: 'success', variant: 'outline'};
const modal: UiPropsMap['dialog'] = {id: 'edit-product', label: 'Edit', title: 'Product', closeLabel: 'Close', centered: true};
```

Màu theo template: `primary`, `secondary`, `success`, `info`, `warning`, `danger`, `dark`, `light`. Button còn có `color: 'link'` với kiểu mặc định. Biến thể là data, không phải một file cho mỗi màu/kích thước.

| Component | Data chính | Sự kiện |
| --- | --- | --- |
| `button` | `label`, `action`, `href`, `icon`, `iconOnly`, `color`, `variant: solid/outline/soft/ghost`, `size: sm/md/lg`, `rounded`, `disabled`, `loading`, `type` | `action(id, DOMEvent)` |
| `card` | `header`, `title`, `subtitle`, `text`, `image`, `imageAlt`, `imagePosition: top/middle/bottom`, `items`, `actions`, `actionsInBody`, `color` | Chuyển tiếp `action` |
| `stat-card` | `label`, `value`, `icon`, `color`, `filled`, `change`, `trend: up/down/neutral`, `caption`, `link` | Chuyển tiếp `action` |
| `alert` | `id`, `title`, `text`, `color`, `icon`, `borderLeft`, `dismissible`, `closeLabel` | `close` |
| `badge` | `label`, `color`, `icon`, `soft`, `rounded` | — |
| `avatar` | `label`, `src` hoặc `initials`/`icon`, `color`, `size: xs/sm/md/lg`, `rounded` | — |
| `breadcrumb` | `label`, `items: {id,label,href?,active?}[]` | Link thông thường |
| `list-group` | `items: {id,label,text?,icon?,badge?,active?,disabled?}[]`, `flush`, `numbered` | — |
| `progress` | `label`, `value` (giới hạn 0–100), `color`, `size`, `striped`, `animated`, `showValue` | — |
| `spinner` | `label`, `color`, `small`, `grow` | — |
| `placeholder` | `label`, `widths` (cột Bootstrap 1–12), `size`, `wave` | — |
| `empty-state` | `title`, `text`, `icon`, `action: UiButton` | Chuyển tiếp `action` |
| `dropdown` | `id`, `label`, `items: {id,label,icon?,disabled?}[]`, `color`, `align` | `action(id)`, `close`, `beforeclose` |
| `tabs` | `id`, `label`, `items: {id,label,text?,icon?,disabled?}[]`, `active`, `variant: tabs/pills/line` | `change(id)` |
| `accordion` | `id`, `items: {id,label,text?,disabled?}[]`, `expanded: string[]`, `multiple`, `flush` | `change(expandedIds)` |
| `dialog` | `id`, `label`, `title`, `text`, `closeLabel`, `open`, `actions`, `variant: modal/drawer`, `size`, `placement`, `centered`, `scrollable`, `dismissible` | `action`, `close`, `beforeclose` |
| `toast` | `id`, `title`, `text`, `caption`, `closeLabel`, `color`, `timeout` (ms, mặc định không tự đóng) | `close` |
| `pagination` | `label`, `previousLabel`, `nextLabel`, `page`, `totalPages`, `pages: number[]`, `size` | `change(page)` |

ID của nhóm tương tác phải duy nhất trong document. `items[].id` phải ổn định/duy nhất trong nhóm; label của action trong cùng card/dialog cũng phải duy nhất. `tabs.active` là ID của một item được phép chọn. Pagination nhận các số trang hợp lệ; ứng dụng quyết định cửa sổ trang, gọi API và URL.

Dialog `dismissible` điều khiển backdrop; Close/Escape vẫn đóng. Listener `beforeclose` trả `false` để giữ dialog khi form chưa lưu. Modal/offcanvas giữ focus trong panel, phục hồi focus/scroll khi đóng. `open`/`active`/`expanded` là trạng thái ban đầu của instance; tạo instance mới khi muốn reset. Nội dung ứng dụng phức tạp tiếp tục được compose bằng view/block của Saola; bộ data này không thực thi HTML hoặc schema nghiệp vụ.

State/listener/timeout/scroll lock thuộc từng view, qua `App.Admin.controllers.ui`, được giải phóng khi pause/stop/destroy. Hai instance không chia sẻ lựa chọn; hai dialog không mở khóa scroll của nhau. Sự kiện chỉ báo về cha, không tự sửa dữ liệu. Link chấp nhận đường cùng site, fragment hoặc HTTP(S); executable URL không trở thành link.

## Xem mẫu

Đặt `saola-admin.panel.ui_catalog = true` và chọn Material. Trang `/{prefix}/_ui` dùng auth/ability bình thường của panel, chỉ đọc dữ liệu mẫu; action chỉ đổi thông báo trên trang. Mặc định catalogue tắt, không phải dashboard số liệu thực.

Với pipeline published/Vite, thêm plugin `adminThemeAssets(projectRoot)` từ `resources/saola/admin/build/themes.mjs` vào **build context admin**, cùng các CSS input `adminThemeCss(projectRoot)`. Plugin phát ảnh catalogue tại URL ổn định sau khi Vite dọn output. Build source mode của Builder mới đã có đồng bộ raw theme assets. Workbench và Gehihi đã nối plugin này; không chỉ chép ảnh thủ công rồi để chúng mất sau build.

Grid, spacing, typography và màu dùng utility class của template; không thêm component rỗng để bọc một class. Swiper, Rater, SweetAlert, tour và nestable cần adapter/dependency/lifecycle riêng, chưa đưa vào bộ này.

Kiểm chứng: `client/tests/compiled/admin-ui.test.ts` dùng compiler thật, sự kiện qua view cha, state từng instance, disabled/loading, bàn phím/focus, timeout/scroll cleanup, Laravel SSR → hydration và TypeScript toàn bộ UI. `PanelTest` render source đã publish; `workbench/check-archive.php` kiểm UI đi cùng archive/publication.
