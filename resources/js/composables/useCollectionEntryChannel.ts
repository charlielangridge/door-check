import { onBeforeUnmount, onMounted } from 'vue';
import { getCollectionEcho } from '@/composables/useCollectionChannel';

export function useCollectionEntryChannel(
    uuid: string,
    claimed: () => void,
): void {
    const name = `collection-entry.${uuid}`;
    onMounted(() => {
        getCollectionEcho()
            ?.private(name)
            .listen('.collection-entry.claimed', claimed);
    });
    onBeforeUnmount(() => {
        getCollectionEcho()?.leave(name);
    });
}
