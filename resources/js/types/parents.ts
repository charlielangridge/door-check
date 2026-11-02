export interface ParentPerson {
    key?: string;
    id?: number | null;
    name: string;
    date_of_birth?: string;
    email?: string | null;
    phone?: string | null;
    also_known_as: string[];
    relationship?: string;
    document_responsible?: boolean;
    authorised_to_collect?: boolean;
    new_photo?: File | null;
}
export interface ParentDocument {
    id: number;
    document_id: number;
    number: number;
    title: string;
    markdown: string | null;
    html: string | null;
    has_pdf: boolean;
    required: boolean;
    accepted: boolean;
}
