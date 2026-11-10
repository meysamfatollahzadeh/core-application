# Phase 2 — Organization, Personnel and Access

Completion was explicitly approved in chat on 2026-11-08, including the reviewed administration UI, persistent appearance, hierarchy labels and modal behavior. This document extends the historical Phase 1 architecture/database documents. API details are in [management-api.md](management-api.md); operational instructions are in the [Persian guide](../guides/organization.fa.md).

> Phase 3 adds local personnel creation, independent account-connection permissions, per-source membership limits and selective token revocation. Its [approved technical specification](personnel-account-connections.md) supersedes the corresponding Phase 2 baseline behavior below.

## Module boundaries and unchanged infrastructure

Organization owns companies, position charts, buildings/areas and position-path resolution. Personnel owns current profile/location editing, user-position assignments and reporting targets. Access owns shared roles, the application permission catalog, delegation and effective authorization. Each module keeps its controllers, requests, actions, models, migrations, providers, commands and tests together under `backend/app/Modules/`.

Login remains the canonical owner of User, Member and UserSource. Keycloak still owns credentials and identity lifecycle; there is no application registration, password management or automatic identity merging. Member continues to reference numeric user_source_id, with public sources identified by slug. All protected business requests require Sanctum Bearer authentication and current provider-grant verification.

There are **no infrastructure, dependency-version, public-port or Camunda-process changes** in this phase. The single public application origin remains Nginx port 8081. Provider management ports are not application login endpoints. Laravel remains bearer-only; Nuxt exchanges its protected credential cookie for Bearer only on the server. No Laravel browser session is introduced.

## Database changes

All domain IDs are bigint. Entity/first-class assignment tables have ordinary timestamps; pure access pivots use composite keys. Titles are not unique identifiers.

| Table | Fields and integrity |
| --- | --- |
| companies | id, title |
| positions | id, company_id, nullable parent_position_id, title; indexes on company/parent; UNIQUE(id, company_id); composite parent/company FK; self-parent CHECK |
| buildings | id, title |
| building_areas | id, building_id, title, is_default; partial unique default per building |
| users | existing identity-backed record; required building_area_id FK; performances JSONB NOT NULL default `{"theme":"auto"}` |
| user_positions | id, user_id, position_id; UNIQUE(user_id, position_id); reverse position index |
| user_position_reports | id, user_position_id, report_to_position_id; unique assignment/target; reverse target index |
| roles | id, unique stable key, title |
| permissions | id, unique stable key, title; version-controlled catalog |
| role_permission | composite PK(role_id, permission_id), reverse permission index |
| user_role | composite PK(user_id, role_id); global assignments |
| company_user_role | composite PK(company_id, user_id, role_id); user/company and role indexes |
| position_role | composite PK(position_id, role_id, scope); scope CHECK company/global; company scope derives from the owning position |

Foreign keys restrict referenced domain deletion. Removing a UserPosition cascades its own reporting rows only. There is no separate company-membership or company-building table, no duplicated user building_id and no historical assignment/location table.

Organization migrations create the organization tables and nullable user location first. Personnel/Access migrations add their dependent tables; Organization invariants use deferred PostgreSQL constraint triggers to validate the final transaction state. The separate Organization `ContractMigrations` directory enforces mandatory location only after explicit preparation. Login's additive `2026_11_08_000006_add_user_performances.php` adds the appearance JSONB default and shape/value constraint. User performances must be an object with string theme auto/light/dark; unrelated keys are allowed.

Existing users were explicitly assigned the default area of building «توسعه» before the location contract was applied. No identity, Member or token mapping was replaced. Preparation/import commands are idempotent; fresh bootstrap creates an explicit development location. Rollback/re-upgrade rehearsals used isolated test data; rolling back phase migrations on live data removes domain information and is not an upgrade procedure.

## Organization and personnel behavior

