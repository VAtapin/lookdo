# LOOKDO — configurable template colors

Every template has its own composition and UI reference, but the reference palette is only an example.

When a business selects a template, it chooses a **primary app color** and can change it later in Tenant Admin without changing template, data or layout. An optional secondary/support color may also be chosen.

Vue uses semantic tokens, never profession-specific hardcoded colors: `--tenant-primary`, `--tenant-primary-hover`, `--tenant-on-primary`, `--tenant-primary-soft`, `--tenant-secondary`, `--tenant-background`, `--tenant-surface`, `--tenant-text`, `--tenant-muted`, `--tenant-border`, `--tenant-success`, `--tenant-warning`, `--tenant-danger`.

The primary color controls CTA buttons, active navigation, links, selected calendar slots, badges and important accents. LOOKDO derives hover/soft/on-primary variants and checks readable contrast. Success/warning/error semantics remain distinguishable from branding.

Store branding at tenant level, conceptually: `primary_color`, optional `secondary_color`, and supported `theme_mode`. A template may provide only a recommended preview palette.