# Current Plan — Phase 1 Revision: Modular Multi-Source Login

## Status, baseline and approval

- Status: **Approved review revision in progress — Member source foreign key.**
- Revision date: 2026-11-03.
- Revision implementation progress: **8 of 9 steps completed.**
- The previous infrastructure implementation reached its review handoff in commit `f2415ac`; later review fixes are also in Git. Phase completion was never approved. The user has now requested a material redesign of authentication.
- This revision replaces the previous current plan, not the existing infrastructure. Earlier commits preserve the baseline plan and evidence. Do not create a competing active plan or mark the phase complete.
- The user explicitly approved implementation and requested textual UserSource slugs on 2026-11-03. All implementation steps below are authorized; completion still requires a separate review approval.
- Preserve the approved monorepo, Laravel/PHP runtime on internal 8000, Nuxt dev runtime on internal 3000, Redis/PostgreSQL, Camunda, local frontend assets, and gateway/management ports 8081–8084.
- After implementation, pause for user review. Only after explicit phase-completion approval create English technical Markdown and Persian usage guides in `docs/`, update Graphify, and close the phase with separately scoped commits.

## 1. Subject and outcome

Replace the session-based single-provider prototype with a modular Login API using Sanctum Bearer tokens, multiple Keycloak sources, pre-imported local memberships, server-rendered Nuxt authentication and bidirectional logout.

Only the Login business module is developed. Source registration/import commands are included; source/user administration screens, roles/permissions, unrelated business modules and automatic account linking are excluded.

## 2. Architectural decisions

### Laravel API and the meaning of stateless

- Install Sanctum in API-token mode. Configure token-only authentication (`auth:sanctum` without a session guard fallback) and route the module through Laravel's API middleware group.
- Remove application authentication routes from the `web` middleware, the session endpoint, StartSession-dependent logic, session cookies and Redis login-session configuration. Old application session cookies are explicitly expired during the transition; do not flush unrelated Redis data.
- Sanctum stores token hashes in PostgreSQL to support lookup, expiry and revocation. This is token-based API authentication without Laravel sessions; it is not a promise of zero server-side persistence.
- Redis remains available for cache, throttling, short-lived single-use OIDC transactions, replay protection and refresh locks. None of these becomes a replacement browser login session.
- Keycloak remains the identity provider. Sanctum is the application's API credential; it does not replace OIDC or validate a Keycloak JWT as though it were a Sanctum token.

### Nuxt credential transport and SSR

- Use a thin Nuxt server gateway: browser requests to `/gateway/login/*` are forwarded internally to Laravel `/api/login/*` with a Bearer header. Direct Laravel API consumers can still call `/api/login/*` with their own Bearer header.
- Nuxt stores the browser's application credential in an encrypted, HttpOnly, SameSite=Lax cookie, with Secure enabled on HTTPS and a development HTTP exception. The cookie transports the token for SSR; it is not a Laravel session ID. No Nuxt session store is introduced.
- Browser JavaScript never receives the Sanctum Bearer or Keycloak access/refresh tokens. Do not put credentials in localStorage/sessionStorage, callback URLs, logs or serialized Nuxt payloads.
- Resolve `/api/login/me` on the Nuxt server and await it before rendering. Serialize only sanitized user/source display data in request-scoped Nuxt state. Never cache authenticated HTML or share identity state between SSR requests.
- SSR renders the correct authenticated or anonymous control in its first HTML response. Hydration must preserve it, without a mounted login-to-logout switch.
- A 401 clears the browser credential and produces an anonymous result. An upstream outage produces an unavailable state/503, not a false successful login or an automatic anonymous flash.
- Nuxt cookie-authenticated mutation routes validate Origin and CSRF proof without a session store. Laravel API authentication remains Bearer-only; the signed provider webhook has its own protocol validation.

## 3. Login module structure

```text
backend/app/Modules/Login/
  Models/                    UserSource, Member, User, PersonalAccessToken
  Actions/                   StartLogin, CompleteLogin, Logout, ImportMembers
  Services/                  Per-source OIDC/admin clients, token validation
  Http/Controllers/
  Http/Requests/
  Http/Resources/
  Http/Middleware/            Membership/source/upstream token checks
  Console/Commands/
  Database/Migrations/
  Database/Factories/
  Providers/LoginServiceProvider.php
  Routes/api.php
backend/tests/Feature/Login/  Module-focused integration tests
frontend/server/routes/gateway/login/
frontend/server/routes/login/  Callback and logout continuation
frontend/app/components/login/ Source-selection dialog
frontend/app/composables/     Request-scoped authentication state
```

