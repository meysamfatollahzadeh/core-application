# Phase 3 — Personnel Creation and Keycloak Account Connections

## 1. Status, baseline and approval gates

- Prepared: 2026-11-10 (Asia/Tehran).
- Status: **Approved; implementation in progress.**
- Explicit plan approval received in chat on 2026-11-10; implementation authorized. Completion approval remains pending.
- Implementation progress: **4 of 8 steps completed**.
- Branch: `codex/feature/personnel-account-linking`.
- Baseline: `84e6f4c1bd143a6da7fe6902922cf94a25534240`.
- Planning preparation: verified a clean working tree on master; fetched origin successfully; switched to master; `git merge --ff-only origin/master` reported already up to date; created the feature branch before replacing this file. HEAD and origin/master both matched the baseline.
- This is the only current root plan. The completed Phase 2 plan remains in Git history.
- The user clarified business requirements one question at a time. Those answers define this proposal; they do not constitute approval of the finished plan or phase.
- Commit this plan and every subsequent plan update, including approval, progress and evidence. Keep all phase work on this feature branch.
- Obtain explicit plan approval before application code, migrations, tests implementing the new behavior, or infrastructure changes. Record and commit approval before implementation.
- After each implementation step, report in Persian: “مرحله X از ۸ تکمیل شد.”
- Step 7 ends at the review gate. Step 8, phase documentation, closeout and integration require separate explicit completion approval. Plan approval alone does not authorize integration.

## 2. Subject, outcome and scope

Allow operators to create a local User from the Personnel interface first, then explicitly connect existing accounts found directly in a selected Keycloak source. One User may have accounts from several sources, but at most one Member per source. A Keycloak identity may belong to only one local User.

In scope:

- Create personnel using the existing canonical Login User model, with mandatory local name and current building area.
- A personnel-row action titled `حساب‌های ورود` for listing, adding and deleting local Member connections.
- Live, paginated Keycloak search by username, first/last name and email, with selection eligibility and existing local owner names.
- Independent global permissions for creation and account-connection management.
- Database enforcement, transactional conflict handling, member-specific token revocation and concurrency protection.
- Preserve bulk import and enforce the same invariants there, including dry-run validation.
- Focused regressions, real-browser verification, and post-approval English technical and Persian usage/operations documentation.

Out of scope:

- Creating, editing, enabling, disabling or deleting Keycloak users; password management; source-configuration CRUD; automatic callback registration; automatic identity matching by name or email.
- Deleting local Users, transferring an occupied account directly between Users, automatically replacing a same-source connection, or synchronizing local profile data from Keycloak.
- New personnel profile fields, employment histories, new company membership, role/position changes, or Camunda workflows.
- Dependency upgrades, replacement identity/authentication protocols, new services or public ports, and changes to existing role-deletion/last-administrator protections outside Member removal.

## 3. Confirmed business behavior

### Personnel creation

1. Save a User before connecting any Member. A User with zero Members is valid and remains visible in the global personnel list.
2. The creation form contains only `نام و نام خانوادگی` and `ساختمان و ناحیه استقرار`; both are mandatory. Store the existing `building_area_id`, rather than adding a redundant building column.
3. Preserve current local defaults, including automatic theme preference. Creation does not assign company membership, positions, roles or credentials.
4. Connection and import mapping to an existing User never overwrite that User's name or location. Keycloak profile information is for account identification and selection.

### Connections and search

