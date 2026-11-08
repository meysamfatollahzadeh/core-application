# Phase 2 — Company, Personnel and Access Management

## 1. Status and approval gates

- Prepared: 2026-11-07.
- Status: **Modal Escape refinement verified; awaiting explicit phase-completion review.**
- The user approved the domain model and subsequently explicitly approved this phase plan. Model/plan approval does not approve the completed phase.
- Implementation progress: **9 of 10 steps completed**.
- Explicit plan approval received in chat on 2026-11-07; implementation authorized. Separate completion approval remains required.
- Branch: `codex/feature/company-personnel-access`.
- Baseline: `2be9b0c149b7e6ca81e6e1f6d5929852f8dcda14`; clean working tree, origin fetched, master synchronized using `git merge --ff-only origin/master`, then this branch created before replacing PLAN.md.
- The previous plan and its Login/maintenance evidence remain in Git history; this is the only current root plan.
- At planning time repository rules authorized Login only. Explicit approval authorized the bounded scope extension described here; AGENTS.md was updated in step 1, preserving all other rules.
- The pre-implementation approval gate was satisfied before application changes. Commit every plan change, including approval and progress updates.
- After each implementation step, report in Persian: “مرحله X از ۱۰ تکمیل شد.” Record evidence and commit the plan update on this branch.
- Step 9 ends with a review handoff. Step 10, phase documentation, closeout and integration require separate explicit completion approval. Plan approval never authorizes integration.

## 2. Subject, outcome and scope

Provide a Persian organizational administration interface and modular Laravel APIs for companies, position charts, personnel position assignments and reporting relationships, buildings/areas, and permission-based access assigned directly to users or through positions.

In scope:

- Company and position CRUD; an independent single-parent chart per company.
- Existing local users as personnel; current positions and one mandatory current location per user; no independent company membership.
- Multiple reporting positions for each user-position assignment, including positions in other companies.
- Shared buildings and areas; automatically create a protected default area titled `نامشخص` for each building.
- Shared role definitions, an application-owned permission catalog, role-permission management, and global/company role assignments to users and positions.
- Effective permissions, backend enforcement, SSR-aware administration navigation, secure bootstrap access, safe migration of existing data, and focused regression/acceptance verification.
- Narrow changes to Login imports, factories and development bootstrap needed to satisfy mandatory user location and to expose sanitized authorization state.

Out of scope:

- Independent company membership, employment contracts, personnel codes, payroll, attendance, HR dossiers, historical assignment/location records, organization-specific role definitions, role inheritance, permission-deny rules, and building-entry permissions.
- Local passwords, identity lifecycle administration screens, automatic identity linking or registration on callback. Keycloak remains responsible for identities and credentials; existing imported Member/User matches remain mandatory.
- Articles CRUD or an Articles module. The article URLs discussed in chat are authorization contract examples verified through test-only routes, not new production article endpoints.
- Camunda business processes, dependency/runtime upgrades, new public ports, external assets and replacement authentication mechanisms.

## 3. Confirmed business behavior

### Companies, positions and reporting

1. A user can hold multiple positions concurrently in one or several companies; multiple users can hold the same position.
2. A position belongs to exactly one company and has zero or one parent position in that same company. Multiple roots are allowed. Self-parenting and chart cycles are forbidden.
3. No company-membership table exists. A user's company associations are the distinct union of companies of their positions and direct company roles. Global roles do not create membership in every company.
4. Positions confer no permissions by themselves. Reporting relationships confer no permissions.
5. Reporting is attached to a specific UserPosition, not to the user generally or the position chart. Its targets are positions, never individual managers.
6. A UserPosition whose position has a parent must have at least one reporting target. Root-position assignments may have zero or more targets.
7. A target may be any existing position, including one in another company or one with no current occupants; it need not be the chart parent.
8. A target cannot equal the position of that UserPosition. Another position held by the same user is allowed. Reciprocal and cyclic reporting relationships are allowed; do not apply chart acyclicity to reporting.
9. Keep current state only. Ordinary timestamps do not constitute assignment history.

### Location

1. Every user has exactly one current BuildingArea, independent of company, position or role.
2. Every area belongs to one building; buildings may house personnel associated with multiple companies. Do not add company-building membership or duplicate building_id on users.
3. Building creation and its `نامشخص` default area are atomic. Each building has exactly one default area identified by is_default, not title matching. Protect its identity/title and prevent standalone deletion or default-flag manipulation through area APIs.
4. Default areas may be assigned explicitly. Do not silently move users when an area/building is removed. Referenced areas/buildings cannot be deleted until users are reassigned.

### Access

1. Roles and their permission sets are shared application-wide; companies do not own role definitions.
2. Direct user roles can be global or company-specific, regardless of whether the user holds any position in that company.
3. Position roles can be global or scoped to the position's own company. A position cannot receive a role scoped to an unrelated company.
4. A position role benefits all and only users currently holding that exact position. Descendants and reporting targets do not inherit it.
5. Effective permissions are the union of direct and position-derived permissions, preserving each grant's global or company scope. There is no deny precedence.
6. Global grants satisfy global routes and equivalent checks for every company. Company grants satisfy only routes for that exact company and never global routes.
7. Possessing an arbitrary role, holding a position or supplying a company ID is insufficient: the requested operation's permission and correct scope must both be present.
8. A user associated with several companies is one shared User record. Editing global profile/location data requires global personnel authority; company personnel APIs cannot modify that shared record or another company's assignments.

## 4. Implementation architecture

Preserve the monorepo, installed dependency locks and existing development services. Use native Laravel policies/middleware and explicit query services; no new RBAC dependency is planned.

```text
backend/app/Modules/Organization/
  Models/                  Company, Position, Building, BuildingArea
  Actions/                 Transactional chart/location operations
  Http/                    Controllers, Requests, Resources, Middleware as needed
  Database/                Migrations, Factories
  Console/Commands/        Location preparation/backfill
  Providers/
  Routes/api.php
  Tests/                   Organization feature/integrity tests
backend/app/Modules/Personnel/
  Models/                  UserPosition, UserPositionReport
  Actions/                 Assignment and reporting replacement
  Http/
  Database/
  Providers/
  Routes/api.php
  Tests/
backend/app/Modules/Access/
  Models/                  Role, Permission and assignment models as needed
  Actions/
  Services/                EffectivePermissions, authorization checks
  Http/
  Database/
  Console/Commands/        Permission sync, initial access bootstrap
  Providers/
  Routes/api.php
  Tests/
```

- Keep each module's routes, models, actions, requests, resources, migrations, commands, providers and tests together. Register providers at the composition root and configure test discovery for module-local tests. Existing Login tests need not be relocated.
- Retain the existing Login User model as the canonical identity-backed user. Add only necessary location/relationship integration; never create a second personnel user table or duplicate Member semantics.
- Specify migration ordering explicitly across modules, including users location constraints and Access dependencies on positions.
- Every business route uses the existing Sanctum Bearer and upstream-grant validity checks before authorization. Health and necessary Login/OIDC protocol routes retain their established contracts.
- Start with request-local effective-permission memoization, not persistent authorization caching. Resolve permissions afresh on subsequent requests so removals, role edits and position changes take effect without token reissue. Redis remains available for its existing purposes.
- Apply authorization and company filtering before pagination, counts, searches and object lookup. Validate nested ownership; no cross-company ID substitution or mass-assignment of scope fields.
- Use transactions and consistent row/advisory locking for chart edits, assignment/report replacements and bootstrap. Return sanitized stable errors without SQL or credentials.