Register only the Login module provider at the application composition root. Its provider loads routes, migrations and commands. Keep the generic infrastructure health endpoint outside business logic. Move the existing OIDC prototype into the module and replace its session behavior; do not leave two competing authentication paths.

## 4. Database design

The three domain models requested by the user are retained. Standard timestamps are added. No local email/password/reset tables are introduced.

| Table | Fields and constraints |
| --- | --- |
| `user_sources` | `id` bigint PK; `slug` varchar(63) UNIQUE NOT NULL; `title` varchar(255) NOT NULL; `is_active` boolean NOT NULL default false; `data` JSONB NOT NULL; timestamps |
| `users` | `id` bigint PK; `name` varchar(255) NOT NULL; timestamps |
| `members` | `id` bigint PK; `uuid` varchar(255) NOT NULL; `user_source_id` bigint FK to `user_sources.id`; `user_id` FK; timestamps; UNIQUE (`uuid`, `user_source_id`); indexes on `user_source_id` and `user_id` |
| `personal_access_tokens` | Sanctum's normal tokenable/hash/abilities/expiry fields plus `member_id` FK, `provider_sid` nullable varchar(255), `provider_config_hash` varchar(64), encrypted `oidc_data` TEXT; index (`member_id`, `provider_sid`) |

### Identity relationships

- UserSource retains an internal numeric primary key, but its immutable lowercase URL-safe slug is the public identifier. Member relationships use the numeric source primary key through `user_source_id`, with UNIQUE (`uuid`, `user_source_id`), as explicitly requested during review. Validate slugs with `[a-z0-9]+(-[a-z0-9]+)*`, maximum 63 characters, and never rename an existing source implicitly. All `{source}` route parameters bind by slug; source resources and import/source commands use slugs, not numeric source IDs.
- A User has many Members. A Member belongs to one User and one UserSource. A UserSource has many Members.
- `uuid` stores Keycloak's exact subject/user ID as an opaque string. A SQL UUID type or globally unique subject constraint would reject some federated IDs or incorrectly conflate different sources.
- Two different sources may use the same `uuid` without identifying the same local User.
- One User can deliberately link several Members. Linking requires an explicit operator-provided mapping; matching names or email claims never links accounts automatically.
- Domain deletes use restrictive foreign keys in this revision; destructive user/source management is outside scope. Revoking a credential deletes its token record. The token owner must match `member.user_id`.

### UserSource.data

- Keep all provider settings in JSONB: exact public issuer, realm/base URLs, trusted internal backchannel endpoints, OIDC client ID/secret, allowed signing algorithms, fixed callback/logout URIs, import service-account settings, pagination/timeouts and source-specific display configuration where needed.
- Encrypt secret values individually inside the JSON object using the application encryption key; do not use Laravel's whole-value encrypted-array cast on a JSONB column. Hide `data` from default serialization. Public resources return only source slug and title.
- Validate configured endpoints and provider discovery against the trusted operator configuration. Never choose issuer/JWKS/token URLs from browser callback parameters or unverified token claims. Preserve strict issuer/audience/signature validation.
- Bind pending transactions and issued tokens to a fingerprint of the authentication-relevant source configuration. Changed issuer/client settings invalidate old transactions and credentials rather than rebinding them to a different provider.
- Inactive sources cannot start/complete login or authorize existing API tokens.

### Token and upstream grant data

- A Sanctum token belongs to User and records the Member used for that login. Store its associated provider session ID and encrypted access/refresh/ID tokens with upstream expiry in `oidc_data`.
- Use finite token expiry (proposed maximum 24 hours), additionally bounded by upstream validity. Perform safe refresh before upstream access-token expiry; use a per-grant lock to avoid concurrent refresh rotation races.
- Unknown Members, changed source configuration, expired/revoked grants and invalid upstream identities do not receive application access.
- Existing business data is not deleted. These are additive application migrations; Keycloak/Camunda continue to own their own schemas. No default Laravel users/password-reset migration is used.

## 5. Source registration and import

