# AI Document Processing (v2.0.1)

Turn an uploaded evidence file into structured data a human can
trust: text extraction, document classification, field
extraction, shipment matching, duplicate detection, and a review
queue where a person approves, corrects, or rejects what the
machine found. Every extracted value carries a confidence score,
and a human-verified value is what downstream features use. The
extraction never silently overwrites a verified value.

## The honest limits, first

- **There is no live OCR service.** No OCR API key exists and
  no external service is called. The extraction runs behind an
  `OcrProvider` interface (`App\Contracts\OcrProvider`) with
  one implementation, the local provider
  (`App\Services\LocalOcrProvider`), chosen by
  `config/documents.php` (`OCR_PROVIDER`, default `local`).
  A cloud OCR provider is a later provider swap: setting
  `OCR_PROVIDER=cloud` today is rejected outright, so a
  misconfigured driver fails loudly instead of silently
  returning no text.
- **The local provider does not do OCR on scanned images.**
  It reads text a document already carries: plain text and CSV
  verbatim, and the text layer of a PDF (including
  FlateDecode streams). A scanned image, a photo of a paper
  document, or a PDF with no text layer yields no text, and
  the extraction is recorded as `failed` with the reason
  stated. Never claim OCR accuracy on scanned images: nothing
  here measures it, and nothing here can do it.
- **Classification and extraction are rule-based, not a
  model.** They match keywords and patterns in the extracted
  text. They do not understand a document, and they make the
  mistakes pattern matching makes. That is why every field
  carries a confidence score and why low-confidence results
  go to a human.
- **Accuracy is measured, never benchmarked.** The accuracy
  endpoint reports agreement between extracted and
  human-verified values only. With no verifications, the rate
  is null, not zero and not an estimate.

## How a document is processed

Processing starts from an evidence file already uploaded to
the organization (`POST /documents` with
`evidence_file_id`). Processing the same evidence file twice
returns the existing extraction: it is idempotent, and the
original upload is never re-read as a second document.

1. **Read and hash.** The stored file is read and hashed
   (SHA-256). If the stored checksum does not match the
   checksum recorded at upload, the extraction fails with
   that reason stated.
2. **Extract text.** The configured OCR provider extracts
   text. No text layer, no text: the extraction fails with
   the provider's reason.
3. **Classify.** See below.
4. **Extract fields.** See below.
5. **Extract and match the shipment reference.** The
   v1.3.0 `ShipmentReferenceExtractor` is reused, not
   duplicated: references are pulled from the extracted text
   and matched against the organization's shipments.
6. **Detect duplicates.** See below.
7. **Decide the status.** `completed` when nothing needs a
   person; `needs_verification` when any of these is true:
   the document is unclassified, the shipment match is a
   candidate rather than exact, the file is an exact or a
   near duplicate, or any field's confidence is below the
   70.00 threshold. A queued extraction sends a
   `document.needs_verification` notification to the
   organization members who can manage document
   processing.

## Classification

