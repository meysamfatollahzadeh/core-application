# Current Plan — Phase 1: Monorepo and Development Infrastructure

## Status and approval gate

- Status: **Approved — implementation in progress.**
- Planning date: 2026-11-02.
- Implementation progress: **4 of 8 steps completed.**
- The user explicitly approved phase 1 and authorized implementation on 2026-11-02. Application scaffolding, Docker configuration, dependency installation, and development service startup are now authorized within this plan.
- Completion requires a separate review and explicit approval from the user after implementation. Documentation and graph closeout follow that approval.
- This file is the only current plan. Update and commit it whenever its content or progress changes.

## 1. Subject and intended outcome

Build the initial monorepo and a reproducible Docker Compose development environment for Laravel, Nuxt/Vuetify, PostgreSQL, Redis, Keycloak, Camunda, pgAdmin, and Nginx.

The application opens at `http://localhost:8081`. Its frontend, backend API, and identity flow share this origin. Separate management entry points exist on ports 8082–8084. All inter-service communication uses Docker networking, not host-published service ports.

## 2. Scope

### Included

- Laravel and Nuxt skeletons, Dockerfiles, Compose configuration, reverse proxy, environment templates, lockfiles, and development commands.
- PostgreSQL databases and service roles, Redis cache configuration, persistent volumes, health checks, and repeatable initialization.
- Keycloak development realm and clients; a minimal login/logout verification path through port 8081. This verifies infrastructure and does not introduce business authorization features.
- Camunda runtime and management UI, persistence, Keycloak integration, and an ephemeral process deployment/execution smoke check.
- A minimal Persian RTL Vuetify landing page with local Vazirmatn, local assets, and light/dark/system theme selection.
- Automated infrastructure smoke checks and browser verification where appropriate.

### Excluded

- Business modules, organizational database entities, production deployment, Kubernetes, production TLS, and production availability architecture.
- Full user administration screens, business roles and permissions, user synchronization, custom Keycloak themes, and business BPMN processes.
- Camunda Optimize, Web Modeler, and other optional products unless explicitly added to this plan.

## 3. Proposed monorepo layout

```text
AGENTS.md
PLAN.md
compose.yaml
.env.example
.gitignore
backend/                      Laravel application and its lockfile
frontend/                     Nuxt/Vuetify application and its lockfile
  assets/styles/              Shared CSS and theme styles
  public/fonts/               Locally stored Vazirmatn files and license
  public/icons/               Local icons, where needed
  public/images/              Local images, where needed
infra/
  docker/backend/             Development PHP image and entrypoint
  docker/frontend/            Development Node image and entrypoint
  nginx/                     Gateway and management listeners
  postgres/                  Database/role initialization
  keycloak/                  Realm/client bootstrap templates
  camunda/                   Runtime and identity configuration
  pgadmin/                   Local server registration
scripts/                     Bootstrap and smoke-check commands
docs/                        Added after phase completion approval
```

The user confirmed Graphify at `https://www.graphify.ai/`. Record the supported graph-update workflow and any export location after verifying its capabilities and account access; do not assume that a local CLI or repository graph export exists. Generated dependency directories, local secrets, database files, and runtime volumes are not committed.

## 4. Implementation method

### Runtime selection

- Select compatible, maintained Laravel/PHP and Nuxt/Node/Vuetify releases from official documentation during step 1; record exact versions before scaffolding.
- Proposed process engine: **Camunda 8.9 Self-Managed**, minimal Orchestration Cluster (Zeebe, Operate, Tasklist and administration), with PostgreSQL secondary storage and persistent engine state. Do not substitute Camunda 7 without a revised approved plan.
- Integrate Camunda directly with the project's Keycloak where supported by the pinned release; do not start an unrelated second identity provider.
- Use exact image versions and committed Composer/npm lockfiles; no floating `latest` tags. Verify the selected Camunda release's development terms and PostgreSQL compatibility.
- Do not switch to H2, SQLite, Elasticsearch, or an additional infrastructure component to bypass a compatibility issue. Report the issue and revise the plan before changing architecture.

### Containers and networking

