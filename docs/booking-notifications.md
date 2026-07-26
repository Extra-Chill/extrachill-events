# Booking Notifications

Booking lifecycle events use Extra Chill Users `ec_users_notify_with_receipts()` directly. Every delivery payload includes the `extrachill-events-booking` producer and a stable source-activity idempotency key, so retries converge on the Users table's per-recipient delivery receipt. Events does not own a parallel notification table.

Each event definition owns an explicit recipient policy. New inquiries notify active venue owners. Assignment changes, hold expiry, and event handoff failures notify active owners plus the current event assignee. Information received notifies the active assignee. Revoked, invited, cross-venue, missing, and unrelated active members are excluded while the exact venue membership range and booking are locked.

Outbox recovery remains append-only and bounded. Dependency and partial-receipt failures append durable attempts. Five unsuccessful attempts produce a terminal `delivery_poisoned` suppression, allowing newer requests to advance instead of remaining behind a permanently failing first page. Successful and exact replay receipts remain independently idempotent from correspondence delivery.
