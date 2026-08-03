# Architecture decision: local-only creative access

**Status:** Accepted  
**Date:** 2026-07-28

## Context

VideoAuto currently stores creative truth in project files on the local filesystem. The application has authentication infrastructure for framework features, but creative projects do not have users, owners, sharing rules, or file-level authorization semantics. Simply adding the `auth` middleware would therefore make the routes require a login without defining who may read or change each creative project.

Creative routes can also generate files and may later invoke cost-bearing providers when a person explicitly enables them. Exposing those routes on a network before ownership and authorization are designed would create an avoidable data and cost boundary.

## Decision

The creative workspace is strictly local-only for the current product stage.

Every creative HTTP route, including `/`, is guarded by `EnsureCreativeLocalAccess`. The middleware:

- accepts only a configured access mode of `local`;
- reads the direct connection address from `REMOTE_ADDR`;
- accepts IPv4 loopback (`127.0.0.0/8`) and IPv6 loopback (`::1`) only;
- accepts only `localhost`, IPv4-loopback, or `[::1]` request hosts and, when the `Host` header includes a port, requires `CREATIVE_LOCAL_PORT` (default `8000`);
- rejects state-changing requests when an `Origin` or `Referer` header identifies a different scheme, host, or port, while allowing same-host local tools that send neither header;
- fails closed with `403 Forbidden` for a non-loopback request;
- fails closed with `503 Service Unavailable` for an unsupported access mode.

It validates the direct `Host` header as an additional DNS-rebinding boundary, but never treats it as proof of locality without the direct loopback peer check. Forwarded host, address, and protocol headers and `APP_ENV` are not trusted. `CREATIVE_ACCESS_MODE=local` and `CREATIVE_LOCAL_PORT=8000` are the documented defaults. Dashboard and settings routes retain their existing authentication middleware and are not placed behind the creative local-access middleware.

## Consequences

- A browser on another device cannot use the creative workspace, even if the Laravel server is accidentally bound to a network interface.
- Creative files remain outside relational user ownership for now.
- Existing Laravel authentication remains available for dashboard and settings behavior, but it does not imply creative-project authorization.
- A reverse proxy, container gateway, or tunnel whose direct peer is not loopback will be rejected. Foreign hosts and cross-origin writes are also rejected. A relay terminating on this machine with an allowed local host can still appear as a loopback peer, so local-only deployments must bind to loopback and must not publish the app through a public proxy or tunnel.
- Local processes on the same machine remain inside the trust boundary.
- The same boundary covers automatic decisions, local render creation/cancellation, performance recording, and project learning summaries; none is left on an unguarded creative route.
- Deterministic media and rendering remain local subprocess/file operations. The local-access decision does not authorize provider use: automatic mode and local delivery retain their separate zero-API boundary.

## Future authenticated mode

Remote access requires a new architecture decision and implementation. At minimum it must define project ownership, authorization for every read and mutation, provider-spend permissions, audit identity, CSRF/session behavior, trusted-proxy handling, and migration of existing unowned filesystem projects. Until those requirements are implemented and tested, setting another access mode does not enable remote access; it disables the creative routes.

## Verification

Feature tests prove that IPv4 and IPv6 loopback requests with `localhost`, IPv4, and bracketed IPv6 hosts can open a creative page; foreign hosts, wrong explicit ports, remote peers, and cross-origin writes are rejected; same-origin browser writes and headerless same-host tool writes remain available; every named creative route uses the local middleware; unsupported modes fail closed; and dashboard/settings keep their existing authentication behavior. The tests force the simulation generation driver and Laravel's shared test harness rejects stray HTTP requests.