- Services: `nginx`, `backend`, `frontend`, `postgres`, `redis`, `keycloak`, `camunda`, and `pgadmin`. Add a one-shot bootstrap/migration service if needed for deterministic startup.
- Use a private application network with service DNS names. Only Nginx publishes host ports; it proxies all four entry points to the relevant containers. Bind development entry points to loopback by default.
- Laravel runs `php artisan serve --host=0.0.0.0 --port=8000`.
- Nuxt runs `npm run dev`, configured to listen on `0.0.0.0:3000`; forward WebSocket upgrades through Nginx for HMR.
- Mount source code for editing and isolate container dependency directories from host dependencies. Enable polling only when the host requires it.
- Start services based on actual readiness, use bounded retries for dependency startup, and persist PostgreSQL, Redis where configured, Keycloak data in PostgreSQL, Camunda engine state, and pgAdmin configuration.
- Provide one documented bootstrap/start command, safe stop/restart commands, and an explicitly destructive reset command. Normal restarts must preserve data.

### Gateway and public routes

| Host entry point | Destination and behavior |
| --- | --- |
| `http://localhost:8081/` | Nuxt frontend on internal port 3000 |
| `http://localhost:8081/api/*` | Laravel API on internal port 8000; preserve Laravel's API prefix |
| `http://localhost:8081/auth/*` | Keycloak public realm/login endpoints under `/auth` |
| `http://localhost:8081/api/auth/*` | Minimal application authentication routes handled by Laravel |
| `http://localhost:8082/` | pgAdmin management |
| `http://localhost:8083/` | Keycloak administration, with path-aware redirects to its admin console |
| `http://localhost:8084/` | Camunda management entry, landing on Operate; Tasklist/admin also reachable here |

The more-specific application auth routes take precedence over generic routing. Application browser requests must never use Docker hostnames or ports 8000, 3000, 5432, 6379, 8083, or 8084.

### Identity and origin handling

- Canonical public Keycloak issuer: `http://localhost:8081/auth/realms/organization-dev`.
- Configure Keycloak's proxy headers, relative path, and separate administration URL consistently. Nginx overwrites forwarded headers; test actual generated redirects, discovery metadata, cookies, and asset URLs.
- Use a Laravel confidential OIDC client with Authorization Code flow and PKCE, state and nonce validation, server-side token storage, and an HttpOnly application session cookie. Keep secrets out of Nuxt public configuration and browser storage.
- Proposed callback: `http://localhost:8081/api/auth/callback`. Register exact redirect/logout URIs and allowed origins; avoid wildcard redirects.
- Keep login and logout redirects on port 8081. The management URL on 8083 must not appear in the application login flow.
- Preserve one issuer identity for browser and server validation. Configure supported internal backchannel access or explicit internal routing to the canonical issuer; never treat container `localhost` as the host or disable issuer validation.
- SSR uses internal backend URLs; browser API requests use relative `/api` URLs. Forward cookies deliberately for SSR.
- Give Camunda its own Keycloak client and audience. Its management UI uses 8084, while its identity redirects use the same Keycloak gateway on 8081. Backend-to-Camunda calls stay internal.
- The application shell remains on 8081; users opening the explicitly requested management consoles may use their respective management ports.

## 5. Database design

Use one PostgreSQL development instance with isolated logical databases and non-superuser service roles:

| Database | Owner role | Purpose / schema owner |
| --- | --- | --- |
| `organization_app` | `organization_app` | Laravel migrations and framework metadata |
| `keycloak` | `keycloak` | Keycloak-managed identity schema |
| `camunda` | `camunda` | Camunda-managed PostgreSQL secondary storage |

- Bootstrap creates roles/databases idempotently where practical. Service roles cannot access other service databases. A separate bootstrap administrator is not used as an application runtime role.
- Use PostgreSQL UTF-8 encoding and consistent UTC timestamps. Localization belongs at presentation boundaries.
- No business tables or business relationships are introduced in phase 1.
- Laravel initially requires only its migration ledger. Use Redis for cache and sessions; keep unused skeleton user/password-reset/database-session tables out of initial migrations.
- Keycloak owns users, credentials, groups, and identity configuration. The minimal application login uses the verified `iss` and `sub` claims in its session without copying credentials or creating an application user table.
- Future application user profiles and foreign keys to business entities require a later approved database design.
- Keycloak and Camunda initialize and migrate their own schemas using their supported tools. Laravel migrations must never modify those databases.
- Camunda PostgreSQL storage does not replace Zeebe's primary engine state: persist the engine's required data directory separately.
- Redis uses distinct prefixes for cache and application sessions; persistence and invalidation behavior are documented. Redis is not the source of truth for users or business records.
- Credentials are configurable through ignored environment files; commit examples/placeholders only.

## 6. Frontend baseline

