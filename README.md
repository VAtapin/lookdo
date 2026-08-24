# LOOKDO

**Working concept:** «Покажи — я сделаю.» / **Show it. Get it done.**

LOOKDO is a multi-tenant SaaS platform for creating branded mobile-first Web Apps/PWAs for service businesses. A customer shows a task with photos/video, leaves a required phone number, and communicates directly with the business through the app.

Primary domain: **lookdo.app**

## Core stack

- Laravel API
- Vue 3
- Vite
- PWA
- Capacitor-ready
- Multi-tenant SaaS architecture
- Client admin + Super Admin
- DE / EN / RU localization

## Product principle

Keep the end-customer app extremely simple. New business categories must **not create new screens**. Categories only change wording, hints, fields and the photo/video capture logic.

Customer navigation:

**Home · Show/Request · Messages**

The first real tenant is Leonid's steering-wheel upholstery business, but there must be **no Leonid-specific code path**. Leonid is an ordinary tenant with a free plan assigned from Super Admin.

See [CODEX.md](CODEX.md) for the full implementation specification.