- Proposed command: `php artisan login:sources:upsert --config-file=/private/source.json`. Validate settings, encrypt secret fields and avoid printing credentials. The initial configured development source is seeded from private bootstrap configuration, not committed credentials.
- Proposed import: `php artisan login:import-members --source=SLUG [--dry-run] [--mapping-file=/private/member-map.json]`.
- Use the selected source's Admin API with a separate least-privilege service account, not an administrator's username/password. Read all pages with bounded retry and report imported/skipped/error counts without sensitive output.
- Import enabled human users by default and skip service accounts/disabled identities. Re-import finds Member by `(source, uuid)` and does not create duplicates or change an existing explicit User association.
- For a new external identity, create one User and its Member transactionally. Choose the initial display name from configured Keycloak name claims, falling back to username. Re-import does not overwrite an existing shared User's name.
- An optional explicit map associates a provider subject with an existing local `user_id`; validate both sides. No automatic email/name merge is performed.
- Import does not copy passwords and does not grant API tokens. The login callback remains responsible for verifying the provider and the imported membership.
- Do not delete local users or memberships just because one import page fails or an account disappears remotely. Upstream validity checks prevent disabled/revoked identities from authorizing API access.

## 6. Login flow and API contract

1. Clicking Login opens a Vuetify dialog listing active sources. Loading/error/empty states are explicit. Selecting a source starts the redirect flow; the selection popup is not an extra browser window.
2. Nuxt initiates login through the API and stores a short-lived HttpOnly transaction-binding cookie. Laravel creates a five-minute transaction in Redis with source/config fingerprint, random state, nonce, PKCE verifier and fixed callback target. This is a temporary protocol transaction, not a Laravel session.
3. The browser visits the selected provider through its approved gateway URL. Credentials are entered only at Keycloak.
4. Keycloak returns to Nuxt `/login/callback/{source}` on port 8081. Nuxt forwards code/state and the browser-binding proof internally to Laravel. Consume the matching transaction atomically once; reject expired, replayed, cross-source or mismatched callbacks.
5. Laravel exchanges the code with that source, validates the identity, and finds the existing Member using the verified subject and selected source. If absent, return a clear access-denied result; do not auto-create User/Member here.
6. Issue the finite Sanctum token for the Member's User. Return the credential only to the Nuxt server, which sets its protected credential cookie, removes the temporary transaction cookie and redirects to the application.

| Laravel API route | Contract |
| --- | --- |
| `GET /api/login/sources` | Public, rate-limited active source slugs/titles only |
| `POST /api/login/sources/{source}/authorize` | Start a bound OIDC transaction and return the source authorization URL/transaction handoff |
| `POST /api/login/sources/{source}/callback` | Validate one callback and imported membership; server-to-server credential response |
| `GET /api/login/me` | Bearer protected; sanitized local User and current source/membership display data |
| `POST /api/login/logout` | Bearer protected; revoke local grant and create the bounded RP logout handoff |
| `POST /api/login/sources/{source}/backchannel-logout` | Signed OIDC logout notification; no browser cookie/session required |

Use API Resources and validation requests within the module. Define consistent machine-readable errors; use 401 for invalid credentials, 403 for an unimported identity, 422 for bad input, 429 for throttling, and 503 for an unavailable provider. Never return provider secrets in errors.

## 7. Bidirectional logout and current validity

### Keycloak to application

- Register a reachable backchannel logout URL per source/client. Docker providers use the private backend route; externally operated providers require an externally reachable approved URL.
- Validate logout-token signature, exact issuer, expected audience, issue time, event claim, absence of nonce and `sid`/`sub`; protect against replay using `jti`.
- Revoke matching application grants for the same source and provider session. If the valid event identifies only a subject, revoke that subject's grants within that source. Never revoke an unrelated source because its subject/session string happens to match.
- Validate the upstream grant on every protected API request and SSR `/me` without a positive validity cache in this revision. Refresh when appropriate; reject invalid grants. An unavailable provider fails closed with 503. This also covers missed logout delivery.
- Already open pages recheck authentication while visible every 15 seconds and on focus; 401 clears displayed authentication. Server access is revoked on the valid event/next validity check; idle-page visual feedback is bounded by the polling interval, not claimed to be instantaneous.

### Application to Keycloak

- Revoke the local token immediately and complete standards-based RP-Initiated Logout for its originating Keycloak session. Preserve the minimal encrypted logout handoff briefly while removing the local grant.
- Use the provider logout endpoint and a validated return to port 8081. Prefer a POST handoff to avoid putting ID-token hints in URL history. Any ID-token hint is only a short-lived protocol form value; access/refresh/Sanctum tokens never enter browser JavaScript or URLs.
- Make retries/idempotent provider notifications safe. A failed remote logout must not restore local access or be reported as successful remote logout.
- Logout covers the originating provider session and grants bound to it. It is not an unrequested global logout from every independent source/device linked to the local User.

