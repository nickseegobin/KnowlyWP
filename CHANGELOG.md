# KnowlyAPI Plugin — Changelog

## [2.2.0] — 2026-05-12 — Lessons Feature

Full Lesson delivery layer built on top of Quest training content.

### New WP REST endpoints (`knowly/v1`)

- `GET    /lessons` — Lesson catalogue (all approved quests, no sequential lock)
- `GET    /lessons/{quest_id}` — Lesson content (same data as quest content)
- `GET    /lessons/{quest_id}/questions` — 3 lesson MCQs without `correct_answer`
- `POST   /lessons/start` — Creates lesson session; no gem cost
- `POST   /lessons/complete` — Marks session done; no badge
- `POST   /lessons/submit-questions` — Silent scoring; returns `{ recorded: true }`
- `GET    /lessons/teacher/catalogue` — Teacher-scoped catalogue

### New WP database tables (via `dbDelta`)

- `wp_knowly_lesson_sessions` — tracks active/completed lesson sessions per child
- `wp_knowly_lesson_question_results` — per-question answer records (admin-visible only)

### New Railway endpoints (`/api/v1/lesson`)

- `POST /lesson/generate-questions` (server key) — AI generates 3 MCQs for a lesson
- `GET  /lesson/:quest_id/questions` (JWT or server key) — delivers lesson questions; server key response includes `correct_answer` for WP-side scoring

### New PHP classes

- `Knowly_Lesson_Service` — catalogue, content, session management, silent scoring
- `Knowly_Lessons_API` — WP REST route registration and handler delegation
- `Knowly_Admin_Lessons_Panel` — admin board with q_count badges and generate/regen buttons

### Key design decisions

- Lessons share `wp_knowly_quests` content — no duplicate content storage
- No gem deduction, no badge award, no sequential lock
- `submit_questions` fetches correct answers via server key internally, scores locally, returns `{ recorded: true }` — student never sees score
- Lesson questions stored in Supabase `lesson_questions` (separate from `quest_questions`)

---

## [2.1.0] — 2026-05-12 — Quest Questions Feature

### New Railway endpoints

- `POST /quest/generate-questions` (server key) — AI generates 3 MCQs per quest
- `GET  /quest/:quest_id/questions` (JWT or server key) — delivers quest questions; server key adds `correct_answer`

### New WP REST endpoints

- `GET  /quests/{quest_id}/questions` — returns 3 active quest questions (no `correct_answer`)
- `POST /quests/submit-questions` — scores child answers server-side, returns score + correct answers

### New WP database tables

- `wp_knowly_quest_question_results` — per-question answer records with is_correct

### New Railway endpoint

- `DELETE /curriculum-topics/by-scope` — archive all topics for a level × subject scope (optional period filter)

---

## [1.7.0] — 2026-05-11 — Phase 4: Sequential Trial Delivery

Pre-built, sequentially numbered packs replace per-request generation. Children always
receive the next unplayed pack in their branch; packs are shared across children (one pack
built once, served to many). Auto-generation fires in the background after every submission
to maintain a ready queue.

### Supabase Migration — `migration_phase4_trial_delivery.sql`

- `trial_packs.branch` (TEXT) — `'easy' | 'medium' | 'hard' | 'dynamic'`; backfilled from `difficulty` for existing packs
- `trial_packs.pack_sequence_number` (INT) — sequential per `(curriculum, level, period, subject, branch)`; backfilled from 1 for existing packs
- `trial_packs.module_assignments` (JSONB) — per-module difficulty lock for dynamic packs
- `child_pack_history` table — `(child_id BIGINT, pack_id UUID)` UNIQUE constraint; records which packs a child has completed
- Index: `idx_trial_packs_next_pack` on `(curriculum, level, subject, branch, pack_sequence_number ASC) WHERE status = 'active'`

### Railway — `src/services/packBuilder.js` (new)