- Build only an infrastructure landing page showing the application shell, theme selector, and login/logout state; do not invent business modules.
- Configure Persian language and RTL, Vuetify, locally stored Vazirmatn and its license, and local icon assets.
- Put all custom CSS and theme styling in dedicated files. No Vue component styles or inline style bindings.
- Provide light, dark, and system modes. Remember an explicit user choice and follow live device-theme changes in system mode without hydration errors.
- Use restrained organization-appropriate colors, readable text, visible focus, and comfortable spacing for long sessions.

## 7. Ordered implementation steps

Initial plan approval has been received. Report each completed step as **step N of 8** and update/commit this plan.

1. [x] **Confirm prerequisites and pin versions.** Check Git, Docker/Compose, host resources and port availability; record the compatible release matrix. Verify the confirmed Graphify service's graph-update capabilities and required account access. Escalate material architecture changes for approval before proceeding.
2. [x] **Create the monorepo skeleton.** Scaffold Laravel and Nuxt/Vuetify, commit dependency lockfiles, add ignore rules, and establish environment templates and shared development commands. Keep backend and frontend commits separate.
3. [x] **Build containerized development runtime.** Add Dockerfiles, Compose services/networking, source mounts, dependency volumes, health checks, startup ordering and development commands with the required internal ports.
4. [x] **Initialize PostgreSQL, Redis and pgAdmin.** Implement isolated roles/databases, persistent volumes, Laravel cache/session connectivity and pgAdmin access through 8082; verify database isolation and repeat startup behavior.
5. [ ] **Configure Nginx and Keycloak.** Implement the 8081 application gateway, HMR forwarding, the 8083 administration entry, realm/client bootstrap and minimal Laravel login/logout flow; verify canonical issuer and same-origin redirects.
6. [ ] **Configure Camunda.** Add the selected Orchestration Cluster, PostgreSQL secondary storage, engine persistence, Keycloak client/configuration and 8084 management proxy. Verify a disposable process deployment/execution through its internal API.
7. [ ] **Complete and verify the integrated baseline.** Finish the minimal Persian Vuetify shell and local assets/theme modes. Run the full acceptance matrix below; fix failures, and present the working phase for user review. Await explicit completion approval and apply requested revisions.
8. [ ] **Close the approved phase.** After completion approval, create English technical documentation and Persian usage guides in `docs/`, update the graph using the verified workflow for `https://www.graphify.ai/`, finish separately scoped frontend/backend/infrastructure/documentation commits, and report final validation and commit IDs.

Do not mark step 7 complete until the review handoff is ready. Step 8 cannot begin before the user's explicit phase-completion approval. Never mark the entire phase complete with missing runtime verification or a missing required graph update.

## 8. Acceptance and verification matrix

| Check | Required result |
| --- | --- |
| Clean bootstrap | A fresh checkout plus environment setup starts with the documented command; all required services become ready |
| Compose validation | Configuration resolves without missing variables; images and application dependencies are pinned |
| Port exposure | Only loopback host ports 8081, 8082, 8083, 8084 are published; service ports remain private |
| Main gateway | Frontend and Laravel API respond through 8081, including Nuxt HMR WebSockets |
| Identity flow | Login, callback, authenticated session and logout work; application redirects remain on 8081 |
| OIDC consistency | Discovery issuer and token `iss` match; server and browser can reach their intended endpoints without weakening validation |
| Management consoles | pgAdmin on 8082, Keycloak admin on 8083, and Camunda Operate/Tasklist/admin on 8084 render with working assets and navigation |
| Database isolation | Each runtime role can access its database and is denied access to other service databases |
| Redis | Laravel cache set/get and session continuity work; application cache/session settings use Redis |
| Camunda | Authenticated internal API access, disposable process execution, and management visibility work |
| Restart | Normal stop/start preserves databases, identity configuration and Camunda state; initialization does not duplicate objects |
| Frontend | Persian RTL, local Vazirmatn/icons, responsive shell, three theme modes, and live system-theme switching work |
| Asset/style policy | Browser performs no CDN asset requests; source scan finds no component style blocks or inline styles authored by the project |
| Developer changes | Editing Laravel/Nuxt source is reflected without rebuilding images for every edit |
| Secret handling | Real secrets, tokens, local env files and volume data are absent from Git |
| Closeout | User completion approval, English technical docs, Persian guide, graph refresh and scoped commits are recorded |

Record commands, outcomes and any environment constraints here as implementation progresses. Use focused smoke checks and actual browser evidence; do not report planned tests as passed.

## 9. Review decisions and current constraints

