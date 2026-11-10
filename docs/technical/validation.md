# Verification evidence

## Phase 3 approved closeout — 2026-11-10

The user explicitly approved phase completion after reviewing creation, account connections and the active-source indicator, and separately authorized documentation, integration and push. Final pre-integration PostgreSQL suite: **76 tests / 491 assertions** passed. All **10 frontend tests**, Nuxt typecheck and a fresh production build passed. Closeout synchronization evidence is recorded in PLAN.md. Existing nonfatal Volar and build-timing warnings remain.

Coverage includes independent global permissions and creator location access, missing/inactive/mixed connection indicators with company boundaries, additive uniqueness migration and non-destructive conflict refusal, directory sanitization, remote eligibility/configuration changes, selective revocation, last-member deletion, rollback on deletion failure, import/dry-run conflicts and real multi-process attach/callback-removal races. Login callback still rejects unknown Members; reconnecting cannot revive deleted tokens.

Read-only checks against the pinned running Keycloak verified username, first name, last name, email and exact-account lookup. Real Chrome/gateway checks covered creation, attach, occupied ownership, same-source search lockout, inactive-source last-member removal, provider failure messages, surviving other-Member authentication, self-detach with immediate anonymous SSR, mobile RTL, focus, light/dark/automatic appearance and dialog behavior. Disabled-account presentation and indicator-only fixtures used intercepted browser responses; backend tests separately verified their eligibility/database semantics. No actual Keycloak identity was created, changed or deleted. Temporary local fixtures and test credentials were cleaned up and test browsers closed.

Reproduce ordinary non-disruptive checks from the repository root:

```bash
docker compose exec -T backend php artisan test --compact
docker compose exec -T frontend node --experimental-strip-types --test tests/admin-navigation.test.ts tests/appearance.test.ts tests/management-errors.test.ts
docker compose exec -T frontend npm run typecheck
docker compose exec -T frontend npm run build
docker compose restart frontend
```

Backend tests enforce a separate PostgreSQL database ending in `_test`. Restarting only frontend after the production build restores the development server's generated files. No infrastructure, dependency or application changes are part of documentation closeout. Post-rebase results and actual merge/push/branch deletion outcomes are recorded in the current [plan](../../PLAN.md) and the chat closeout. Current schema/business/API behavior is described in [Phase 3](personnel-account-connections.md).

## Phase 2 approved closeout — 2026-11-08

The user explicitly approved the implementation and review changes. Latest implementation verification: PostgreSQL backend **52 tests / 332 assertions**, seven frontend tests, Nuxt typecheck and production build passed. The existing nonfatal vue-router/volar warning remains. Real browser checks covered SSR/403 and scoped menus, grouped dashboard, mobile drawer/focus, persistent theme without stored-dark light flash, full hierarchy labels in lists/forms, actual role-subject names, logout confirmation, overlay protection and Escape dismissal including nested menus.

Isolated fresh-environment/management OIDC acceptance, location backfill and rollback/re-upgrade/concurrency evidence are recorded in PLAN.md. Review tests did not reset active data or reassign operator roles. No infrastructure/dependency versions changed. Final closeout and post-synchronization validation results are recorded in PLAN.md.

Commands: backend `php artisan test --compact`; frontend `npm run typecheck`, `node --experimental-strip-types --test tests/admin-navigation.test.ts tests/appearance.test.ts`, and `npm run build`, run through Docker Compose. `scripts/smoke-management.py` deliberately refuses the active development project; destructive/outage acceptance is for isolated environments.

## Historical Phase 1 evidence

## Reviewed implementation

The user approved the reviewed phase after the numeric Member source relationship correction. Evidence below reports checks actually completed during implementation/review; writing these documents does not rerun a previous test.

| Check | Observed result |
| --- | --- |
| Laravel regression suite after numeric FK correction | **20 tests passed, 137 assertions** against `organization_test` |
| Source schema/integrity | Numeric required source FK, requested composite uniqueness, same subject across sources, immutable public slug, encrypted secrets |
| Populated migration round-trip | Restored old slug relationship and reapplied numeric FK while preserving User/Member/token associations on disposable test data |
| Existing development upgrade | Applied additive migration; private before/after association snapshots matched; both repeat imports reported zero new members |
| OIDC negative cases | Wrong binding/source/nonce/issuer/audience/signature, expiry, callback replay and unknown member denied |
| Grant lifecycle | Expired/revoked credentials, disabled/changed source, safe refresh, inactive upstream grants and provider outages covered |
| Real two-provider flows after FK correction | Login through both independent Keycloaks, source isolation, CSRF/Origin rejection, 12 concurrent authenticated SSR requests and both logout directions passed |
| Concurrent real refresh | Three independent PHP workers sent exactly one upstream refresh request |
| Real Keycloak backchannel | This run's session grants removed before the next `/me`; old expired grants belonging to other sessions were excluded from the assertion |
| Nuxt typecheck/build | Passed after the final SSR request-context fix; public bundle inspection found no server credential/crypto configuration |
| Real Chrome | Correct authenticated initial HTML with JavaScript disabled; open-tab upstream logout detected; automatic device theme changes and mobile RTL source dialog verified |
| Real provider outage/restart | Unavailable SSR/503 without anonymous flash or credential loss; valid SSO grant survived restart and logout still worked |
| Fresh temporary Compose project | Bootstrap, migrations, source registration/import, regression tests, management routes, role/database isolation and real Camunda deployment/completion passed |
| Persistence/restoration | Only temporary fresh-test volumes removed; original development project restored with its data and healthy services |
| Public network surface | Only Nginx loopback host ports 8081–8084; both identity namespaces on 8081 and both admin paths on 8083 |

Screenshots and temporary runtime artifacts are ignored local validation output, not published credentials or committed project documents. Existing expired token records are not automatically purged by this phase; every protected request still enforces credential expiry/validity.

## Reproduction

From the repository root, `./scripts/dev test` prepares the isolated test database, runs Laravel tests, infrastructure smoke checks, real multi-provider login/refresh/logout checks and a disposable Camunda process check. These are development acceptance tests: they use the configured demo accounts, terminate their provider sessions and may deploy a disposable process definition before removing it.

```bash
./scripts/dev test
docker compose exec -T frontend npm run typecheck
docker compose exec -T frontend npm run build
```

Focused backend verification:

```bash
docker compose exec -T backend php artisan test --compact --filter=SourceSchemaTest
```

Optional disruptive development checks are explicit standalone commands, not part of ordinary startup:

```bash
python3 scripts/smoke-provider-recovery.py
./scripts/check-fresh-development.sh
```

The first briefly stops/restarts the primary provider using a `finally` restoration path. The second stops the original development project, starts a separate empty project on the same four host ports, tests it, removes **only its temporary volumes**, then restores the original project through an exit trap. It refuses to replace an existing temporary acceptance project. Do not run these against another team's active environment.

No application code changed during documentation closeout. Documentation validation and approved phase completion are recorded in the current plan. Database/business/API effects are described in [database](database.md), [architecture](architecture.md) and [API](login-api.md).
