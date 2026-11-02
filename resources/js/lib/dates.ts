export function formatLongDate(value: string | null | undefined): string {
    if (!value) {
        return 'Not provided';
    }

    const dateOnly = value.slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateOnly)) {
        return value;
    }

    const date = new Date(dateOnly + 'T00:00:00Z');
    if (
        Number.isNaN(date.getTime()) ||
        date.toISOString().slice(0, 10) !== dateOnly
    ) {
        return value;
    }

    const day = date.getUTCDate();
    const suffix =
        day % 100 >= 11 && day % 100 <= 13
            ? 'th'
            : ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] ?? 'th');
    const month = new Intl.DateTimeFormat('en-GB', {
        month: 'long',
        timeZone: 'UTC',
    }).format(date);

    return `${day}${suffix} ${month} ${date.getUTCFullYear()}`;
}