`App\Services\DocumentClassifier` scores the extracted text
against weighted keyword lists for six types — bill of
lading, proof of delivery, invoice, rate confirmation,
detention notice, other — plus the filename as a hint
(+0.10 when the filename contains the type's hint word).
Each type's score is the matched keyword weight divided by
the type's total keyword weight, rounded to four decimals.

- The best-scoring type wins when its score reaches the
  **0.25 threshold**; its confidence is the score as a
  percentage (two decimals).
- Below the threshold, a document that still looks like a
  document (enough words, some structure) is classified
  `other` at a flat **50.00** confidence: the classifier is
  saying "a document, but not one I recognize".
- Anything else is `unclassified` at **0.00** confidence.
  An unclassified document is never forced into a type; it
  goes to the verification queue, where a human names the
  type (or rejects the extraction).

## Field extraction

`App\Services\DocumentFieldExtractor` pulls structured
fields per document type from the extracted text with
patterns: amounts, dates (ISO, US, and month-name forms,
normalized to ISO dates), references, and parties. Every
field carries a confidence score from its match quality:

- **95** — labelled match, e.g. `Detention amount: $450.00`
  next to its label.
- **80** — proximity match, the value near its label.
- **60** — bare match, the pattern found with no label
  nearby.

Amounts are stored to two decimals; dates to ISO format.
A shipment-reference field is added whenever a reference
was extracted from the text: confidence **95** when the
reference matched a shipment exactly, **60** when it only
produced candidates. Fields below the **70.00**
low-confidence threshold flag the extraction for
verification.

## Shipment matching

Matching is exact-match first, then ranked candidates, and
it never auto-links on a weak match.

- **Exact match** (confidence **100.00**, method `exact`):
  the extracted reference matches one shipment's internal
  or external load reference. The shipment is linked
  automatically.
- **Candidates** (method `candidate`, no link, no
  confidence): the reference is close to one or more
  shipments but not exact. The candidates are listed on the
  extraction, and the extraction goes to the verification
  queue for a human to link or dismiss.
- **No match** (method `none`): nothing is linked.

A human can always link a shipment by hand
(`link-shipment`, confidence 100.00, method `manual`) or
dismiss the match (`dismiss-match`: this document belongs
to no shipment). Either decision must be made before the
extraction can be verified when a candidate match is
outstanding.

## Duplicate detection

Two checks, both flagging, neither deleting anything:

- **Exact duplicate:** the SHA-256 content hash matches an
  earlier extraction in the same organization. The later
  extraction is flagged `is_duplicate` with `duplicate_of_id`
  pointing at the earliest one, and goes to the verification
  queue.
- **Near duplicate:** the same shipment reference, amount,
  and date as an earlier extraction, with different content.
  Flagged with `near_duplicate_of_id` and a reason naming
  the earlier file, and sent to the verification queue: only
  a human decides it is the same document twice.

Duplicates are flagged, never auto-deleted: the same
document can legitimately be re-uploaded, and only a human
decides it is the same document twice.

## Confidence scoring, stated as a formula

There is no single document score. Three numbers are
reported, each with its own formula:

- **Classification confidence:** the winning type's
  keyword score × 100 (two decimals); `other` is a flat
  50.00; `unclassified` is 0.00.
- **Field confidence:** 95 / 80 / 60 by match quality
  (labelled / proximity / bare); the shipment-reference
  field is 95 on an exact match, 60 on candidates.
- **Match confidence:** 100.00 on an exact match, 100.00
  on a manual link, null otherwise (a candidate match has
  no confidence because it is not a match).

The low-confidence threshold is 70.00: any field below it
sends the extraction to verification. No precision is
invented beyond these rules.

## Human verification

The review queue (`GET /documents/queue`, oldest first)
lists extractions with `needs_verification` status and
their confidences. A member with `manage-document-processing`
(owner, admin, operations manager, dispatcher) can:

- **Verify a field** (`decision: verified`): the extracted
  value stands. `corrected`: the human supplies the right
  value, which is stored as `verified_value` alongside the
  extracted value — the extracted value itself is never
  edited, so the disagreement stays visible. `rejected`:
  the value is unusable and no replacement is recorded.
  A field that already has a decision can be decided again;
  the latest human decision stands.
- **Verify the extraction:** only when every field has a
  decision and any candidate match has been linked or
  dismissed. An optional `classification` may be supplied
  when the classifier could not name the document; it must
  be a known type, never `unclassified`.
- **Reject the extraction:** with a reason; a verified
  extraction cannot be rejected, and a rejected extraction
  cannot be verified.

A human-verified value is what downstream features use.
Extraction never silently overwrites a verified value: the
extracted value is stored once, and corrections live in
`verified_value`.

## Extraction accuracy monitoring

`GET /documents/accuracy` reports measured agreement
between extracted and human-verified values, and nothing
else:

- **Overall:** decided fields, agreed fields, and
  `accuracy = agreed / decided × 100` (two decimals).
  A field counts as agreed when the human verified it as
  extracted, or corrected it to the same value; a corrected
  field with a different value and a rejected field count
  as disagreed.
- **By field name** and **by classification:** the same
  three numbers per group.
- **Counts:** total extractions, needing verification,
  verified extractions, duplicates, decided fields.

When no fields have been decided, `accuracy` is **null** in
every group: no verifications, no rate. This endpoint
reports what humans actually decided. It is not a benchmark
and it is not a claim about documents nobody reviewed.

## Permissions and plan gate

- Reading extractions, the queue, and accuracy needs any
  organization member; the `manage-document-processing`
  permission (owner, admin, operations manager, dispatcher)
  is needed to process a document, decide on a field, verify
  or reject an extraction, link a shipment, or dismiss a
  match.
- Document processing is a paid-plan feature: the Free plan
  is refused with **402** on every write endpoint
  (`authorizeDocumentProcessing`). Reading is not gated.
- One notification type is added: `document.needs_verification`,
  sent when an extraction lands in the review queue.

## API

See docs/API.md ("Document Processing (v2.0.1)") and
docs/openapi.yaml (tag `DocumentProcessing`). The frontend
review interface lives at `/documents`.
