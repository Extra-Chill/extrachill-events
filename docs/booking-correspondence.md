# Booking correspondence

Venue booking configuration contains a versioned `correspondence` section with a booking address, fixed variable schema, three plain-text templates, and reminder policies. Updates use the existing venue-scoped revision lock and atomic audit record.

`extrachill/preview-booking-correspondence-template` requires venue authorization and an expected template version. Rendering is a single non-recursive replacement pass over allowlisted `{{variable}}` tokens. Unknown variables, subject newlines, unsupported template keys, stale versions, and invalid reminder statuses are rejected.

End-to-end email threading and inbound attachment ingestion remain blocked by [Data Machine #2992](https://github.com/Extra-Chill/data-machine/issues/2992). Events must not invent a second transport or mailbox. The blocker must first provide queued-send threading headers, durable outbound Message-ID receipts, opaque inbound provider references, and private attachment handoffs. Extra-Chill/extrachill-events#313 stays open until that contract is available and the remaining ingestion, matching, suppression, and reconciliation work is complete.
