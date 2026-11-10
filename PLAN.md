# Phase 4 — Login Source Administration

## 1. Status and approval gates

- Prepared: 2026-11-10 (Asia/Tehran).
- Status: **Approved; implementation in progress.**
- Explicit approval: the user approved this complete plan in chat on 2026-11-10 and requested implementation plus development permission synchronization. Completion approval remains pending.
- Planning revision: the user requested explicit execution of permission synchronization in the development environment so the new administration section is ready for manual testing. This request does not approve the complete phase plan.
- Implementation progress: **3 of 7 steps completed**.
- Branch: `codex/feature/login-source-management`.
- Baseline: `e070cf820eae0a2449c79b30276235e51a1d18e6`.
- Preparation: verified a clean master working tree, fetched origin, switched to master, updated using `git merge --ff-only origin/master` (already up to date), and created this feature branch before replacing PLAN.md.
- The previous completed Phase 3 plan remains in Git history. This is the only current root plan.
- Commit every plan update, including approval, progress and verification evidence.
- Obtain explicit approval of this plan before writing application code, migrations, implementation tests or infrastructure changes. Record and commit approval first.
- After each implementation step, report in Persian: “مرحله X از ۷ تکمیل شد.”
- Step 6 ends at the completion-review gate. Step 7 requires separate explicit approval of completed implementation; plan approval does not authorize documentation closeout or integration.

## 2. Subject and scope

Provide a Persian administrative section for listing, adding, editing, activating, deactivating and deleting application login sources. A source continues to represent one independently configured Keycloak realm/client integration.

In scope:

- Admin navigation, paginated source list, configuration form, activity controls and confirmed deletion.
- Global permissions for viewing, creating, updating, changing activity and deleting sources.
- Protected Laravel management APIs and Nuxt server gateway integration.
- Shared validation and lifecycle behavior between the admin API and existing `login:sources:upsert` command.
- Secret-preserving updates, encrypted storage, sanitized resources, source identity protection and local credential revocation.
- Focused automated checks and real-browser acceptance verification.

Out of scope:

- Direct Google, generic OAuth2, generic OIDC or Laravel Passport provider drivers.
- Configuring Keycloak identity brokering or introducing additional external providers in this phase.
- Creating/deleting remote realms, clients, users or credentials; remote user lifecycle changes.
- Automatic local registration, automatic identity matching, password management or changing personnel account-connection rules.
- Bulk deletion of Members, transferring identities, or a new last-login-source/administrator protection policy.
- Dependency upgrades, new public ports, replacement services or authentication-session changes.

## 3. Existing behavior and provider compatibility

- UserSource already stores stable slug, title, activity and JSON configuration; client secrets are encrypted by the model.
- The existing command creates/updates sources and revokes local source tokens on security-configuration changes or deactivation.
- The public login source list exposes active sources for login selection; it is not an administrative configuration API.
- ProviderClient builds Keycloak-specific `/protocol/openid-connect/*` endpoints. Identity validation requires ID tokens with a session identifier; protected requests use upstream token introspection. Account lookup/import use Keycloak Admin APIs.
- Consequently, changing a source URL does not enable direct Google or Passport support. Such support needs a separately approved design covering provider capabilities, identity lookup, current grant validity and logout semantics.
- Google via Keycloak identity brokering is a possible future route while keeping the application integrated with Keycloak. Local Members would still identify Keycloak subjects and must pre-exist. Brokering does not automatically guarantee propagation of an external provider's logout; that must be assessed separately.

## 4. Proposed business behavior

### Listing and permissions

- Add a global admin section named `سورس‌های ورود`, with title, slug, active state and local connection count. Both active and inactive sources remain visible to authorized administrators.
- Add independent global permissions `login_sources.view`, `login_sources.create`, `login_sources.update`, `login_sources.activate`, and `login_sources.delete`.
- Company-scoped grants must not authorize these global operations. Controls reflect individual permissions; the backend enforces them independently.
- Use the existing permission synchronization mechanism and preserve its established bootstrap-role behavior. Do not automatically assign roles to users or broaden existing custom-role grants.

### Create and edit

- A source has a required Persian display title and a unique immutable slug matching the existing format. Default new sources to inactive, with activation available as a separate authorized operation.
- Creation accepts the existing required OIDC client and operational settings, with optional complete admin-read/import credentials. Explain that admin-read settings are needed for personnel account search/import.
- Show callback and post-logout URLs derived from the fixed gateway and slug, rather than accepting arbitrary redirect destinations.
- Preserve the current RS256-only contract and bounded timeout/page-size validation.
- For updates, omitted or blank secret inputs retain stored secrets. Never return stored secret values, encrypted values or fake password placeholders; expose only configured/not-configured indicators. Explicit removal of the optional directory configuration removes its complete credential group.
- Do not return secrets through resources, validation errors, browser logs, SSR payloads or application logs. Use server-side credentials for upstream requests only.
- Once any Member references a source, its public issuer is immutable: changing the identity authority under existing local mappings could connect a different person with the same subject. Require a new source and explicit personnel connections for an authority change. Slug remains immutable for every existing source.
- Changes to client/security settings use the existing fingerprint and revoke local source tokens transactionally. Display a clear confirmation before submitting an edit that invalidates current credentials.
- Title-only and directory-only changes do not unnecessarily revoke application tokens. Internal endpoint changes must continue to represent the same public issuer.
- No new network requests are required to save or toggle a source; unavailable providers must not prevent deactivation or correction of settings. Activation is configuration enablement, not a claim that connectivity has been verified.

