<?php

namespace App\Services;

use App\Models\Carrier;
use App\Models\Shipment;
use App\ShipmentStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Two-step CSV import for shipments, plus stored-file runs.
 *
 * Preview parses and validates the file without creating anything and
 * stores the parsed rows server-side under a token. Confirm reads the
 * stored rows back, so the client can never smuggle in rows that were
 * not previewed. Only valid, non-duplicate rows are created, and the
 * summary reports exactly what happened to every selected row.
 *
 * Scheduled imports and sync runs use runFile instead: the same
 * parsing and creation, straight from a file the organization
 * already uploaded, with the full per-row result returned so the
 * caller can record the import run and its rows.
 */
class ShipmentCsvImporter
{
    /**
     * Maximum data rows per file. Larger files must be split.
     */
    public const MAX_ROWS = 1000;

    /**
     * How long a preview token stays usable, in seconds.
     */
    public const PREVIEW_TTL_SECONDS = 1800;

    /**
     * Normalized header text to shipment field.
     *
     * Headers are matched case-insensitively after stripping spaces
     * and punctuation, so "Internal Reference", "internal_reference",
     * and "INTERNALREFERENCE" all map to the same field. The "carrier"
     * entry is a pseudo-field: it resolves a carrier by name within
     * the organization instead of mapping to a column directly.
     *
     * @return array<string, string>
     */
    public static function headerAliases(): array
    {
        return [
            'internalreference' => 'internal_reference',
            'reference' => 'internal_reference',
            'loadid' => 'internal_reference',
            'shipmentreference' => 'internal_reference',
            'externalloadreference' => 'external_load_reference',
            'externalloadref' => 'external_load_reference',
            'loadreference' => 'external_load_reference',
            'brokerload' => 'external_load_reference',
            'brokerorcustomername' => 'broker_or_customer_name',
            'broker' => 'broker_or_customer_name',
            'customer' => 'broker_or_customer_name',
            'brokercustomer' => 'broker_or_customer_name',
            'brokername' => 'broker_or_customer_name',
            'customername' => 'broker_or_customer_name',
            'carrier' => 'carrier',
            'carriername' => 'carrier',
            'drivername' => 'driver_name',
            'driver' => 'driver_name',
            'drivercontact' => 'driver_contact',
            'driverphone' => 'driver_contact',
            'driveremail' => 'driver_contact',
            'pickuplocation' => 'pickup_location',
            'pickup' => 'pickup_location',
            'deliverylocation' => 'delivery_location',
            'delivery' => 'delivery_location',
            'dropofflocation' => 'delivery_location',
            'pickupappointment' => 'pickup_appointment',
            'pickupappt' => 'pickup_appointment',
            'pickuptime' => 'pickup_appointment',
            'pickupdatetime' => 'pickup_appointment',
            'deliveryappointment' => 'delivery_appointment',
            'deliveryappt' => 'delivery_appointment',
            'deliverytime' => 'delivery_appointment',
            'deliverydatetime' => 'delivery_appointment',
            'actualarrivalat' => 'actual_arrival_at',
            'actualarrival' => 'actual_arrival_at',
            'arrivalat' => 'actual_arrival_at',
            'arrival' => 'actual_arrival_at',
            'actualdepartureat' => 'actual_departure_at',
            'actualdeparture' => 'actual_departure_at',
            'departureat' => 'actual_departure_at',
            'departure' => 'actual_departure_at',
            'baserate' => 'base_rate',
            'rate' => 'base_rate',
            'baseamount' => 'base_rate',
            'amount' => 'base_rate',
            'currency' => 'currency',
            'curr' => 'currency',
            'status' => 'status',
            'shipmentstatus' => 'status',
            'notes' => 'notes',
            'note' => 'notes',
            'comments' => 'notes',
        ];
    }

