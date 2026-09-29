---
name: wallet-passes
description: Building and updating Apple Wallet (.pkpass) and Google Wallet loyalty passes for punchcard enrollments. Use when working on pass generation, the Apple pass web service endpoints, APNs/Google update pushes, pass design, or the "Add to Wallet" buttons.
---

# Wallet passes

The wallet pass is the customer's primary card: it shows progress, the reward and a member QR, and it
updates on the lock screen after each stamp. One pass per `card_enrollments` row, tracked in `wallet_passes`
(`platform`, `serial`, `auth_token`, `push_token`, `device_library_id`, `updated_at`).

Always verify details against the current Apple and Google docs before shipping; both platforms change
limits and relevance rules between OS versions.

## Apple Wallet

- Style: `storeCard`. Required images: `icon.png` (+ @2x/@3x); use `logo.png` and a `strip.png` rendered per enrollment showing the stamp grid (generate server-side, cache by `enrollment_id + current_stamps + card design hash`).
- `pass.json` essentials: `formatVersion: 1`, `passTypeIdentifier`, `teamIdentifier`, `serialNumber` (random, not the DB id), `organizationName`, `description`, `webServiceURL` (HTTPS, e.g. `https://app.<domain>/wallet/apple`), `authenticationToken` (≥ 16 random chars, per pass), `barcodes: [{ format: PKBarcodeFormatQR, message: <signed member token>, messageEncoding: iso-8859-1 }]`, colors as `rgb(r, g, b)`.
- Put progress in a field with `changeMessage: "%@"` so updates show a lock-screen notification ("7 of 10 stamps").
- Package: all files + `manifest.json` (SHA-1 of each file) + `signature` = detached PKCS#7 of the manifest signed with the Pass Type ID certificate, including Apple's WWDR intermediate. Build the zip in memory; never write certificates into the repo (`storage/app/private/wallet-certs/`, read via config).
- Web service routes (all under `webServiceURL`, `Authorization: ApplePass <authenticationToken>`):
    - `POST /v1/devices/{device}/registrations/{passTypeId}/{serial}` body `{pushToken}` → 201 new, 200 existing
    - `GET /v1/devices/{device}/registrations/{passTypeId}?passesUpdatedSince=<tag>` → `{serialNumbers, lastUpdated}` or 204
    - `GET /v1/passes/{passTypeId}/{serial}` → latest `.pkpass`, honour `If-Modified-Since` (304)
    - `DELETE /v1/devices/{device}/registrations/{passTypeId}/{serial}`
    - `POST /v1/log` → log and 200
- Update push: HTTP/2 to APNs, empty JSON payload `{}`, `apns-topic` = pass type id, authenticated with the pass certificate. Wallet then calls the endpoints above. Do it in a queued job after the stamp transaction commits.
- Location relevance: add the business locations (max 10) with `relevantText`. Check current iOS behaviour; it has changed across versions.

## Google Wallet

- `LoyaltyClass` per `loyalty_cards` row: id `<issuerId>.card_<id>`, `issuerName`, `programName`, `programLogo`, `hexBackgroundColor`, `reviewStatus: UNDER_REVIEW` on create.
- `LoyaltyObject` per enrollment: id `<issuerId>.enr_<random>`, `classId`, `state: ACTIVE`, `accountName`, `loyaltyPoints.balance.int` + label, `barcode {type: QR_CODE, value: <member token>}`, `textModulesData` for the reward, `locations`.
- Save button: RS256 JWT signed with the service account, `aud: google`, `typ: savetowallet`, `origins: [app origin]`, payload `{loyaltyObjects: [...]}` → `https://pay.google.com/gp/v/save/<jwt>`.
- Updates: `PATCH walletobjects/v1/loyaltyObject/{id}`. For a visible notification use `addMessage` with `messageType: TEXT_AND_NOTIFY`, which Google rate-limits per object per day; do not use it on every stamp, only reward unlocked and campaigns.

## Rules

- Member token in any barcode: signed, contains enrollment/user id and issue time, verified server-side; never a raw id.
- Passes are regenerated from DB state, never patched blindly. A failed push is retried with backoff and never blocks stamping.
- Test pass JSON/object building with pure unit tests; mock APNs and Google HTTP in feature tests.
