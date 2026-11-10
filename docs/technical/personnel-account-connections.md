# Phase 3 — Personnel creation and account connections

Completion was explicitly approved on 2026-11-10 after review of account management, administrator access and the active-source connection indicator. This document extends [Phase 2](organization-personnel-access.md). The [management API](management-api.md) is the route index; the [Persian personnel guide](../guides/personnel.fa.md) covers use and operations. Current validation and integration evidence is recorded in [PLAN.md](../../PLAN.md).

## Ownership and business behavior

Login retains the canonical `User`, `Member`, `UserSource` and extended Sanctum token models. Personnel owns the creation and connection-management HTTP interface; Login owns reusable directory reads, connection transactions and import compatibility. Access owns the independent permission catalog entries. Organization supplies the existing building-area selector.

Operators can create a local User before connecting any account. Creation requires a trimmed, nonempty name of at most 255 characters and an existing building area. The User retains automatic appearance defaults. It receives no implicit company, position, role, Member or password. A User with zero Members remains a valid personnel record. Existing company associations still derive only from positions and direct company roles.

A User can have several Members, with at most one per source. A Keycloak account identified by `(uuid, user_source_id)` can belong to only one User. The same subject in independently configured sources remains a distinct identity. Neither UI attachment nor import mapping to an existing User overwrites its local name or location. Name/email never triggers identity matching or merging.

Source selection and search use active UserSources only. A source already connected to the selected User remains visible in the active choices with `can_search: false`; the operator must delete that connection before searching that source again. Inactive-source connections remain listed and deletable.

Search reads Keycloak directly using the selected source's configured read client. It searches username, first name, last name and email. Disabled accounts are displayed but cannot be attached; service accounts are excluded. Occupied accounts remain visible with their local owner's ID/name and cannot be selected. Submission re-reads the exact remote account and rejects missing, disabled or service accounts, stale configuration and conflicting local associations. Browser-supplied profile/owner/eligibility values are never authoritative.

The row action is `حساب‌های ورود`. Closing it refreshes the directory. A warning chip with a local disconnected-link icon and text `فاقد اتصال به منبع فعال` marks Users whose `has_active_member` is false. This boolean is derived using a database EXISTS query over Members and active sources. It is true when at least one Member references an active source, including mixed active/inactive connections. It does not assert that the remote account is enabled, that Keycloak is reachable or that a particular login grant is valid. Directory-only readers receive this boolean without Member/source identity details.

## Database changes

| Object | Change / invariant |
| --- | --- |
| members | Add named `members_user_source_unique` on `(user_id, user_source_id)`; preserve existing UNIQUE `(uuid, user_source_id)`, bigint numeric foreign keys and timestamps. |
| users | No schema changes. Existing required name/location, appearance default, positions and roles remain. |
| user_sources | No schema changes. Immutable public slug remains distinct from the internal numeric key. |
| personal_access_tokens | No schema changes. Existing `member_id` restrictive FK remains; remove matching tokens explicitly before deleting a Member. |
| permissions | Register `personnel.create` and `personnel.members.manage` using the existing catalog synchronization; no schema changes. |
| directory status | No stored columns or status migration. Compute `has_active_member` from current associations and source activity. |

The two pairwise unique constraints imply uniqueness of the three-field `(uuid, user_id, user_source_id)` combination; a redundant triple index is unnecessary and would not alone enforce the rules.

Migration `2026_11_10_000007_limit_members_per_user_source.php` locks Members in a transaction, checks for multiple rows per User/source and fails with the conflicting local identifiers before adding the constraint if reconciliation is needed. It does not choose an identity, merge Users, delete associations or revoke tokens. An operator must explicitly decide how to resolve existing conflicts. Rollback removes only the new constraint, retaining associations and the existing identity constraint. The migration was applied to development without conflicts.

## Removal, tokens and concurrency

