# LOOKDO template color system

Every template UI reference shows only an example palette. During template setup the tenant must be able to choose the primary accent color and change it later without changing the template or layout.

Use semantic tokens: `--tenant-primary`, `--tenant-primary-hover`, `--tenant-on-primary`, `--tenant-primary-soft`, `--tenant-secondary`, `--tenant-surface`, `--tenant-background`, `--tenant-text`, `--tenant-muted`, `--tenant-border`.

Generate hover/soft/on-primary variants from the selected color with accessibility contrast checks. An optional secondary/support color may also be selected. Never hardcode the example Brow pink into Vue components.