## 8. Multi-provider development and gateway

- Keep the existing provider under `/auth` and preserve its management access on 8083 and Camunda's integration.
- Add a second independent development Keycloak instance for acceptance testing, with a separate PostgreSQL database/role and persistent data. Use the same pinned Keycloak version. This proves different providers, not merely two clients in one realm.
- Propose the second provider's public base at `http://localhost:8081/identity/secondary`, with path/hostname/proxy settings configured consistently. Route its admin console under a distinct path on existing management port 8083; do not publish a fifth host port.
- Keep Nuxt callbacks under `/login/*` and browser gateway calls under `/gateway/login/*` so they do not collide with Keycloak's `/auth/*` routes or Laravel's `/api/*` routes.
- Bootstrap the added database/role idempotently on both existing and fresh PostgreSQL volumes. Do not reset existing databases or replace the primary Keycloak realm.
- Configured providers in this phase must support the approved public gateway URLs. Do not silently rewrite an independently operated provider's issuer if it cannot be configured this way; resolve that integration constraint in the plan first.
- Verify SSR, refresh, callbacks and logout under the same application origin. Recheck existing pgAdmin/Keycloak/Camunda management entry points after routing changes.

## 9. Ordered implementation steps

The revised plan is approved; implementation may proceed. Report **step N of 9 completed** and commit each plan update. Preserve separate backend/frontend/shared-infrastructure commits.

1. [x] **Establish the Login module and token-only API foundation.** Add compatible Sanctum, register the module, move routes to API middleware and remove application session authentication without leaving a fallback.
2. [x] **Implement the schema and source configuration.** Add UserSource/Member/User and extended Sanctum migrations, relationships, secret handling, source validation and factories; verify migration/rollback on disposable data.
3. [x] **Register sources and import memberships.** Implement validated source/import commands, pagination, repeat-safe imports, dry-run and explicit identity mapping. Bootstrap/import the first development source.
4. [x] **Implement source-bound login and token lifecycle.** Build PKCE/state/nonce callbacks, imported-member authorization, token issuing/expiry, refresh locking and source/config invalidation.
5. [x] **Implement logout and upstream verification.** Add verified backchannel handling, replay protection, per-request upstream validity checks, RP logout and failure semantics.
6. [x] **Implement Nuxt SSR and source selection.** Add credential transport/gateway, awaited SSR identity, dialog, logout handoff, visible-page/focus rechecks and appropriate unavailable states. Preserve local assets/RTL/themes and external CSS rules.
7. [x] **Validate two independent providers in Docker.** Add the second provider/database and same-origin routing, register/import both sources and verify cross-source identity isolation while preserving management services.
8. [x] **Complete integrated acceptance and hand off for review.** Run the matrix below, fix failures, update the plan and present the working revision. Await explicit phase-completion approval and apply review adjustments.
9. [ ] **Close the approved phase.** Only after approval, write English database/business/API documentation and Persian operational guides, update Graphify using its verified accessible workflow, and finish separately scoped commits.

## 10. Acceptance matrix

- Protected Laravel endpoints accept a valid Sanctum Bearer, reject missing/expired/revoked credentials, and cannot authenticate through a Laravel session cookie. No StartSession/session-based Sanctum SPA middleware is used by application API routes.
- The source-selection dialog lists only active sources and never exposes provider configuration or secrets. A disabled source rejects both new login and existing API access.
- An enabled imported identity logs in; an equally valid unimported Keycloak identity receives 403 and creates no User/Member/token.
- Re-import across multiple pages creates no duplicates; a dry-run changes nothing. Identical subjects in two providers remain distinct. Explicit mappings allow two Members to share one User without automatic merging.
- Code/state/nonce/PKCE validation, expiry, replay, wrong source/issuer/audience/signature and changed configuration fail safely.
- The initial SSR HTML already has the correct user and login/logout control, including after a reload. Test with JavaScript disabled and concurrent different-user SSR requests. Authenticated responses/payloads never leak credentials or another user's data.
- Signed provider logout invalidates the correct grants; forged/replayed/wrong-source events cannot revoke others. Missed notifications are caught by upstream verification. Provider outages yield 503 without silently accepting stale credentials or unnecessarily destroying valid local credentials.
- Expired access tokens refresh safely once under concurrency. Invalid/revoked refresh grants revoke application access. Local token expiry cannot be extended without an approved lifecycle action.
- Application logout clears local access and actually terminates the originating Keycloak SSO login. A visible idle tab reflects remote logout within 15 seconds; focus/request/SSR checks also detect it.
- Browser transport remains HttpOnly; mutating Nuxt gateway routes enforce Origin/CSRF checks. No Sanctum Bearer, Keycloak access/refresh token or provider secret is present in localStorage, URLs, logs or SSR payloads.
- Two distinct Keycloak instances work through 8081; pgAdmin 8082, Keycloak admin 8083 and Camunda 8084 remain functional. Only the original four host ports are published.
- Fresh bootstrap, upgrade of existing development data, ordinary restart persistence, focused backend tests, frontend type checking/build and real browser checks pass. Do not delete existing volumes to make upgrades pass.

