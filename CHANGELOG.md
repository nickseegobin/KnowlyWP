# KnowlyAPI Plugin — Changelog

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