1. Every Member belongs to exactly one User and one UserSource and identifies one Keycloak subject by `(uuid, user_source_id)`.
2. Each User can have at most one Member from each source. If a connection already exists for that source, disable search and connection for that User/source until the Member is deleted. Enforce this on the server before making any remote search request as well as in the UI.
3. Search directly in the selected source's Keycloak, not in an imported local directory. Only active sources may be searched or used for new connections.
4. Display username, first name, last name and email in results. Preserve missing fields as empty/absent values rather than inventing identity data.
5. Accounts already connected to another User remain visible but cannot be selected; display the connected personnel name. Never silently reassign an occupied identity.
6. Disabled Keycloak accounts remain visible but cannot be selected. Exclude service accounts from search results. Validate eligibility again on submission using a fresh server-side account lookup.
7. Existing connections remain visible and deletable even when their source is inactive or the provider cannot be reached. Source inactivity must not hide local Member records.
8. Source identity in public routes, resources and configuration remains the stable slug. Numeric `user_source_id` is internal to the database.
9. Use explicit search submission and bounded pagination. Preserve truthful continuation after excluding service accounts; an empty filtered page must not incorrectly imply that the upstream result set is exhausted.

### Removal and authentication

1. Delete only the local Member and its own application tokens. The remote Keycloak account remains unchanged; do not call Keycloak user-deletion, remote session-termination or profile-write APIs for this operation.
2. Revoke every local Sanctum token whose `member_id` matches the deleted connection, regardless of browser/device. Tokens belonging to other Members of the same User remain valid, subject to normal upstream checks.
3. Commit token deletion and Member deletion atomically. After commit, revoked credentials must fail subsequent protected requests. Requests already authorized before commit are not retroactively cancelled.
4. If the operator removes the Member used by their current browser, complete the deletion and let the existing unauthenticated flow clear the protected browser credential and return to login.
5. Removing the last Member is explicitly allowed, including for an administrator and even if no administrator retains a connection. Preserve the User, location, positions, reporting assignments, preferences and roles. Do not introduce a last-login-connection guard.
6. A later login without a matching Member remains forbidden by the existing callback. Removing and later reconnecting an account never revives old application tokens.

### Bulk import

1. Keep `login:import-members`, including source slugs, pagination, building-area requirements, explicit mapping, repeat-safe imports and `--dry-run`.
2. Retain both uniqueness rules for every write path. An explicit mapping cannot connect a second account from the same source to a User, or take an account already connected to another User.
3. A conflicting entry fails with a clear sanitized conflict report and a nonzero exit code; preserve existing associations and already completed entries. Do not silently merge Users, replace connections or delete tokens on import conflicts.
4. Dry-run performs the same logical conflict checks, including conflicts among entries within the pending batch, without creating Users, Members or tokens. Track pending mappings so two subjects mapped to the same User/source are detected even without writes.
5. Importing an already-connected identity with the same mapping stays repeat-safe; importing a new identity without an explicit mapping retains the existing new-User behavior and mandatory building area.

## 4. Architecture and authorization

- Preserve Laravel modular APIs, Nuxt/Vuetify, Redis, PostgreSQL, Keycloak and Camunda and all existing container/service versions.
- Keep personnel orchestration, requests, controllers, routes and feature tests in Personnel; reusable provider reads, membership integrity/lifecycle, migrations and import changes in Login; permission catalog changes in Access. Extend Organization's existing location-options authorization narrowly for personnel creation.
- Keep existing `personnel.view` for directory access and `personnel.update` for profile changes. Add independent permissions:
  - `personnel.create` — create a local User, without implying edit or connection authority.
  - `personnel.members.manage` — inspect and manage account connections, including source choices, remote search, attach and detach, without implying profile creation/edit authority.