    /**
     * Parse and validate a CSV file without creating any shipments.
     *
     * @param  array<string, string>  $mappingOverrides  Exact header text to field name.
     * @return array{preview_token: string, summary: array{total: int, valid: int, invalid: int, duplicate: int}, columns: array<int, string>, rows: array<int, array{index: int, data: array<string, mixed>, errors: array<string, array<int, string>>, is_duplicate: bool, valid: bool}>}
     */
    public function preview(UploadedFile $file, string $organizationId, array $mappingOverrides = []): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['The file could not be read.']]);
        }

        try {
            $parsed = $this->parseStream($handle, $organizationId, $mappingOverrides);
        } finally {
            fclose($handle);
        }

        $token = Str::random(40);

        Cache::put(
            'shipment_import:'.$token,
            [
                'organization_id' => $organizationId,
                'filename' => $file->getClientOriginalName(),
                'rows' => $parsed['rows'],
            ],
            self::PREVIEW_TTL_SECONDS
        );

        return [
            'preview_token' => $token,
            'summary' => $parsed['summary'],
            'columns' => $parsed['headers'],
            'rows' => $parsed['rows'],
        ];
    }

    /**
     * Create the selected preview rows.
     *
     * Rows come from the server-side preview payload, never from the
     * client. Only valid, non-duplicate rows are created. Creation
     * runs in one transaction: an unexpected failure rolls every row
     * back and the summary reports nothing imported. The returned
     * rows carry the outcome of every selected row, with the created
     * shipment id where one was made, so the caller can record the
     * import run.
     *
     * @param  array<int, int>|null  $rowIndexes  1-based row indexes to import, or null for all valid rows.
     * @return array{imported: int, skipped: int, skipped_rows: array<int, array{index: int, reason: string, is_duplicate: bool, errors: array<string, array<int, string>>}>, rows: array<int, array{index: int, data: array<string, mixed>, errors: array<string, array<int, string>>, is_duplicate: bool, status: string, shipment_id: string|null}>, filename: string|null}
     */
    public function confirm(string $token, string $organizationId, ?array $rowIndexes, int $userId): array
    {
        $preview = Cache::get('shipment_import:'.$token);

        if ($preview === null) {
            throw ValidationException::withMessages(['preview_token' => [
                'The import preview has expired. Upload the file again.',
            ]]);
        }

        if ($preview['organization_id'] !== $organizationId) {
            abort(404);
        }

        $rows = $preview['rows'];
        $selected = $rowIndexes === null
            ? $rows
            : array_values(array_filter(
                $rows,
                fn ($row) => in_array($row['index'], $rowIndexes, true)
            ));

        $created = $this->createRows($selected, $organizationId, $userId);

        Cache::forget('shipment_import:'.$token);

        return [
            'imported' => $created['imported'],
            'skipped' => count($created['skipped_rows']),
            'skipped_rows' => $created['skipped_rows'],
            'rows' => $created['rows'],
            'filename' => $preview['filename'] ?? null,
        ];
    }

    /**
     * Import a file already stored on disk, in one pass.
     *
     * Used by scheduled imports and sync runs: the file is one the
     * organization uploaded when the schedule or sync workflow was
     * created. Parsing, validation, and duplicate detection are the
     * same as the preview path; creation is the same as confirm.
     * The result has the confirm shape, plus the summary, so the
     * caller can record the import run and its rows.
     *
     * @param  array<string, string>  $mappingOverrides  Exact header text to field name.
     * @return array{imported: int, skipped: int, skipped_rows: array<int, array{index: int, reason: string, is_duplicate: bool, errors: array<string, array<int, string>>}>, rows: array<int, array{index: int, data: array<string, mixed>, errors: array<string, array<int, string>>, is_duplicate: bool, status: string, shipment_id: string|null}>, summary: array{total: int, valid: int, invalid: int, duplicate: int}, filename: string|null}
     */
    public function runFile(string $path, string $organizationId, array $mappingOverrides, int $userId, ?string $filename = null): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['The stored file could not be read.']]);
        }

        try {
            $parsed = $this->parseStream($handle, $organizationId, $mappingOverrides);
        } finally {
            fclose($handle);
        }

        $created = $this->createRows($parsed['rows'], $organizationId, $userId);

        return [
            'imported' => $created['imported'],
            'skipped' => count($created['skipped_rows']),
            'skipped_rows' => $created['skipped_rows'],
            'rows' => $created['rows'],
            'summary' => $parsed['summary'],
            'filename' => $filename,
        ];
    }

    /**
     * Parse and validate every data row of an open CSV stream.
     *
     * Shared by preview and runFile. No shipment is created here.
     *
     * @param  resource  $handle
     * @param  array<string, string>  $mappingOverrides
     * @return array{headers: array<int, string>, rows: array<int, array{index: int, data: array<string, mixed>, errors: array<string, array<int, string>>, is_duplicate: bool, valid: bool}>, summary: array{total: int, valid: int, invalid: int, duplicate: int}}
     */
    protected function parseStream($handle, string $organizationId, array $mappingOverrides): array
    {
        $headers = $this->readRow($handle);

        if ($headers === null || $this->rowIsEmpty($headers)) {
            throw ValidationException::withMessages(['file' => ['The file has no header row.']]);
        }

        $headers = array_map(fn ($header) => $this->stripBom(trim((string) $header)), $headers);
        $columnMap = $this->buildColumnMap($headers, $mappingOverrides);

        if (! in_array('internal_reference', $columnMap, true)
            || ! in_array('broker_or_customer_name', $columnMap, true)
        ) {
            throw ValidationException::withMessages(['file' => [
                'The file must have columns for the internal reference and the broker or customer name.',
            ]]);
        }

        $existingReferences = Shipment::forOrganization($organizationId)
            ->pluck('internal_reference')
            ->map(fn ($reference) => mb_strtolower((string) $reference))
            ->all();
        $existingReferences = array_flip($existingReferences);
        $seenReferences = [];
        $rows = [];
        $index = 0;

        while (($rawRow = $this->readRow($handle)) !== null) {
            if ($this->rowIsEmpty($rawRow)) {
                continue;
            }

            $index++;

            if ($index > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => [
                    'The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.',
                ]]);
            }

            $rows[] = $this->parseRow($rawRow, $columnMap, $organizationId, $existingReferences, $seenReferences, $index);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['The file has no data rows.']]);
        }

        $summary = [
            'total' => count($rows),
            'valid' => count(array_filter($rows, fn ($row) => $row['valid'])),
            'invalid' => count(array_filter($rows, fn ($row) => ! $row['valid'] && ! $row['is_duplicate'])),
            'duplicate' => count(array_filter($rows, fn ($row) => $row['is_duplicate'])),
        ];

        return [
            'headers' => $headers,
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * Create the valid, non-duplicate rows in one transaction.
     *
     * Shared by confirm and runFile. An unexpected failure rolls
     * every row back. Each returned row carries its outcome and,
     * where one was created, the shipment id.
     *
     * @param  array<int, array{index: int, data: array<string, mixed>, errors: array<string, array<int, string>>, is_duplicate: bool, valid: bool}>  $rows
     * @return array{imported: int, skipped_rows: array<int, array{index: int, reason: string, is_duplicate: bool, errors: array<string, array<int, string>>}>, rows: array<int, array{index: int, data: array<string, mixed>, errors: array<string, array<int, string>>, is_duplicate: bool, status: string, shipment_id: string|null}>}
     */
    protected function createRows(array $rows, string $organizationId, int $userId): array
    {
        $skipped = [];
        $results = [];
        $created = 0;

        try {
            DB::transaction(function () use ($rows, $organizationId, $userId, &$skipped, &$results, &$created) {
                foreach ($rows as $row) {
                    if (! $row['valid']) {
                        // The per-field errors ride along so the client can
                        // show exactly what was wrong with each skipped row,
                        // not just that it failed.
                        $skipped[] = [
                            'index' => $row['index'],
                            'reason' => $row['is_duplicate']
                                ? 'Duplicate internal reference.'
                                : 'Row failed validation.',
                            'is_duplicate' => $row['is_duplicate'],
                            'errors' => $row['errors'],
                        ];
                        $results[] = [
                            'index' => $row['index'],
                            'data' => $row['data'],
                            'errors' => $row['errors'],
                            'is_duplicate' => $row['is_duplicate'],
                            'status' => $row['is_duplicate'] ? 'duplicate' : 'skipped',
                            'shipment_id' => null,
                        ];
                        continue;
                    }

                    $data = $row['data'];

                    // Re-check at write time: another import may have
                    // taken the reference since the preview ran.
                    $taken = Shipment::forOrganization($organizationId)
                        ->where('internal_reference', $data['internal_reference'])
                        ->exists();

                    if ($taken) {
                        $skipped[] = [
                            'index' => $row['index'],
                            'reason' => 'Duplicate internal reference.',
                            'is_duplicate' => true,
                            'errors' => ['internal_reference' => ['A shipment with this reference already exists.']],
                        ];
                        $results[] = [
                            'index' => $row['index'],
                            'data' => $row['data'],
                            'errors' => ['internal_reference' => ['A shipment with this reference already exists.']],
                            'is_duplicate' => true,
                            'status' => 'duplicate',
                            'shipment_id' => null,
                        ];
                        continue;
                    }

                    if (! empty($data['carrier_id'])) {
                        $carrierExists = Carrier::forOrganization($organizationId)
                            ->whereKey($data['carrier_id'])
                            ->exists();

                        if (! $carrierExists) {
                            $skipped[] = [
                                'index' => $row['index'],
                                'reason' => 'The carrier no longer exists.',
                                'is_duplicate' => false,
                                'errors' => ['carrier' => ['The carrier no longer exists.']],
                            ];
                            $results[] = [
                                'index' => $row['index'],
                                'data' => $row['data'],
                                'errors' => ['carrier' => ['The carrier no longer exists.']],
                                'is_duplicate' => false,
                                'status' => 'skipped',
                                'shipment_id' => null,
                            ];
                            continue;
                        }
                    }

                    $data['organization_id'] = $organizationId;
                    $data['created_by'] = $userId;
                    $data['status'] = $data['status'] ?? ShipmentStatus::Draft->value;

                    $shipment = Shipment::create($data);
                    $created++;

                    $results[] = [
                        'index' => $row['index'],
                        'data' => $row['data'],
                        'errors' => [],
                        'is_duplicate' => false,
                        'status' => 'imported',
                        'shipment_id' => (string) $shipment->getKey(),
                    ];
                }
            });
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['preview_token' => [
                'The import failed and no shipments were created. Try again.',
            ]]);
        }

        return [
            'imported' => $created,
            'skipped_rows' => $skipped,
            'rows' => $results,
        ];
    }

    /**
     * Map each header column to a shipment field.
     *
     * Caller-supplied overrides win over the built-in aliases. Unknown
     * columns map to null and are ignored.
     *
     * @param  array<int, string>  $headers
     * @param  array<string, string>  $mappingOverrides
     * @return array<int, string|null>
     */
    protected function buildColumnMap(array $headers, array $mappingOverrides): array
    {
        $aliases = self::headerAliases();
        $allowedFields = array_values(array_unique(array_merge(
            array_values($aliases),
            ['carrier_id']
        )));

        $map = [];

        foreach ($headers as $position => $header) {
            if (isset($mappingOverrides[$header]) && in_array($mappingOverrides[$header], $allowedFields, true)) {
                $map[$position] = $mappingOverrides[$header];
                continue;
            }

            $map[$position] = $aliases[self::normalizeHeader($header)] ?? null;
        }

        return $map;
    }

    /**
     * The shipment fields a mapping may target.
     *
     * @return array<int, string>
     */
    public static function allowedFields(): array
    {
        return array_values(array_unique(array_merge(
            array_values(self::headerAliases()),
            ['carrier_id']
        )));
    }

    /**
     * Parse and validate one data row.
     */
    protected function parseRow(array $rawRow, array $columnMap, string $organizationId, array $existingReferences, array &$seenReferences, int $index): array
    {
        $data = [];
        $carrierName = null;

        foreach ($columnMap as $position => $field) {
            if ($field === null) {
                continue;
            }

            $value = isset($rawRow[$position]) ? trim((string) $rawRow[$position]) : '';

            if ($value === '') {
                continue;
            }

            if ($field === 'carrier') {
                $carrierName = $value;
                continue;
            }

            $data[$field] = $field === 'currency' ? mb_strtoupper($value) : $value;
        }

        $errors = [];

        if ($carrierName !== null) {
            $carrier = Carrier::forOrganization($organizationId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($carrierName)])
                ->first();

            if ($carrier === null) {
                $errors['carrier'] = ['No carrier named "'.$carrierName.'" exists in this organization.'];
            } else {
                $data['carrier_id'] = $carrier->getKey();
            }
        }

        // Import accepts any non-archived status: historical shipments
        // are legitimately past draft. The create endpoint is stricter.
        $rules = ShipmentRules::createRules($organizationId, false);
        $rules['status'] = ['nullable', 'string', Rule::in([
            ShipmentStatus::Draft->value,
            ShipmentStatus::Planned->value,
            ShipmentStatus::InTransit->value,
            ShipmentStatus::Delivered->value,
            ShipmentStatus::Completed->value,
        ])];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->toArray());
        }

        $isDuplicate = false;
        $reference = $data['internal_reference'] ?? null;

        if ($reference !== null) {
            $normalized = mb_strtolower($reference);

            if (isset($existingReferences[$normalized]) || isset($seenReferences[$normalized])) {
                $isDuplicate = true;
                $errors['internal_reference'] = ['A shipment with this reference already exists.'];
            }

            $seenReferences[$normalized] = true;
        }

        return [
            'index' => $index,
            'data' => $data,
            'errors' => $errors,
            'is_duplicate' => $isDuplicate,
            'valid' => $errors === [] && ! $isDuplicate,
        ];
    }

    /**
     * Read one CSV row, or null at end of file.
     *
     * @return array<int, string>|null
     */
    protected function readRow($handle): ?array
    {
        $row = fgetcsv($handle, 0, ',', '"', '\\');

        return $row === false ? null : $row;
    }

    /**
     * Whether a row has no non-empty cell.
     */
    protected function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize a header for alias lookup.
     */
    protected static function normalizeHeader(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));
    }

    /**
     * Strip a UTF-8 byte order mark from the first header.
     */
    protected function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