- Each position belongs to one immutable company and has at most one chart parent in that company. Multiple roots are allowed. Self-parenting and chart cycles are forbidden. Transactional locking and deferred checks cover concurrent edits.
- One user may hold several positions in several companies; one position may have several occupants. Company associations derive from positions and direct company roles; global roles alone do not create membership everywhere.
- Reporting targets belong to a specific user-position assignment and refer to positions, not individual managers. Nonroot assignments require at least one target; root assignments may have none. Targets can be in other companies and need not equal the chart parent or have occupants. The assigned position itself is forbidden as its own target; another position held by the same person and reciprocal/cyclic reporting are allowed.
- Reparenting an occupied root must satisfy required-report invariants; the service does not invent reporting targets. Referenced positions, buildings, areas, companies and roles cannot be silently deleted.
- Every user has one current area, independent of company. Building creation and its protected default «نامشخص» area are atomic. Existing users are never silently relocated on deletion.
- Position labels are resolved in batched recursive queries as company / root / ... / position, independently of page/search results. Reporting labels include the target company's chart. Display paths do not replace IDs or editable leaf titles and do not confer access.

## Authorization and delegation

Effective grants are the union of global user roles, direct company roles and roles on the exact positions currently held. A global grant satisfies the same permission in any company; a company grant cannot satisfy a global endpoint or another company's operation. Descendants and reporting relationships inherit nothing. There are no deny rules.

The catalog contains view/create/update/delete for companies, positions, buildings, roles and user_positions, plus personnel.view, personnel.update, roles.permissions.update, permissions.view and access.assign. Global shared-profile/location edits require global personnel.update. Shared company/building/role/catalog routes require global authority. Company-scoped operations check the requested company and nested object ownership.

Full-set replacements are transactional. Delegation requires access.assign and every permission carried by each assigned role in the target scope. Assigning someone to a position also checks the authority that its roles would confer. Company-only operators cannot inspect or replace global position grants; their replacements preserve those grants. Mutations cannot remove the last effective global access administrator once one exists. The protected administrator is defined by the required effective global permission set, not just a role title.

EffectivePermissions is request-scoped, with no cross-request positive authorization cache. Revocation/position/role edits affect subsequent requests without token reissue. Login validity checks remain independent of these permission calculations.

## Browser administration and appearance

Nuxt resolves identity and effective-access state before initial HTML. Shared predicates drive direct-route guards, two-level navigation and grouped square dashboard links; unauthorized children and empty parents are omitted. Direct denied SSR routes return the dedicated Persian page with HTTP 403; denied client navigation redirects to /403. The API independently enforces permissions. Anonymous users retain the login flow; unavailable identity/access is represented as unavailable rather than authorized.

The workspace uses the desktop width, a right-side mobile drawer, separate navigation/content surfaces, local icons/Vazirmatn and semantic operation colors. The header account menu shows the authenticated name/source and provides connected theme buttons and confirmed synchronized logout. Access dialogs display the actual user name/full position path. Position/assignment tables and create/edit selectors show complete ancestry and wrap long selections. Position forms preview the complete resulting path while editing only the leaf title.

Authenticated theme selection updates only that user's performances.theme atomically, preserving other JSON keys. SSR initializes Vuetify after identity resolution; stored dark wins over stale light browser hints and survives hydration without a light-first interval. Auto tracks live device changes and uses validated client hints/last-known device cookies for SSR. On a first-ever request without a device hint, the server cannot infer the device scheme and uses a deterministic light fallback. Presentation cookies contain no credentials. Persistence failures show an error and restore the prior choice.

All dialogs ignore overlay clicks. Escape closes the topmost idle dialog; when an autocomplete menu is open, its first Escape closes that menu and the next closes the dialog. During submission/authorization handoff, busy guards prevent accidental closure. Explicit cancel/close and successful actions retain their existing behavior. Logout confirmation cancellation sends no logout request.

## Validation and limits

The final backend regression run passed **52 tests / 332 assertions** on PostgreSQL, including migration preservation, chart/reporting constraints, concurrency, delegation, scope, paths and theme persistence. Seven frontend navigation/appearance tests, Nuxt typecheck and production build passed. The existing nonfatal vue-router/volar typecheck warning remains. Browser review covered SSR/403, responsive dashboard/drawer, theme first render/refresh/later login, hierarchy list/create/edit and role subjects, and final overlay/Escape behavior. Earlier isolated fresh-environment and OIDC/management smoke results remain recorded in PLAN.md.

No production deployment, process workflow, historical HR tracking, identity lifecycle UI or article CRUD is introduced. Article paths discussed in design are test-only permission-contract examples, not production endpoints. Final integration verification is recorded in PLAN.md and [validation.md](validation.md).