- Both new operations require global grants because User and Member are shared across companies. A company-scoped grant must not satisfy them. Directory navigation still requires the applicable existing `personnel.view`; creation and management controls require their own permission. Global managers may use a row's connection action in either personnel list; creation appears on the global personnel page.
- Location-option reads accept global `personnel.create` OR global `personnel.update`, returning only existing sanitized location choices. Creation must work for an actor with view/create and no update permission.
- Sync the permission catalog using the existing mechanism. Do not automatically add new permissions to existing roles or broaden their grants. Explicit role assignment remains an operational action by an authorized administrator.
- All new APIs retain Sanctum Bearer authentication and current provider-grant validity checks. Nuxt exchanges its protected browser cookie for a Bearer header only on its server. Preserve SSR authentication resolution, CSRF enforcement for mutations, private/no-store responses and the Nginx `8081` origin.
- Use each source's existing configured import/admin read client for Keycloak queries. Fail with a sanitized provider-configuration/unavailable response if that source lacks working read credentials; do not fall back to another source or hard-coded credentials.
- Use configured provider URLs and bounded timeouts; never accept a client-supplied provider URL, access token, source numeric ID, local owner or remote profile as authority.
- Search responses expose only the specified profile fields, external subject, selection state/reasons and authorized existing owner ID/name. Never expose raw Keycloak representations, configuration, secrets or protocol tokens. Remote text must render as escaped text.
- Return stable machine-readable reasons for connection conflicts and provider errors through the Nuxt gateway, with targeted Persian messages. Do not collapse all 409 cases into the current unrelated assignment/role error message.

## 5. PostgreSQL database design and integrity

| Table | Planned changes / invariant |
| --- | --- |
| users | No schema changes. Continue required name and building_area_id, current preferences default and existing relations. |
| user_sources | No schema changes. Keep unique stable slug, activity flag and protected configuration. |
| members | Preserve bigint PK, uuid, numeric user_source_id FK, user_id FK and timestamps. Preserve existing UNIQUE(uuid, user_source_id). Add named UNIQUE(user_id, user_source_id). |
| personal_access_tokens | No schema/FK changes planned. Preserve member_id and restrictive FK; explicitly delete matching tokens before deleting the Member in the same transaction. |
| permissions and role pivots | Add catalog entries via existing permission synchronization. No new schema or implicit role grants. |

The two pairwise unique constraints enforce the confirmed requirements and imply uniqueness of `(uuid, user_id, user_source_id)`. A separate triple index would be redundant and would not alone prevent the forbidden associations.

Migration safety:

- Before adding the new constraint, check for multiple Members per `(user_id, user_source_id)` and report conflicting local identifiers without credentials.
- If conflicts exist, abort without modifying/deleting associations, Users or tokens. Request an explicit operator decision before data reconciliation; this plan does not authorize automatic destructive cleanup.
- Use an additive migration in Login; retain the prior identity constraint and all foreign keys. Rollback removes only the newly added constraint.
- Do not add cached identity-profile columns or store slugs in members. Local connection listing must always be available using source title/slug and uuid; remote profile enrichment is best effort and may display an unavailable state without blocking removal.

Concurrency and transaction strategy:

- Reuse a consistent source-row lock followed by relevant User/Member locks for attach, detach, callback and import writes. Freshly recheck source status, same-source occupancy and external identity ownership under the lock before inserting.
- Coordinate with the callback's existing source lock so detach cannot race token issuance to create a live token for a deleted Member. Preserve GrantVerifier's fresh-token checks and test concurrent refresh/deletion behavior.
- Perform remote account eligibility reads before the short write transaction, then verify local source configuration/activity under lock against the configuration used for the lookup. Do not hold a database transaction open during slow provider requests.
- Enforce the two constraints in PostgreSQL as the final concurrency boundary and convert unique-constraint races into stable conflict responses without leaking SQL.
- Removing a Member locks the source/Member, deletes its tokens and then the Member atomically. Keep the restrictive FK as an integrity backstop rather than allowing orphaned tokens.

## 6. Proposed API contracts

All paths below are under `/api/` and use the existing protected API route registration. Nuxt exposes only explicitly allowlisted counterparts under `/gateway/manage/`.

