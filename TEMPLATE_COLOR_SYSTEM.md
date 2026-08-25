# LOOKDO — configurable template colors

Every LOOKDO template has its own composition and UI reference, but the reference image is **not a fixed color theme**.

## Tenant choice

When a business selects a template during onboarding/setup, it must be able to choose a **primary app color**. The color can be changed later in Tenant Admin without changing the template, data, routes or layout.

Optional: a secondary/support color may be chosen. If omitted, LOOKDO derives it safely from the primary palette.

## Semantic tokens

Vue components use semantic tokens, never profession-specific hardcoded colors:

- `--tenant-primary`
- `--tenant-primary-hover`
- `--tenant-on-primary`
- `--tenant-primary-soft`
- `--tenant-secondary`
- `--tenant-background`
- `--tenant-surface`
- `--tenant-text`
- `--tenant-muted`
- `--tenant-border`
- `--tenant-success`
- `--tenant-warning`
- `--tenant-danger`

The chosen primary color controls CTA buttons, active navigation, links, selected calendar slots, badges and key accents. Background/surface/text remain readable and must not simply be recolored blindly.

## Accessibility

LOOKDO must derive hover/soft/on-primary variants and validate readable contrast. If the chosen color is too light/dark for white text, `--tenant-on-primary` changes automatically. System success/warning/error semantics must remain distinguishable from branding.

## Template UI references

Every main or specialized template should have a `UI/` reference showing approximately 2–4 important mobile states. References should differ where the business flow differs (for example automotive damage capture, appliance diagnostics, furniture references, garden area capture, cleaning before/after, signage project reference, beauty booking/calendar).

The reference defines **composition and hierarchy**, not a mandatory palette. Each reference should visibly indicate that its accent is replaceable.

## Data

Store branding at tenant level, not template level, conceptually:

```text
tenant_branding
- tenant_id
- primary_color
- secondary_color nullable
- theme_mode (light/dark/system or supported subset)
```

A template may provide a recommended/default palette only for preview. Selecting a template copies no permanent hardcoded color into frontend code.