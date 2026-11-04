# Login API and browser gateway contracts

## Conventions

Laravel base path is `/api/login` on the application origin `http://localhost:8081`. Public source route parameters bind by immutable **slug**, even though Member stores numeric `user_source_id`. Send `Accept: application/json`; JSON POST bodies use `Content-Type: application/json`. Provider backchannel logout also accepts the standard form body.

Protected API calls authenticate only with `Authorization: Bearer <sanctum-token>`. An application cookie does not authenticate Laravel. For browsers, Nuxt handles credentials and adds this header internally; browser code must not call the callback API to obtain/store a token itself.

Successful object responses normally use HTTP 200. Token/transaction responses are sensitive server-to-server protocol data and must not be cached or logged. Examples below use placeholders, never working credentials. There is no endpoint to register local passwords or auto-create a User at login.

## Laravel endpoints

| Method and path | Authorization / limit | Result |
| --- | --- | --- |
| `GET /sources` | Public; 60/minute | Active source summaries, ordered by title |
| `POST /sources/{slug}/authorize` | Public; 20/minute; browser binding | One authorization URL with source-bound state, nonce and PKCE |
| `POST /sources/{slug}/callback` | Public; 30/minute; code/state/binding proof | Finite Sanctum credential for a verified imported Member |
| `GET /me` | Sanctum plus upstream validity middleware | Sanitized current user/source/member |
| `POST /logout` | Sanctum; 30/minute | Immediate local revocation and bounded RP logout handoff |
| `POST /logout/continue` | Public; 30/minute; handoff/binding proof | Single-use provider POST form parameters |
| `POST /sources/{slug}/backchannel-logout` | Verified provider signature; 120/minute | Scoped grant revocation, `{"status":"ok"}` |

The limits above are Laravel throttle declarations. Nuxt's internal requests use the backend-visible gateway address; a separate per-browser gateway rate limiter is not implemented in this phase. `/me` has no route-specific throttle declaration. `/logout` deliberately does not require a successful upstream validity check, so an upstream outage cannot prevent local revocation when the local credential is valid.

### Source list

`GET /api/login/sources` returns only active source slugs/titles, not numeric source IDs or provider settings:

```json
{"data":[{"slug":"primary","title":"کاربران سازمان"},{"slug":"secondary","title":"کاربران سازمان همکار"}]}
```

### Start authorization

`POST /api/login/sources/{slug}/authorize` body:

```json
{"browser_binding":"<cryptographically-random-browser-proof>"}
```

`browser_binding` is a string of 43–128 characters. Nuxt generates a 48-byte random proof encoded as base64url and keeps it in a protected transaction cookie. Response:

```json
{"authorization_url":"http://localhost:8081/auth/realms/organization-dev/protocol/openid-connect/auth?<oidc-parameters>"}
```

The five-minute Redis transaction records the selected slug, auth configuration fingerprint, binding hash, state/nonce, PKCE verifier and creation time. Inactive sources fail with 401. Provider endpoints and callback target come from trusted source settings.

### Complete callback

`POST /api/login/sources/{slug}/callback` body:

```json
{"state":"<64-alphanumeric-characters>","code":"<provider-authorization-code>","browser_binding":"<same-browser-proof>"}
```

State is exactly 64 ASCII alphanumeric characters; code is a nonempty string, at most 4096 characters. Binding validation and expiry/config/source checks precede single-use consumption. The code exchange and RS256 identity validation must succeed before membership lookup. Unknown membership returns `403 {"error":"member_not_imported"}` without creating User/Member/token records.