Extracted shared pack-building logic from the route layer:
- `buildPack()` — draws 3 difficulty pools, assigns `branch` + `sequence_number`, saves to `trial_packs`, locks questions via `assigned_pack_id`
- `buildDynamicPack()` — randomly assigns a difficulty per module at build time, draws `questions_per_module` per module, saves as `branch='dynamic'`
- `getNextSeq()` — queries `MAX(pack_sequence_number)` for the scope+branch and returns `+1`

### Railway — `src/services/autoGenerate.js` (new)

Background pipeline triggered after every `POST /submit-pack-exam`:

| Constant | Value | Meaning |
|----------|-------|---------|
| `QUESTION_MIN.easy` | 6 | Min unassigned questions per (module × easy) slot |
| `QUESTION_MIN.medium` | 9 | Min unassigned questions per (module × medium) slot |
| `QUESTION_MIN.hard` | 12 | Min unassigned questions per (module × hard) slot |
| `PACK_MIN` | 3 | Min active packs to maintain per scope+branch |
| `DYNAMIC_QPM` | 4 | Questions per module in auto-built dynamic packs |

`checkAndAutoGenerate()` fires four parallel checks: easy, medium, hard, and dynamic branches.
Each check tops up question slots first, then builds a pack if below `PACK_MIN`.

### Railway — New endpoints

**`GET /trial/next-pack`** (server key)
Finds the lowest-sequence active pack in the requested branch that the child has not yet
completed. Returns questions shuffled for delivery + the full answer sheet for WP session
storage. On 503 (`no_pack_available`) automatically queues background generation.

**`POST /submit-pack-exam`** (Bearer JWT — child)
Scores answers against the pack's answer sheet in `question_bank`. Writes `exam_sessions`
(`source='pack'`), upserts `child_pack_history`, fires `checkAndAutoGenerate` in background.
Returns `{ score, total, percentage, topic_breakdown, answer_sheet }`.

**`DELETE /trial/child-history`** (server key)
Admin reset — deletes all `child_pack_history` rows for a child, restarting them from
Pack #1 on every branch. Returns `{ deleted, child_id }`.

### Railway — Updated endpoints

**`GET /trial-packs/list`** — now returns `branch` and `pack_sequence_number` per pack.

**`POST /trial-packs/build`** — delegates to `buildPack()` service; response now includes `sequence_number`.

### WP Admin — `class-knowly-admin-users.php`

- **Reset Pack History button** added to the child History panel (amber, alongside "Remove All Trials")
- Clicking confirms, then calls `knowly_admin_reset_pack_history` WP AJAX handler
- Handler calls `DELETE /trial/child-history?child_id=X` on Railway with server key

### Spec Tests — Group 15 (11 fast tests)

Covers auth guards for all three new endpoints, input validation (missing fields, invalid branch),
503 behaviour for unknown scope, `deleted: 0` noop for nonexistent child, schema column presence
for `branch` and `pack_sequence_number`, and WP AJAX action registration.

---

## [1.6.0] — 2026-05-11 — Phase 3C: Trials System — Pack Builder, Question Bank Editor, Admin Tooling

Full buildout of the WP admin Trials system and supporting Railway endpoints. Covers
pack delivery infrastructure, a full question bank editor, and curriculum-driven admin UI.

### Railway — `src/routes/trialPacks.js`

**Difficulty mix rewrite (3-pool explicit draw):**

Previous implementation only drew from `easy` and `hard` pools for easy packs (no medium
questions were being included). Rewritten to fetch each pool independently via `Promise.all`:

| Pack type | Total Qs | Easy | Medium | Hard |
|-----------|----------|------|--------|------|
| easy      | 12       | 9    | 2      | 1    |
| medium    | 18       | 4    | 9      | 5    |
| hard      | 24       | 3    | 9      | 12   |

Shortfalls per pool are now reported in `shortfalls[]` rather than aborting the build.

**New endpoint — `POST /trial-packs/dynamic-preview`:**
Multi-topic preview for admin use. Accepts `modules[]` and `questions_per_module`.
Randomly assigns a difficulty to each module (simulating live React exam behaviour).
Nothing is saved. Returns `module_assignments[]` (with `difficulty_drawn`, `questions_drawn`)
plus the full shuffled question list.