## 5. PostgreSQL database design

Use bigint primary/foreign keys, bounded strings and timestamps for domain entities and first-class assignments. Use composite unique constraints on pure pivots. All required columns are NOT NULL unless explicitly marked nullable.

| Table | Columns and invariants |
| --- | --- |
| companies | id PK; title varchar(255); timestamps |
| positions | id PK; company_id FK; parent_position_id nullable FK; title varchar(255); timestamps; index company_id and parent_position_id; UNIQUE(id, company_id) to support same-company composite parent FK |
| user_positions | id PK; user_id FK users; position_id FK; timestamps; UNIQUE(user_id, position_id); reverse index position_id |
| user_position_reports | id PK; user_position_id FK; report_to_position_id FK positions; timestamps; UNIQUE(user_position_id, report_to_position_id); reverse index report_to_position_id |
| buildings | id PK; title varchar(255); timestamps |
| building_areas | id PK; building_id FK; title varchar(255); is_default boolean default false; timestamps; partial UNIQUE(building_id) WHERE is_default; index building_id |
| users (existing) | add building_area_id FK building_areas, ultimately NOT NULL; index building_area_id; retain existing identity fields and associations |
| roles | id PK; key varchar(100) UNIQUE; title varchar(255); timestamps |
| permissions | id PK; key varchar(100) UNIQUE; title varchar(255); timestamps |
| role_permission | role_id FK; permission_id FK; composite PK(role_id, permission_id); reverse index permission_id |
| user_role | user_id FK; role_id FK; composite PK(user_id, role_id); reverse index role_id; always global |
| company_user_role | company_id FK; user_id FK; role_id FK; composite PK(company_id, user_id, role_id); index(user_id, company_id), role_id |
| position_role | position_id FK; role_id FK; scope varchar constrained to company/global; composite PK(position_id, role_id, scope); reverse index role_id; company scope derives from positions.company_id |

Additional integrity rules:

- Composite FK (parent_position_id, company_id) references positions(id, company_id). Reject self-parenting with a CHECK; prevent longer chart cycles under a company-level transaction lock. Existing position company_id is immutable; moving a position between companies is out of scope.
- Enforce the reporting self-target rule, minimum required reports and default-area existence with deferred PostgreSQL constraint triggers plus transactional service validation. Check final committed state so atomic replacements and building creation are possible. Cover related parent updates and deletes, not only report insertion; serialize affected writes to prevent concurrent write skew.
- Reparenting a root position with occupants requires valid reports for all affected assignments in the same transaction or returns 409 with affected IDs. Do not invent reporting managers automatically.
- No UNIQUE constraint on display titles is assumed by the approved design. Role/permission keys, not titles, are stable identifiers. Prevent ambiguous display with company/building context in selectors.
- Restrict domain deletes when referenced: occupied/report-targeted/parent positions, inhabited areas, nonempty buildings and companies, assigned roles. Remove explicit assignments before deleting referenced entities; do not silently cascade authority or reporting changes.
- Deleting a UserPosition may cascade its own report rows. Role-permission pivots may be removed through explicit role editing. User/identity deletion remains out of scope.
- Database tables for Keycloak, Camunda, Member identity binding, tokens and source settings otherwise remain unchanged.

## 6. Existing-data migration, imports and initial administration

Mandatory location cannot be implemented by adding a failing NOT NULL column to populated users or by silently assigning an invented location.

1. Use an expand/backfill/contract sequence. Add location tables and a temporarily nullable users.building_area_id in a preparation migration. Keep management writes gated until the contract migration succeeds.
2. Provide an idempotent CLI preparation command and dry-run: an operator creates actual buildings/default areas and supplies a private user-ID-to-area-ID map, or explicitly selects one existing area's ID as a fallback for all currently unassigned users. This proposed operational choice is part of this plan, not an inferred historical location.
3. Validate all users/areas and the full mapping before applying changes transactionally. Report unresolved counts/IDs, never identity secrets. Preserve users, Member mappings, positions if present, and existing tokens. No reset or volume deletion.
4. A separate contract migration refuses to enforce NOT NULL while any user lacks a valid area. A failed preflight leaves data intact and gives a recoverable CLI instruction. Do not let normal startup apply the contract before the documented preparation step on an existing database.
5. Extend login:import-members with an explicit --building-area option required whenever creating new Users. Existing mappings retain their location. Dry-run validates the same inputs; failures must not leave newly created users without locations. Callback behavior stays unchanged and never creates users.
6. Update factories, tests, smoke scripts and development initialization for mandatory locations. Fresh development bootstrap explicitly provisions a development building/default area before imports, then passes that area to both source imports. This is development fixture data, not a production location inference.
7. Seed/synchronize a version-controlled permission catalog and an initial system-administrator role, but never auto-grant that role to all users or trust a Keycloak role claim as application authorization.
8. Provide a local operator command to grant the bootstrap global role to one explicitly identified existing imported Member via source slug and exact external subject. Support dry-run and idempotency; do not create/link identities or choose the first imported account. Ordinary web login does not grant administrative rights.
9. Include a populated upgrade rehearsal and isolated rollback/re-upgrade checks. Warn that rolling back removes this phase's organization/access/location data; never perform destructive rollback on the user's active database as a verification shortcut.

## 7. API and authorization contract

All API paths below are under the application's existing Nginx origin on port 8081. JSON resources expose only authorized fields. Use Laravel pagination with data/meta/links, bounded page size, validated search, 201 for creation, 200 for reads/replacements, 204 for deletion, 401 for invalid credentials, 403 for insufficient authority, 404 for missing or out-of-scope nested resources, 422 for malformed data, and 409 for domain dependency/invariant conflicts.

| Routes | Purpose and authority |
| --- | --- |
| GET/POST /api/companies; GET/PATCH/DELETE /api/companies/{company} | Global company administration; provide a separate visible-company selector for scoped users rather than granting global listing access |
| GET /api/access/me | Sanitized effective global permissions, per-company permissions and permitted company selector data; no bearer/provider tokens |
| GET/POST /api/companies/{company}/positions; GET/PATCH/DELETE .../positions/{position} | Position CRUD/chart data; company-scoped operation permissions or their global equivalents |
| GET /api/personnel; GET/PATCH /api/personnel/{user} | Global personnel directory/profile/location; no local-user creation, credential editing or identity deletion |
| GET /api/companies/{company}/personnel | Users associated through positions or direct company roles; company-scoped personnel.view; no unrelated company role/position details |
| GET/POST /api/companies/{company}/user-positions; GET/PUT/DELETE .../user-positions/{assignment} | Assign position, atomically replace report targets, or remove assignment; company authority follows the assigned position, not report target |
| GET /api/personnel/{user}/positions | All position assignments for a user; global personnel read authority |
| GET /api/organization/reporting-position-options | Minimal id/title/company target selector for users authorized to edit assignments; cross-company choices do not reveal target occupants or confer target-company authority |
| GET/POST /api/buildings; GET/PATCH/DELETE /api/buildings/{building}; corresponding /areas routes | Global building/area administration; location lookup available separately to global personnel editors |
| GET/POST /api/roles; GET/PATCH/DELETE /api/roles/{role}; GET /api/permissions; PUT /api/roles/{role}/permissions | Global shared role administration; permission catalog is read-only via HTTP and synchronized from code |
| GET/PUT /api/personnel/{user}/global-roles | Global direct user-role set replacement |
| GET/PUT /api/companies/{company}/users/{user}/roles | Direct company-role set replacement, including users with no positions in that company |
| GET/PUT /api/companies/{company}/positions/{position}/roles | Position-role assignments with explicit company/global scope; company authority alone cannot read/manage global grants |

