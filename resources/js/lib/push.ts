import { postJson } from '@/lib/http';

export type PushState =
    | 'unsupported' // browser without Web Push (or iPhone outside the installed app)
    | 'denied' // the person blocked notifications
    | 'default' // not asked yet
    | 'granted';

export function pushState(): PushState {
    if (
        !('serviceWorker' in navigator) ||
        !('PushManager' in window) ||
        !('Notification' in window)
    ) {
        return 'unsupported';
    }

    return Notification.permission;
}

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
    const base64 = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

/**
 * Asks for permission when needed (must run from a tap) and registers this phone with the
 * server, so notifications arrive even with the app closed. Safe to call again: the
 * browser returns the same subscription.
 */
export async function enablePush(
    publicKey: string,
    url: string,
): Promise<PushState> {
    if (pushState() === 'unsupported') {
        return 'unsupported';
    }

    const permission =
        Notification.permission === 'default'
            ? await Notification.requestPermission()
            : Notification.permission;

    if (permission !== 'granted') {
        return permission;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyBytes(publicKey),
        }));

    const json = subscription.toJSON();
    const encodings = (
        PushManager as unknown as { supportedContentEncodings?: string[] }
    ).supportedContentEncodings;

    await postJson(url, {
        endpoint: json.endpoint,
        keys: json.keys,
        contentEncoding: encodings?.includes('aes128gcm')
            ? 'aes128gcm'
            : (encodings?.[0] ?? 'aes128gcm'),
    });

    return 'granted';
}