- **Camunda proposal:** 8.9 with PostgreSQL secondary storage; approval of this plan includes this architecture choice. Exact patch versions are recorded in step 1 after compatibility checks.
- **Graph tool confirmed:** The user explicitly selected `https://www.graphify.ai/`. Its public page describes KPI tracking and visualization, not a source-code knowledge-graph CLI. Supported graph updates, account access, and any export/API capabilities remain to be verified before integration; do not install a similarly named product or promise an unsupported automation. This clarification is not approval to start phase implementation.
- **Git:** The workspace initially had no usable repository. Git initialization succeeded using the environment's approved permission mechanism; the initial rules and draft plan were committed as `5acef50`. Never delete or replace protected metadata as a workaround.
- **Approval record:** Initial plan approval: received on 2026-11-02 (user: "پلن فاز اول رو تأیید می‌کنم، پیاده‌سازی رو شروع کن."). Phase completion approval: pending.

## 10. Official references used for this proposal

- [Keycloak reverse proxy configuration](https://www.keycloak.org/server/reverseproxy)
- [Keycloak hostname and administration URL configuration](https://www.keycloak.org/server/hostname)
- [Camunda 8.9 Docker Compose development configuration](https://docs.camunda.io/docs/8.9/self-managed/quickstart/developer-quickstart/docker-compose/configuration/)
- [Camunda 8.9 PostgreSQL secondary storage](https://docs.camunda.io/docs/8.9/self-managed/quickstart/developer-quickstart/docker-compose/secondary-storage/)
- [Graphify — user-confirmed service](https://www.graphify.ai/)

## Implementation evidence

### Step 1 — prerequisites and release matrix

- Docker Engine 29.5.3 and Compose 5.1.4 are available through the environment permission mechanism; ports 8081–8084 were free. Host: 15 GiB RAM, approximately 5 GiB available, 282 GiB free disk. Scope runtime memory for this development host.
- Selected versions: PHP 8.4.21 CLI (Bookworm), Laravel framework 13.39.0 (skeleton 13.11.0), Composer 2.10.3, Node 24.22.1 (Bookworm slim), Nuxt 4.6.3, Vue 3.5.34, Vuetify 3.13.7, vite-plugin-vuetify 2.1.3, Keycloak 26.7.3, Camunda 8.9.27, PostgreSQL 17.6 Alpine, Redis 8.2.2 Alpine, Nginx 1.28.0 Alpine, pgAdmin 9.10. Image pulls and compatibility checks continue before runtime validation.
- Official Camunda 8.9 distribution downloaded for configuration reference; it supports PostgreSQL secondary storage without Elasticsearch. The development environment uses the applicable non-production evaluation terms.
- Graphify identity is confirmed. Its public page provides organization signup/login and KPI visualization, but no verified code-graph API/CLI or authenticated project access is available. Graph closeout remains an explicit dependency; no graph update is claimed.
- Phase approval recorded in commit `8e7e4c2`.

### Step 2 — monorepo skeleton

- Laravel skeleton and OIDC/session infrastructure committed separately in `18eb4bc`; 8 focused authentication tests passed with 47 assertions. Pint formatting and Composer validation pass. No application user/password tables or frontend build system are retained in the backend.
- Nuxt/Vuetify skeleton, npm lockfile, local Vazirmatn font/license, local SVG icon dependencies and Persian shell are prepared. `npm install` using Node 24.22.1 completed and `nuxt prepare` generated types. Browser validation remains pending.
- Environment examples, private generated secrets and development command scaffolding are prepared. No phase-completion documentation has been created.

### Step 3 — development runtime

- Both PHP and Nuxt development images built successfully from pinned base versions and committed lockfiles. Compose configuration validation passes. Source binds, separate dependency volumes, health checks, restart policies and bounded `up --wait` are configured.
- `scripts/dev` provides bootstrap/start, stop, restart, logs, status, test and explicit destructive reset commands. Python bootstrap creates ignored local credentials without printing them; service initialization is separate from normal runtime commands.
- PostgreSQL, Redis, Keycloak, pgAdmin and the gateway are running. Laravel readiness responds through port 8081. Nuxt runtime and full browser checks are being completed next.

### Step 4 — data services

- PostgreSQL/Redis/pgAdmin are healthy. Real connection checks passed for all three own-database connections and all six cross-database denials. The application database contains only `migrations`.
- Laravel readiness verifies a real database query and Redis cache set/read/delete on port 8081. Containerized PHPUnit now passes all 8 authentication tests and 47 assertions with explicit test-environment isolation.
- Browser login to pgAdmin through 8082 succeeds and the registered development server is present. Redis-backed browser session verification and restart persistence are included in the remaining integrated review.
