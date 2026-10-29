/**
 * Bridge to the Windows app (Tauri). In a normal browser every function is a no-op.
 */
type TauriGlobal = {
    core: {
        invoke: (
            command: string,
            args?: Record<string, unknown>,
        ) => Promise<unknown>;
    };
};

function tauri(): TauriGlobal | null {
    const global = (window as unknown as { __TAURI__?: TauriGlobal }).__TAURI__;

    return global?.core ? global : null;
}

export function insideDesktopApp(): boolean {
    return tauri() !== null;
}

/**
 * Windows notification and a flashing taskbar button, even when the app is hidden.
 */
export function notifyNewOrders(title: string, body: string): void {
    void tauri()
        ?.core.invoke('new_orders', { title, body })
        .catch(() => {});
}
