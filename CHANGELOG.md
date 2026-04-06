# KnowlyAPI Plugin — Changelog

## [1.2.0] — 2026-04-06 — Block 2: Identity and Access

### Roles
- Registered `knowly_teacher` role (Knowly Teacher) — requires admin approval before access
- DB version bumped to `1.2` — activator safety net upgrades existing installs

### New Database Tables
- `knowly_notifications` — simple and confirmation notifications (class invitations, system events)
- `knowly_migration_log` — per-user UM → Knowly meta migration audit trail

### New Auth Endpoints
- `POST /auth/register/parent` — canonical parent registration path (legacy `/auth/register` retained)
- `POST /auth/register/teacher` — teacher registration with `pending_approval` status; admin notified via email
- `POST /auth/password/reset` — triggers WordPress core password reset email (user enumeration safe)

### Auth Updates
- Login now accepts `knowly_teacher` role; returns `approval_status` in response for teachers
- `GET /auth/me` now returns teacher profile branch (`Knowly_Teacher_Service::get_profile`)

### New Services
- `Knowly_Teacher_Service` — teacher registration, approval/suspension, profile, approval guard (`is_approved()`)
- `Knowly_Notification_Service` — create, list, respond, mark-read for simple and confirmation notifications

### New API Base Method
- `Knowly_API_Base::require_teacher()` — authenticates + enforces `knowly_teacher` role + `approved` status gate; returns 403 with `knowly_pending_approval` code for unapproved teachers
- `Knowly_API_Base::is_teacher()` — role check helper

### WP Admin — New Pages
- **Teachers** (`knowly-teachers`) — pending applications list with approve/suspend, approved teachers with red gem balance editor, suspended teachers with re-approve
- **UM Migration** (`knowly-migration`) — audit UM meta keys, run migration script (legacy `knowly_standard`/`knowly_term` → `knowly_level`/`knowly_period`, no overwrites), view per-user log, confirm completion

### WP Admin — Updates
- **Dashboard** — shows teacher count with pending-approval alert badge; Teachers and UM Migration added to quick links
- **Settings** — added User Management section: max children per parent, email verification toggle, teacher red gem stipend default, UM migration status indicator

### Test Suite — New Groups
- **Block 2 — Test Account Setup** — provisions test parent, pre-approved teacher, and std_4/term_1 child (all flagged `is_test_account`)
- **Block 2 — Auth** — `/auth/register/parent` and `/auth/password/reset`
- **Block 2 — Teacher** — register, login pending, admin approve, login approved
- **Block 2 — Notifications** — create simple notification, list for user

### System DB Tables check updated
- Added `knowly_notifications` and `knowly_migration_log` to table existence check

---

## [1.1.0] — 2026-04-05 — Block 1: Clear the Decks

### Rebranding
- Plugin renamed from NoeyAPI → KnowlyAPI
- Main plugin file renamed: `noey-api.php` → `knowly-api.php`
- All 30 include files renamed: `class-noey-*.php` → `class-knowly-*.php`
- Plugin header: name, URI, author, description updated to Knowly branding
- REST namespace changed: `noey/v1` → `knowly/v1` — all endpoints now served under `/wp-json/knowly/v1/`
- All PHP constants renamed: `NOEY_*` → `KNOWLY_*`
- All class names renamed: `Noey_*` → `Knowly_*`
- All WordPress meta keys, option names, table prefixes, and WP_Error codes renamed: `noey_*` → `knowly_*`
- Reference doc renamed: `NOEY-API-DOCS.md` → `KNOWLY-API-DOCS.md`

### Modular Rename (folded in from Block 0.5)
- All `standard` field names → `level` across all PHP files (API params, service methods, admin panel, DB queries)
- All `term` field names → `period` across all PHP files
- Variable names `$standard`/`$term` → `$level`/`$period` throughout

### Exam Service — `class-knowly-exam-service.php`
- `start()` method signature updated: `$standard, $term` → `$level, $period`, added `$trial_type`, `$topic` params
- Removed WP-side pool (`serve_from_pool`, `store_in_pool`, `mark_served`) — Railway is now the sole pool authority
- Removed `completed_package_ids` — sequential pointer model on Railway replaces exclusion list
- Removed `get_seen_package_ids()` — no longer needed
- `fetch_from_railway()` rewritten to call `POST /api/v1/generate-exam` with correct body (`user_id`, `curriculum`, `level`, `period`, `subject`, `difficulty`, `trial_type`, `topic`, `source`)
- 503 `pool_empty` handled gracefully — returns `WP_Error` with user-friendly message; Railway has already triggered background generation
- Plugin timeout fixed: WordPress never waits on Claude directly; Railway serves from pool (fast) or returns 503 immediately

### Leaderboard Service — `class-knowly-leaderboard-service.php`
- `get_board()` signature: `$standard, $term` → `$level, $period`
- `generate_nickname()` signature: `$standard, $term` → `$level, $period`
- `reset_board()` signature: `$standard, $term` → `$level, $period`
- All Railway POST paths prefixed with `/api/v1/` (e.g. `/api/v1/leaderboard/upsert`)
- Board key docblock updated: `level:period:subject` colon-separated format, `none` for null period
- `handle_submit_upsert()`: `standard`/`term` session fields updated to `level`/`period`

### Reference Doc — `KNOWLY-API-DOCS.md`
- All `noey/v1` namespace references → `knowly/v1`
- `completed_package_ids` field removed from all request body examples
- Question count table added: Easy=10q/90s, Medium=15q/90s, Hard=20q/90s
- Token storage note updated: HttpOnly cookie required, `localStorage` explicitly prohibited
- `standard`/`term` field names → `level`/`period` throughout all examples

---

## [1.0.0] — 2026-03-xx — Initial Release (Block 0)

- Initial plugin build: auth, children, tokens, exams, results, insights, leaderboard
- Sequential pool model via Railway
- JWT auth with 7-day expiry
- WooCommerce gem purchase integration (stub)