**Updated — `PATCH /trial-packs/:id`:**
Now accepts `release_questions: true` in the body. When set, NULLs `assigned_pack_id`
on all questions belonging to the pack before archiving — returning them to the available pool.

**New endpoint — `DELETE /trial-packs/:id` (Disband Pack):**
Permanently deletes a pack row and releases all locked questions back to the pool.
Works on active or archived packs. Returns `{ disbanded: true, released: N }`.

### Railway — `src/routes/questionBank.js`

**New endpoint — `GET /question-bank/modules`:**
Returns `[{ module_number, module_title }]` for a given level/subject/period scope.
Used by WP Admin dropdowns to show named modules instead of bare numbers.

**Expanded — `PATCH /question-bank/questions/:id`:**
Previously status-only. Now accepts any combination of: `question`, `options` (A/B/C/D),
`correct_answer`, `difficulty`, `explanation`, `tip`, `topic`, `status`.

**Fixed — `GET /question-bank/questions` missing fields:**
Supabase select was missing `explanation` and `tip`, causing blank fields in the QB Editor
modal. Both fields added to the select list.

### WP Admin — `class-knowly-admin-trials.php`

**New tab: Pack Library**
- Filter bar (level, period, subject, difficulty, status) with paginated pack table
- "View Qs" button expands a drawer showing full question cards (with explanation + tip) for any pack
- Inline archive confirmation row: choose "Archive — keep questions locked" or "Disband Pack — release Qs to pool"
- Archived packs show Reactivate and Disband Pack buttons (previously showed nothing)
- Collapsible Pack Builder section at the top of the tab (same functionality as Simulations)
- Module dropdown populated via `GET /question-bank/modules` AJAX — shows module titles not numbers

**Rebuilt: Simulations tab**
- "Load Modules" AJAX button replaces the old module number text input
- Named module select dropdown populated from QB
- **Dynamic Preview section** — select multiple modules via checkboxes; system randomly assigns
  a difficulty per module and draws `questions_per_module` questions. Preview only, nothing saved.
- Instruction guide (collapsible) explains pack types, difficulty mix ratios, and the Load Modules flow
- Static Pack Builder moved to Simulations tab

**New: `curriculum_parts()` private helper:**
Reads `knowly_curriculum_subjects` WP option and returns `[levels, periods, subjects]` as
associative arrays. Replaces all hardcoded dropdown options — adding a new curriculum (e.g. secondary
school) to the WP option automatically propagates to all Trials dropdowns.

**All level / period / subject dropdowns now curriculum-driven:**
Pack Library filters, Pack Builder, and Simulations all use `curriculum_parts()` output.
No more drift when secondary school content is added.

**New AJAX handlers registered:**
- `knowly_trials_packs_list` — paginated pack list with filters
- `knowly_trials_pack_detail` — full question list for one pack
- `knowly_trials_pack_archive` — archive with optional question release
- `knowly_trials_pack_reactivate` — restore archived pack to active
- `knowly_trials_pack_disband` — permanently delete pack + release questions
- `knowly_trials_get_modules` — proxy to `GET /question-bank/modules`
- `knowly_dynamic_preview` — proxy to `POST /trial-packs/dynamic-preview`

**Bug fix — Disband Pack button not firing:**
Duplicate `class=` attribute on the button HTML (`class="button"` followed by a second
`class="pl-archive-release-btn"` on the same element). HTML ignores duplicate attributes
so the jQuery event handler never matched. Fixed by merging into a single `class=` attribute.

### WP Admin — `class-knowly-admin-editor.php`

**Tab restructure:**
- "Training Materials" tab removed — Pinecone-backed content store was not curriculum-driven
  and created drift risk relative to the curriculum page. QB generation uses curriculum taxonomy
  directly in prompts; a separate content store is redundant.