### Activity and deletion

- Deactivation hides the source from public login selection, rejects new authorization/callbacks and new personnel connections/search, and revokes all local Sanctum tokens belonging to that source. Preserve Users, Members and remote accounts.
- Existing in-flight protocol transactions must not issue usable credentials after a concurrent deactivation or relevant configuration change. Recheck source activity/configuration at credential issuance and retain existing protected-request checks.
- Reactivation permits new logins and connections; previously revoked credentials stay revoked.
- An administrator may deactivate their current source, including the last active source, after a clear impact confirmation. Do not invent a new last-source guard. Explain that the current browser will lose access and operational recovery may require the existing CLI.
- Hard deletion is allowed only for sources with zero Members. Return HTTP 409 when connections exist and suggest deactivation instead. Never cascade-delete personnel or connections, and never call remote deletion APIs.
- Confirm deletion with the displayed source title/slug. Preserve database foreign-key restrictions and handle concurrent connection creation/deletion safely.

## 5. Implementation method and security boundaries

- Keep source controllers, requests, resources, reusable management actions/services and tests in the Login module. Extend Access's permission catalog and the existing admin navigation without adding a new framework.
- The API and CLI must share source validation and lifecycle safeguards, including immutability, revocation and locking. Preserve the CLI's complete-config create/update contract while making API partial secret updates explicit.
- Use transactions and row locking for source mutations; coordinate with existing membership and token issuance paths where necessary. Keep unique-slug and membership foreign-key enforcement as final database safeguards.
- Extend the existing Nuxt management gateway with an explicit allowlist of source-management routes and methods. Require the existing protected browser credential, server-side Bearer conversion, mutation CSRF checks and private/no-store responses.
- Continue server-resolved initial authentication, upstream validity checks, bearer-only Laravel authentication and the Nginx port 8081 application origin.
- Administrative provider URLs cause server-side HTTP requests. Validate URL syntax, exclude userinfo/query/fragment, enforce production HTTPS and retain no-redirect requests. Add an explicit deployment-controlled allowlist of permitted provider origins for writes, including the known Docker Keycloak origin and gateway origin in development. Do not allow the admin form to choose arbitrary internal services or bypass this allowlist. Provide environment examples without secrets and a clear validation error when an origin is not allowed. Preserve existing configured sources for reads; require allowlist compliance when endpoint settings are changed.
- Use Nuxt/Vuetify, local icons/fonts, Vazirmatn, Persian RTL forms, all existing theme modes, accessible focus/labels and responsive dialogs. Keep CSS in dedicated project files; no Vue style blocks, inline styles or JavaScript style bindings.

## 6. Database design

- Reuse `user_sources.id`, unique `slug`, `title`, `is_active`, encrypted-secret `data` and timestamps. No new source or credential tables are planned.
- Preserve numeric `members.user_source_id` referencing `user_sources.id` with restrictive deletion and uniqueness of `(uuid, user_source_id)` and `(user_id, user_source_id)`.
- Preserve PersonalAccessToken membership references; revoke source credentials through their Member relationship in the same transaction as source invalidation.
- Add the five permission catalog entries using the existing synchronization mechanism and existing permission/role tables.
- No new application schema migration is expected. If implementation reveals a necessary schema or scope change, update and commit this plan and obtain approval before implementing that change.

## 7. Proposed API contracts

Under the existing authenticated management API prefix, using the source slug for binding:

| Method | Relative route | Permission | Result |
| --- | --- | --- | --- |
| GET | `login-sources` | `login_sources.view` | Paginated sanitized summaries |
| GET | `login-sources/{source:slug}` | `login_sources.view` | Sanitized editable configuration and secret-presence flags |
| POST | `login-sources` | `login_sources.create` | Create inactive source; HTTP 201 |
| PATCH | `login-sources/{source:slug}` | `login_sources.update` | Update title/configuration; exclude activity and slug changes |
| PATCH | `login-sources/{source:slug}/activity` | `login_sources.activate` | Set required boolean `is_active` |
| DELETE | `login-sources/{source:slug}` | `login_sources.delete` | Delete unreferenced source; HTTP 204 |