## 11. Review decisions and closeout dependencies

- Proposed default linking policy: explicit mapping only. The supplied model has no shared verified identifier that could safely deduplicate one person across different providers.
- Proposed browser transport: Nuxt's protected credential cookie with Bearer forwarding. This is necessary to let SSR receive browser credentials without a Laravel session; no browser-readable token store is used.
- Proposed logout display bound: 15-second visible-page recheck, plus immediate server revocation on a valid backchannel event and checks on requests/focus. A push-based instantaneous display update is not included.
- Graphify remains the user-confirmed `https://www.graphify.ai/`. No update is claimed; authenticated access and its supported update workflow are still required for closeout.
- Revised-plan approval: **received on 2026-11-03**. The user subsequently approved the review correction to numeric Member source relationships; public URLs and commands remain slug-based. Phase-completion approval: **pending**.

## 12. Primary references

- [Laravel 13 Sanctum: API tokens, expiry, revocation and custom token models](https://github.com/laravel/docs/blob/13.x/sanctum.md)
- [Keycloak OIDC endpoints and introspection](https://www.keycloak.org/securing-apps/oidc-layers)
- [OpenID Connect Back-Channel Logout](https://openid.net/specs/openid-connect-backchannel-1_0.html)
- [OpenID Connect RP-Initiated Logout](https://openid.net/specs/openid-connect-rpinitiated-1_0.html)

## Implementation evidence

### Step 1

- Approved slug-based revision recorded in `94522aa`. Installed compatible Sanctum 4.3.3 with a committed lockfile.
- Registered the Login module provider and API-only routes. Removed the old session-based controller/service and `/api/auth/session` route. Sanctum has no session-guard fallback or stateful SPA configuration.
- Focused foundation test checks the route middleware, missing/old-cookie credential rejection and removal of the old session endpoint. Application session variables were removed from Compose and the backend environment example.

### Step 2

- Added the three requested domain models and the extended Sanctum token table. UserSource.slug is unique/immutable; Member's foreign key and uniqueness constraint use user_source_slug. Source JSONB secret values and token grant data are encrypted; public serialization hides configuration and internal source IDs.
- Added factories and schema tests. Five foundation/schema tests passed (18 assertions), including same subject in separate sources, foreign-key relationships, encrypted settings and immutable slugs.
- Tests use a separate organization_test database and refuse non-test database names. Additive application migration was applied without resetting existing service data; rollback/re-apply is checked only against disposable test data.

### Step 3

- Added validated slug-based source registration and a paginated, bounded-retry import using a dedicated view-users/query-users service account. Import supports dry-run and explicit mappings without automatic identity merging.
- Registered/imported the existing primary development source without resetting Keycloak or Camunda. Repeat import reported zero new memberships. Two focused import tests passed (9 assertions).

### Step 4

- Implemented source/config/browser-bound five-minute PKCE transactions, verified RSA identity claims, single-use callbacks and pre-imported-member checks. Sanctum credentials are hashed with finite expiry and encrypted upstream grants.
- Added serialized refresh and configuration invalidation. Callback, wrong source/binding/nonce, replay and unknown member checks pass in the isolated integration suite.

### Step 5

- Added signature/issuer/audience/event/time/replay-validated backchannel logout, scoped by source and provider session/subject. Local logout revokes immediately and creates an encrypted, bound, short-lived single-use RP POST handoff.
- Every protected identity request introspects the current upstream grant. Provider outage returns 503 while retaining the credential; inactive grants and invalid refresh return 401 and revoke it. All 14 backend tests pass (93 assertions). Real provider/browser logout checks follow in steps 7–8.

### Step 6

- Added awaited, request-scoped Nuxt SSR identity resolution, encrypted HttpOnly credential transport, internal Bearer forwarding, Origin/CSRF validation, source dialog and visible/focus rechecks. A single-use provider logout POST keeps ID-token hints out of URLs.
- Nuxt type checking/build passed. Real login checks confirmed correct initial authenticated HTML, no credential serialization and source isolation across 12 concurrent SSR requests. Provider audience/basic-scope settings were reconciled after real integration exposed missing access-token subject/audience claims.

### Step 7

- Added a separate Keycloak instance, PostgreSQL role/database and public /identity/secondary routing, with its admin console sharing port 8083 under the separate path. Existing databases were preserved; the additional database initializer is idempotent.
- Both sources registered/imported. Real acceptance checks passed for source isolation, concurrent SSR, CSRF/Origin rejection, application-to-provider POST logout and provider-to-application signed backchannel revocation before the next /me request. Expanded backend suite: 17 tests, 120 assertions passed.

### Step 8 — review handoff (2026-11-04)

- Backend regression suite: **17 tests, 120 assertions passed**, using isolated PostgreSQL test data. Covers bearer-only middleware, schema/slug constraints, imports/dry-run/explicit mapping, callback binding/replay/claims/signature/expiry, refresh, source invalidation and signed logout scope/replay.
- Nuxt type checking and production build passed after the final SSR request-context fix. Real Chrome verified authenticated initial HTML with JavaScript disabled, upstream logout in an open tab, automatic light/dark changes and the mobile RTL source dialog. Screenshots are local ignored validation artifacts.
- Real two-provider acceptance passed: Origin/CSRF rejection, distinct local identities, 12 concurrent SSR requests without cross-user/credential leakage, application POST logout of only the originating provider session and signed backchannel revocation before the next request.
- Three independent PHP workers verified the same real grant concurrently and sent **exactly one** refresh request. A real provider outage produced unavailable SSR/503 without clearing the credential; the valid SSO grant survived provider restart and logout still worked afterward.
- A completely fresh temporary Compose project passed bootstrap, both imports, the regression suite, gateway/management/database isolation checks and actual Camunda process deployment/completion. Only that temporary project's volumes were removed. The original development project and its existing persistent data were restored; its source logins and recovery checks passed afterward.
- Both public identity namespaces stay on 8081. Management entry points 8082, both admin paths on 8083, and 8084 passed. No additional host ports are published.
- Scoped implementation/test commits are recorded separately for backend, frontend and shared infrastructure. No phase documentation has been created before completion approval. User-owned untracked IDE settings were preserved.
- **Review gate:** the user may now inspect the implementation and request revisions. Step 9 is deliberately pending explicit phase-completion approval. Graphify has not been updated; authenticated access and its supported workflow remain closeout dependencies.

## Current review revision — Member source foreign key

The user's review instruction explicitly authorizes this correction. This supersedes the earlier slug-based Member relationship design; historical implementation evidence above describes the original implementation.

- Subject/scope: replace `members.user_source_slug` with `members.user_source_id`, referencing `user_sources.id`, and enforce UNIQUE (`uuid`, `user_source_id`). Keep UserSource.slug and every public source URL/command/callback identifier unchanged.
- Method: add a transactional PostgreSQL migration after the existing applied migration. Backfill each numeric ID by joining the existing slug, enforce NOT NULL and a restrictive foreign key, add the requested composite uniqueness and source lookup index, then remove the old slug column/constraints. The reverse migration restores slugs from source IDs. Never reset existing application or provider data.
- Preserve Member/User primary keys, identity associations and existing Sanctum token relationships. Update model relations/factories, imports, callbacks, source invalidation, logout queries and acceptance scripts to use the numeric relationship internally.
- Verification: focused PostgreSQL schema/data migration round-trip and duplicate/orphan constraints; existing Login regression suite; apply the upgrade to current development data and verify repeat import and real two-provider login/logout. Frontend API contracts remain unchanged, so no frontend implementation is needed.
- Acceptance: members contain numeric source IDs and no stored source slug; duplicate (`uuid`, `user_source_id`) is rejected while the same uuid across different sources remains allowed; existing data and login/logout behavior are preserved.
- Progress: **1 of 3 review-revision steps completed**. Overall phase closeout remains pending; this request is not approval of phase completion.

1. [x] Migrate Member storage and update internal relationships/queries/factories/scripts.
2. [ ] Verify migration round-trip, integrity constraints and Login regression behavior; upgrade existing development data.
3. [ ] Verify real provider flows, commit the scoped revision and return to the phase-review gate.