- Full-set mutations validate duplicate/unknown IDs, ownership and every item before writing; partial success is not allowed. Company-only replacements preserve global position-role grants and cannot submit global entries.
- Define permission keys before implementation: companies.view/create/update/delete; positions.view/create/update/delete; personnel.view/update; user_positions.view/create/update/delete; buildings.view/create/update/delete; roles.view/create/update/delete; roles.permissions.update; permissions.view; access.assign. Building permissions cover their areas in this phase. Scope is a property of the grant, not part of a key string.
- Shared role/permission definitions, buildings, shared personnel changes and global grants always require global authority for their corresponding permission. Company-level access.assign authorizes company grants only.
- Prevent delegation-based escalation: in addition to access.assign, an actor may assign a role only if its permissions are within their effective permissions in the target scope. Changing shared role permissions requires global roles.permissions.update and possession of every added permission globally. Global position grants require global authority even when the position belongs to a company the actor administers.
- Ordinary API changes must not remove the last effective global administrator capable of managing shared roles and global grants. Evaluate affected users and position-derived grants transactionally; the explicit local bootstrap command is the recovery path.
- Provide minimal authorized user/role/location selectors for assignment forms. These selectors do not grant access to full global directories, identity-provider configuration, Member identifiers or unrelated company data.
- The existing Login me response stays compatible. Access state is served separately and can be refreshed by SSR/server gateways; the frontend is not the authority.

### Required example behavior

For a test permission articles.view, verified through test-only routes:

| Effective grant | /api/articles | /api/companies/A/articles | /api/companies/B/articles |
| --- | --- | --- | --- |
| No articles.view | Denied | Denied | Denied |
| Company A, direct or from an A position | Denied | Allowed | Denied |
| Global, direct or from any position | Allowed | Allowed | Allowed |

Repeat for mutation permissions. Company route queries/creates/updates must bind data to the route company, including when the caller has a global grant. Do not create production article storage or permissions solely for the example.

## 8. Nuxt/Vuetify interface

- Add Persian RTL administration pages for companies/charts, personnel/current location, user-position/reporting assignments, buildings/areas, shared roles/permissions and scoped user/position role assignment.
- Show company context clearly. Separate global and company scope in role editors; company selectors and navigation reflect actual effective permissions. No company selection converts company authority to global authority.
- Permit cross-company reporting targets with their company labels. Save assignment and report targets together; explain required reports for nonroot positions and optional reports for roots.
- Show shared-position occupants without assuming one manager/person per position. Do not recursively render reporting relationships as an acyclic chart.
- Every user location selection requires a building area. Selecting a building alone is insufficient; its named default area is an explicit selectable option.
- Resolve authentication and required authorization state on the Nuxt server before initial protected-page rendering. Direct navigation, refresh, denied access and provider-unavailable states must work without a client-only permission flash or cross-request leakage.
- Add thin allowlisted Nuxt server gateway handlers for authorized endpoints. Continue encrypted HttpOnly credential transport, server-only Bearer exchange, Origin/CSRF validation on mutations, no-store authenticated responses, and no secrets in browser storage, URLs or SSR payloads.
- Preserve Vazirmatn, local assets/icons, light/dark/automatic themes including live device changes, keyboard focus, accessible labels, restrained styling and responsive long-session layouts. All custom styling stays in dedicated CSS files; no Vue style blocks, inline styles or JavaScript style bindings.

## 9. Ordered execution and acceptance

| Step | Work and completion evidence | Status |
| --- | --- | --- |
| 1 of 10 | After explicit approval, commit approval status; update authorized scope in AGENTS.md; establish module boundaries, provider/test discovery and the permission/route inventory without widening scope. | Complete |
| 2 of 10 | Implement schema and database invariants, location preparation/contract migration, import/factory/bootstrap compatibility; verify fresh and populated databases and preservation of identity/token data. | Complete |
| 3 of 10 | Implement companies, independent position charts, buildings/default areas and safe deletes; verify same-company parents, cycles, defaults and concurrency. | Complete |
| 4 of 10 | Implement personnel location/profile operations, assignment CRUD and atomic reporting replacement; verify all reporting and cross-company rules. | Complete |
| 5 of 10 | Implement permission catalog, roles/pivots, effective grants, bootstrap command and authorization/delegation enforcement; pass scope and privilege-escalation matrix. | Complete |
| 6 of 10 | Wire protected APIs and secure Nuxt gateway/SSR access state; verify denied direct requests, CSRF, scoped queries and existing Login behavior. | Complete |
| 7 of 10 | Build Persian company/chart/building/personnel and reporting interfaces; verify initial SSR, validation, default locations and responsive themes. | Complete |
| 8 of 10 | Build shared role/permission and scoped direct/position-role interfaces; verify effective-access feedback, no inheritance and permission removal on next request. | Complete |
| 9 of 10 | Complete meaningful backend/browser/build/integration checks, migration rehearsal and review fixes; commit actual evidence in PLAN.md; report 9 of 10 and pause for explicit completion approval. | Complete |
| 10 of 10 | Only after completion approval, create/update English technical documentation and Persian usage/operations guides, validate them, commit closeout, rebase/revalidate, merge/push master and delete integrated branches per section 11. | Blocked by future completion approval |

Backend changes may span several scoped commits; frontend, shared development scripts and documentation use separate commits. Every progress/status change to this plan is committed. Do not mark steps complete based only on code being written.

## 10. Validation and phase acceptance criteria

- Run database tests on PostgreSQL, not SQLite substitutes: uniqueness/FKs, same-company chart relationships, cycles, minimum reporting targets, atomic replacements, default areas and concurrent invariant-changing writes.
- Cover multi-company/multi-position users, shared positions with different managers, unoccupied and cross-company reporting targets, permitted reciprocal reports and another self-held position, forbidden same-assignment position, and root/nonroot transitions.
- Cover mandatory location, default-area creation, referenced deletes, populated backfill preflight/retry, fresh setup, new imports, mapped imports, idempotent repeat imports and dry-run behavior. Confirm existing Member/source identity and token records survive migration.
- Exercise every protected route positively and negatively: unauthenticated/invalid upstream grants, missing permission, wrong company, nested-ID substitution, mass assignment, unauthorized selectors, global versus company grants, role unions, no descendant inheritance, immediate revocation and delegation escalation attempts.
- Verify global and company grant combinations from users and positions using the article example matrix in tests only. Test filtering, counts, paging and company-bound writes independently of route admission.
- Check bootstrap idempotency/exact identity targeting and last-administrator protection without auto-granting imported users.
- Run the full backend suite including existing Login regressions; Pint checks for changed PHP; existing infrastructure/Login/Camunda smoke acceptance where affected; frontend typecheck and build.
- Verify real browser flows on port 8081: initial SSR, multiple-user isolation, company switches, role changes, hard refresh, CSRF rejection, upstream logout and provider outage behavior; desktop/mobile RTL, keyboard interaction and all theme modes.
- Check no new published ports, remote runtime assets, inline/component styles, exposed credentials or accidental session authentication. Existing ports 8081–8084, Docker service DNS, hot reload and locked dependencies remain intact.
- Record exact commands, outcomes, test/assertion totals when available and any unverified items here. Existing historical Login results are not evidence that new checks have passed. Use isolated test data; do not reset active development volumes or run disruptive checks without an actual need.

