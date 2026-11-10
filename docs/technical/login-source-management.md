# Phase 4 — Login source administration

Phase completion was explicitly approved after user testing on 2026-11-10. This document covers the implemented database behavior, business rules and API contracts. [PLAN.md](../../PLAN.md) records execution evidence; Git history and the closeout report record integration outcomes.

## Scope and ownership

The Persian admin section `/admin/login-sources` manages independent Keycloak source configurations: list, create, update, activate, deactivate and delete. Keycloak continues to own credentials and identity lifecycle. No remote realm, client or user is created or deleted by these operations. Local User and Member lifecycle remains governed by [personnel account connections](personnel-account-connections.md).

There are no direct Google, generic OAuth2/OIDC or Laravel Passport drivers in this phase. ProviderClient still uses Keycloak endpoint paths, signed OIDC identities with session identifiers, introspection and Keycloak Admin account reads. Changing a source URL cannot turn it into another provider type. Any future brokering or provider adapter requires its own design, including logout/current-grant validity and explicit membership mapping.

## Database and permissions

**Schema changes: none.** No migration, source table, credential table or foreign-key change was added. The phase reuses:

- `user_sources`: internal numeric ID, unique immutable textual slug, display title, activity flag, JSONB data and timestamps. `client_secret` and `import_client_secret` remain encrypted through the existing model mutator using APP_KEY.
- `members.user_source_id`: numeric FK to user_sources.id with restrictive deletion. Existing unique `(uuid, user_source_id)` and `(user_id, user_source_id)` constraints remain intact.
- `personal_access_tokens.member_id`: source-token revocation follows the Member relationship; tokens persist as hashed Sanctum credentials with encrypted upstream grant data.
- Existing permission, role and grant tables.

The permission catalog adds five independent **global** permissions:

| Key | Authority |
| --- | --- |
| `login_sources.view` | List summaries and read sanitized configuration |
| `login_sources.create` | Create an inactive source |
| `login_sources.update` | Edit title/configuration |
| `login_sources.activate` | Set active or inactive state |
| `login_sources.delete` | Delete an unreferenced source |

Company-scoped grants do not authorize these operations. Existing global-grant resolution also applies to globally scoped position roles. Navigation requires view; actions require their own key. Mutation APIs do not require view and therefore return only a minimal summary, never configuration.

`access:sync-permissions` adds the catalog keys and attaches these five permissions to the existing `system-administrator` role according to established synchronization behavior. It does not grant that role to users or expand custom roles. Existing Phase 3 exceptions for personnel.create/personnel.members.manage remain unchanged.

## Business behavior and lifecycle

Create stores an inactive source. Activation is a separate authorized operation and is not a connectivity test. A provider outage does not block saving, deactivation or deletion because source mutations do not call the provider.

Slugs use `[a-z0-9]+(?:-[a-z0-9]+)*`, maximum 63 characters, and cannot be renamed. Once any Member references a source, its public issuer cannot change, including through the CLI. Use a new source and explicit personnel connections for a different authority. Internal endpoints must represent the same public issuer; runtime identity validation still checks the configured public issuer.

For API updates, omitted or blank secret fields preserve stored values. Read responses expose only configured/not-configured flags; neither ciphertext nor plaintext is returned. Setting `remove_directory: true` removes import_client_id, import_client_secret and admin_base_url together. Otherwise directory settings are merged and validated as a complete optional group.

Authentication fingerprint fields are issuer, internal_issuer, client_id, client_secret, callback_url, post_logout_redirect_uri and allowed_algorithms. A fingerprint change revokes every local Sanctum token for that source in the same transaction. Title, directory settings, timeout and page size do not change the fingerprint. Saving an inactive existing source also removes any remaining source tokens.

Deactivation immediately deletes all source tokens and hides the source from public login selection. New authorization/callbacks and new personnel connections/search cannot use it. Users, Members, remote accounts and tokens from other sources remain intact. Existing Members stay visible/removable under personnel management. Reactivation permits fresh login but never restores revoked tokens.

There is no last-source guard: an administrator can deactivate their current source or the last active source after the UI impact confirmation. The current browser then becomes anonymous. Operational recovery is described in the [Persian guide](../guides/login-sources.fa.md).

Deletion is hard deletion of the local source only. A source with any Members returns 409 `source_has_members`; the UI suggests deactivation. It never cascades into personnel, connections or remote identities.

Shared ManageLoginSources actions serve both the API and `login:sources:upsert`. Mutations lock the source row inside PostgreSQL transactions. Existing callback issuance, membership attachment and import already lock the same source before writing; issuance rechecks activity/fingerprint while holding it. A concurrent callback cannot leave a surviving token after deactivation, and attachment/deletion cannot leave an orphan. Database constraints remain the final safeguard. Query failures are sanitized so configuration bindings cannot appear in API errors.

## Configuration and endpoint boundaries

Required source data fields are issuer, internal_issuer, client_id, client_secret, callback_url, post_logout_redirect_uri, allowed_algorithms (`["RS256"]`), timeout (integer 1–30) and page_size (integer 1–500). Optional directory configuration requires all three fields import_client_id, import_client_secret and admin_base_url.

API creation defaults timeout to 10, page_size to 100 and algorithms to RS256. The API derives callback and post-logout URLs from APP_URL and the slug; clients cannot provide those fields. The full-config CLI continues to require them and validates exact gateway targets.

