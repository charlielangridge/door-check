import { board } from '@/routes/staff';
import {
    show,
    data,
    candidates,
    checks,
    timeline,
    update,
} from '@/routes/staff/collections';
import type { RouteQueryOptions } from '@/wayfinder';

export function useStaffCollectionRoutes(site: string) {
    const args = (collectionRequest: string) => ({ site, collectionRequest });
    return {
        board: (options?: RouteQueryOptions) => board(site, options),
        show: {
            url: (uuid: string, options?: RouteQueryOptions) =>
                show.url(args(uuid), options),
        },
        data: {
            url: (uuid: string, options?: RouteQueryOptions) =>
                data.url(args(uuid), options),
        },
        candidates: {
            url: (uuid: string, options?: RouteQueryOptions) =>
                candidates.url(args(uuid), options),
        },
        checks: {
            url: (uuid: string, options?: RouteQueryOptions) =>
                checks.url(args(uuid), options),
        },
        timeline: {
            url: (uuid: string, options?: RouteQueryOptions) =>
                timeline.url(args(uuid), options),
        },
        update: {
            url: (uuid: string, options?: RouteQueryOptions) =>
                update.url(args(uuid), options),
        },
    };
}
