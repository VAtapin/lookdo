export const buildMonthGrid = (month: string): Array<string | null> => {
    const [year, monthNumber] = month.split("-").map(Number);
    const first = new Date(year, monthNumber - 1, 1);
    const count = new Date(year, monthNumber, 0).getDate();
    const offset = (first.getDay() + 6) % 7;
    const dates = Array.from(
        { length: count },
        (_, index) =>
            `${year}-${String(monthNumber).padStart(2, "0")}-${String(index + 1).padStart(2, "0")}`,
    );

    return [...Array(offset).fill(null), ...dates];
};

export const eventsOnDay = <T extends { starts_at?: string }>(
    events: T[],
    date: string,
) => events.filter((event) => String(event.starts_at).slice(0, 10) === date);