| Method and path | Permission | Request / success |
| --- | --- | --- |
| POST personnel | global personnel.create | `{name, building_area_id}`; 201 with sanitized local User fields. Required trimmed nonempty name, max 255, existing building area. No Member is created. |
| GET personnel/{user}/members | global personnel.members.manage | 200 with local connections, source slug/title/activity and uuid; optional sanitized live profile/status with explicit unavailable fallback. Includes inactive sources. |
| GET personnel/{user}/member-sources | global personnel.members.manage | 200 with active source slug/title, existing-connection state and can_search. An occupied source remains visibly unavailable for search. |
| GET personnel/{user}/member-sources/{source:slug}/accounts | global personnel.members.manage | Validated search and bounded page/per_page; 200 with sanitized remote results, selection reasons, local owner where applicable, and truthful pagination continuation. Reject inactive or occupied source before remote I/O. |
| POST personnel/{user}/members | global personnel.members.manage | `{source_slug, uuid}`; fresh remote account validation; 201 with the created connection. Never trust eligibility or owner fields from the client. |
| DELETE personnel/{user}/members/{member} | global personnel.members.manage | Validate nested ownership; atomic token and Member deletion; 204. Works without contacting Keycloak. |

Common failures: 401 for absent/revoked credentials; 403 for insufficient global authority; 404 for unknown/nested-owner mismatch; 409 for occupied identity, existing User/source connection, or source activity/configuration changing during attachment; 422 for invalid input or ineligible/missing remote account; 429 for bounded search rate limits; 503 for unavailable/misconfigured provider reads. Resolve an unknown or inactive search source without making provider calls. A remote account deleted after search cannot be attached by submitting its old uuid.

Extend the gateway allowlist and forward only the validated search/pagination inputs. Preserve stable sanitized reason codes and bounded error information. Use no-store for identity searches; do not place provider credentials or app Bearer tokens in URLs, browser storage or SSR payloads.

## 7. Frontend behavior

- Add `افزودن پرسنل` on the global personnel list for authorized creators. Reuse existing form layout/location chooser, validation, loading and explicit cancellation. Successful creation refreshes the list; it does not require a Member or grant a role.
- Update the existing empty-state explanation, which currently says all new accounts arrive from identity imports.
- Add a row action `حساب‌های ورود` for authorized connection managers. Its dialog identifies the selected personnel by name, lists current connections and offers the active source selector/search flow.
- Display each connection's source title, account identifiers/profile when available, activity/unavailable status and explicit delete action. Confirm deletion and explain that it removes the local connection and its active application access while retaining the Keycloak account.
- Disable search for an occupied source with a clear explanation that its existing connection must first be removed. Refresh source eligibility after successful attach/detach and recover cleanly from concurrent conflicts.
- Search results show username, full name and email, disabled-account status, and the name of the already-connected personnel where applicable. Only eligible results offer connection. Require explicit selection and save, preserving local User profile data.
- Discard stale asynchronous results when the selected User/source/query changes; prevent duplicate submissions. Handle empty, loading, conflict, provider-unavailable and retry states explicitly.
- An empty or unavailable remote profile never hides an existing connection or disables its local deletion. Membership deletion remains usable during provider outages for an otherwise valid authenticated request, subject to the existing upstream authentication checks on that request.
- Preserve Persian RTL, Vuetify, locally stored Vazirmatn/assets, accessible labels/focus, responsive layouts and light/dark/automatic themes. Keep CSS in dedicated files, with no component style blocks, inline styles or JavaScript style bindings.
- Preserve overlay-click protection, explicit cancel controls and current Escape behavior, including busy-state guards and nested choices.

## 8. Ordered implementation steps

