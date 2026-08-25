const token = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

export async function api<T = any>(path: string, options: RequestInit = {}): Promise<T> {
    const response = await fetch(`/api${path}`, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token(),
            'X-Locale': localStorage.getItem('lookdo-locale') || 'de',
            ...(options.headers || {}),
        },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        const first = body.errors ? Object.values(body.errors).flat()[0] : null;
        throw new Error(String(first || body.message || `HTTP ${response.status}`));
    }
    return body as T;
}