Planning verification passed before approval: repository inspection, Git baseline synchronization, git diff --check, balanced Markdown fences and ordered-step/schema/scope coverage. Implementation verification is recorded below. Phase-completion approval has not occurred.

## 11. Review, documentation and integration

At the step-9 handoff, provide a concrete reviewable result, actual verification evidence and any limitations; allow revisions and rerun relevant checks. Wait for explicit completion approval before editing phase documentation in docs/.

After approval:

1. Update English technical Markdown for database changes and migration operations, business behavior, authorization rules, API requests/responses/errors, module architecture and actual validation. Explicitly identify unchanged identity, provider and process contracts.
2. Update Persian usage/development/operations guides for charts, assignments, locations, role scope, bootstrap/recovery, existing-data backfill and imports. Validate links/examples and commit documentation separately; commit final PLAN.md closeout.
3. Ensure the feature branch is clean; fetch origin, rebase onto latest origin/master, resolve conflicts on this branch with staged resolutions and git rebase --continue. Repeat affected validation and commit additional fixes if needed.
4. Switch to master, fast-forward from origin/master, merge the verified feature branch with --no-ff, and push master. If upstream moves or rejects the push, fetch, resynchronize, rebase and validate before retrying; never reset or force-push master.
5. Only after successful integration and push, delete the local feature branch with git branch -d and its remote counterpart if published. Preserve unintegrated work if access/protection blocks completion.
6. Report actual rebase, validation, merge, push and deletion results in Persian. Mark the phase complete only when all required authorized closeout work is finished; report blocked integration honestly.

### Step 1 evidence — 2026-11-07

- Explicit approval and authorized scope extension recorded; Organization, Personnel and Access providers and module-local test discovery registered.
- PermissionCatalog implements the approved operation inventory; API inventory remains in section 7.
- Existing seven Login routes load successfully through the running backend container; git diff --check passed.

### Step 2 evidence — 2026-11-07

- Additive organization/personnel/access schema, deferred invariants, serialized organization writes, separately invoked location contract migration and preparation/dry-run commands implemented.
- User explicitly selected building `توسعه` and its `نامشخص` area for both existing users. Preparation dry-run rolled back; actual backfill reported two users and zero unresolved; contract migration succeeded. No identity/token mutation was part of the migrations.
- Import requires explicit area for new users; mapped/existing users retain location. Development bootstrap and factories updated.
- Full backend suite passed: 27 tests, 157 assertions, including PostgreSQL constraint, populated-location preparation and existing Login regressions. Pint formatted changed PHP; bash -n scripts/dev passed.
- A fresh-test rebuild initially exposed leftover PostgreSQL functions after migrate:fresh; using CREATE OR REPLACE for those functions fixed repeatability.

### Step 3 evidence

- Transactional company, position-chart, building/default-area and area actions implemented. Protected default edits, nonempty deletion, cross-company parents and chart cycles checked. Deferred commit errors are translated to sanitized HTTP 409 conflicts.
- Organization action tests passed (2 tests, 7 assertions); the combined organization/personnel action run passed 3 tests and 12 assertions.

### Step 4 evidence

- Current shared-user profile/location actions and transactional position/report assignment replacement implemented; no history or company-membership table added.
- Distinct managers for shared positions, cross-company reporting, nonroot requirements and failed-replacement preservation verified in the personnel action test (5 assertions). Occupancy privilege checks and last-administrator checks integrate with the Access service.

### Step 5 evidence

- Shared catalog and roles, union-based fresh permission resolution, direct and position global/company grant replacement, bounded delegation, occupancy escalation prevention and last-global-administrator protection implemented.
- Explicit source-slug/subject bootstrap and dry-run/idempotent catalog commands implemented; no active user received automatic administrative access.
- Access tests passed: 5 tests, 25 assertions, including company/global separation, no chart inheritance, immediate removal, nonmember grants, protected global position grants and exact bootstrap matching.

### Step 6 evidence

- Protected management APIs, bounded/nested company queries, minimal selectors and sanitized effective-access endpoint wired behind Sanctum, existing upstream validity checks and completed location migration.
- Nuxt allowlisted management gateway uses server-only Bearer exchange, existing HttpOnly credential envelope, Origin/CSRF mutation checks and sanitized errors. Access state resolves after Login on SSR and refreshes with identity checks.
- HTTP authorization suite passed: 7 tests, 40 assertions, including direct anonymous denial, company/global separation, nested-ID substitution, reporting replacement and test-only article routes. Nuxt typecheck passed; Pint applied. Browser/CSRF end-to-end verification remains in step 9.

### Step 7 evidence

