import type {View} from '@saolabs/client';

/** Keep Saola's reactive-state bridge in one place, independent of theme markup. */
export function bindViewState<State extends object>(view: View) {
    return {
        get<Key extends keyof State>(key: Key): State[Key] {
            return view.$state.__.getStateByKey(key);
        },
        set<Key extends keyof State>(key: Key, value: State[Key]): void {
            view.$state.__.setters[key](value);
        },
    };
}
