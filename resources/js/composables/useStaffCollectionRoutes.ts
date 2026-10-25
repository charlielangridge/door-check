import { board as adminBoard } from '@/routes/admin/staff';
import {
    show as adminShow,
    data as adminData,
    candidates as adminCandidates,
    checks as adminChecks,
    timeline as adminTimeline,
    update as adminUpdate,
} from '@/routes/admin/staff/collections';
import { board as staffBoard } from '@/routes/staff';
import {
    show,
    data,
    candidates,
    checks,
    timeline,
    update,
} from '@/routes/staff/collections';
import type { RouteQueryOptions } from '@/wayfinder';

export function useStaffCollectionRoutes(adminSite?: string | null) {
    const collectionRoutes = adminSite
        ? {
              show: adminShow,
              data: adminData,
              candidates: adminCandidates,
              checks: adminChecks,
              timeline: adminTimeline,
              update: adminUpdate,
          }
        : { show, data, candidates, checks, timeline, update };

    return {
        ...collectionRoutes,
        board: (options?: RouteQueryOptions) =>
            adminSite ? adminBoard(adminSite, options) : staffBoard(options),
    };
}
