// Chứng minh `App.Admin` có kiểu thật: sai tên phương thức/tham số phải LỖI biên dịch.
// Nếu một dòng `@ts-expect-error` không còn lỗi thì `tsc` đỏ.
import type {Application} from '@saolabs/client';
import type {} from '../../resources/saola/admin/app/contracts/admin';

import {AdminUI} from '../../resources/saola/admin/app/bundle';
import type {AdminApi} from '../../resources/saola/admin/app/contracts/admin';

declare const App: Application;

// AdminUI phải thỏa AdminApi. Không dùng `implements` trong bundle.ts vì bản chép
// của nó trong cây sinh ra sẽ có `ResourceManager` riêng (class có private member
// là nominal), nên ràng buộc đó chỉ kiểm ở đây, nơi chỉ có một cây.
const conformance: AdminApi = new AdminUI({} as Application);
void conformance;

App.Admin.canChangeLanguage();
App.Admin.registerLanguageGuard(() => true)();

// @ts-expect-error phương thức không tồn tại
App.Admin.khongCo();
// @ts-expect-error sai kiểu tham số
App.Admin.setCurrentPage(1);
// @ts-expect-error guard phải trả boolean
App.Admin.registerLanguageGuard(() => 'x');

// Controller cho theme: `App` do gói truyền, theme chỉ đưa view + props.
declare const view: import('@saolabs/client').View;
App.Admin.controllers.page(view, () => 'settings');
// @ts-expect-error thiếu hàm đọc props
App.Admin.controllers.page(view);
// @ts-expect-error controller không tồn tại
App.Admin.controllers.khongCo(view, () => '');