Removal deletes only the selected local Member and **all** application tokens with its `member_id`, atomically. Other Members' tokens for the same User remain subject to normal validity checks. The remote Keycloak account and its SSO session are unchanged by this operation. A later callback without a matching Member still returns `403 member_not_imported`; reconnecting creates a new association and never revives deleted credentials.

Removing the final Member is permitted, including for the last administrator. User data, preferences, location, positions, reporting and roles remain. This differs from existing role/assignment mutations that protect the last effective access administrator; those protections are unchanged. Operators may leave a User without any way to log in until an authorized connection or explicit import mapping restores one.

After removal commits, credentials for that Member fail subsequent protected requests. Requests already authorized before commit are not retroactively cancelled. Removing the browser's current Member triggers a full navigation to the same-origin home page so Nuxt renders anonymous HTML immediately. The gateway clears invalid transport cookies; it never exposes Bearer credentials to JavaScript.

Attach and detach lock the source first, then the relevant User/Member rows. Callback already locks the source and now locks the matched Member while issuing its token. Import locks the source and an explicitly mapped User. PostgreSQL uniqueness is the final integrity boundary; insertion races become stable 409 errors. Token refresh/validity checks retain their final fresh-token checks so concurrent removal cannot restore deleted credentials. Slow directory reads occur before the short attachment transaction; source activity and the configuration hash are checked again under lock before insertion.

Local deletion and local connection-list fallback add no directory dependency. Existing Sanctum authentication and upstream-grant verification still apply to these endpoints: if the caller cannot be authenticated during a provider outage, normal 401/503 behavior can prevent access before the action runs.

## Authority and deployment behavior

- Global `personnel.create` creates a local User; it does not imply profile editing or connection management.
- Global `personnel.members.manage` permits connection list/source reads, directory search, attach and detach; it does not imply User creation/editing.
- Directory access continues to use `personnel.view`. Company grants can open the corresponding company directory but never satisfy the two global mutations. Connection controls on either directory require the global manage grant.
- Location options accept global `personnel.create` OR global `personnel.update`, allowing creators to select an area without profile-update authority.
- Synchronization creates both catalog entries but does not add them implicitly to a pre-existing administrator role. A newly created bootstrap role receives the catalog as before. Explicit grants can then be delegated through the existing authorization model; an operator cannot delegate a permission they do not possess.
- In this development environment, the user separately authorized both grants to the existing `system-administrator` role titled `مدیر سامانه`. They were applied and verified without changing user-role assignments or other permissions. This operational change is not an automatic migration grant for other environments.

## API contracts

Base: `http://localhost:8081/api`. All routes require Sanctum Bearer and valid upstream authentication. Browser callers use the explicitly allowlisted `/gateway/manage/` equivalents, with same-origin Origin and CSRF proof on mutations. Responses are private/no-store. Public source identifiers are slugs; numeric `user_source_id`, source secrets and provider tokens are not returned.

| Method and path | Global permission | Success |
| --- | --- | --- |
| POST /personnel | personnel.create | 201 `data: {id, name, building_area_id}` |
| GET /personnel/{user}/members | personnel.members.manage | 200 `data: Connection[]` |
| GET /personnel/{user}/member-sources | personnel.members.manage | 200 `data: {slug, title, can_search}[]`, active sources only |
| GET /personnel/{user}/member-sources/{slug}/accounts | personnel.members.manage | 200 search page described below; throttled to 30 requests/minute |
| POST /personnel/{user}/members | personnel.members.manage | 201 `data: {id, uuid, source: {slug, title, is_active}}` |
| DELETE /personnel/{user}/members/{member} | personnel.members.manage | 204; nested Member ownership is checked |

Personnel creation body:

```json
{"name":"Example person","building_area_id":2}
```

Attachment body:

```json
{"source_slug":"primary","uuid":"external-subject"}
```

