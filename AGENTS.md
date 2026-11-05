# Project Development Rules

## Required stack and repository

- Maintain one monorepo for the integrated organizational system.
- Use Laravel for the backend, Redis for caching, PostgreSQL for relational data, Keycloak for identity and user management, Camunda for process management, and Nuxt with Vuetify for the frontend.
- Keycloak owns credentials and identity lifecycle. Support multiple independently configured Keycloak user sources. Do not introduce independent application password management without an approved phase design.

## Phase approval and planning

- Before creating or replacing `PLAN.md` for a new phase, follow the Git workflow below and create its feature branch first.
- Define each phase before implementation: its subject, scope, implementation method, database design, ordered steps, and acceptance criteria.
- Obtain the user's explicit approval of that phase plan before writing application code or implementing infrastructure.
- Maintain exactly one current plan at the repository root: `PLAN.md`. Write it in English with complete phase details and execution steps. Do not create competing active plans.
- Commit every update to `PLAN.md`, including progress and approval status changes.
- After completing each implementation step, tell the user exactly which step has completed out of the total, for example: "Step 3 of 8 completed." Use Persian when communicating with the user.
- Do not interpret plan approval as approval of the completed phase.

## Git workflow and branch lifecycle

- Use the project's feature-branch workflow with `master` as the main integration branch and `origin/master` as its upstream. This is the project's Gitflow convention; do not introduce a `develop` branch unless the user approves a workflow change.
- Start each new phase with a clean working tree, fetch `origin`, switch to `master`, and update it with `git merge --ff-only origin/master`. If local `master` has diverged, preserve its commits and resolve the divergence explicitly; never reset or force-push `master`.
- Create a descriptive `codex/feature/<phase-name>` branch from the updated `master` **before** creating or replacing the root `PLAN.md`. Then write and commit the plan on that feature branch, obtain explicit plan approval, and continue the phase there.
- Keep all plan updates, implementation, review fixes, verification evidence and approved phase documentation on that feature branch. Commit every plan update and keep backend, frontend, shared infrastructure and documentation commits appropriately scoped.
- Finish implementation and verification, pause for the user's explicit completion approval, and only then create or update the phase documentation and commit the final closeout. Plan approval alone never authorizes integration.
- After approved completion and documentation, ensure the feature branch is clean, fetch `origin` again, and rebase the feature branch onto the latest `origin/master`.
- Resolve any rebase conflicts on the feature branch, stage the resolutions and use `git rebase --continue` to record them in the replayed commits. Commit any additional fixes on the feature branch. Repeat the relevant validation after rebasing and resolving conflicts; never move unresolved conflicts or failing changes into `master`.
- Switch to `master`, update it from `origin/master` with `git merge --ff-only origin/master`, then merge the verified feature branch using `git merge --no-ff <feature-branch>`. If upstream advances before integration or rejects the push, fetch and repeat synchronization, feature-branch rebase and relevant validation before retrying; never force-push `master`.
- Push the merged `master` to `origin`. Only after successful integration and push, delete the local feature branch with `git branch -d <feature-branch>` and delete its remote counterpart if one was published. Do not delete a branch containing unintegrated work.
- Report actual rebase, validation, merge, push and deletion results. If access or repository protection blocks a step, preserve the feature branch and report the remaining work; do not claim the phase has been integrated.

## Review, documentation, and phase completion

- At the end of implementation and verification, pause for the user's review. Allow small revisions and repeat relevant checks.
- Only after the user explicitly approves completion, create or update phase documentation in `docs/`.
- Technical documentation must be English Markdown and cover database changes, business behavior, and API contracts. Explicitly record when a category has no changes.
- Usage and operational guides must be Persian Markdown.
- Commit the final phase changes. Keep frontend and backend changes in separate commits, using multiple commits when useful. Keep shared infrastructure and documentation clearly scoped as well.
- Report actual validation results and any incomplete items honestly. Never claim a commit exists when Git operations are blocked.

## Backend and authentication architecture

- Build the Laravel backend as a modular API. Keep each business module's routes, models, actions/services, requests, resources, migrations, commands, providers and tests together. The current authorized business scope is the Login module only.
- Use Laravel Sanctum API token authentication with `Authorization: Bearer ...`. Do not use Laravel sessions, the `web` guard, or Sanctum's session-based SPA authentication for application login.
- Stateless means no server-side browser login session; Sanctum token persistence/revocation and short-lived OIDC protocol transactions are still required. Do not describe Sanctum personal access tokens as storage-free JWT authentication.
- Use `UserSource`, `Member` and `User` for source configuration, external identity membership and local users. Identify a Keycloak account by `(uuid, user_source_id)`; never assume a subject is unique across sources or automatically merge people by name/email.
- Give UserSource a unique, stable textual `slug`. Use it instead of its numeric ID in public API resources, URLs, commands, callback bindings and provider configuration. Store the numeric `user_source_id` foreign key in Member, referencing `user_sources.id`, with a unique constraint on `(uuid, user_source_id)`. Do not store the source slug in members.
- The login callback must match a pre-existing imported Member and its User before issuing an application token. Do not auto-register unknown identities during callback.
- Nuxt must resolve authentication on the server before rendering the initial HTML. Do not render an anonymous login control first and replace it after a mounted/client-only authentication check.
- Keep Laravel authentication bearer-only. If Nuxt uses a protected browser cookie to transport credentials for SSR, exchange it for a Bearer header only on the Nuxt server; do not introduce a Laravel authentication session or expose bearer credentials in browser storage, URLs or SSR payloads.
- Present active user sources in a frontend selection dialog when Login is clicked.
- Synchronize application logout and the originating Keycloak login using verified OIDC logout mechanisms and current upstream validity checks. Never rely only on local token expiration to detect upstream logout.

## Frontend rules

- Use Vuetify and the Vazirmatn font.
- Do not write styles inside Vue components: no `<style>` blocks, inline `style` attributes, or JavaScript style bindings. Keep custom CSS and theme styling in dedicated project files; classes and Vuetify component APIs may be used in templates.
- Store all icons, images, CSS, and fonts locally in the project. Do not use CDN links or runtime third-party asset downloads.
- Support light, dark, and automatic theme modes. Automatic mode follows the device theme, including changes while the application is open.
- Design for a formal organization and long daily working sessions: restrained colors, readable Persian typography, RTL layout, accessible contrast, consistent spacing, clear focus states, and simple interactions.

## Development infrastructure requirements

- Provide Docker-based development services connected through private Docker networking and service DNS names.
- Nginx on host port `8081` is the application's public gateway. Application navigation, API calls, login, logout, and authentication callbacks must remain on this origin; never redirect application users to another service port.
- Run Laravel with `php artisan serve --host=0.0.0.0 --port=8000` inside its container.
- Run Nuxt with `npm run dev`, listening on `0.0.0.0:3000` inside its container.
- Expose pgAdmin management on host port `8082`, Keycloak management on `8083`, and Camunda management on `8084`.
- Management ports are additional entry points, not application authentication endpoints. Keep the main application's authentication flow on Nginx port `8081`.
- Do not publish backend, frontend, database, Redis, or process-engine internal ports directly unless the user approves a plan change.
- Provide reproducible dependency versions, persistent development data, environment examples without real secrets, health checks, and working hot reload.
