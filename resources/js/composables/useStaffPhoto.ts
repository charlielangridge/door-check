import { computed, onScopeDispose, ref, watch, type Ref } from 'vue';

export type StaffPhotoState = 'loading' | 'loaded' | 'unavailable';

export function useStaffPhoto(
    source: Readonly<Ref<string | null | undefined>>,
    pending: Readonly<Ref<boolean>>,
) {
    const attempts = ref(0);
    const loadState = ref<StaffPhotoState>('loading');
    let retryTimer: ReturnType<typeof setTimeout> | undefined;

    function clearRetry(): void {
        clearTimeout(retryTimer);
        retryTimer = undefined;
    }

    const imageSrc = computed(() => {
        if (!source.value || attempts.value === 0) return source.value;
        const separator = source.value.includes('?') ? '&' : '?';
        return `${source.value}${separator}photo_retry=${attempts.value}`;
    });
    const state = computed<StaffPhotoState>(() => {
        if (!source.value) return pending.value ? 'loading' : 'unavailable';
        return loadState.value;
    });

    watch(
        source,
        () => {
            clearRetry();
            attempts.value = 0;
            loadState.value = 'loading';
        },
        { immediate: true, flush: 'sync' },
    );

    function loaded(): void {
        clearRetry();
        loadState.value = 'loaded';
    }

    function failed(): void {
        if (retryTimer !== undefined) return;
        if (attempts.value >= 5) {
            loadState.value = 'unavailable';
            return;
        }
        loadState.value = 'loading';
        retryTimer = setTimeout(() => {
            retryTimer = undefined;
            attempts.value += 1;
        }, 2000);
    }

    onScopeDispose(clearRetry);

    return { imageSrc, state, loaded, failed };
}
