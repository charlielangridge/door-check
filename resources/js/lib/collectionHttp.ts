export class CollectionHttpError extends Error {
    constructor(
        public status: number,
        message: string,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

export async function collectionHttp<T>(
    url: string,
    data?: Record<string, unknown>,
): Promise<T> {
    const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);
    const response = await fetch(url, {
        method: data ? 'POST' : 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(data
                ? {
                      'Content-Type': 'application/json',
                      'X-XSRF-TOKEN': decodeURIComponent(xsrf ?? ''),
                  }
                : {}),
        },
        ...(data ? { body: JSON.stringify(data) } : {}),
    });
    if (!response.headers.get('content-type')?.includes('application/json')) {
        throw new CollectionHttpError(
            response.status === 200 ? 401 : response.status,
            'Please sign in again to continue.',
        );
    }
    const result = await response.json();
    if (!response.ok) {
        throw new CollectionHttpError(
            response.status,
            result.message ?? 'The action could not be saved.',
            result.errors ?? {},
        );
    }
    return result;
}
