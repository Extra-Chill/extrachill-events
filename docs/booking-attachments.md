# Booking Attachments

Booking attachment metadata and policy belong to Extra Chill Events. The bytes do not.

## Existing Primitives Audited

- WordPress `wp_handle_upload()` and `wp_upload_bits()` write beneath public uploads and return public URLs. `wp_check_filetype_and_ext()` is useful for admission-time extension/content validation, but WordPress media is not a private authorization boundary.
- Data Machine `FileStorage` owns flow/job files beneath `datamachine-files`, intentionally exposes `get_public_url()`, and deletes old flow files by mtime. It has no opaque private object identity, claim/finalization, authorized stream handoff, reference-aware deletion, or domain retention hold.
- Event submission flyer handling temporarily uses WordPress upload handling and copies the flyer into the Data Machine flow-file repository. That is appropriate for public event artwork, not contracts, insurance, riders, or tax documents.
- Booking metadata, activity, lifecycle, and exact venue authorization already live in site-scoped transactional tables in this plugin.

The missing generic private-object capability is proposed in `Extra-Chill/data-machine#2966`. This is not a claim that Data Machine's existing public workflow-file contract is defective. A generic implementation requires owner intent and consumer evidence.

## Privacy Model

`ec_booking_attachments` stores an opaque storage reference plus trusted filename, detected MIME type, byte size, SHA-256 hash, uploader attribution, optional canonical artist references, lifecycle state, and timestamps. Public paths, public URLs, download tokens, and filesystem paths are never persisted.

The `BookingPrivateFileProvider` boundary has no production fallback. Until an approved provider is registered with `extrachill_events_booking_private_file_provider`, attach, download, and physical cleanup fail with `booking_private_storage_unavailable`. Ordinary WordPress uploads and Data Machine flow files are never used as a fallback.

The provider must atomically claim provisional bytes using the supplied claim key, release failed claims, issue short-lived opaque stream tokens, and retire exact objects. Booking abilities always load the booking first and authorize its stored venue. Attachment operations then require the attachment to belong to that same booking.

## Classification And Limits

- Public/publishable assets: promo images, EPKs, and press releases may intentionally use public media elsewhere. References attached to a booking through this private contract are still treated as private.
- Private operational evidence: stage plots, technical riders, hospitality riders, insurance, contracts, and other private evidence require the private provider.
- Tax identity documents: W-9-like filenames are default-denied. SSN/EIN-bearing forms require a separately approved secure vault, access policy, and retention policy.
- Maximum object size: 20 MiB.
- Allowed content: JPEG, PNG, WebP, PDF, DOCX, XLSX, CSV, and plain text. Provider-detected MIME must agree with the filename extension.
- Filenames must already equal WordPress's sanitized basename. SHA-256 metadata is required.

## Lifecycle

Attachment creation is booking-scoped and idempotent. The private object claim is released if metadata validation, metadata insertion, or audit insertion fails. Reuse of one object across bookings is allowed only for an authenticated uploader with the same canonical artist identity.

Replacement creates the new reference first, then transactionally retires the previous metadata as `replaced` with its activity entry; the old record remains as audit evidence. Delete is logical and transactional with its activity entry. Physical cleanup considers only retired records older than the retention window, refuses objects with any active reference, and preserves all non-promotional operational evidence for confirmed or completed bookings.

No download token or storage reference is written to booking activity. Physical cleanup is callable through `BookingAttachmentService::cleanup()` but is not scheduled until the private provider and an owner-approved retention integration exist.

## Future Admission Seam

`attach()` accepts explicit `anonymous`, `user`, `email`, or `system` attribution and an optional inbound reference. This supports future public inquiry and inbound-email adapters without teaching the booking domain about REST or mail transport. Public REST admission, Turnstile, provisional upload orchestration, and request rate limiting remain in `Extra-Chill/extrachill-api#123`.
