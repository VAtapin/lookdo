# LOOKDO — Technical Specification for Codex

## 1. Product definition

LOOKDO is a **multi-tenant SaaS** for service businesses whose customers can show a task/problem with photos or short video and receive a response from a real specialist.

Core idea:

> **Покажи — я сделаю.**  
> **Show it. Get it done.**

This is not a classical website builder, not a CRM-heavy system and not an online price calculator. The product must remain simple.

The end-customer flow is always approximately:

1. See the business and examples of work.
2. Tap the main action.
3. Take/upload photos or short video.
4. Add minimal task information.
5. Enter a **required phone number**.
6. Send the request.
7. Optionally enable push notifications.
8. Continue conversation with the business inside the Web App or via an enabled external channel.

**Do not calculate binding prices automatically.** The owner answers after reviewing the request.

---

## 2. Mandatory technology stack

Use this stack as the project baseline:

- **Backend:** Laravel API
- **Frontend:** Vue 3
- **Build:** Vite
- **Web App:** installable PWA
- **Native-ready:** Capacitor-ready from the beginning
- **Database:** MariaDB/MySQL-compatible schema unless there is a strong implementation reason to use PostgreSQL
- **Queue:** Laravel queues
- **Cache/session:** Redis-ready
- **Storage:** Laravel filesystem abstraction; local/S3-compatible storage

Frontend and backend must be cleanly separated through API contracts.

Do not build Blade-first UI and later retrofit Vue.

---

## 3. Multi-tenancy

LOOKDO is SaaS from the first commit.

Every business is a `tenant`.

A tenant owns its own:

- business profile;
- branding;
- domain(s);
- staff/admin users;
- requests;
- conversations/messages;
- media;
- before/after examples;
- videos;
- reviews;
- notification settings;
- integrations;
- subscription/plan;
- localization/content settings.

Tenant data must never leak between tenants.

Implement tenant resolution by hostname/domain with a safe fallback for platform-owned preview/subdomain use.

### First tenant

Leonid's steering-wheel upholstery business will be the first real tenant.

**Do not create special Leonid-only logic.**

Leonid receives an ordinary plan assigned as free/complimentary from Super Admin.

---

## 4. Custom domains

Primary platform domain: **lookdo.app**.

Each SaaS customer may use their own domain.

Expected workflow:

1. Customer registers a domain externally.
2. LOOKDO shows DNS instructions.
3. Customer points DNS to our server.
4. Tenant admin adds the domain.
5. System verifies DNS.
6. Super Admin or automated provisioning activates it.
7. SSL is issued.
8. Domain resolves directly to the tenant Web App.

The visitor should see the tenant's own brand, not LOOKDO branding, unless platform branding is intentionally enabled by plan/settings.

Store domain status such as:

- pending
- dns_detected
- verifying
- active
- failed

---

## 5. End-customer Web App — strict simplicity rule

The public/customer UI is a **mobile app-style interface**, not a long landing page.

Main navigation must remain small:

- **Home**
- **Primary action / Show request**
- **Messages**

Do not add a separate permanent Works/Gallery tab in v1. Work examples belong on Home.

A tenant can customize the wording of the primary action, for example:

- Оценить мой руль
- Показать повреждение
- Показать объект
- Показать неисправность
- Показать мебель
- Отправить фото

The number of screens should **not grow when a new business category is added**.

Business templates only change:

- labels;
- field visibility;
- field wording;
- photo/video hints;
- required media types;
- default content.

---

## 6. Public/customer screens

### 6.1 Home

App-like one-screen-first layout, not a content "scroll wall".

Must support:

- tenant logo/name;
- hero photo or optional short muted video;
- short headline/subheadline;
- compact **before/after** showcase;
- selected advantages/benefits;
- compact business/master profile block;
- customer reviews preview;
- phone/contact button;
- VK/Telegram/contact action depending on tenant market/integrations;
- persistent bottom navigation.

Some vertical scrolling is acceptable for smaller screens, but the information architecture must feel like an app, not a classical landing page.

### 6.2 Request / primary action

This is the main conversion screen.

Support:

- camera capture;
- gallery/file upload;
- 1–4 photos by default, configurable per template;
- optional short video;
- media preview;
- remove/re-take;
- photo guidance;
- template-specific minimal fields;
- free-text comment;
- required customer phone number;
- preferred reply channel.

**Customer e-mail is not required in the standard flow and should not be shown by default.**

Request owner's internal notification e-mail is separate.

### 6.3 Camera / media capture state

Provide clear visual guidance such as:

- overall view;
- left/right/detail;
- damage close-up;
- model/label plate;
- installation location;
- reference/example.

The guidance is defined by the business template.