Success (consumed only by Nuxt's server or a trusted API consumer):

```json
{"token":"<record-id>|<one-time-plaintext-sanctum-secret>","expires_at":"2026-11-05T00:00:00+00:00"}
```

The token is shown once; Laravel stores its hash. Local expiry is finite and is not extended by upstream refresh. The browser callback is **Nuxt GET `/login/callback/{slug}`**, which calls this POST internally, sets the protected credential cookie, clears the pending cookie and redirects with 303 to `/`. Failure redirects carry a fixed error label, not credentials.

### Current identity

`GET /api/login/me` requires a valid local token with `login:access` and a currently valid upstream grant:

```json
{"data":{"user":{"id":1,"name":"کاربر آزمایشی"},"source":{"slug":"primary","title":"کاربران سازمان"},"member":{"id":1,"uuid":"<external-subject>"}}}
```

The API does not expose UserSource.data, source numeric ID, Sanctum secret or provider tokens. Responses are private/no-store. A source/config/identity mismatch or inactive upstream grant returns 401; unavailable upstream verification returns 503 and retains the otherwise valid local credential.

### Logout and handoff

`POST /api/login/logout` requires Bearer authentication and the same `browser_binding` format used by authorization. It deletes the local token first. Typical response:

```json
{"handoff":"<96-alphanumeric-characters>","state":"<64-random-characters>","remote_logout":"pending"}
```

If the source no longer matches the issued configuration, it returns `{"handoff":null,"remote_logout":"unavailable"}` after local revocation. This is not confirmation of upstream logout.

Within 120 seconds, `POST /api/login/logout/continue` consumes the handoff once:

```json
{"handoff":"<96-alphanumeric-characters>","browser_binding":"<same-browser-proof>"}
```

Response (Nuxt server only):

```json
{"endpoint":"http://localhost:8081/auth/realms/organization-dev/protocol/openid-connect/logout","fields":{"id_token_hint":"<provider-id-token>","client_id":"organization-backend","post_logout_redirect_uri":"http://localhost:8081/login/logged-out","state":"<logout-state>"}}
```

Nuxt removes the credential cookie and navigates to `/login/logout`. That route emits a same-origin POST form with a local auto-submit script and manual-submit fallback. Access/refresh/Sanctum tokens are never form fields. ID-token hints do not enter URL history. `/login/logged-out` checks its protected state cookie and redirects to `/`; unverified completion carries a fixed logout error label.

### Provider backchannel logout

`POST /api/login/sources/{slug}/backchannel-logout` accepts `logout_token=<signed JWT>` (string, maximum 16384 characters). It is authenticated by the OIDC logout-token signature/claims, not a browser cookie or Sanctum token.

Required checks include exact issuer, expected client audience, issue time from five minutes ago to 30 seconds ahead, nonempty `jti`, an empty object at `events["http://schemas.openid.net/event/backchannel-logout"]`, absence of `nonce`, and at least `sid` or `sub`. Session/subject identifiers are bounded strings. Replay markers are source/config scoped and retained for ten minutes. Matching source/session/subject grants are revoked; already processed valid events return success without reapplying revocation.

Invalid logout claims return 400. Signature/decoding failure uses the shared `invalid_identity` 401 error; JWKS/provider availability failure returns 503. Successful notification returns HTTP 200 and `{"status":"ok"}`.

## Nuxt browser contract

| Method and route | Browser-visible response |
| --- | --- |
| `GET /gateway/login/sources` | Same active source-summary envelope |
| `POST /gateway/login/sources/{slug}/authorize` | `{"url":"<same-origin-provider-authorization-url>"}` |
| `GET /gateway/login/me` | Login status, sanitized identity, CSRF proof and `canLogout`; HTTP 200 for represented states |
| `POST /gateway/login/logout` | `{"url":"/login/logout"}` or a local completion/error URL |
| `GET /login/callback/{slug}` | Sets/clears protected cookies and issues a 303 redirect |
| `GET /login/logout` | Same-origin provider logout POST form, or a 303 error redirect |
| `GET /login/logged-out` | Verifies protected logout state and issues a 303 redirect |

Identity gateway shape:

```json
{"status":"authenticated","identity":{"user":{"id":1,"name":"کاربر آزمایشی"},"source":{"slug":"primary","title":"کاربران سازمان"},"member":{"id":1,"uuid":"<external-subject>"}},"csrf":"<browser-csrf-proof>","canLogout":true}
```

`status` is `authenticated`, `anonymous` or `unavailable`. The latter two have `identity:null`. Unavailable verification retains `canLogout:true` when a credential cookie exists, allowing local logout. On SSR, `unavailable` sets the **page** status to 503. Backend 401 clears the credential and becomes anonymous.

Browser POST gateway requests must send exact application `Origin` and `X-CSRF-Token` matching the proof returned for that browser. Nuxt compares it against an encrypted HttpOnly cookie without a session store. The credential, login transaction, CSRF and logout cookies use path `/`, HttpOnly and SameSite=Lax. Credential TTL is bounded by the API expiry; login pending is five minutes, logout pending two minutes and CSRF one day. Secure follows HTTPS with the approved local HTTP exception. The old `organization_session` cookie is expired.

## Errors and infrastructure endpoint

| Status | Meaning |
| --- | --- |
| 400 | Invalid backchannel logout claims |
| 401 | Missing/expired/revoked local credential; invalid transaction/identity/upstream grant; inactive or changed source |
| 403 | Verified but unimported member, or Nuxt Origin/CSRF rejection |
| 404 | Unknown source or route |
| 422 | Request validation errors |
| 429 | Declared rate limit exceeded |
| 503 | Provider/configuration unavailable or refresh lock busy |

Module protocol failures use `{"error":"<machine-code>"}`. Laravel validation uses its normal `message`/`errors` envelope; unauthenticated/unknown-route responses use framework JSON. Do not assume every failure has the same envelope. Nuxt sanitizes forwarded errors and never forwards raw provider credentials/error bodies.

`GET /api/health` is a separate readiness API checking PostgreSQL/Redis and returning infrastructure status. `/up` is framework health routing. The former session prototype `/api/auth/*` is removed. This phase adds no other business APIs.