- A mutation response must not disclose configuration requiring separate view permission; return a minimal sanitized summary or no content as appropriate.
- Preserve established API error conventions: 401 for unauthenticated/invalid upstream grant, 403 for insufficient global permission, 404 for unknown source, 409 for a referenced deletion or lifecycle conflict, and 422 for invalid configuration or immutable identity changes.
- Keep the existing public login-source API unchanged; never expose admin configuration there.

## 8. Ordered execution steps

1. **Source lifecycle and validation:** implement shared safe create/update/activity/delete behavior, secret preservation, origin validation, issuer immutability, transactional revocation and concurrency safeguards; align the CLI.
2. **Authorization and management API:** register global permissions, add protected requests/controllers/resources/routes, sanitized errors and API coverage for permissions and data exposure.
3. **Nuxt gateway and management state:** allowlist the new API contracts, add typed server-mediated calls, permission-aware navigation and SSR-safe data loading.
4. **Admin interface:** implement Persian source listing, create/edit forms, secret-presence handling, activity confirmation and restricted deletion with meaningful errors.
5. **Verification and manual-test preparation:** after registering the new permissions, execute `docker compose exec -T backend php artisan access:sync-permissions` against the running development database, rather than only documenting the command or running it in tests. Verify that all five `login_sources.*` permissions exist and are attached to the existing `system-administrator` role according to the command's established behavior. Confirm that an existing administrator with that global role can access the section and its actions; do not create new user grants or silently modify custom roles. If no suitable existing administrator is assigned, report the missing role assignment explicitly. Run appropriate backend tests, frontend checks and real-browser scenarios against development services; fix failures and record the synchronization command, exit status, permission/role verification and actual validation results in this plan. Include the CLI and existing login/personnel regressions. Before presenting completion for review, leave the development environment ready for the user's manual test and report the panel route and any remaining access prerequisites.
6. **Review gate:** prepare appropriately scoped backend/frontend/configuration commits, update and commit progress/evidence, and present the implemented behavior for explicit completion approval. Stop before final phase documentation or integration.
7. **Approved closeout:** after explicit completion approval, write English technical documentation covering database behavior, business rules and API contracts, and Persian usage/operations guidance including recovery. Commit documentation and final plan status; fetch origin, rebase the feature branch onto latest origin/master, resolve conflicts on the feature branch and repeat relevant checks. Fast-forward master from origin/master, merge using `--no-ff`, push master, then delete integrated local and any published remote feature branches. Report actual outcomes and preserve the branch if blocked.

## 9. Acceptance criteria and verification evidence

- An authorized global administrator can list, add, edit, activate, deactivate and delete an unreferenced source from the admin panel.
- Permission synchronization has actually run successfully in the development environment; all five source permissions are present and available through the existing system-administrator role. Record the execution result and verify administrative access before requesting completion review, or explicitly report any blocked prerequisite. Merely adding permission keys to code does not satisfy this criterion.
- Unauthorized actors and actors with only company-scoped source grants cannot perform global operations, including direct API attempts.
- Existing secrets survive blank/omitted update fields and are never exposed through API/SSR/errors. Optional directory credentials can be explicitly removed as a group.
- Slugs cannot be renamed; an issuer with existing Members cannot be changed. Invalid origins, redirect destinations, algorithms and malformed settings are rejected.
- Deactivation and relevant configuration changes revoke only the affected source's local credentials; reactivation does not restore them. Confirm concurrent token issuance cannot create a surviving stale credential.
- Inactive sources disappear from login selection and reject new connections, while existing personnel connections remain manageable under their current rules.
- Referenced-source deletion returns 409 without deleting or modifying Users/Members/remote identities. Concurrent attachment cannot leave orphaned relationships.
- Public source selection, imported-member-only callbacks, existing Keycloak login/logout, upstream checks and personnel account management continue to work.
- Browser verification covers create, edit without secret replacement, secret replacement, activity changes, deletion conflict, empty-source deletion, permission visibility and current-source deactivation. Check mobile layout and light/dark modes.
- Actual commands and results will be recorded here during execution. No implementation checks have run at proposal time.

## 10. Execution evidence

- Step 1 completed: shared lifecycle actions and CLI integration, origin validation, encrypted/retained secrets, issuer immutability and transactional source token revocation. Existing callback, attachment and import paths already lock the source row; source mutations use the same lock order.
- Initial source-management API checks: `docker compose exec -T backend php artisan test --compact --filter=SourceManagementTest` passed (11 tests, 106 assertions).
- Step 2 completed: six protected source-management API routes, independent global permissions, sanitized summary/detail resources, private responses and focused API/CLI regression coverage.
- Step 3 completed: exact Nuxt gateway route/method allowlist, sanitized lifecycle error reasons, shared typed source contracts and global-only admin navigation. Existing server Bearer exchange, CSRF and no-store controls remain in place.