Support:

- camera capture on capable browsers;
- normal file input fallback;
- multiple image upload;
- short video;
- preview and delete;
- compression/resizing before upload where safe;
- server-side validation.

### 6.4 Request sent

Show:

- success state;
- request number/reference;
- short request summary;
- business contact summary;
- prompt to enable notifications.

### 6.5 Push permission pre-prompt

Never trigger the browser notification permission immediately on first visit.

First show a human-readable internal prompt explaining:

> Enable notifications so we can tell you when the business replies.

Only after the user taps **Enable notifications** should the real browser permission prompt be triggered.

Service notifications and marketing consent must be treated separately.

### 6.6 Messages

Simple request-linked conversation UI.

Support:

- messages from customer and business;
- attachments/photos;
- message timestamps;
- request context;
- unread counters;
- push notification deep-link to the correct conversation/request.

Do not build a complex social messenger.

### 6.7 Reviews

Public reviews screen/view must support:

- average rating;
- total count;
- text review;
- optional photos;
- optional short video;
- business reply;
- moderation/publish state;
- link review to completed request when possible.

After a completed job, tenant can send a service notification asking for a review.

### 6.8 Contacts modal / bottom sheet

Not a permanent main screen.

Support configurable actions:

- call;
- VK;
- Telegram where enabled;
- address;
- route/navigation link;
- opening hours.

---

## 7. Admin login

Tenant staff/admin has a simple login screen.

Support:

- login/phone + password;
- remember me;
- password recovery;
- architecture ready for one-time code login.

Do not label this area as Leonid-only.

---

## 8. Tenant Admin

Tenant Admin is required.

Recommended navigation:

- Dashboard
- Requests
- Messages
- Content
- Reviews
- Profile
- Integrations
- Domain
- Subscription
- Settings

Keep the mobile experience usable.

### 8.1 Requests

Owner can:

- view new requests;
- open customer phone/contact;
- inspect all uploaded media;
- reply;
- mark status;
- archive;
- download media if needed.

Minimal statuses:

- new
- in_progress
- answered
- completed
- archived

Avoid building a huge CRM workflow in v1.

### 8.2 Profile screen

The Profile screen **is required**.

Tenant can manage:

- company/business name;
- logo;
- avatar/master photo if applicable;
- description;
- phone;
- address;
- opening hours;
- external contact links;
- languages;
- primary colors;
- notification preferences.

### 8.3 Branding

The current black/gold Leonid mockups are the initial visual direction, but the SaaS must allow tenant branding.

At minimum tenant admin can change:

- primary/accent color;
- secondary/supporting color;
- logo;
- hero image/video.

Do **not** build a free-form page designer in v1.

The structure remains fixed and polished.

### 8.4 Media/content management

Provide very easy mobile-friendly upload and management for:

- hero image;
- hero video;
- work photos;
- before/after pairs;
- gallery media;
- review media.

Required UX:

- drag/drop on desktop;
- camera/gallery on mobile;
- reorder;
- delete;
- crop/thumbnail where useful;
- progress indicator;
- sensible compression/transcoding strategy.

### 8.5 Before/After component

Implement a reusable **before/after slider component**.

Tenant admin can create a pair:

- before image;
- after image;
- title;
- optional short caption.

Public UI renders a touch-friendly comparison slider.

### 8.6 Video

Support short business videos and customer/request videos.

Do not assume direct unbounded original upload storage forever.

Implement:

- size limits;
- duration limits configurable by plan;
- MIME validation;
- thumbnail/poster generation strategy;
- background processing/transcoding-ready architecture.

---

## 9. Notifications

### Customer

- reply received;
- request status changed if useful;
- completed/review request;
- optional marketing only with separate consent.

### Tenant owner/staff

- new request;
- new customer message;
- optionally new review.

Channels:

- Web Push/PWA;
- e-mail to tenant owner;
- VK integration where configured;
- Telegram integration for supported markets.

Customer e-mail should not be required for normal request submission.

---

## 10. Telegram bot

A Telegram bot is part of the SaaS concept, especially for non-Russian deployments where appropriate.

It must use the same backend data, not create a second business logic implementation.

Initial bot functions:

- notify tenant of new request;
- show request summary;
- show/link media;
- notify of new customer message;
- quick link back to Tenant Admin;
- later allow replies through the bot if cleanly implemented.

Telegram is an integration, not a separate product.

---

## 11. VK integration

For tenants using VK, allow configuration of VK community/contact integration.

Do not assume the platform can message arbitrary VK users without permission/interaction.

Use VK as an optional communication/notification channel according to current API permissions.

Keep the customer phone number as the reliable mandatory fallback contact.

---

