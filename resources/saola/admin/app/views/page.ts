import type {} from '../contracts/admin';
import type {Application, View} from '@saolabs/client';

export function bindPage(view: View, App: Application, currentPage: () => string) {
    let stop = () => {};
    const activate = () => {
        const key = currentPage();
        if (key) App.Admin?.setCurrentPage(key);
    };
    // Dữ liệu `@await` về SAU `started` và runtime không bắn hook nào lúc đó, nên lần
    // điều hướng SPA đầu tiên thấy `currentPage` rỗng. Theo dõi state để bắt giá trị
    // khi nó tới; đường tải thẳng và `resumed` vẫn đúng ngay từ đầu.
    const watch = () => {
        stop();
        stop = view.$state?.__?.subscribe('currentPage', activate) ?? (() => {});
    };
    const start = () => { activate(); watch(); };
    const leave = () => { stop(); stop = () => {}; };
    view.__ctrl__.setUserDefinedConfig({started: start, resumed: start, paused: leave, stopped: leave, destroyed: leave});
}
