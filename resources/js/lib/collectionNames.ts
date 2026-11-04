type CollectionNames = {
    requestUuid: string;
    adultName: string;
    childName: string;
};

function storageKey(siteUuid: string): string {
    return `collection-request:names:${siteUuid}`;
}

export function readCollectionDraft(
    siteUuid: string,
): { adultName: string; childName: string } | null {
    try {
        const saved: unknown = JSON.parse(
            window.localStorage.getItem(
                `collection-request:draft:${siteUuid}`,
            ) ?? 'null',
        );
        if (
            saved !== null &&
            typeof saved === 'object' &&
            'adultName' in saved &&
            'childName' in saved &&
            typeof saved.adultName === 'string' &&
            saved.adultName.length <= 255 &&
            typeof saved.childName === 'string' &&
            saved.childName.length <= 255
        ) {
            return { adultName: saved.adultName, childName: saved.childName };
        }
    } catch {
        return null;
    }
    return null;
}

export function rememberCollectionDraft(
    siteUuid: string,
    adultName: string,
    childName: string,
): void {
    try {
        window.localStorage.setItem(
            `collection-request:draft:${siteUuid}`,
            JSON.stringify({ adultName, childName }),
        );
    } catch {
        // Storage availability must not prevent submission.
    }
}

export function forgetCollectionDraft(siteUuid: string): void {
    try {
        window.localStorage.removeItem(`collection-request:draft:${siteUuid}`);
    } catch {
        // Storage availability must not prevent submission.
    }
}

export function readCollectionNames(siteUuid: string): CollectionNames | null {
    try {
        const saved: unknown = JSON.parse(
            window.localStorage.getItem(storageKey(siteUuid)) ?? 'null',
        );
        if (
            saved !== null &&
            typeof saved === 'object' &&
            'requestUuid' in saved &&
            typeof saved.requestUuid === 'string' &&
            'adultName' in saved &&
            typeof saved.adultName === 'string' &&
            saved.adultName.length > 0 &&
            saved.adultName.length <= 255 &&
            'childName' in saved &&
            typeof saved.childName === 'string' &&
            saved.childName.length > 0 &&
            saved.childName.length <= 255
        ) {
            return {
                requestUuid: saved.requestUuid,
                adultName: saved.adultName,
                childName: saved.childName,
            };
        }
    } catch {
        // Browser storage may be unavailable or contain invalid JSON.
    }

    return null;
}

export function rememberCollectionNames(
    siteUuid: string,
    names: CollectionNames,
): void {
    try {
        window.localStorage.setItem(
            storageKey(siteUuid),
            JSON.stringify(names),
        );
    } catch {
        // Remembering names must never interrupt a collection request.
    }
}

export function forgetCollectionNames(
    siteUuid: string,
    requestUuid: string,
): void {
    if (readCollectionNames(siteUuid)?.requestUuid !== requestUuid) {
        return;
    }

    try {
        window.localStorage.removeItem(storageKey(siteUuid));
    } catch {
        // The form must still work when browser storage is blocked.
    }
}