## 12. Localization

Platform must be localization-first.

Required locales:

- German (`de`)
- English (`en`)
- Russian (`ru`)

No user-facing strings should be hardcoded in Vue components.

Separate:

- platform translations;
- tenant-editable business text;
- template-specific labels/hints.

Tenant may enable only selected languages for its public app.

---

## 13. Business onboarding and AI classification

During tenant registration, ask the user to describe their business/activity in natural language.

Example inputs:

- "перетягиваю автомобильные рули"
- "устанавливаю входные двери"
- "ремонтирую стиральные машины"

The system searches a database of known phrases/synonyms and may use AI to select the best matching **fixed category + fixed variation**.

**AI must not invent a new workflow/template.**

Its job is classification only.

If confidence is low, show 2–3 likely matches and ask the tenant to select one.

Store classification phrases by locale.

Suggested phrase table concept:

```text
business_phrases
- id
- category_id
- variation_id
- locale
- phrase
- normalized_phrase
- weight/confidence_hint (optional)
```

---

## 14. Fixed business taxonomy v1

The UI/logic is driven by a fixed taxonomy. The current agreed v1 taxonomy contains the following categories and variations.

### 1. Automobiles and auto service

- External damage
- Interior / vehicle elements
- Fault / malfunction
- Desired modification

### 2. Repair, finishing and installation

- Damage repair
- Installation / mounting
- Finishing work
- Large object/project

### 3. Household appliance repair

- Not working
- Mechanical damage
- Error/code
- Noise / movement / leak

### 4. Computers and electronics

- Device malfunction
- Physical damage
- Software error
- General/other electronics request

### 5. Furniture and interior

- Repair / restoration
- Upholstery
- Manufacturing / modification
- General interior object

### 6. Garden and property

- Specific object
- Whole property/area
- Damage/problem
- General garden work

### 7. Cleaning and cleaning services

- Room/object
- Local contamination
- Large area
- Special cleaning request

### 8. Repair and restoration of items

- Damage
- Restoration
- Modification
- General item request

### 9. Bicycles, machinery and equipment

- Breakdown
- External damage
- Operational problem
- General equipment request

### 10. Tailoring and custom-made items

- Repair / modification
- Manufacturing
- Reference/example based request
- General custom request

### 11. Advertising, signs and visual installation

- New installation
- Replacement / repair
- Manufacturing
- General visual/sign request

Architecture must support adding/removing taxonomy entries later without adding new public screens.

---

## 15. Business phrase dictionary

Seed the database with many realistic phrases/synonyms mapped to category + variation.

Examples:

- `перетяжка руля` → automobiles / interior
- `удаление вмятин` → automobiles / external damage
- `установка дверей` → repair+installation / installation
- `ремонт стиральных машин` → appliances / not working
- `ошибка стиральной машины` → appliances / error
- `перетяжка дивана` → furniture / upholstery
- `реставрация мебели` → furniture / restoration
- `обрезка деревьев` → garden / specific object
- `химчистка дивана` → cleaning / local contamination
- `ремонт обуви` → item repair / damage
- `ремонт ноутбука` → computers / malfunction
- `разбит экран` → computers / physical damage
- `ремонт велосипеда` → equipment / breakdown
- `пошив одежды` → tailoring / manufacturing
- `установка вывески` → advertising/signs / installation

The dictionary will later be expanded in DE/EN/RU.

---

## 16. Template logic

Each category variation defines a small schema, not a new Vue screen.

Example schema idea:

```json
{
  "primary_action_label": "Show the damage",
  "media": {
    "photos_min": 1,
    "photos_max": 4,
    "video_allowed": true,
    "hints": ["Overall view", "Damage close-up"]
  },
  "fields": [
    {"key":"subject", "type":"text", "required":false},
    {"key":"comment", "type":"textarea", "required":false},
    {"key":"phone", "type":"phone", "required":true}
  ]
}
```

Implement this as DB/config driven behavior.

Do not fork components by profession.

---

## 17. Reviews workflow

Tenant can request a review after completion.

Customer review supports:

- stars;
- text;
- photos;
- short video.

Tenant can:

- reply;
- publish/hide where lawful and appropriate;
- feature selected reviews on Home.

Keep moderation/audit metadata.

---

## 18. Super Admin

Separate Super Admin area is required.

Functions:

- tenants;
- tenant users;
- domains;
- plans;
- subscriptions;
- payments;
- complimentary/free plan assignment;
- usage/limits;
- storage usage;
- push usage/status;
- integrations status;
- business taxonomy;
- business phrase dictionary;
- global localization strings/config;
- system settings.

Leonid can be assigned a paid-tier feature set at price 0 without changing tenant code.

