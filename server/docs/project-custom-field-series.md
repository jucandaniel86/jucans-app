# Numeric Custom Field series

Authenticated read endpoint:

`GET /api/projects/{project}/custom-fields/{field}/series`

Parameters: `from`, `to` (inclusive `YYYY-MM-DD`), optional `note_type_id`,
`interval=raw|day|week|month` (default day), `page` (1–1000000),
`per_page` (1–100, default 50; used only in raw mode).
Omitted dates independently default to UTC today minus 29 days and UTC today.
The inclusive maximum range is 366 calendar days. No observation truncation.
Raw order is observation date then note ID ascending. Grouped modes use the
complete eligible dataset, never a raw page. All eligible history is checked
for incompatibilities before returning even a raw page.

Requires the existing Phase 1/2 schema and PHP BCMath on the serving runtime.
No migrations or new dependencies. The local CLI currently provides BCMath;
other PHP runtimes must enable it. Missing BCMath returns HTTP 503.

Response shape (illustrative, not created records):

```json
{
  "data": {
    "project_id": 1,
    "field": {"id": 2, "name": "Distance", "unit": "km", "archived_at": null},
    "aggregation": "average",
    "interval": "day",
    "timezone": "UTC",
    "date_convention": "noted_at calendar date; UTC determines default today; Monday-start weeks",
    "range": {"from": "2026-11-01", "to": "2026-11-01", "max_days": 366},
    "note_type_id": null,
    "observation_count": 2,
    "pagination": null,
    "points": [{
      "id": "2026-11-01", "date": "2026-11-01",
      "bucket_from": "2026-11-01", "bucket_to": "2026-11-01",
      "included_from": "2026-11-01", "included_to": "2026-11-01",
      "partial": false, "count": 2, "value": "1.250000"
    }]
  }
}
```

Raw points instead contain `id` (value-row ID string), `note_id`, `date`,
`value` and `count: 1`. Raw pagination contains `page`, `per_page`, `total`
and `last_page`. Empty raw results have one empty page. Out-of-range pages
return validation errors. Filters changing on the client reset to page one.

Notes are project-scoped, visible through the existing `visibleTo(user)`
scope, and excluded by normal soft-delete rules. Current field attachments
are irrelevant. Archived fields/types remain readable. Legacy notes and null
values do not contribute; zero contributes. Counts and diagnostics use only
eligible notes. No note text is parsed or returned.

Non-null saved observations must have numeric saved and captured types, an
unambiguous schema-v1 field definition with matching field/project IDs, and
a captured unit strictly equal to the selected field's unit. Null and empty
units differ. Conflicting/missing/malformed history blocks the whole series:
HTTP 409 with `code: incompatible_field_history`, `errors.history`, and
`diagnostics` containing the total conflict count and up to 20 examples
(note ID, field ID, reason). No conversion or silent skipping occurs.

Current aggregation applies uniformly; null means average. Aggregation
changes recalculate reports, never rewrite observations. BCMath retains exact
six-place decimal sums beyond individual storage bounds. Average is rounded
to six decimal places, half away from zero. Min/max use exact comparison;
latest uses greatest `(noted_at, note_id)`, without inventing observation times.
All output numbers are decimal strings. Empty buckets are null/count zero,
including sum. Weeks start Monday and months follow calendar boundaries;
filtering precedes grouping and boundary buckets expose their included dates.

The independent frontend section uses transient selections, explicit raw
pagination, archived labels, exact details and stale-response protection.
The dedicated SVG uses calendar positions, negative/constant scaling and
null gaps. Raw observations share an x-position on the same date and have
no connecting line. Overlapping points can be selected individually through
the accessible exact-values selector. Numbers are approximate only for SVG
coordinates; at very close magnitudes, distinct exact values can visually
overlap. Existing task charts are unchanged.

Reports reflect current saved observations and current permissions. Separate
page requests are not a frozen historical export; concurrent edits may change
pagination between requests. The range is bounded, but grouped reports still
scan all eligible observations in that range in chunks of 200 notes.

No tests or browser/manual scenarios were performed for this implementation.
