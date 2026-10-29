import { onBeforeUnmount, ref } from 'vue';
import { postJson } from '@/lib/http';

type WakeLockLike = { release: () => Promise<void> };

/**
 * Sends the phone's position to the store while the courier is delivering.
 *
 * Browsers pause GPS when the screen locks or the tab goes to the background, so the
 * screen is kept awake (Wake Lock) while sharing. Positions are sent at most every 10 s.
 * With heartbeatMs, the last position is sent again that often even when the phone stands
 * still (the GPS only reports changes), so the server knows the courier is still there.
 */
export function useLocationSharing(
    url: string,
    intervalMs = 10_000,
    heartbeatMs: number | null = null,
) {
    const sharing = ref(false);
    const error = ref<string | null>(null);
    const lastSentAt = ref<Date | null>(null);
    /** False when the server ignored the position (nothing out for delivery). */
    const tracking = ref<boolean | null>(null);

    let watchId: number | null = null;
    let wakeLock: WakeLockLike | null = null;
    let lastSent = 0;
    let lastPosition: GeolocationPosition | null = null;
    let heartbeat: ReturnType<typeof setInterval> | null = null;

    async function keepScreenOn(): Promise<void> {
        const nav = navigator as Navigator & {
            wakeLock?: { request: (type: 'screen') => Promise<WakeLockLike> };
        };

        try {
            wakeLock = (await nav.wakeLock?.request('screen')) ?? null;
        } catch {
            wakeLock = null; // Not supported or refused: sharing still works while the screen is on.
        }
    }

    async function send(position: GeolocationPosition): Promise<void> {
        lastPosition = position;
        const now = Date.now();

        if (now - lastSent < intervalMs) {
            return;
        }

        lastSent = now;

        const response = await postJson<{ tracking?: boolean }>(url, {
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
        });

        if (response.ok) {
            lastSentAt.value = new Date();
            tracking.value = response.data.tracking ?? null;
            error.value = null;
        } else {
            error.value =
                'Não foi possível enviar a localização. Verifique a internet.';
        }
    }

    function start(): void {
        if (!('geolocation' in navigator)) {
            error.value = 'Este aparelho não informa a localização.';

            return;
        }

        error.value = null;
        sharing.value = true;
        lastSent = 0;
        void keepScreenOn();

        watchId = navigator.geolocation.watchPosition(
            (position) => void send(position),
            (failure) => {
                error.value =
                    failure.code === failure.PERMISSION_DENIED
                        ? 'Permita o acesso à localização nas configurações do navegador.'
                        : 'Não foi possível obter a localização. Tente ao ar livre.';
            },
            { enableHighAccuracy: true, maximumAge: 5_000, timeout: 20_000 },
        );

        if (heartbeatMs !== null) {
            heartbeat = setInterval(() => {
                if (lastPosition !== null) {
                    void send(lastPosition);
                }
            }, heartbeatMs);
        }
    }

    function stop(): void {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }

        if (heartbeat !== null) {
            clearInterval(heartbeat);
            heartbeat = null;
        }

        lastPosition = null;

        void wakeLock?.release();
        wakeLock = null;
        sharing.value = false;
    }

    // The wake lock is dropped when the page is hidden; ask again when it comes back.
    function onVisibility(): void {
        if (sharing.value && document.visibilityState === 'visible') {
            void keepScreenOn();
        }
    }

    document.addEventListener('visibilitychange', onVisibility);

    onBeforeUnmount(() => {
        stop();
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { sharing, error, lastSentAt, tracking, start, stop };
}