1. [x] **Database invariants and permission foundation.** Add conflict preflight and the User/source unique constraint; extend permission catalog/labels and creation location-options authorization. Verify fresh migration/rollback and conflict refusal on PostgreSQL. Commit scoped backend changes and plan evidence.
2. [x] **Live Keycloak account reads.** Add reusable sanitized search/account lookup using configured read clients, bounded pagination, disabled/service-account handling and explicit provider failures. Test the requested search fields and the pinned Keycloak runtime's behavior. Commit Login changes and plan evidence.
3. [x] **Personnel creation and connection APIs.** Implement create/list/sources/search/attach/detach endpoints, independent global authorization, nested ownership, live attach validation, stable reason codes, transactional uniqueness and member-specific token deletion. Add focused API/integrity tests; commit backend work and evidence.
4. [x] **Import and authentication concurrency compatibility.** Bring bulk import/dry-run under the same constraints, cover pending batch conflicts, coordinate callback/removal locking, and verify selective revocation and fresh credential checks. Commit scoped backend changes and plan evidence.
5. [ ] **Nuxt gateway and Personnel interface.** Extend allowlisted routes/error transport, add creation and account dialogs/actions, permission-based controls, result eligibility/owner labels and resilient source/profile states. Keep frontend commits separate from backend commits; commit evidence.
6. [ ] **Automated verification.** Run targeted and full PostgreSQL backend suites, meaningful concurrency/integrity regressions, existing frontend tests, Nuxt typecheck/build and diff checks. Fix failures on this branch and record actual results and limitations.
7. [ ] **Browser acceptance and review handoff.** Verify creation, remote search, successful and blocked connections, inactive/disabled sources/accounts, same-source search lockout, local-only deletion, selective revocation and self-detach across the gateway. Check desktop/mobile, RTL, themes, focus and dialog behavior. Use isolated fixtures and preserve unrelated development data; record actual cleanup. Pause for explicit user completion approval; small requested fixes repeat relevant checks.
8. [ ] **Approved documentation, closeout and integration.** Only after explicit completion approval, update English technical docs for schema, business behavior and API contracts and Persian usage/operations guides, including permission assignment, import conflicts and reconnecting Users with no Members. Record and commit closeout. Fetch origin, rebase feature onto latest origin/master, resolve conflicts with git rebase --continue and repeat relevant validation. Update master with --ff-only, merge the verified feature using --no-ff and push master. If upstream advances, repeat synchronization/rebase/validation before retrying; never reset or force-push master. Delete local feature with git branch -d and any published remote feature only after successful integration/push. Report actual results and preserve the branch if blocked.

## 9. Acceptance criteria and verification matrix

- Create a User with only mandatory name/area, with zero Members and intact defaults; reject empty/overlong name and invalid/missing area without partial writes. Creation with view/create but no update or members-manage succeeds, including location selection.
- New permissions are independently enforceable. Existing update does not imply create/manage; company-only grants never authorize the global operations. Unauthorized calls do not trigger remote searches or expose account owners.
- Connect one identity from source A and another from B to the same User. A second account from A fails both through the API and direct database writes; occupied-source search fails before remote I/O.
- A single `(uuid, source)` cannot be linked to two Users, including concurrent attempts. The same uuid in different independently configured sources remains a distinct identity.
- Search demonstrates username, first name, last name and email behavior against Keycloak; shows disabled and occupied accounts with correct reasons/owner labels, excludes service accounts, and paginates without false exhaustion. No identity or profile is silently created/updated remotely.
- Attach rejects forged/stale remote identity, missing/disabled/service account, inactive source, changed source configuration and conflicting local ownership. Preserve the local name/location and existing connections on every failure.
- Existing inactive-source connections remain visible and removable. Remote profile failure has an explicit local fallback. Detach itself makes no Keycloak calls or changes.
- Deleting a Member removes exactly its tokens and local row atomically. Other Members' tokens continue passing validity checks. Callback/refresh races cannot restore revoked tokens; removing/reconnecting never revives old tokens. Test rollback on deletion failure.
- Deleting the last Member, including one's own administrator connection, succeeds while preserving all User/domain/role data; the affected browser transitions to login on its next protected request.
- Bulk import preserves repeat safety, explicit mapping, required area, skip behavior for disabled/service accounts and no automatic merging. Real import and dry-run detect both database conflicts and within-batch mapping conflicts; failed entries do not overwrite established associations.
- Upgrade with valid existing data succeeds. A conflicting fixture aborts before destructive changes. PostgreSQL constraints and rollback behavior are verified on isolated test data, not by deleting development associations.
- Existing login/logout, upstream validity, SSR, CSRF, role assignments, organization, appearance, and navigation regressions pass. No service/port changes, remote asset dependencies, component styles or credential leaks are introduced.
- Browser evidence covers desktop/mobile RTL, light/dark/auto appearance, keyboard/focus/overlay/Escape behavior, permission visibility, provider failures and creation/connection success states. Record fixtures and actual test cleanup.
- Commit implementation and plan evidence on this feature branch. Stop after step 7 until the user explicitly approves completion; documentation and integration only follow that approval.

