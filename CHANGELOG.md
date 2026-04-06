# KnowlyAPI Plugin — Changelog

## [1.4.0] — 2026-04-06 — Block 4: Notifications API

### New API Endpoints
- `GET  /notifications` — JWT — list notifications for the authenticated user (`?unread_only=true` default, `?limit`, `?offset`)
- `GET  /notifications/count` — JWT — unread count only, for badge rendering
- `POST /notifications` — Admin JWT — create a notification (simple or confirmation type)
- `POST /notifications/{id}/read` — JWT — mark one notification as read
- `POST /notifications/read-all` — JWT — mark all notifications as read for the authenticated user
- `POST /notifications/{id}/respond` — JWT — accept or decline a confirmation notification

### Service Updates — `Knowly_Notification_Service`
- `list_for_user()` now accepts `$limit` and `$offset` parameters (pagination)
- `count_unread()` added — single integer query for badge use
- `mark_all_read()` added — bulk mark read, returns row count

### WP Admin — Dashboard
- Unread notification count added to stat grid (highlighted red when > 0)
- Endpoint reference table updated with all 6 Block 4 routes

### Test Suite — New Group
- **Block 4 — Notifications** (5 tests): admin create, list, count, respond to confirmation, read-all + verify count is 0

---

## [1.3.0] — 2026-04-06 — Block 3: Gem Economy

### New Database Tables
- `knowly_gem_transactions` — blue gem audit trail (user_id, child_id, type, amount, balance_after, curriculum, reference_id)
- `knowly_red_gem_transactions` — teacher red gem audit trail (teacher_user_id, type, amount, balance_after)
- `knowly_processed_webhooks` — Fygaro idempotency guard (unique transaction_id, gateway)

### New Services
- `Knowly_Gem_Service` — blue gem wallet: credit, deduct, allocate (parent→child), get_balance, has_enough, grant_on_registration, run_monthly_refresh, get_ledger
  - Gem costs read from WP options at deduction time: `knowly_gem_cost_{curriculum}_{difficulty}`
  - Monthly free tier: `knowly_gem_free_monthly_{curriculum}`
  - Dev bypass: `knowly_dev_bypass_gems` option
- `Knowly_Red_Gem_Service` — teacher red gem wallet: credit, deduct, get_balance, has_enough, run_monthly_stipend_reset, get_ledger
  - Monthly stipend reset: hard overwrites balance to per-teacher (or global default) stipend

### New API Endpoints
- `GET  /gems/balance` — JWT — blue gem wallet balance for the authenticated user
- `GET  /gems/ledger` — JWT — gem transaction history (paginated)
- `POST /gems/allocate` — JWT Parent — transfer gems from parent wallet to a child wallet
- `GET  /gems/products` — Open — list purchasable gem packages (from WP options)
- `POST /gems/checkout` — JWT Parent — initiate Fygaro payment checkout; returns payment_url
- `POST /gems/fygaro-webhook` — HMAC-SHA256 — receive Fygaro payment event, validate signature, credit gems (idempotent)
- `POST /gems/admin/credit` — Admin JWT — credit gems to any user
- `POST /gems/admin/deduct` — Admin JWT — deduct gems from any user
- `POST /gems/admin/refresh` — Admin JWT — trigger monthly gem refresh manually

### Exam Deduction Updated
- `Knowly_Exam_Service::start()` now deducts from the **child's** gem wallet instead of the parent token wallet
- Deduction cost read from `Knowly_Gem_Service::get_exam_cost($curriculum, $difficulty)` — never hardcoded

### WooCommerce
- Product field renamed: `_knowly_token_amount` → `_knowly_gem_quantity` (old field kept as fallback for existing products)
- Order handler credits via `Knowly_Gem_Service::credit()` instead of `Knowly_Token_Service::credit()`
- Idempotency guard: `_knowly_gems_granted` order meta (was `_knowly_tokens_granted`)

### Cron Jobs Added
- `knowly_monthly_gem_refresh` — 1st of month 00:10 UTC — resets free-tier parent gem balances
- `knowly_monthly_red_gem_stipend` — 1st of month 00:15 UTC — resets approved teacher red gem balances to stipend amount

### WP Admin — Gems Page
- **Settings tab** — default curriculum, gem costs per difficulty, monthly free tier, dev bypass, Fygaro gateway credentials, gem product manager (add/remove rows)
- **Health tab** — DB table status, Fygaro config check, gem cost display, wallet summary stats
- **Unit Tests tab** — 12 tests across 3 groups: blue gem service, red gem service, Fygaro/webhook

### WP Admin — Updates
- **Dashboard** — Gems quick link added
- **Menu** — Gems submenu added between Tokens and Settings
- **Admin AJAX** — `knowly_gems_test` action registered

### Default Options Added
- `knowly_default_curriculum` — `tt_primary`
- `knowly_fygaro_merchant_id`, `knowly_fygaro_api_key`, `knowly_fygaro_webhook_secret`
- `knowly_dev_bypass_gems`

---

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
