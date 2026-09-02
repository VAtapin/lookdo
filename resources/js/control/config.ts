export const planLocales = [
    ["de", "Deutsch"],
    ["en", "English"],
    ["ru", "Русский"],
    ["uk", "Українська"],
];

export const nav = [
    ["dashboard", "▦", "Übersicht"],
    ["tenants", "◎", "Kunden"],
    ["administrators", "♜", "Administratoren"],
    ["subscriptions", "◉", "Abrechnung"],
    ["plans", "€", "Tarife"],
    ["stripe", "S", "Stripe"],
    ["sms", "✉", "SMS-Protokoll"],
    ["templates", "≡", "Vorlagen"],
    ["ai", "✦", "KI-Wörterbuch"],
    ["classifications", "↯", "KI-Entscheidungen"],
    ["content", "□", "Inhalte"],
    ["settings", "⚙", "Einstellungen"],
    ["backups", "↥", "Backups"],
    ["audit", "⌁", "Prüfprotokoll"],
];
export const endpoint: Record<string, string> = {
    dashboard: "/control/dashboard",
    tenants: "/control/tenants",
    administrators: "/control/administrators",
    subscriptions: "/control/subscriptions",
    plans: "/control/plans",
    stripe: "/control/stripe",
    sms: "/control/sms",
    templates: "/control/taxonomy",
    ai: "/control/phrases",
    classifications: "/control/classifications",
    content: "/control/settings",
    settings: "/control/settings",
    backups: "/control/backups",
    audit: "/control/audits",
};
export const serverSections = new Set([
    "tenants",
    "administrators",
    "subscriptions",
    "ai",
    "classifications",
    "sms",
    "backups",
    "audit",
]);
export const addLabels: Record<string, string> = {
    tenants: "Kunde",
    plans: "Tarif",
    templates: "Eintrag",
    ai: "Begriff",
};
export const metricLabels: Record<string, string> = {
    tenants: "Kunden",
    active_tenants: "Technisch aktive Konten",
    trialing: "Testphase",
    paid: "Bezahlt",
    complimentary: "Kostenlos",
    domains_attention: "Domains prüfen",
    classifications_30d: "Klassifizierungen (30 Tage)",
    ai_spend_month: "KI-Kosten im Monat",
    mrr: "Monatlicher Umsatz",
};
export const smsEventLabels: Record<string, string> = {
    request_received: "Anfrage erhalten",
    master_replied: "Meister hat geantwortet",
    work_ready: "Arbeit fertig",
    agreement_reminder: "Vereinbarung erinnern",
};
export const smsStatusLabels: Record<string, string> = {
    queued: "Warteschlange",
    sending: "Wird gesendet",
    accepted: "Angenommen",
    delivered: "Zugestellt",
    failed: "Fehlgeschlagen",
};
export function tenantAccessLabel(tenant: any): string {
    if (tenant?.manual_access_active)
        return `Manuell freigeschaltet · noch ${Number(tenant.manual_access_days_remaining || 0)} Tage`;
    const subscription = tenant?.current_subscription;
    const days = Number(subscription?.access_days_remaining || 0);
    switch (subscription?.access_state) {
        case "trialing":
            return `Testphase · noch ${days} Tage`;
        case "complimentary":
            return subscription.access_expires_at
                ? `Manuell freigeschaltet · noch ${days} Tage`
                : "Manuell freigeschaltet";
        case "paid":
            return "Bezahlt";
        case "expired":
            return "Zugang abgelaufen";
        case "past_due":
            return "Zahlung überfällig";
        case "canceled":
            return "Gekündigt";
        default:
            return "Nicht bezahlt";
    }
}
export function tenantAccessClass(tenant: any): string {
    return tenant?.manual_access_active
        ? "complimentary"
        : tenant?.current_subscription?.access_state || "unpaid";
}
export function subscriptionAccessLabel(subscription: any): string {
    return tenantAccessLabel({ current_subscription: subscription });
}
export function subscriptionAccessClass(subscription: any): string {
    return subscription?.access_state || "unpaid";
}
export const sortOptions: Record<string, Array<[string, string]>> = {
    tenants: [
        ["created_at", "Erstellt"],
        ["name", "Name"],
        ["status", "Status"],
        ["last_activity_at", "Letzte Aktivität"],
    ],
    administrators: [
        ["created_at", "Erstellt"],
        ["name", "Name"],
        ["email", "E-Mail"],
        ["last_login_at", "Letzte Anmeldung"],
    ],
    subscriptions: [
        ["created_at", "Erstellt"],
        ["status", "Status"],
        ["provider", "Anbieter"],
        ["current_period_end", "Periodenende"],
    ],
    plans: [
        ["sort_order", "Reihenfolge"],
        ["code", "Code"],
        ["price_monthly", "Preis"],
    ],
    templates: [
        ["kind", "Typ"],
        ["code", "Code"],
        ["sort_order", "Reihenfolge"],
        ["enabled", "Status"],
    ],
    ai: [
        ["created_at", "Erstellt"],
        ["phrase", "Begriff"],
        ["locale", "Sprache"],
        ["weight", "Gewichtung"],
    ],
    classifications: [
        ["created_at", "Datum"],
        ["confidence", "Sicherheit"],
        ["source", "Quelle"],
    ],
    content: [
        ["label", "Name"],
        ["key", "URL"],
    ],
    settings: [],
    sms: [
        ["created_at", "Datum"],
        ["status", "Status"],
        ["event_type", "Ereignis"],
        ["cost", "Kosten"],
    ],
    backups: [
        ["created_at", "Erstellt"],
        ["name", "Name"],
        ["tenant_name", "Kunde"],
    ],
    audit: [
        ["created_at", "Datum"],
        ["action", "Aktion"],
        ["actor_id", "Benutzer"],
    ],
};
export const statusOptions: Record<string, Array<[string, string]>> = {
    tenants: [
        ["active", "Aktiv"],
        ["suspended", "Gesperrt"],
        ["archived", "Archiviert"],
    ],
    administrators: [
        ["active", "Aktiv"],
        ["inactive", "Gesperrt"],
    ],
    subscriptions: [
        ["active", "Aktiv"],
        ["trialing", "Testphase"],
        ["incomplete", "Unvollständig"],
        ["past_due", "Überfällig"],
        ["canceled", "Gekündigt"],
    ],
    plans: [
        ["active", "Aktiv"],
        ["inactive", "Archiviert"],
    ],
    templates: [
        ["active", "Aktiv"],
        ["inactive", "Inaktiv"],
    ],
    sms: [
        ["queued", "Warteschlange"],
        ["sending", "Wird gesendet"],
        ["accepted", "Angenommen"],
        ["delivered", "Zugestellt"],
        ["failed", "Fehlgeschlagen"],
    ],
    ai: [
        ["active", "Aktiv"],
        ["inactive", "Inaktiv"],
    ],
    content: [
        ["active", "Veröffentlicht"],
        ["inactive", "Entwurf"],
    ],
};
