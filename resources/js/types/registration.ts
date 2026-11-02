export interface RegistrationPerson {
    name: string;
    date_of_birth: string;
    also_known_as: string[];
    new_photo: File | null;
    create_different: boolean;
}

export interface RegistrationAdult extends RegistrationPerson {
    key: number;
    mode: 'existing' | 'new';
    adult_id: number | null;
    relationship: string;
    photo: string | null;
}

export interface RegistrationMatch {
    id: number;
    uuid: string | null;
    name: string;
    date_of_birth: string;
    photo: string | null;
}

export interface RegistrationDuplicateGroup {
    name: string;
    date_of_birth: string;
    people: RegistrationMatch[];
}

export interface RegistrationDuplicates {
    child: RegistrationDuplicateGroup | null;
    adults: Record<number, RegistrationDuplicateGroup>;
}