Provider URLs reject credentials, queries and fragments. Production provider endpoints require HTTPS. `LOGIN_SOURCE_ALLOWED_ORIGINS` is an operator-controlled comma-separated allowlist for newly configured or changed endpoint origins; comparison normalizes scheme, hostname and effective port. It does not authorize arbitrary redirects or turn every private Docker service into an allowed destination. HTTP provider requests continue to disable redirects.

Development Compose defaults permit exactly `http://localhost:8081`, `http://keycloak:8080` and `http://keycloak-secondary:8080`. The examples document the same setting. Backend config outside Compose defaults to an empty allowlist. Existing unchanged endpoint settings remain usable for reads and title/config edits; adding/changing endpoint values requires allowlist compliance. Editing the deployment environment requires applying it to the backend container.

No public port, dependency version, service or authentication protocol changed. Laravel remains Bearer-only; Nuxt retains protected HttpOnly credential transport, server-side Bearer conversion, server-resolved initial authentication, mutation Origin/CSRF checks and private/no-store responses.

## API contracts

Base path is `http://localhost:8081/api`. Use `Accept: application/json`, Bearer authentication and a currently valid upstream grant. Source management routes declare 60/minute throttling. Browser equivalents use `/gateway/manage/` with the same relative path; the gateway allowlists methods and slug syntax explicitly.

| Method | Path | Permission | Success |
| --- | --- | --- | --- |
| GET | `/login-sources` | view | 200, paginated summaries |
| GET | `/login-sources/{slug}` | view | 200, sanitized detail |
| POST | `/login-sources` | create | 201, summary |
| PATCH | `/login-sources/{slug}` | update | 200, summary |
| PATCH | `/login-sources/{slug}/activity` | activate | 200, summary |
| DELETE | `/login-sources/{slug}` | delete | 204, no body |

Permission names in the table use the login_sources prefix. Listing accepts search (title/slug, max 100 characters), page (positive integer) and per_page (1–100, default 25). Response uses Laravel resource pagination with data, links and meta, including meta.total and meta.last_page. Inactive sources remain included. Each summary has slug, title, is_active and members_count; no numeric source ID or provider configuration is included.

Detail shape:

```json
{
  "data": {
    "slug": "head-office",
    "title": "ستاد سازمان",
    "is_active": false,
    "members_count": 0,
    "data": {
      "issuer": "http://localhost:8081/auth/realms/example",
      "internal_issuer": "http://keycloak:8080/auth/realms/example",
      "client_id": "organization-backend",
      "callback_url": "http://localhost:8081/login/callback/head-office",
      "post_logout_redirect_uri": "http://localhost:8081/login/logged-out",
      "allowed_algorithms": ["RS256"],
      "timeout": 10,
      "page_size": 100
    },
    "client_secret_configured": true,
    "import_client_secret_configured": false
  }
}
```

The detail data allowlist additionally includes import_client_id and admin_base_url when configured. Secret values are never fields in this object.

Create body, with nonworking placeholders:

```json
{
  "slug": "head-office",
  "title": "ستاد سازمان",
  "data": {
    "issuer": "http://localhost:8081/auth/realms/example",
    "internal_issuer": "http://keycloak:8080/auth/realms/example",
    "client_id": "organization-backend",
    "client_secret": "<existing-keycloak-client-secret>"
  }
}
```

is_active and remove_directory are prohibited on creation. Unknown data keys and client-supplied callback/logout targets are rejected. Configure the real remote client separately before using a source.

PATCH accepts optional title, partial data and optional boolean remove_directory. Slug and is_active are prohibited; use the activity route for state. `{"title":"عنوان جدید","data":{"client_secret":""}}` retains the existing secret. `{"remove_directory":true}` removes the complete directory group. New secret input replaces the stored value; algorithm and numeric settings retain their bounds.

Activity body is `{"is_active":false}` or `{"is_active":true}`. Create/update/activity successes return `{"data":{"slug":"head-office","title":"ستاد سازمان","is_active":false,"members_count":0}}` with the actual current values. They do not return detail/configuration even when the actor can view it.

| Status | Meaning |
| --- | --- |
| 401 | Missing/expired/revoked credential or invalid current upstream grant |
| 403 | Missing global permission; browser Origin/CSRF failure |
| 404 | Unknown source or unallowlisted gateway route/method |
| 409 | Connected-source deletion (`source_has_members`), duplicate slug or concurrent database conflict (`source_conflict`) |
| 422 | Invalid settings, disallowed origin, immutable identity change or incomplete directory group |
| 429 | Rate limit |
| 503 | Upstream verification unavailable or sanitized source persistence failure (`source_save_failed`) |

Laravel module failures use `{"error":"<code>"}`; validation uses message/errors keyed by fields such as data.issuer. Framework authentication/route failures retain framework JSON. Nuxt maps only recognized lifecycle reasons, forwards sanitized validation errors, and returns source failures as handled JSON with statusCode/message/data to preserve private/no-store even on errors. Consumers must use status and supported fields rather than parse arbitrary upstream messages.

The public `/api/login/sources` contract remains unchanged: active slug/title summaries only. Source-management authority does not permit automatic registration, identity merging or remote user/password management.
