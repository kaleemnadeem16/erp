[← Decisions](README.md)

# 0088 — Device tokens expire, people manage their devices, and sign-in leaves room for stronger authentication

- **Status:** accepted
- **Date:** 2026-10-06
- **Affects:** Core authentication (sign-in, bearer tokens), the Account screen, deployment housekeeping; resolves Q36 and opens Q37

## Context

[ADR 0005](0005-sanctum-authentication.md) gives the browser a cookie session and a phone or integration a bearer token, chosen by whether the login request carries `device_name`. A session ends after 120 minutes of inactivity. A bearer token did not end at all: `sanctum.expiration` was null, so a token issued today was valid for as long as the account existed unless the password changed, the member was suspended, or that device logged out. A lost phone stayed signed in, and the person had no way to see or end it. No mobile client or integration exists yet, and the development database holds no tokens, so tightening costs nobody anything today.

The owner's brief (2026-10-06): this is a company's most sensitive data. Set 30 days, but design it so that **two-factor authentication (company-configurable, mandatory or not), re-login policies and fingerprint sign-in on mobile can be added later without core changes**; research them properly later.

## Decision

- **Lifetime.** Every bearer token expires 30 days after it is issued and the person signs in again. There is no refresh token, so a lifetime can never be silently extended, and no inactivity timeout yet. The expiry is on each token (`expires_at`) and a hard ceiling (`sanctum.expiration`, 30 days, `SANCTUM_EXPIRATION`) also covers any token issued without its own, so tokens that exist when this ships expire 30 days after they were created. Browser sessions are unchanged.
- **One place decides.** `DeviceTokenPolicy` returns the lifetime and abilities for a person; today the same answer for everyone from `config/signin.php`, clamped to the ceiling. `IssueDeviceToken` is the only code that creates a bearer token and always asks the policy. A shorter lifetime for some roles or companies, "sign in every time", or a narrow-ability service credential is a change to the policy, not to sign-in.
- **A seam for stronger authentication.** Sign-in asks a registry (`SignInRequirements`) whether anything more than a correct password is required, before any session or token is issued and before the attempt is recorded as a login. A requirement implements `SignInRequirement` and returns the challenge still unmet, or null when satisfied or not applicable (for example, the company has not switched it on). It registers from its own service provider, the way modules register permissions and routes. Nothing is registered today, so behaviour is unchanged; a stand-in requirement in the tests proves the seam stops both session and token sign-in. The 401 response shape (`message`, `challenge.type`, `challenge.data`) is **provisional** until Q37 is researched.
- **Record how a device authenticated.** Each token stores `meta`: `auth_methods` (`["password"]` today; a second factor would add to it), `authenticated_at`, address and client. A later rule such as "a sensitive action needs a recent second factor" reads it; nothing has to be reconstructed.
- **Devices are the person's own to manage.** `GET /account/devices` lists the caller's live bearer tokens (name, issued, last used, expires, whether it is this one, client) and `DELETE /account/devices/{id}` signs one out; the query starts from the caller's tokens, so another person's device is a 404. The Account screen shows it. Browser sessions are not listed.
- **Bearer sign-in stays available**, now with expiry, listing and revocation (mobile development and integration testing need it; sign-in remains throttled to 5 a minute). A switch (`ERP_BEARER_SIGN_IN`, default on) turns `device_name` sign-in off without a release should it ever be needed.
- **Housekeeping.** `sanctum:prune-expired` runs daily from the scheduler. An expired token is refused at the door regardless; pruning only deletes rows.
- **Not built, by decision (Q37):** two-factor (TOTP, passkeys/WebAuthn), per-company mandatory policy, re-authentication or step-up for sensitive actions, biometric unlock on mobile, service credentials for integrations, inactivity expiry, and a security-event audit trail for sign-ins and device changes.

## How the deferred capabilities plug in

| Later capability | Where it goes | Core change |
|---|---|---|
| Two-factor (TOTP, passkey) | A `SignInRequirement` registered by its own module; a second endpoint completes the challenge and calls `IssueDeviceToken` with `['password','totp']` | None to sign-in, token or policy |
| Mandatory per company (or optional) | The requirement reads the company setting inside `challenge()` and returns null when not required | None |
| Shorter lifetime / sign in every time | `DeviceTokenPolicy` reads the company or role rule | None |
| Fingerprint on mobile | A client-side unlock of the stored token; the server demands fresh strong authentication where needed using `authenticated_at` and `auth_methods` | None |
| Integration credentials | `DeviceTokenPolicy` returns narrow abilities and a distinct lifetime for a service principal | None |

The one thing deliberately left open is the **challenge-completion protocol** (what the client sends back, whether a pending sign-in is a short-lived server state). That is exactly what the research should decide, so it is not fixed here.

## Consequences

- **Positive:** a lost or forgotten device stops working on its own after 30 days and can be ended sooner by its owner; nothing about the ceiling can be bypassed by omitting a per-token expiry.
- **Positive:** the later capabilities need no change to sign-in, token creation or the policy, only new registered pieces.
- **Negative:** a device signs in again every 30 days. Tokens in use when this ships expire 30 days after their creation, so field staff on an old token are asked to sign in sooner than 30 days from release. Before release, count tokens in production (`erp:audit-access-inventory` lists them per member); the development database has none.
- **Negative:** until Q37 is decided there is no second factor; the protection is the password, the throttle and the expiry.
- **Requires:** the scheduler runs on deployment (it does, as a service). It had no tasks before this one.

## Alternatives considered

**Rely on `sanctum.expiration` alone** — rejected: one number for everyone cannot become per-company or per-role, which is the point of the policy.

**Build two-factor now** — rejected by the owner: it deserves proper research first, and the seam makes that research an addition.

**Refresh tokens** — rejected: a refresh token recreates an unlimited lifetime with extra steps. Renewal is a fresh sign-in.

**Switch bearer sign-in off until Phase 7** — rejected as the default: it blocks mobile development and integration testing and adds nothing once tokens expire, can be listed and are revocable; the switch remains for the day it is wanted.

**Inactivity expiry now** — deferred: it needs a decision about offline use that the mobile requirements will give.

## Research basis

No new comparison was needed for what is built. [ADR 0005](0005-sanctum-authentication.md) fixes the two client modes; Laravel's [Sanctum token expiration](https://laravel.com/docs/12.x/sanctum#token-expiration) supports a global ceiling and a per-token expiry, which this uses, and says nothing about a lifetime; 30 days is the owner's product choice. Two-factor, step-up and mobile re-authentication are consequential, security-sensitive behaviours with materially different solutions in mature ERPs, so under the [research rule](../architecture/research-and-design.md) they need their own bounded, primary-source comparison before anything is built, and the owner has asked for that to happen later. It is recorded as Q37 so it is not forgotten.

## Related

- [Open questions](../OPEN-QUESTIONS.md#q37--two-factor-step-up-and-mobile-re-authentication)
- [ADR 0005](0005-sanctum-authentication.md), [authorisation](../architecture/authorization.md), [access audit](../architecture/access-security-audit.md)
