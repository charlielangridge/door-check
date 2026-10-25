export type CollectionState =
    | 'needs_checking'
    | 'reviewed'
    | 'preparing'
    | 'collected'
    | 'cancelled';

export interface CollectionPerson {
    id: number;
    name: string;
    aliases: string[];
    photo: string | null;
    confidence?: number | null;
}

export interface CollectionTimeline {
    events: {
        id: number;
        event: string;
        actor: string;
        at: string | null;
        old_values: Record<string, unknown>;
        new_values: Record<string, unknown>;
    }[];
    next_cursor: number | null;
}

export interface StaffChildCollection {
    uuid: string;
    submitted_name: string;
    child: CollectionPerson | null;
    state: CollectionState;
    label: string;
    match_status: string;
    match_confidence: number | null;
    duplicate: boolean;
    relationship: string | null;
    relationship_override_reason: string | null;
    photo_verification_reason: string | null;
    cancellation_reason: string | null;
    cancellation_notes: string | null;
    candidates: CollectionPerson[];
}

export interface StaffCollection {
    uuid: string;
    revision: number;
    site_name: string;
    site_id: number;
    requested_at: string;
    submitted_adult_name: string;
    adult: CollectionPerson | null;
    adult_locked: boolean;
    requester_photo: string | null;
    adult_candidates: CollectionPerson[];
    adult_match_status: string;
    adult_match_confidence: number | null;
    threshold: number | null;
    finished: boolean;
    children: StaffChildCollection[];
    timeline: CollectionTimeline;
}