`source_slug` is a string of at most 63 characters matching `[a-z0-9]+(?:-[a-z0-9]+)*`. The subject is at most 255 characters, starts with an ASCII letter/digit and otherwise allows ASCII letters/digits, `.`, `_`, `:` and `-`. The source and remote account must exist. No User-delete or credential-management API is introduced.

`Connection` contains `id`, `uuid`, `source: {slug, title, is_active}`, `profile` and `profile_state`. Profile is null or `{uuid, username, first_name, last_name, email, enabled}`. Text fields may be null and are bounded to 255 characters. `profile_state` is `available`, `account_missing`, `source_inactive` or `unavailable`. Inactive-source and failed/missing remote profiles never hide the local connection. Creation returns the local connection envelope; the optional live profile is obtained from the list endpoint.

Search requires nonempty `search` (max 100); `page` defaults to 1 and is bounded to 1–10000; `per_page` defaults to 25 and is bounded to 1–50. UI requests 10 raw records per page. The backend requests one extra upstream record to compute `has_more`, filters service accounts from the current raw page and returns:

```json
{
  "data": [{
    "uuid": "external-subject", "username": "example",
    "first_name": "Example", "last_name": "Person", "email": "example@example.org",
    "enabled": true, "can_connect": false,
    "reasons": ["identity_already_connected"], "owner": {"id": 7, "name": "Local personnel name"}
  }],
  "page": 1, "per_page": 25, "has_more": false
}
```

Disabled rows include `account_disabled`; occupied rows include `identity_already_connected`; both can appear together. `owner` is null for an unconnected account. Selection requires an empty reasons array. There is no total/last_page estimate; a filtered empty page can still have `has_more: true`. Source occupancy/inactivity is rejected before remote I/O, and checked again after search. Attachment performs its own validation regardless of a previously selectable result.

Existing GET `/personnel` and GET `/companies/{company}/personnel` add boolean `has_active_member` to each authorized User row. Existing pagination, search and company filtering are unchanged; User detail and selector contracts do not gain Member identities.

### Failures and gateway reasons

| HTTP | Stable error / behavior |
| --- | --- |
| 401 / 403 | Existing invalid authentication/upstream grant or missing global permission; gateway also enforces Origin/CSRF. |
| 404 | Unknown local User/source/Member or nested-owner mismatch. |
| 409 | `source_inactive`, `source_changed`, `user_source_occupied`, `identity_already_connected`, `membership_conflict`. |
| 422 | Framework input validation or `account_ineligible` for a missing, disabled, service or invalid subject. |
| 429 | Search rate limit. |
| 503 | `provider_directory_unconfigured` for missing read configuration; `provider_directory_unavailable` for failed/malformed provider reads; existing upstream-authentication failures also remain. |

LoginFailure errors use `{"error":"code"}` at the backend. Nuxt transports only the allowlisted directory/connection codes as `data.reason` in its sanitized error envelope for Persian messages. Framework field errors retain their separate validation shape. A best-effort profile failure on local connection listing is represented by `profile_state`, not by dropping the connection.

## Bulk import

`login:import-members` remains repeat-safe and supports source slug, required area for newly created Users, explicit subject-to-User JSON mapping and dry-run. It preserves local names/locations for mapped Users and never silently reassigns an occupied identity. Import rejects a second identity mapped to the same User/source, including two pending entries in a dry-run before either is written. Real runs and dry-runs report sanitized `user_source_occupied`, `identity_already_connected` or `source_changed` conflicts and exit unsuccessfully. Already completed real imports and established associations remain intact; the command does not promise all-or-nothing batch behavior. Disabled/service accounts are skipped. Removing a Member does not prevent an operator from explicitly importing the account later.

## Unchanged categories

No infrastructure, ports, dependencies, source configuration schema, Keycloak identity lifecycle, local password system, Laravel sessions, Camunda process, organization/role schema or company-membership model changes. No remote profile synchronization or automatic callback registration was added. New credentials remain revocable, persisted Sanctum tokens rather than storage-free JWTs. Existing OIDC logout and current upstream checks remain in effect.