## 10. Planning references and current evidence

- Repository findings: existing User has a members relationship and mandatory building area; Member already has UNIQUE(uuid, user_source_id); tokens already have member_id with restrictive deletion; callback and importer already lock source rows. Personnel currently supports list/edit but no create or Member management API. The Nuxt management proxy currently rejects POST personnel and Member routes, and location options currently require personnel.update.
- Keycloak search/account reads will follow the official [Keycloak Admin REST API](https://www.keycloak.org/docs-api/latest/rest-api/index.html#_users), including users search fields, pagination and user-by-ID lookup; verify behavior against the repository's pinned 26.7.3 runtime rather than assuming latest-documentation compatibility.
- Planning checks only: clean-tree inspection, current code/schema/route inspection, successful origin fetch and fast-forward synchronization, and feature-branch creation. Application tests and live Keycloak behavior have not been run for this proposed phase.
- No application code, infrastructure, development data, phase closeout documentation, role grants or credentials were changed during planning.
- Approval gate satisfied by explicit user approval in chat on 2026-11-10; step 1 may begin after committing this approval record.

### Step 1 evidence — 2026-11-10

- Added additive member uniqueness migration with locked conflict preflight and non-destructive rollback; preserved existing external identity uniqueness. Added independent permissions, protected existing admin roles from implicit new grants, and enabled location options for creators.
- PostgreSQL targeted tests: 15 passed / 109 assertions (MemberIntegrityTest and ManagementApiTest), covering migration conflict refusal/reapplication and independent location access. No development associations reconciled or deleted.

### Step 2 evidence — 2026-11-10

- Added sanitized source-configured directory search and exact subject lookup, service-account filtering, disabled-account visibility, raw-page continuation and safe provider errors. Profile results omit credentials/configuration.
- AccountDirectoryTest: 3 passed / 10 assertions. Read-only checks against the running pinned Keycloak succeeded for username, first name, last name, email and exact user lookup; no identity data was changed.

### Step 3 evidence — 2026-11-10

- Added independent creation and connection-management APIs, source-slug binding, remote eligibility/configuration rechecks, per-source search guards, sanitized owner labels and local fallback listing. Attach/detach use source/User/Member locking; removal atomically deletes only matching tokens and the Member.
- MemberConnectionTest: 9 passed / 75 assertions, including invalid creation, independent/global grants, cross-source connections, occupied identity rejection, inactive-source behavior, profile preservation, selective/last-member deletion, nested ownership and rollback on injected deletion failure.

### Step 4 evidence — 2026-11-10

- Import now checks source configuration/activity under lock, protects mapped Users and detects existing and within-batch User/source conflicts in dry-run and real runs. Conflicts report stable sanitized codes and preserve completed entries. Callback locks the matched Member while its source lock serializes token issuance with detach.
- ImportMembersTest, TokenLifecycleTest and MemberConcurrencyTest: 18 passed / 152 assertions. Two real PostgreSQL process races verified exactly one successful connection for competing identity owners and competing same-source accounts. Lifecycle tests covered surviving other-Member credentials, unknown membership rejection, non-revival after reconnect and detach during upstream introspection.
