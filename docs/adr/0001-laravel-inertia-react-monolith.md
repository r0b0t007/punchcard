# 0001. One Laravel + Inertia React monolith

- Status: accepted
- Date: 2026-09-29

## Context

punchcard has five surfaces: marketing site, customer PWA, business PWA (owner + staff), the NFC tap endpoint and a super-admin. One developer builds it. The tap flow depends on a first-party session cookie in Safari.

## Decision

A single Laravel 13 application with Inertia 3 + React 19 + TypeScript, started from `laravel/react-starter-kit`. Filament for the super-admin. Business logic in `app/Actions`. Single PostgreSQL database with a `business_id` global scope (customers belong to many businesses, so per-tenant databases would not fit).

## Consequences

- One repo, one deploy, one auth system; session cookies work for the tap flow without CORS or token handling.
- Heavy work must go to queues to keep the tap response fast.
- If a native app is ever needed, expose the same Actions through a versioned JSON API.