- Persian RTL administration shell, company/building/area directories, recursive independent chart, shared personnel/location editor, and current assignment/reporting editor implemented with bounded searchable selectors.
- Retained existing theme tokens (primary #24577A, secondary #526A7B, background #F3F6F8, surface #FFFFFF, text #253746), Vazirmatn and dedicated CSS; used a quiet navigation rail and one working table surface.
- Real disposable-project SSR loaded all management sections. Playwright created a company through the form, expanded the chart, verified existing cross-company manager chips and checked nonroot empty-manager validation.
- Desktop and 390px mobile screenshots inspected; corrected mobile action wrapping/navigation alignment and made global scope an explicit selector option. Light/dark and automatic live device-theme changes passed. Screenshots stay ignored under output/playwright/.
- User explicitly declined bootstrap grants for both original imported users. Administrative browser fixtures exist only in organization-fresh-acceptance; original users remain unprivileged.

### Step 8 evidence

- Shared role catalog/editor, role-permission dialog, direct global/company user-role editor and separate company/global position-role editors implemented. Scope and lack of chart/report inheritance are explained in Persian.
- Playwright saved a shared role permission change, assigned a limited global role to a disposable user, and saved the same limited role in both company/global scopes on a disposable position. All writes used the protected browser gateway.
- Real acceptance exercised company-role removal and position-derived global-role removal against an already issued token; subsequent requests were denied immediately. Identity/source boundaries remain unchanged.
- Searchable selector labels now include stable identifiers to distinguish users or positions with identical names. Selected options can be prioritized across pagination without bypassing company authorization (8 HTTP tests, 46 assertions passed).

### Step 9 evidence — 2026-11-07

- Final PostgreSQL backend suite: **45 tests passed, 256 assertions**. Includes separate-process concurrent reparenting (one commit, one safe conflict), full phase rollback/re-upgrade preserving User/Member/source/token relationships, required-location imports, selected-option pagination and the authorization matrix. All existing Login regressions remain included.
- Final formatting: `vendor/bin/pint --test app/Modules tests/Feature/Login/ImportMembersTest.php` passed for 77 PHP files. Nuxt `npm run typecheck` and `npm run build` passed. Typecheck emitted a nonfatal vue-router/volar plugin-loading warning; the build emitted tooling timing warnings. Neither command failed.
- Final `bash scripts/check-fresh-development.sh` completed with **exit 0**, without a simultaneous frontend build. Fresh bootstrap, both source imports, all backend tests, infrastructure isolation/ports, real multi-provider login/refresh/logout, concurrent SSR, Camunda deployment/completion, real management API/SSR/CSRF and provider outage/recovery acceptance passed.
- Management acceptance verified company-bound queries, direct company grants, position-derived global grants and immediate removal on an existing credential. Verified an empty nonroot reporting replacement preserves the old targets, protected default-area deletion, denied second-user access and public-origin pagination links on port 8081.
- Provider recovery checked both home and authorized management SSR during a real provider stop: HTTP 503 without anonymous login rendering or credential loss; valid SSO and management access recovered after provider restart; application logout remained functional.
- Final runtime inventory: 57 production API routes, zero article routes. Dependency locks, Compose service/port configuration and docs/ have no phase changes. Vue style-separation checks, shell/Python checks and git diff --check passed.
- Original development services were restored: backend, frontend, PostgreSQL, Redis, both Keycloak services, Nginx, pgAdmin and Camunda all running/healthy. Temporary acceptance containers/volumes were removed by the successful cleanup; the encrypted browser fixture was deleted.
- Final original-data check: two users, zero users without location, zero direct global grants, zero company grants and zero position grants. No original account was bootstrapped as administrator, as the user explicitly requested.
- Review corrections: forward real backend HTTP statuses through Nuxt (including 201), preserve the canonical public origin for pagination, add scoped-only position selectors and selected-ID prioritization, show human location/manager/parent labels, and distinguish identical names with stable IDs. Shared-role permission editing checks added permissions against the actor's global authority.
- Effective grants use request-scoped memoization, invalidated around mutations, with no persistent authorization cache. Last-administrator evaluation uses one aggregate query over direct and position-derived global grants. Protected pages leave their content when fresh authorization is removed; upstream invalidation still uses the established Login checks.
- Verification iterations are recorded honestly: an early management acceptance run exposed the 200/201 gateway mismatch and was fixed; an optional visual-review hold later ended with signal 143 and cleanup restored the original project; another run timed out in Login while a frontend build ran concurrently. The final sequential acceptance run passed completely. The temporary review-hold hook is not present in the committed acceptance script.
- Backend, frontend and shared acceptance changes are committed separately. No phase documentation, rebase, merge, push or feature-branch deletion has occurred; these remain gated by explicit completion approval in step 10.

## Review handoff

Implementation and verification are ready for user review: **9 of 10 steps completed**. Review screenshots are local ignored artifacts under output/playwright/, including phase2-companies-desktop.png, phase2-chart-desktop.png, phase2-personnel-mobile.png, phase2-personnel-dark.png and phase2-position-roles.png; they show disposable test data.

At the 2026-11-07 handoff, the original environment deliberately had no administrator grant. At the 2026-11-08 UI review, two pre-existing global user-role assignments were found; this revision did not create or remove them. The access:bootstrap command still requires an explicitly chosen source slug and subject; do not assign or revoke either user's roles without a new explicit instruction.

Await separate explicit phase-completion approval. Only then perform step 10 documentation, final closeout and the approved Git integration lifecycle. This handoff does not mark the phase complete or integrated.

## Authorized UI review revision — 2026-11-08

The user explicitly requested these revisions during the existing phase review. Continue on the current feature branch; this is not a new business phase or completion approval. Database design, authentication mechanisms, permission semantics and backend API contracts do not change.

Scope and method:

- Move navigation into a shared Vuetify application drawer: persistent on desktop and hamburger-controlled temporary drawer opening from the RTL start side on mobile. Apply it to the administration home and all section pages.
- Group related pages into two-level Organization, Personnel and Access menus. Filter children by current effective permissions, then remove empty parents. Use the same section authorization rules for menu visibility and direct URL navigation.
- Render a dedicated Persian 403 page for authenticated users denied a section or requested company. Preserve a real HTTP 403 on initial SSR, avoid loading protected content, and handle client navigation and loss of permissions without loops. Unauthenticated requests retain the existing login behavior; unavailable identity/access remains 503.
- Add an authenticated header account menu with user name, identity source, local user ID, theme selection and logout using the existing protected logout/CSRF flow. Preserve SSR authentication and automatic live theme changes.
- Expand desktop administration to available width, distinguish the navigation surface from the content surface, use clear blue/teal/amber action colors with red destructive actions, and place action columns/buttons physically on the left of RTL lists.
- Visual tokens: navigation navy #183A52, navigation foreground #E7F0F7, working background #F3F6F8, white surface #FFFFFF, primary blue #24577A, teal action #087F83; preserve Vazirmatn and accessible dark-mode equivalents. Use dedicated CSS, local icons and Vuetify APIs only. The layout is a header above a right-side navigation drawer and one broad content surface; no decorative dashboard metrics are added.

Ordered revision steps (separate from the phase's 10-step lifecycle):

1. [x] Implement the grouped permission model, shared desktop/mobile drawer and header account/theme/logout controls.
2. [x] Implement the dedicated 403 flow and finish full-width content, contrasted surfaces, colored left-aligned list actions and responsive refinements.
3. [x] Verify TypeScript/build, SSR and client permission behavior, drawer breakpoints/keyboard behavior, theme and header logout; inspect desktop/mobile screenshots and commit the frontend changes and this revision's evidence.

Acceptance: parents appear only with an authorized child; company-only rights never open a global page or another company; direct denied URLs return a dedicated 403 page with HTTP 403; mobile has a right-side closable overlay drawer, desktop uses the available width; header identity is server-resolved, logout follows the existing OIDC synchronization, and original role assignments are not changed for verification. No docs/ update or integration is authorized by this request.

Revision progress: **3 of 3 steps completed**. The prior phase implementation remains at its 9-of-10 review handoff; step 10 still requires explicit completion approval.

### UI revision step 1 evidence

- Shared permission predicates now drive both menu groups and route access. Empty parents are removed after filtering children. Shared Vuetify drawer/header implemented across administration pages, using installed drawer breakpoint/focus/route-watcher behavior.
- Account menu displays the SSR identity/source and provides existing protected logout plus light/dark/system controls. Existing role assignments were read, not modified.
- Nuxt typecheck passed; four meaningful navigation tests passed (empty groups, scoped/global isolation, unknown pages and revoked/unavailable access).

### UI revision step 2 evidence

- Added a Persian 403 component, explicit /403 route and custom Nuxt error page. SSR denied routes return HTTP 403 before protected page data loads; client navigation redirects to /403. Invalid/unauthorized company query values use the same gate.
- A real authenticated SSR request for an unauthorized company returned the dedicated 403 page without the position directory; /403 returned 403; anonymous administration navigation redirected to the normal login home. Header identity and permission groups were present in private SSR HTML without credentials.
- Full-width administration, separated navigation/content theme surfaces and semantic blue/teal/amber/red left-side list actions implemented in dedicated CSS/Vuetify APIs. Nuxt build passed; only frontend was restarted before browser QA.

### UI revision step 3 evidence — 2026-11-08

- Final Nuxt typecheck, four Node navigation/security tests, and Nuxt production build all passed. The existing nonfatal vue-router/volar plugin-loading warning appeared during typecheck. No backend or database behavior changed, so the prior 45-test backend result is historical and was not rerun for this frontend revision.
- Real authenticated SSR included the user's name and source in the header and filtered navigation data, with private/no-store headers and no bearer/provider tokens. An invalid/out-of-scope company URL returned HTTP 403 with the dedicated Persian denied page before rendering the position directory. Explicit /403 also returned HTTP 403; anonymous administration navigation retained its redirect to the login home.
- Playwright inspected the 1600px desktop workspace, 390px mobile drawer and a 320px viewport without document overflow. Verified right-side overlay, two-level groups, left-positioned operation columns, semantic button colors and visually distinct navigation/content surfaces.
- Browser assertions verified drawer closure on page selection, Escape closure and focus return to the hamburger control. Semantic button/menuitem roles were added to group activators and account logout. Automatic theme followed live dark/light device changes; appearance behavior is shared with the custom error page.
- A read-only browser-only interception of the effective-access response simulated a personnel-only company grant: organization/access parents disappeared, the authorized personnel group remained, and client back-navigation to the now-denied company page redirected to /403. This test did not alter application roles or weaken backend enforcement; actual SSR denial was tested separately against the live gateway.
- Real logout from the header account menu ended the test's own login flow, returned to the anonymous home and removed authenticated header controls. The test browser was closed and .runtime/admin-ui-review-browser.json was deleted.
- Final data/service checks: two users and two pre-existing global user-role assignments, unchanged by this revision; all nine long-running development services healthy. Only frontend was restarted. Dependency manifests/locks, backend, Compose ports and docs/ have no revision changes.
- CSS separation and git diff --check passed. Local ignored review screenshots: output/playwright/admin-ui-desktop.png, admin-ui-mobile.png, admin-ui-mobile-menu.png, admin-ui-account.png and admin-ui-forbidden.png.
- All three UI revision steps are complete. The phase remains at its 9-of-10 completion-review gate; no documentation closeout, rebase, merge, push or branch deletion was performed.

## Authorized persistent appearance review revision — 2026-11-08

The user explicitly requested a JSON user field named `performances`, persistent default appearance, no light-first refresh for a stored dark theme, logout confirmation and connected theme buttons. This is an authorized review revision on the same feature branch, not phase-completion approval.

Database/API design and method:

- Add users.performances as NOT NULL PostgreSQL JSONB, default {"theme":"auto"}, with object/string/allowed-value checks for theme in auto/light/dark. Backfill existing rows with the database default without modifying identities, grants, locations or credentials; cast to an array in the canonical Login User model. Preserve the exact requested field name.
- Return the current user's performances in the existing sanitized Login identity resource. Add PATCH /api/login/performances behind existing Bearer/upstream validity checks; accept only a validated theme, derive the target from the authenticated user and update that JSON path atomically, preserving unrelated JSON keys. No administrator permission or user ID is required for one's own preference.
- Add the equivalent Nuxt server gateway mutation with existing Origin/CSRF protections and server-only Bearer transport. Initialize Vuetify after SSR identity resolution using the stored preference; hydrate with the same mode/resolved theme. Authenticated preferences override legacy browser storage. For auto, use validated device-color hints/cookies when available and continue tracking live device changes; absent initial device hints, the server cannot infer an unknown device setting.
- Store only non-secret appearance/device hints in presentation cookies for SSR fallback/anonymous continuity, never authentication credentials. Keep authenticated HTML private/no-store and preserve existing SSR identity isolation.
- Replace direct logout handlers with a shared confirmation dialog on the header and home control. Cancellation sends no logout request; confirmation uses the existing synchronized logout flow. Render theme options as one connected segmented button group with no per-button rounding/gaps. All custom styling remains in dedicated CSS.

Ordered revision steps:

1. [x] Implement additive performances schema/model/resource, authenticated self-preference API/gateway and meaningful database/authorization tests.
2. [x] Implement server-first theme initialization and persistence, connected theme group and shared logout confirmation.
3. [x] Verify backend regressions, typecheck/build, dark initial SSR and refresh/login continuity, auto behavior, connected controls and cancel/confirm logout in the browser; record actual evidence and commit scoped changes.

Acceptance: existing and newly imported users default to {"theme":"auto"}; another user's preferences cannot be changed via this endpoint; unrelated JSON keys survive updates; invalid modes and anonymous updates are rejected. Stored dark is present in initial HTML and survives hydration without a light interval, refresh and later login. Theme writes stay on origin 8081 with CSRF validation and no browser bearer exposure. Cancel preserves the login; confirm logs out. No phase docs/ update or integration is authorized by this request.

Appearance revision progress: **3 of 3 steps completed**. Phase step 10 remains gated by explicit completion approval.

### Appearance revision step 1 evidence

- Additive JSONB performances migration applied successfully to the development database, with auto defaults and validated theme shape. Canonical User cast/default and sanitized identity resource updated; self-only PATCH API and protected Nuxt gateway implemented.
- Full PostgreSQL backend suite passed: 49 tests, 279 assertions. New checks cover populated migration defaults, new-user defaults, invalid JSON/theme rejection, anonymous/foreign-user-input denial, preserving unrelated keys and upstream revocation enforcement.
- Nuxt typecheck and seven navigation/appearance utility tests passed. No identity, role or location records were reassigned.

### Appearance revision step 2 evidence

- Vuetify now initializes after SSR Login identity resolution, with preference/resolved mode hydrated consistently. Authenticated JSON preference wins over presentation cookies and legacy browser storage; auto continues device tracking.
- Shared logout confirmation replaces direct header/home logout handlers. Theme controls are one connected toggle with disabled saving state and rollback/error feedback on persistence failure.
- Nuxt typecheck, seven frontend utility tests and production build passed. A real stored-dark request returned dark initial application HTML despite explicit light presentation cookies. CSRF-free mutation and invalid mode were rejected through the real gateway.

### Appearance revision step 3 evidence

- Verification totals: full PostgreSQL backend suite 49 tests / 279 assertions; seven frontend utility tests; Nuxt typecheck and production build passed. No application changes followed those checks. Existing nonfatal vue-router/volar typecheck warning remains.
- Real Playwright verification monitored the application theme classes from initial DOM creation through hydration and refresh. Stored dark produced only dark, despite a light device setting and stale light localStorage. Separate HTTP SSR verification covered contradictory light presentation cookies.
- The connected header buttons saved light through the real gateway and retained it after refresh. Auto was saved and followed live dark/light device changes. A fresh OIDC login retained stored dark in both the identity response and initial SSR HTML without relying on a previous browser appearance cookie.
- Cancellation of the confirmation dialog sent zero logout requests and retained authenticated controls; confirmation sent exactly one logout request and returned to the anonymous home through the existing OIDC flow. A browser-only simulated preference-save failure displayed the Persian error and rolled the UI back to the previous dark preference.
- Inspected settled dark account-menu and logout-dialog screenshots: output/playwright/appearance-account-dark.png and appearance-logout-confirm.png (local ignored review artifacts). Initial screenshot captures were repeated after overlay transitions completed.
- Expired review authentication was refreshed for the browser checks. The development account's original auto preference was restored via its protected self API; verification logins were ended and the browser closed. Encrypted review-cookie fixtures were deleted. No roles, locations or identities were reassigned; no other sessions were explicitly revoked.
- Backend implementation committed separately in 6848d85; frontend implementation committed in 3295baa. git diff --check passed. No docs/ closeout, rebase, merge, push or branch deletion was performed; phase step 10 still awaits explicit completion approval.

## Authorized grouped dashboard review revision — 2026-11-08

The user requested a dashboard organized by titled groups, with square link cards containing an icon, title and explanatory text. Render a card only when the user can open its destination. This is an authorized frontend review revision on the current feature branch; no new phase or completion approval is implied.

Scope and implementation: replace the /admin list with semantic group sections and a responsive square-card grid. Reuse useAdminSections and the same permission predicates used by the navigation and route middleware; filter individual cards and empty groups before rendering. Retain existing backend enforcement, empty/unavailable states, SSR identity and light/dark/auto behavior. No database or API changes.

Design: keep the established organization palette (light background #F3F6F8, surface #FFFFFF, blue #24577A, teal #087F83, dark surface #22313E), Vazirmatn and RTL alignment. Group titles sit above compact square tiles with centered icon/title/description; group accents use blue, teal and amber theme tokens. Desktop tiles stay a readable size while using the available width; mobile tiles wrap without clipped Persian text. Dedicated CSS only.

1. [x] Replace the dashboard list with grouped, permission-filtered square destination cards and responsive theme-aware styling.
2. [x] Verify typecheck/build, existing permission tests, authenticated dashboard rendering, restricted-access groups and desktop/mobile visual layout; commit implementation and evidence.

Dashboard revision progress: **2 of 2 steps completed**. Acceptance: only authorized links and nonempty groups render, all cards have local icons/title/description, square layout remains readable on mobile and desktop in both themes, and direct unauthorized routes retain existing 403 enforcement. No docs/ closeout or integration is authorized.

### Dashboard revision step 1 evidence

- Replaced the flat list with titled semantic group sections and square linked cards containing local icons, headings and descriptions. Shared useAdminSections supplies only authorized cards and nonempty groups; route/API guards are unchanged.
- Dedicated CSS supplies responsive grids, blue/teal/amber accents, both theme surfaces and visible focus states. Nuxt typecheck passed (existing nonfatal vue-router/volar warning); all seven existing navigation/appearance tests passed inside the frontend container.

### Dashboard revision step 2 evidence

- Nuxt production build passed. Only the frontend development service was restarted after building. No backend/database/API changes were made, so backend regressions were not rerun for this visual revision.
- Real authenticated /admin SSR included the titled groups and destination cards in private/no-store HTML without bearer/provider credentials. Playwright verified all seven available cards, matching heading references and square dimensions.
- Inspected desktop light/dark screenshots at 1600px and dark mobile screenshots at 390px and 320px. Browser assertions checked square proportions, no document overflow and description text inside each card. Screenshots are local ignored artifacts under output/playwright/dashboard-{desktop-light,desktop-dark,mobile-390,mobile-320}.png.
- A browser-only interception of the access response limited the test display to company-scoped personnel.view. Exactly one personnel card and its group remained; unauthorized cards and empty groups disappeared, and the remaining card opened /admin/personnel. The simulation did not change stored roles or backend enforcement. Screenshot: output/playwright/dashboard-restricted.png.
- The test's own login was ended through confirmed OIDC logout, its browser closed and encrypted cookie fixture removed. No user preferences, identities, locations or role assignments were changed. Existing 403 middleware was reused without changes; its shared permission tests passed.
- Frontend changes committed in f87d549; git diff --check and component CSS-separation check passed. No phase documentation, integration, push or branch deletion performed. Phase completion remains at the explicit review gate.

## Authorized assignment position-path review revision — 2026-11-08

The user requested complete position and manager-position labels in assignments, including consecutive ancestor titles, in both the list and create/edit forms. This is an authorized review revision on the current phase branch.

Design/method: add an Organization service that resolves requested positions and all ancestors in a batched PostgreSQL recursive query, producing root-to-leaf labels prefixed by company to distinguish cross-company managers. Resolve ancestors independently of pagination/search so a leaf's path stays complete. Preserve existing title/ID fields and add position_path plus report_paths to assignment responses, and position_path to both company/reporting position option responses. Existing endpoint authorization remains unchanged; labels reveal only the chart context of positions already allowed by those endpoints. No schema changes.

1. [x] Implement shared position-path resolution, additive assignment/option response fields and meaningful API tests for deep paths, cross-company managers, pagination and selection scope.
2. [x] Display full paths in assignment rows, edit/delete context and create/edit selectors; allow paths and selected chips to wrap using dedicated CSS.
3. [x] Run relevant backend regression checks, frontend typecheck/build, and browser checks for list/create/edit hierarchy labels; commit scoped changes and verification evidence.

Revision progress: **3 of 3 steps completed**. Acceptance: labels read company / root / ... / selected position, including reporting targets from another company; no truncated selected titles, lost ancestors across option pages, or relaxed permissions. No phase documentation or integration is authorized; phase step 10 still awaits explicit completion approval.

### Assignment position-path steps 1 and 2 evidence

- Added a shared recursive ancestor resolver with cycle protection and same-company traversal. Option labels resolve only requested page IDs; assignment page labels and reporting targets resolve in batches. Existing title/ID fields are preserved with additive position_path/report_paths.
- Full PostgreSQL backend suite passed: 51 tests, 304 assertions. New API tests verify deep root-to-leaf labels, company-qualified foreign managers, create/detail/list/update responses, selected options, ancestors outside search/page results and continued company-scope denial. Pint passed on four changed PHP files (explicit paths because Git is unavailable inside the container).
- Assignment rows show full position paths and individual manager paths. Create/edit selectors wrap option and selected-chip text; edit/delete context also shows the full path. Remote choices reload missing selected labels when switching edited records. Dedicated CSS only; initial Nuxt typecheck passed with the existing nonfatal vue-router/volar warning.

### Assignment position-path step 3 evidence

- Final frontend typecheck, seven existing navigation/appearance tests, and production build passed. Backend suite passed 51 tests / 304 assertions before frontend-only refinements; no subsequent PHP changes. The existing nonfatal vue-router/volar warning remains.
- Real authenticated gateway responses included the existing five-level position path and manager path. Browser assertions compared those responses with the table and inspected complete labels in edit context, reporting options and the creation position selector.
- Mobile QA found Vuetify's default single-selection ellipsis and fixed it with a conditional selection slot plus explicit wrapping/auto-height CSS. Repeated 390px checks confirmed no document overflow and no clipped selected-position or manager-chip text, including the existing manager loaded into edit. Inspected desktop/list/options and mobile/create/selected/edit screenshots under output/playwright/assignment-path-*.png (local ignored artifacts).
- Forms were cancelled without saving. The live data changed independently during review; verification did not create, replace or delete assignments, roles or positions. Browser logout ended only the verification flow, the browser was closed and its encrypted fixture removed. Only the frontend was restarted after builds.
- Backend committed in 2f6afbb and frontend in d9b1453. git diff --check and no-inline/component-style checks passed. No docs/ closeout, integration, push or branch deletion performed; phase step 10 remains gated by explicit completion approval.

## Authorized position directory hierarchy review revision — 2026-11-08

The user requested full ancestor paths for positions and their chart parents in the position directory and create/edit forms. Reuse the approved position-path resolver and wrapping selector. Add position_path and nullable parent_path to position list/detail/create/update responses, preserving leaf title and parent ID for correct editing. Resolve paths in batches after filtering/pagination so ancestors outside the page remain present. Keep current position-view authorization for parent choices; no schema or permission changes.

1. [x] Add complete directory/parent paths and API regression coverage; show paths in table and parent options plus a live full-path preview in create/edit while the title field edits only the leaf.
2. [x] Verify backend tests, frontend typecheck/build and real browser list/create/edit paths including mobile wrapping; commit scoped changes and evidence.

Revision progress: **2 of 2 steps completed**. Acceptance: root-to-leaf company-qualified paths remain complete after search/pagination, root parents display as root, create/edit preview and selected parent show the whole hierarchy without changing the stored leaf title. No docs/ closeout or integration authorized; phase step 10 remains pending explicit completion approval.

### Position directory revision step 1 evidence

- Position list/detail/create/update responses expose complete position_path and nullable parent_path through the existing batched resolver; leaf title and parent ID remain unchanged. Parent options continue using the authorized company position directory.
- Table and deletion context show paths. Parent selection wraps and reports its selected path to a live full-path preview that follows title/parent changes in create/edit. The title field retains only the editable leaf name.
- Pint passed; all 11 ManagementApiTest cases passed (99 assertions), including root/deep paths, search/page/selected ancestors, renamed ancestors, title-only editing and company denial. Nuxt typecheck passed with the existing nonfatal vue-router/volar warning.

### Position directory revision step 2 evidence

- Full PostgreSQL backend suite passed: 52 tests, 332 assertions. Frontend typecheck, seven existing navigation/appearance tests and production build passed; no code edits followed verification. Only frontend was restarted after building.
- Real directory API and authenticated SSR included complete paths. Playwright compared full position and parent paths in the table, verified edit input retains only the leaf title, and checked that changing the leaf updates the full-path preview. Create selected a deep parent and verified all ancestors in the resulting preview.
- At 390px the selected parent wrapped completely without horizontal document overflow. Inspected local ignored screenshots: output/playwright/position-directory-list.png, position-directory-edit.png and position-directory-create-mobile.png. Forms were cancelled without saving; no live positions, roles or assignments were changed by verification.
- Verification logout completed through the existing OIDC flow; the browser was closed and its encrypted cookie fixture deleted. git diff --check passed; dedicated CSS and shared hierarchy-selector rules preserved.
- Backend changes committed in 677a6c5 and frontend in 330fded. No docs/ closeout, rebase, merge, push or branch deletion performed. Phase step 10 still requires explicit completion approval.

## Authorized access subject-label review revision — 2026-11-08

The user requested full position names/ancestor paths instead of position IDs in access assignment and position-role forms, and real user names instead of user IDs in user-role forms. Preserve selected option records from existing authorized endpoints rather than manufacturing numeric labels. Existing position_path supplies company/root/ancestor context; add optional identifier-free labels in these selectors and enable hierarchy wrapping for positions. Pass the selected record into role dialogs; show complete, wrapping subject titles in all entry points. No database, backend API or permission changes.

1. [x] Implement selected-record propagation and readable user/position labels in access selection and role dialogs.
2. [x] Verify typecheck/build, existing frontend regressions and real browser user/position dialog subjects without saving roles; commit implementation and evidence.

Revision progress: **2 of 2 steps completed**. Acceptance: access selectors and dialogs display actual user names/full position paths with no ID placeholders, IDs remain unchanged internally for API operations, and long subjects wrap on mobile. No phase closeout or integration authorized.

### Access subject-label step 1 evidence

- Remote choices can emit the original authorized selected record and optionally omit numeric label suffixes. Access assignment passes real selected user/position records into role dialogs instead of fabricating ID-based titles; controls stay disabled until matching labels are resolved.
- Access position selection uses the shared complete-path/wrapping mode; position-role dialog titles prefer position_path for both access-page and directory entry points. User-role titles use the selected name. Dialog headings wrap in dedicated CSS. No API/schema/permission changes.

### Access subject-label step 2 evidence

- Nuxt typecheck, all seven existing frontend navigation/appearance tests, production build and git diff --check passed. Existing nonfatal vue-router/volar warning remains. Backend unchanged; prior 52-test backend result was not rerun for this frontend-only revision.
- Real browser selections from authorized personnel and position endpoints displayed actual names/full paths without numeric suffixes. User-role dialog title exactly matched the selected user name; position-role title exactly matched the selected full path. At 390px the full heading wrapped without clipping or document overflow.
- Inspected local ignored screenshots output/playwright/access-user-role-name.png and access-position-role-path-mobile.png. Both forms were cancelled without saving; no role or other business data was changed. Verification logout completed, browser closed and encrypted cookie fixture removed. Only frontend restarted after building.
- Frontend committed in 0bf5712. No docs/ closeout, rebase, merge, push or branch deletion performed; phase step 10 remains pending explicit completion approval.

## Authorized persistent modal review revision — 2026-11-08

The user requested that modal forms stay open when their overlay is clicked. Set the shared Vuetify VDialog default to persistent and remove local conditional-persistence overrides so all existing form/confirmation dialogs consistently require their explicit cancel/close/action buttons. Vuetify persistent also prevents accidental Escape/back dismissal; existing busy-state controls and programmatic success closure remain intact. No database/API/permission changes.

1. [x] Apply the shared dialog default and remove weaker local overrides.
2. [x] Verify typecheck/build and browser overlay-click preservation of an unsaved form plus explicit cancel closure; commit implementation and evidence.

Revision progress: **2 of 2 steps completed**. Acceptance: overlay clicks preserve open dialogs and entered values, explicit cancel/close still works, and successful actions can still close programmatically. No phase closeout or integration authorized.

Step 1: shared VDialog persistent default applied; all eight local conditional overrides removed. Existing explicit cancel/close, busy-state disabling and successful-action handlers are preserved, including confirmation dialogs. No backend changes.

Step 2 evidence: Nuxt typecheck and production build passed (existing nonfatal vue-router/volar warning); git diff --check passed. Real browser tests verified overlay-click persistence of the source selection, an unsaved company form and logout confirmation. The typed company title survived overlay clicks and Escape; explicit cancel closed the form without saving. Confirmed logout still completed normally. No business data was created/changed; verification browser closed and its encrypted cookie fixture deleted. Only frontend restarted after build. Backend unchanged and not retested for this UI-only default. Implementation committed separately; no docs/ closeout or integration performed.

### Authorized Escape refinement — 2026-11-08

The user clarified that Escape must close dialogs while overlay clicks must not. Keep the shared persistent default for overlay protection and handle Vuetify's topmost-dialog Escape event explicitly for each dialog's existing model/close action. Preserve existing busy/submitting protection; a nested autocomplete menu consumes its first Escape before the parent dialog closes. No database/API changes.

1. [x] Restore Escape dismissal across forms, confirmations and source selection while keeping overlay protection and busy guards.
2. [x] Verify typecheck/build and browser overlay/Escape/cancel behavior including a nested selection menu; commit changes and evidence.

Refinement progress: **2 of 2 steps completed**. This supersedes the previous Escape-blocking behavior; no phase closeout or integration authorized.

Verification: Nuxt typecheck/build and git diff --check passed (existing nonfatal vue-router/volar warning). Real browser checks confirmed source-selection overlay clicks keep it open and Escape closes it; an unsaved position form retained its typed title after overlay click; first Escape closed only the nested parent-choice menu and second Escape closed the form. Escape dismissed logout confirmation without logging out, and explicit confirmation still completed logout. No business data was saved; verification browser closed and encrypted fixture removed. Busy guards remain in each handler. Backend unchanged and not retested. Frontend committed separately; no docs/ closeout or integration performed.
