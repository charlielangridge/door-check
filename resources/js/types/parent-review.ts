export interface RegistrationReviewAdult {
    key: string;
    name: string;
    date_of_birth: string;
    email: string | null;
    phone: string;
    relationship: string;
    also_known_as: string[];
    has_photo: boolean;
    is_manager: boolean;
    document_responsible: boolean;
    authorised_to_collect: boolean;
}
export interface RegistrationReview {
    id: number;
    uuid: string;
    child_name: string;
    child_date_of_birth: string;
    email: string;
    status: string;
    revision: number;
    failed_attempts: number;
    expires_at: string;
    correction_message: string | null;
    payload: {
        name: string;
        also_known_as?: string[];
        has_photo: boolean;
        adults: RegistrationReviewAdult[];
    } | null;
    duplicates: {
        child: { id: number; name: string }[];
        adults: Record<number, { id: number; name: string }[]>;
    } | null;
    outstanding: { name: string; email: string; documents: string[] }[];
}
