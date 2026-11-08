# Management API contracts

These implemented routes extend [Login](login-api.md). Base URL: `http://localhost:8081/api`. Protected requests require `Accept: application/json`, Bearer authentication and a valid upstream grant. Browser code uses the equivalent `/gateway/manage/{path}`; Nuxt forwards Bearer internally and requires same-origin Origin plus X-CSRF-Token for POST/PUT/PATCH/DELETE. Credentials are never returned in management responses.

## Routes and authority

`R` below denotes a resource operation suffix: view for GET, create for POST, update for PATCH/PUT and delete for DELETE. A global grant satisfies its corresponding company check.

| Methods / path | Required permission |
| --- | --- |
| GET, POST /companies; GET, PATCH, DELETE /companies/{company} | global companies.R |
| GET /access/me | authenticated; effective global/company keys and visible company summaries |
| GET, POST /companies/{company}/positions; GET, PATCH, DELETE .../positions/{position} | positions.R in company; nested ownership enforced |
| GET, POST /buildings; GET, PATCH, DELETE /buildings/{building}; same methods on /buildings/{building}/areas[/{area}] | global buildings.R |
| GET /personnel; GET /personnel/{user}; GET /personnel/{user}/positions | global personnel.view |
| PATCH /personnel/{user} | global personnel.update |
| GET /companies/{company}/personnel | personnel.view in company |
| GET, POST /companies/{company}/user-positions; GET, PUT, DELETE .../user-positions/{assignment} | user_positions.R in company |
| GET, POST /roles; GET, PATCH, DELETE /roles/{role} | global roles.R |
| GET /permissions | global permissions.view |
| PUT /roles/{role}/permissions | global roles.permissions.update; role delegation checks |
| GET, PUT /personnel/{user}/global-roles | global access.assign; replacement checks each role's permissions |
| GET, PUT /companies/{company}/users/{user}/roles | access.assign in company; replacement checks each role |
| GET, PUT /companies/{company}/positions/{position}/roles | access.assign in company; global grants additionally require global authority |

Read-only selector endpoints have independent bounded authorization and do not expose credentials or occupant identities:

| GET path | Contract |
| --- | --- |
| /organization/company-position-options?company_id=N | access.assign or user_positions.create/update in N; company position options with position_path |
| /organization/reporting-position-options | any user_positions.create/update grant; cross-company position options with company_id, company_title and position_path |
| /organization/location-options | global personnel.update; areas with building context |
| /personnel/options | user_positions.create or access.assign grant; minimal user id/name options |
| /access/role-options[?company_id=N] | access.assign in scope; only delegable roles |

Selectors paginate at 100 (role options use their controller's bounded pagination). Organization selectors and personnel options accept selected as a comma-separated list of at most 100 positive IDs to prioritize current labels, without bypassing scope/search. Position directory also supports selected. Selection order is an aid to rendering, not authority to use IDs.

## Request bodies

```json
{"title":"Company or building or area title"}
```

Titles are strings up to 255 characters. Position create requires title and accepts nullable parent_position_id; PATCH accepts changed title/parent only. company_id is derived from the route and cannot move a position. Personnel PATCH accepts name and/or building_area_id; no local user creation/deletion/password endpoint exists.

Assignment creation and reporting replacement:

```json
{"user_id":1,"position_id":13,"report_position_ids":[7,21]}
```

PUT on an existing assignment accepts only report_position_ids, a present array of at most 100 distinct existing position IDs. user_id/position_id are prohibited on update. Empty reporting is valid only for roots; self-targets are rejected. Selection from another company is allowed for reporting, without granting authority there.

Role creation requires a unique stable key matching `^[a-z][a-z0-9.-]*$` (max 100) and title (max 255); PATCH edits title only. Replacements use these envelopes, with complete-set semantics and bounded distinct existing IDs:

```json
{"permission_ids":[1,2]}
```

```json
{"role_ids":[3,4]}
```

```json
{"grants":[{"role_id":3,"scope":"company"},{"role_id":4,"scope":"global"}]}
```

Position scope is company/global only, never an arbitrary company ID. Company-only replacements cannot submit global entries and preserve existing global entries. Direct company roles do not require a position/membership in that company. Replacements validate everything before committing; there is no partial success.

## Responses, paths and errors

Organization directories use `data`, `links`, `meta`; raw personnel/access/assignment paginators use `data` with top-level pagination fields including current_page, last_page and total. Normal directories default to 25 and validate per_page up to 100; assignment pages use 25. Search is a validated title/name substring (max 100); hierarchy labels remain complete even when ancestors are outside search/page results. Follow server pagination rather than assuming every response has meta.

Position list/detail/create/update adds these fields without changing title or IDs:

```json
{"data":{"id":13,"title":"Portal","company_id":1,"parent_position_id":7,"position_path":"Company / Director / IT / Development / Portal","parent_path":"Company / Director / IT / Development"}}
```

Roots have parent_path null. Directory listings retain immediate parent_title. Assignment responses include id, user_id, position_id, user_name, position_title, company_id, report_position_ids, legacy report_titles and full position_path/report_paths:

```json
{"data":{"id":5,"user_id":1,"position_id":13,"user_name":"Example user","position_title":"Portal","company_id":1,"position_path":"Company / Director / IT / Development / Portal","report_position_ids":[7],"report_titles":["Company / Development"],"report_paths":["Company / Director / IT / Development"]}}
```

GET /access/me returns `{"data":{"global":["positions.view"],"companies":{"1":["user_positions.view"]},"visible_companies":[{"id":1,"title":"Company"}]}}`. visible_companies is selector context, not blanket permission; every operation still checks its own key/scope. Role detail adds permission_ids. Role assignment GET/PUT responses use role_ids or grants.

Creation returns 201, reads/updates 200 and deletion 204. Errors: 401 invalid login/upstream grant; 403 missing authority/delegation or gateway CSRF/Origin denial; 404 missing/nested ownership mismatch; 409 dependencies, administrator protection or domain constraints; 422 validation; 429 throttling; 503 unavailable identity/provider verification. Error responses are sanitized; clients must not depend on one identical framework/protocol error envelope. No endpoints permit clearing a required location, modifying protected default areas, or bypassing chart/report invariants.