- "Trials" tab renamed → "Question Bank"
- "Lessons" placeholder tab added (rich-text lesson authoring per module — deferred build)
- Default tab changed from `training` → `trials`

**Question Bank Editor (rebuilt Trials tab):**
- Filter bar: Level, Period, Subject, "Load Modules" AJAX button, Module dropdown (named), Difficulty, Status
- Paginated question table with columns: Mod, Topic, Question (truncated), Difficulty, Status,
  Times Served, Actions
- Edit modal — full field editing: question text, all four options (A/B/C/D), correct answer,
  difficulty, explanation, tip, topic, status
- Module dropdown uses `knowly_trials_get_modules` AJAX action — shows named module titles

**Level / period / subject dropdowns now curriculum-driven:**
Extracted from the `$taxonomy` parameter (same `knowly_curriculum_subjects` option). Replaces
the hardcoded std_1–std_5 / term_1–term_4 / math-english-science-social_studies option tags.

**New AJAX handlers:**
- `knowly_qb_load` — proxies `GET /question-bank/questions` with all filter params
- `knowly_qb_edit` — proxies `PATCH /question-bank/questions/:id` with all editable fields

### Docs

- `KnowlyAPI/docs/api-reference.md` — created as canonical React-client reference; covers all
  trial, pack, QB, progress, leaderboard, and progression endpoints with request/response examples
- Updated `api-reference.md` to reflect all endpoint changes from this phase:
  `DELETE /trial-packs/:id`, `PATCH /:id` with `release_questions`, `POST /dynamic-preview`,
  expanded `PATCH /question-bank/questions/:id`, `GET /question-bank/modules`

---

## [1.5.0] — 2026-04-06 — Block 5: Class Management

### New Database Tables
- `knowly_classes` — teacher classes (teacher_user_id, name, description, level)
- `knowly_class_members` — child enrolment (class_id, child_id, parent_id, status active/removed)
- `knowly_tasks` — class tasks (class_id, teacher_user_id, title, description, subject, difficulty, due_date, gem_cost)

### New Services
- `Knowly_Class_Service` — create, get, list (teacher/child), find child by nickname, invite (dual notification), add_member, remove_member, teacher_owns guard
- `Knowly_Task_Service` — create (red gem deduction), list for class. Cost read from `knowly_task_gem_cost` option (default 1)

### New API Endpoints — Teacher (JWT Teacher)
- `POST /classes` — Create a class
- `GET  /classes` — List teacher's classes (with member + task counts)
- `GET  /classes/{id}` — Class detail (members + tasks inline)
- `GET  /classes/child-lookup?nickname=X` — Find student by public nickname
- `POST /classes/{id}/invite` — Invite child by nickname → creates simple notif for child + confirmation notif for parent
- `GET  /classes/{id}/members` — List active members
- `DELETE /classes/{id}/members/{child_id}` — Remove a member (soft delete)
- `POST /classes/{id}/tasks` — Create task (deducts red gem)
- `GET  /classes/{id}/tasks` — List tasks for class

### New API Endpoint — Child (JWT Child)
- `GET /classes/my` — List classes the student is enrolled in

### Notification Respond — Class Invitation Flow
- `POST /notifications/{id}/respond` now triggers `Knowly_Class_Service::add_member()` automatically when a parent accepts a `class_invitation` confirmation notification
- `Knowly_Notification_Service::get()` added — single notification fetch scoped to recipient

### WP Admin
- **Classes** submenu added (between Gems and Settings)
  - Summary stat grid: total classes, active memberships, tasks created
  - Full class table: teacher, level, member count, task count, date
  - Settings: `knowly_task_gem_cost` (red gems per task)
- **Dashboard** — Classes count added to stat grid, Classes quick link added

### Default Options Added
- `knowly_task_gem_cost` — `1` (red gems deducted per task creation)

### Test Suite — New Group
- **Block 5 — Classes** (8 tests): create class, child lookup, invite, parent accept, verify member, create task, list tasks, child lists classes

---

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
