/**
 * JSON POST to our own backend with Laravel's CSRF protection (XSRF-TOKEN cookie).
 * For small endpoints that answer data instead of an Inertia page.
 */
export async function postJson<T>(
    url: string,
    body: Record<string, unknown>,
): Promise<{ ok: boolean; status: number; data: T }> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        body: JSON.stringify(body),
    });

    let data = {} as T;

    try {
        data = (await response.json()) as T;
    } catch {
        // Empty or non-JSON body: callers rely on ok/status.
    }

    return { ok: response.ok, status: response.status, data };
}
