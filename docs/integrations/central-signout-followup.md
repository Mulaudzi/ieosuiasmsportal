# Central sign-out follow-up

SMS now revokes its local token and performs a hard navigation to the public home
page, rather than letting the protected route redirect immediately to SSO.
This is application sign-out, not central-session termination.

Confirmed in central auth source: `/logout` is POST-only and CSRF-protected;
`handleAuthorize()` automatically issues a code for an existing identity and does
not implement `prompt=login`. Discovery advertises no end-session endpoint.
Do not invent a GET logout URL or remove CSRF protection.

Required central change: implement an authenticated, CSRF-protected logout flow
reachable by each registered application, validate return URLs against client
registration, clear identity/pending-login/authorization sessions and cookies,
and honor `prompt=login` with genuine fresh authentication (including admin PIN).
Preserve PKCE, state and application access checks. Update SMS and Invoice clients
to use the supported central logout flow after revoking their application tokens.

Acceptance: one sign-out ends the intended session, back/refresh does not restore
it, stale application tokens receive 401, next explicit login requests credentials,
and an attacker-controlled return URL is rejected. Test customer/admin and two tabs.