---

## 19. Plans and limits

Design for plans even if billing is implemented later.

Potential limit dimensions:

- media storage;
- number of active requests/history retention;
- video upload allowance;
- custom domain;
- number of tenant staff users;
- integrations;
- branding options;
- advanced notifications.

Do not hardcode plan checks throughout components. Use centralized feature/entitlement service.

---

## 20. Suggested core data model

At minimum plan for entities similar to:

- users
- tenants
- tenant_users
- tenant_profiles
- tenant_branding
- tenant_domains
- plans
- subscriptions
- entitlements
- business_categories
- business_variations
- business_phrases
- tenant_business_profile
- request_templates
- requests
- request_fields / request_payload
- media
- conversations
- messages
- push_subscriptions
- notification_consents
- notifications
- integrations
- reviews
- review_media
- before_after_items
- audit_logs

Use appropriate polymorphism only where it simplifies rather than obscures the model.

Every tenant-owned record must be tenant-scoped.

---

## 21. Security and privacy

Implement from the beginning:

- tenant isolation;
- authorization policies;
- rate limiting;
- CSRF/auth strategy appropriate to SPA/API architecture;
- secure upload validation;
- MIME sniffing/validation;
- image/video size limits;
- safe filenames/storage keys;
- signed/private media URLs where necessary;
- phone and personal data protection;
- audit trail for important admin actions;
- consent timestamps for push/marketing.

Do not expose internal storage paths or tenant data through predictable URLs.

---

## 22. PWA / Capacitor readiness

PWA requirements:

- manifest;
- tenant-aware app name/icon where technically possible;
- service worker;
- offline-friendly shell/error state;
- installability;
- Web Push;
- deep links into requests/conversations.

Capacitor readiness:

- keep browser/native capability wrappers isolated;
- do not spread direct browser-only APIs across components;
- create service abstractions for camera, notifications, files and sharing;
- Web implementation first, Capacitor implementation later.

---

## 23. UI principles

The supplied/current visual direction is the baseline:

- premium dark UI;
- black/graphite surfaces;
- large photography;
- restrained gold accent for the first tenant;
- rounded touch-friendly controls;
- clear mobile hierarchy.

But colors must be tenant configurable.

Do not create separate designs per business category.

Do not overfill screens with cards/settings.

Customer UI must remain visually simple and task-focused.

---

## 24. MVP implementation order

### Phase 1 — foundation

1. Laravel API + Vue/Vite project structure.
2. Authentication.
3. Multi-tenancy.
4. Tenant/domain resolution.
5. Localization foundation.
6. Basic tenant branding.
7. Taxonomy + request-template model.

### Phase 2 — customer app

1. Home.
2. Before/after component.
3. Request form.
4. Camera/photo upload.
5. Short video upload.
6. Required phone.
7. Request submitted state.
8. Messages.
9. Push permission flow.
10. Contacts modal.
11. Reviews.

### Phase 3 — Tenant Admin

1. Requests.
2. Messages.
3. Profile.
4. Branding/colors.
5. Easy media upload.
6. Before/after management.
7. Video management.
8. Reviews.
9. Domain settings.
10. Notifications/integrations.

### Phase 4 — Super Admin / SaaS

1. Tenants.
2. Plans/entitlements.
3. Complimentary plans.
4. Domains.
5. Taxonomy/dictionary administration.
6. Usage/storage.
7. Subscription/payment foundation.

### Phase 5 — integrations

1. Tenant e-mail notifications.
2. Web Push.
3. Telegram bot.
4. VK integration.
5. Capacitor wrappers / Android build when required.

---

## 25. Explicit non-goals for MVP

Do **not** add unless later requested:

- automatic price calculator;
- complex quotation engine;
- large product catalog/e-commerce;
- shopping cart;
- full CRM;
- appointment calendar;
- marketplace;
- social feed;
- page builder;
- separate frontend per profession;
- dozens of public screens.

The product advantage is **simplicity and interaction**, not feature count.

---

## 26. Definition of success for the first usable version

A new tenant should be able to:

1. Register.
2. Describe their activity.
3. Be mapped to a fixed business category/variation.
4. Add logo, colors, contact details and work media.
5. Add before/after examples and reviews.
6. Connect a custom domain.
7. Publish their branded PWA.

Their customer should be able to:

1. Open the domain on a phone.
2. Understand what the business does.
3. See examples/reviews.
4. Tap the primary action.
5. Take/upload photos or video.
6. Enter minimal details and required phone number.
7. Send the request.
8. Enable notifications.
9. Receive and answer messages.

The tenant should receive the request through Tenant Admin plus configured notifications and be able to answer without using a heavyweight CRM.
