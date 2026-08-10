<?php

declare(strict_types=1);

/**
 * ConnectPro Import and Export Service
 *
 * File: api/classes/ImportExportService.php
 *
 * Responsibilities:
 * - Validate uploaded CSV and XLSX files
 * - Preview and normalize contact import rows
 * - Import contacts with duplicate strategies and transactions
 * - Export contacts to CSV safely
 * - Prevent CSV formula injection
 * - Produce structured row-level validation results
 * - Write import and export activity logs
 *
 * XLSX support requires PhpSpreadsheet:
 * composer require phpoffice/phpspreadsheet
 */
final class ImportExportService
{
    private const SUPPORTED_EXTENSIONS = ['csv', 'xlsx'];

    private const SUPPORTED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    private const DUPLICATE_STRATEGIES = [
        'skip',
        'update',
        'error',
    ];

    private const EXPORT_COLUMNS = [
        'employee_code' => 'Employee Code',
        'display_name' => 'Display Name',
        'position' => 'Position',
        'department_code' => 'Department Code',
        'department_name' => 'Department Name',
        'location_code' => 'Location Code',
        'location_name' => 'Location Name',
        'extension_number' => 'Extension',
        'mobile_number' => 'Mobile Number',
        'email' => 'Email',
        'ip_address' => 'IP Address',
        'status' => 'Status',
        'notes' => 'Notes',
        'created_at' => 'Created At',
        'updated_at' => 'Updated At',
    ];

    private const HEADER_ALIASES = [
        'employee_code' => [
            'employee_code', 'employee code', 'employee id', 'emp code',
            'emp_code', 'รหัสพนักงาน',
        ],
        'display_name' => [
            'display_name', 'display name', 'name', 'full name',
            'ชื่อ', 'ชื่อพนักงาน', 'ชื่อและนามสกุล',
        ],
        'position' => ['position', 'job title', 'title', 'ตำแหน่ง'],
        'department_code' => [
            'department_code', 'department code', 'dept code',
            'dept_code', 'รหัสแผนก',
        ],
        'department_name' => [
            'department_name', 'department name', 'department', 'dept',
            'แผนก', 'ชื่อแผนก',
        ],
        'location_code' => [
            'location_code', 'location code', 'site code', 'รหัสสถานที่',
        ],
        'location_name' => [
            'location_name', 'location name', 'location', 'site',
            'สถานที่', 'ชื่อสถานที่',
        ],
        'extension_number' => [
            'extension_number', 'extension', 'ext', 'phone extension',
            'เบอร์ต่อ',
        ],
        'mobile_number' => [
            'mobile_number', 'mobile number', 'mobile', 'phone',
            'โทรศัพท์มือถือ', 'เบอร์มือถือ',
        ],
        'email' => ['email', 'e-mail', 'อีเมล'],
        'ip_address' => ['ip_address', 'ip address', 'ip', 'ไอพี'],
        'status' => ['status', 'สถานะ'],
        'notes' => ['notes', 'note', 'remark', 'remarks', 'หมายเหตุ'],
    ];

    private const REQUIRED_IMPORT_FIELDS = [
        'employee_code',
        'display_name',
        'extension_number',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly array $config = []
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * Validate a PHP uploaded-file array.
     *
     * @param array<string, mixed> $file
     * @return array{path: string, original_name: string, extension: string, mime_type: string, size: int}
     */
    public function validateUploadedFile(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(
                $this->uploadErrorMessage($error)
            );
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $originalName = basename((string) ($file['name'] ?? ''));
        $size = (int) ($file['size'] ?? 0);
        $maximumSize = max(
            1,
            (int) ($this->config['max_size_bytes'] ?? 10 * 1024 * 1024)
        );

        if (
            $temporaryPath === ''
            || !is_file($temporaryPath)
            || (!is_uploaded_file($temporaryPath)
                && empty($this->config['allow_local_files']))
        ) {
            throw new InvalidArgumentException('ไฟล์อัปโหลดไม่ถูกต้อง');
        }

        if ($size < 1 || $size > $maximumSize) {
            throw new InvalidArgumentException(sprintf(
                'ขนาดไฟล์ต้องไม่เกิน %.2f MB',
                $maximumSize / 1024 / 1024
            ));
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = $this->config['allowed_extensions']
            ?? self::SUPPORTED_EXTENSIONS;

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException(
                'รองรับเฉพาะไฟล์ CSV และ XLSX'
            );
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $fileInfo->file($temporaryPath);
        $allowedMimeTypes = $this->config['allowed_mime_types']
            ?? self::SUPPORTED_MIME_TYPES;

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new InvalidArgumentException('ชนิดไฟล์ไม่ถูกต้อง');
        }

        if ($extension === 'xlsx') {
            $this->assertValidXlsxContainer($temporaryPath);
        }

        return [
            'path' => $temporaryPath,
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    /**
     * Preview normalized rows without changing the database.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function preview(array $file, int $limit = 50): array
    {
        $validated = $this->validateUploadedFile($file);
        $limit = min(max(1, $limit), 200);
        $parsed = $this->readFile(
            $validated['path'],
            $validated['extension'],
            $limit
        );
        $resolved = $this->resolveReferenceMaps();
        $rows = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($parsed['rows'] as $index => $rawRow) {
            $rowNumber = $index + 2;
            $normalized = $this->normalizeImportRow(
                $rawRow,
                $parsed['header_map']
            );
            $normalized = $this->resolveReferences($normalized, $resolved);
            $errors = $this->validateImportRow($normalized);

            $rows[] = [
                'row_number' => $rowNumber,
                'data' => $normalized,
                'valid' => $errors === [],
                'errors' => $errors,
            ];

            $errors === [] ? $validCount++ : $invalidCount++;
        }

        return [
            'file' => [
                'name' => $validated['original_name'],
                'extension' => $validated['extension'],
                'size' => $validated['size'],
            ],
            'headers' => $parsed['headers'],
            'header_map' => $parsed['header_map'],
            'rows' => $rows,
            'summary' => [
                'previewed' => count($rows),
                'valid' => $validCount,
                'invalid' => $invalidCount,
                'truncated' => $parsed['truncated'],
            ],
        ];
    }

    /**
     * Import contacts from a validated CSV or XLSX upload.
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function importContacts(
        array $file,
        int $actorUserId,
        string $duplicateStrategy = 'skip',
        bool $stopOnError = false
    ): array {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Invalid actor user ID.');
        }

        $duplicateStrategy = strtolower(trim($duplicateStrategy));

        if (!in_array(
            $duplicateStrategy,
            self::DUPLICATE_STRATEGIES,
            true
        )) {
            throw new InvalidArgumentException('Invalid duplicate strategy.');
        }

        $validated = $this->validateUploadedFile($file);
        $maximumRows = max(1, (int) ($this->config['max_import_rows'] ?? 5000));
        $parsed = $this->readFile(
            $validated['path'],
            $validated['extension'],
            $maximumRows + 1
        );

        if (count($parsed['rows']) > $maximumRows || $parsed['truncated']) {
            throw new InvalidArgumentException(sprintf(
                'ไฟล์นำเข้าต้องมีข้อมูลไม่เกิน %d แถว',
                $maximumRows
            ));
        }

        $references = $this->resolveReferenceMaps();
        $summary = [
            'total' => count($parsed['rows']),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $errors = [];

        $this->db->beginTransaction();

        try {
            foreach ($parsed['rows'] as $index => $rawRow) {
                $rowNumber = $index + 2;

                try {
                    $data = $this->normalizeImportRow(
                        $rawRow,
                        $parsed['header_map']
                    );
                    $data = $this->resolveReferences($data, $references);
                    $rowErrors = $this->validateImportRow($data);

                    if ($rowErrors !== []) {
                        throw new InvalidArgumentException(
                            implode(' ', array_values($rowErrors))
                        );
                    }

                    $existingId = $this->findDuplicateContactId($data);

                    if ($existingId !== null) {
                        if ($duplicateStrategy === 'skip') {
                            $summary['skipped']++;
                            continue;
                        }

                        if ($duplicateStrategy === 'error') {
                            throw new DomainException(
                                'พบรหัสพนักงานหรืออีเมลซ้ำ'
                            );
                        }

                        $this->updateContact($existingId, $data);
                        $summary['updated']++;
                    } else {
                        $this->insertContact($data);
                        $summary['created']++;
                    }
                } catch (Throwable $rowException) {
                    $summary['failed']++;
                    $errors[] = [
                        'row_number' => $rowNumber,
                        'employee_code' => $rawRow[
                            $parsed['header_map']['employee_code'] ?? -1
                        ] ?? null,
                        'message' => $rowException->getMessage(),
                    ];

                    if ($stopOnError) {
                        throw $rowException;
                    }
                }
            }

            $this->writeActivityLog(
                $actorUserId,
                'import',
                'นำเข้าข้อมูลผู้ติดต่อจาก ' . $validated['original_name'],
                [
                    'file_name' => $validated['original_name'],
                    'file_size' => $validated['size'],
                    'duplicate_strategy' => $duplicateStrategy,
                    'summary' => $summary,
                ]
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        return [
            'success' => $summary['failed'] === 0,
            'summary' => $summary,
            'errors' => $errors,
        ];
    }

    /**
     * Export contacts to a temporary UTF-8 CSV file with BOM.
     * Caller is responsible for streaming and deleting the file.
     *
     * @return array{path: string, filename: string, mime_type: string, row_count: int, size: int}
     */
    public function exportContacts(
        array $filters,
        int $actorUserId,
        ?array $columns = null
    ): array {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException('Invalid actor user ID.');
        }

        $selectedColumns = $this->resolveExportColumns($columns);
        [$whereSql, $params] = $this->buildExportConditions($filters);
        $sort = (string) ($filters['sort'] ?? 'name_asc');
        $sortMap = [
            'name_asc' => 'c.display_name ASC',
            'name_desc' => 'c.display_name DESC',
            'department' => 'd.name ASC, c.display_name ASC',
            'updated_desc' => 'c.updated_at DESC',
        ];
        $orderBy = $sortMap[$sort] ?? $sortMap['name_asc'];

        $sql = <<<SQL
            SELECT
                c.employee_code,
                c.display_name,
                c.position,
                d.code AS department_code,
                d.name AS department_name,
                l.code AS location_code,
                l.name AS location_name,
                c.extension_number,
                c.mobile_number,
                c.email,
                c.ip_address,
                c.status,
                c.notes,
                c.created_at,
                c.updated_at
            FROM contacts c
            LEFT JOIN departments d ON d.id = c.department_id
            LEFT JOIN locations l ON l.id = c.location_id
            {$whereSql}
            ORDER BY {$orderBy}
            SQL;

        $statement = $this->db->prepare($sql);
        $this->bindValues($statement, $params);
        $statement->execute();

        $directory = $this->temporaryDirectory();
        $this->ensureDirectory($directory);
        $path = tempnam($directory, 'contacts_export_');

        if ($path === false) {
            throw new RuntimeException('Unable to create export file.');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            @unlink($path);
            throw new RuntimeException('Unable to open export file.');
        }

        $rowCount = 0;

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv(
                $handle,
                array_map(
                    static fn (string $key): string => self::EXPORT_COLUMNS[$key],
                    $selectedColumns
                )
            );

            while (($row = $statement->fetch()) !== false) {
                $output = [];

                foreach ($selectedColumns as $column) {
                    $output[] = $this->sanitizeCsvValue($row[$column] ?? '');
                }

                fputcsv($handle, $output);
                $rowCount++;
            }
        } finally {
            fclose($handle);
        }

        $filename = 'connectpro-contacts-'
            . (new DateTimeImmutable())->format('Ymd-His')
            . '.csv';

        $this->writeActivityLog(
            $actorUserId,
            'export',
            'ส่งออกข้อมูลผู้ติดต่อ',
            [
                'filename' => $filename,
                'row_count' => $rowCount,
                'columns' => $selectedColumns,
                'filters' => $this->safeLogFilters($filters),
            ]
        );

        return [
            'path' => $path,
            'filename' => $filename,
            'mime_type' => 'text/csv; charset=utf-8',
            'row_count' => $rowCount,
            'size' => (int) filesize($path),
        ];
    }

    /**
     * Create a CSV import template.
     *
     * @return array{path: string, filename: string, mime_type: string, size: int}
     */
    public function createImportTemplate(): array
    {
        $directory = $this->temporaryDirectory();
        $this->ensureDirectory($directory);
        $path = tempnam($directory, 'contacts_template_');

        if ($path === false) {
            throw new RuntimeException('Unable to create template file.');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            @unlink($path);
            throw new RuntimeException('Unable to open template file.');
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'employee_code',
                'display_name',
                'position',
                'department_code',
                'location_code',
                'extension_number',
                'mobile_number',
                'email',
                'ip_address',
                'status',
                'notes',
            ]);
            fputcsv($handle, [
                '10001234',
                'Example User',
                'Technician 2',
                'IT',
                'BKK',
                '1234',
                '081-234-5678',
                'example@company.com',
                '192.168.1.100',
                'active',
                'Sample row. Delete before import.',
            ]);
        } finally {
            fclose($handle);
        }

        return [
            'path' => $path,
            'filename' => 'connectpro-contact-import-template.csv',
            'mime_type' => 'text/csv; charset=utf-8',
            'size' => (int) filesize($path),
        ];
    }

    /** @return array{headers: list<string>, header_map: array<string, int>, rows: list<array<int, mixed>>, truncated: bool} */
    private function readFile(
        string $path,
        string $extension,
        int $rowLimit
    ): array {
        return match ($extension) {
            'csv' => $this->readCsv($path, $rowLimit),
            'xlsx' => $this->readXlsx($path, $rowLimit),
            default => throw new InvalidArgumentException(
                'Unsupported import format.'
            ),
        };
    }

    /** @return array{headers: list<string>, header_map: array<string, int>, rows: list<array<int, mixed>>, truncated: bool} */
    private function readCsv(string $path, int $rowLimit): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('ไม่สามารถเปิดไฟล์ CSV ได้');
        }

        try {
            $sample = fread($handle, 8192);
            rewind($handle);
            $delimiter = $this->detectCsvDelimiter((string) $sample);
            $headers = fgetcsv($handle, 0, $delimiter);

            if (!is_array($headers)) {
                throw new InvalidArgumentException('ไม่พบ Header ในไฟล์ CSV');
            }

            $headers = array_map(
                fn (mixed $value): string => $this->cleanHeader((string) $value),
                $headers
            );
            $headerMap = $this->buildHeaderMap($headers);
            $rows = [];
            $truncated = false;

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                if (count($rows) >= $rowLimit) {
                    $truncated = true;
                    break;
                }

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return compact('headers', 'headerMap', 'rows', 'truncated') + [
            'header_map' => $headerMap,
        ];
    }

    /** @return array{headers: list<string>, header_map: array<string, int>, rows: list<array<int, mixed>>, truncated: bool} */
    private function readXlsx(string $path, int $rowLimit): array
    {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            throw new RuntimeException(
                'การอ่าน XLSX ต้องติดตั้ง phpoffice/phpspreadsheet'
            );
        }

        $factory = 'PhpOffice\\PhpSpreadsheet\\IOFactory';
        $reader = $factory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $headerRow = array_shift($data);

        if (!is_array($headerRow)) {
            throw new InvalidArgumentException('ไม่พบ Header ในไฟล์ XLSX');
        }

        $headers = array_map(
            fn (mixed $value): string => $this->cleanHeader((string) $value),
            $headerRow
        );
        $headerMap = $this->buildHeaderMap($headers);
        $rows = [];
        $truncated = false;

        foreach ($data as $row) {
            if (!is_array($row) || $this->isEmptyRow($row)) {
                continue;
            }

            if (count($rows) >= $rowLimit) {
                $truncated = true;
                break;
            }

            $rows[] = array_values($row);
        }

        return [
            'headers' => $headers,
            'header_map' => $headerMap,
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    /** @return array<string, int> */
    private function buildHeaderMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);

            foreach (self::HEADER_ALIASES as $field => $aliases) {
                $normalizedAliases = array_map(
                    fn (string $alias): string => $this->normalizeHeader($alias),
                    $aliases
                );

                if (in_array($normalized, $normalizedAliases, true)) {
                    $map[$field] = (int) $index;
                    break;
                }
            }
        }

        $missing = array_values(array_filter(
            self::REQUIRED_IMPORT_FIELDS,
            static fn (string $field): bool => !array_key_exists($field, $map)
        ));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'ไฟล์ขาด Header ที่จำเป็น: ' . implode(', ', $missing)
            );
        }

        if (
            !isset($map['department_code'])
            && !isset($map['department_name'])
        ) {
            throw new InvalidArgumentException(
                'ต้องมี department_code หรือ department_name'
            );
        }

        if (!isset($map['location_code']) && !isset($map['location_name'])) {
            throw new InvalidArgumentException(
                'ต้องมี location_code หรือ location_name'
            );
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private function normalizeImportRow(array $row, array $headerMap): array
    {
        $data = [];

        foreach (array_keys(self::HEADER_ALIASES) as $field) {
            $index = $headerMap[$field] ?? null;
            $value = $index === null ? null : ($row[$index] ?? null);
            $data[$field] = is_string($value) ? trim($value) : $value;
        }

        $data['employee_code'] = trim((string) $data['employee_code']);
        $data['display_name'] = trim((string) $data['display_name']);
        $data['position'] = $this->nullableString($data['position']);
        $data['extension_number'] = trim((string) $data['extension_number']);
        $data['mobile_number'] = $this->nullableString($data['mobile_number']);
        $data['email'] = $this->nullableLowerString($data['email']);
        $data['ip_address'] = $this->nullableString($data['ip_address']);
        $data['status'] = strtolower(trim((string) ($data['status'] ?: 'active')));
        $data['notes'] = $this->nullableString($data['notes']);

        return $data;
    }

    /** @return array{departments_by_code: array<string, int>, departments_by_name: array<string, int>, locations_by_code: array<string, int>, locations_by_name: array<string, int>} */
    private function resolveReferenceMaps(): array
    {
        $departmentsByCode = [];
        $departmentsByName = [];
        $locationsByCode = [];
        $locationsByName = [];

        $departments = $this->db->query(
            "SELECT id, code, name FROM departments WHERE status = 'active'"
        )->fetchAll();

        foreach ($departments as $department) {
            $departmentsByCode[$this->lookupKey((string) $department['code'])]
                = (int) $department['id'];
            $departmentsByName[$this->lookupKey((string) $department['name'])]
                = (int) $department['id'];
        }

        $locations = $this->db->query(
            "SELECT id, code, name FROM locations WHERE status = 'active'"
        )->fetchAll();

        foreach ($locations as $location) {
            $locationsByCode[$this->lookupKey((string) $location['code'])]
                = (int) $location['id'];
            $locationsByName[$this->lookupKey((string) $location['name'])]
                = (int) $location['id'];
        }

        return [
            'departments_by_code' => $departmentsByCode,
            'departments_by_name' => $departmentsByName,
            'locations_by_code' => $locationsByCode,
            'locations_by_name' => $locationsByName,
        ];
    }

    /** @return array<string, mixed> */
    private function resolveReferences(array $data, array $maps): array
    {
        $departmentCode = $this->lookupKey(
            (string) ($data['department_code'] ?? '')
        );
        $departmentName = $this->lookupKey(
            (string) ($data['department_name'] ?? '')
        );
        $locationCode = $this->lookupKey(
            (string) ($data['location_code'] ?? '')
        );
        $locationName = $this->lookupKey(
            (string) ($data['location_name'] ?? '')
        );

        $data['department_id'] = $maps['departments_by_code'][$departmentCode]
            ?? $maps['departments_by_name'][$departmentName]
            ?? null;
        $data['location_id'] = $maps['locations_by_code'][$locationCode]
            ?? $maps['locations_by_name'][$locationName]
            ?? null;

        unset(
            $data['department_code'],
            $data['department_name'],
            $data['location_code'],
            $data['location_name']
        );

        return $data;
    }

    /** @return array<string, string> */
    private function validateImportRow(array $data): array
    {
        $errors = [];

        if (
            $data['employee_code'] === ''
            || mb_strlen((string) $data['employee_code']) > 50
        ) {
            $errors['employee_code'] = 'รหัสพนักงานไม่ถูกต้อง';
        }

        $nameLength = mb_strlen((string) $data['display_name']);

        if ($nameLength < 2 || $nameLength > 150) {
            $errors['display_name'] = 'ชื่อผู้ติดต่อต้องมี 2-150 ตัวอักษร';
        }

        if (!is_int($data['department_id']) || $data['department_id'] < 1) {
            $errors['department_id'] = 'ไม่พบแผนกที่ Active';
        }

        if (!is_int($data['location_id']) || $data['location_id'] < 1) {
            $errors['location_id'] = 'ไม่พบสถานที่ที่ Active';
        }

        if (
            preg_match(
                '/^[0-9+#*()\-]{1,20}$/',
                (string) $data['extension_number']
            ) !== 1
        ) {
            $errors['extension_number'] = 'รูปแบบเบอร์ต่อไม่ถูกต้อง';
        }

        if (
            $data['email'] !== null
            && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
        }

        if (
            $data['ip_address'] !== null
            && filter_var($data['ip_address'], FILTER_VALIDATE_IP) === false
        ) {
            $errors['ip_address'] = 'รูปแบบ IP Address ไม่ถูกต้อง';
        }

        if (!in_array($data['status'], ['active', 'inactive'], true)) {
            $errors['status'] = 'สถานะไม่ถูกต้อง';
        }

        if (mb_strlen((string) ($data['notes'] ?? '')) > 1000) {
            $errors['notes'] = 'หมายเหตุต้องไม่เกิน 1,000 ตัวอักษร';
        }

        return $errors;
    }

    private function findDuplicateContactId(array $data): ?int
    {
        $sql = <<<SQL
            SELECT id
            FROM contacts
            WHERE employee_code = :employee_code
                OR (:email IS NOT NULL AND email = :email)
            ORDER BY id ASC
            LIMIT 1
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':employee_code', $data['employee_code']);
        $statement->bindValue(
            ':email',
            $data['email'],
            $data['email'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->execute();
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function insertContact(array $data): int
    {
        $sql = <<<SQL
            INSERT INTO contacts (
                employee_code, display_name, position, department_id,
                location_id, extension_number, mobile_number, email,
                ip_address, status, notes, created_at, updated_at
            ) VALUES (
                :employee_code, :display_name, :position, :department_id,
                :location_id, :extension_number, :mobile_number, :email,
                :ip_address, :status, :notes,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            SQL;

        $statement = $this->db->prepare($sql);
        $this->bindContactValues($statement, $data);
        $statement->execute();

        return (int) $this->db->lastInsertId();
    }

    private function updateContact(int $contactId, array $data): void
    {
        $sql = <<<SQL
            UPDATE contacts SET
                employee_code = :employee_code,
                display_name = :display_name,
                position = :position,
                department_id = :department_id,
                location_id = :location_id,
                extension_number = :extension_number,
                mobile_number = :mobile_number,
                email = :email,
                ip_address = :ip_address,
                status = :status,
                notes = :notes,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :contact_id
            SQL;

        $statement = $this->db->prepare($sql);
        $this->bindContactValues($statement, $data);
        $statement->bindValue(':contact_id', $contactId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function bindContactValues(
        PDOStatement $statement,
        array $data
    ): void {
        foreach ([
            'employee_code', 'display_name', 'position', 'department_id',
            'location_id', 'extension_number', 'mobile_number', 'email',
            'ip_address', 'status', 'notes',
        ] as $field) {
            $value = $data[$field] ?? null;
            $type = is_int($value)
                ? PDO::PARAM_INT
                : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->bindValue(':' . $field, $value, $type);
        }
    }

    /** @return list<string> */
    private function resolveExportColumns(?array $columns): array
    {
        if ($columns === null || $columns === []) {
            return array_keys(self::EXPORT_COLUMNS);
        }

        $selected = array_values(array_unique(array_filter(
            $columns,
            static fn (mixed $column): bool => is_string($column)
                && array_key_exists($column, self::EXPORT_COLUMNS)
        )));

        if ($selected === []) {
            throw new InvalidArgumentException('ไม่พบคอลัมน์สำหรับ Export');
        }

        return $selected;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function buildExportConditions(array $filters): array
    {
        $conditions = [];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $conditions[] = '('
                . 'c.employee_code LIKE :search '
                . 'OR c.display_name LIKE :search '
                . 'OR c.extension_number LIKE :search '
                . 'OR c.email LIKE :search'
                . ')';
            $params['search'] = '%' . $this->escapeLike($search) . '%';
        }

        foreach (['department_id', 'location_id'] as $field) {
            $value = (int) ($filters[$field] ?? 0);

            if ($value > 0) {
                $conditions[] = 'c.' . $field . ' = :' . $field;
                $params[$field] = $value;
            }
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));

        if (in_array($status, ['active', 'inactive'], true)) {
            $conditions[] = 'c.status = :status';
            $params['status'] = $status;
        }

        return [
            $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    private function sanitizeCsvValue(mixed $value): string
    {
        $text = str_replace("\0", '', (string) ($value ?? ''));
        $trimmed = ltrim($text);

        if (
            $trimmed !== ''
            && in_array($trimmed[0], ['=', '+', '-', '@'], true)
        ) {
            return "'" . $text;
        }

        return $text;
    }

    private function assertValidXlsxContainer(string $path): void
    {
        if (!class_exists('ZipArchive')) {
            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('ไฟล์ XLSX เสียหาย');
        }

        try {
            if (
                $zip->locateName('[Content_Types].xml') === false
                || $zip->locateName('xl/workbook.xml') === false
            ) {
                throw new InvalidArgumentException('โครงสร้าง XLSX ไม่ถูกต้อง');
            }
        } finally {
            $zip->close();
        }
    }

    private function detectCsvDelimiter(string $sample): string
    {
        $candidates = [',', ';', "\t", '|'];
        $bestDelimiter = ',';
        $bestCount = -1;
        $firstLine = strtok($sample, "\r\n") ?: '';

        foreach ($candidates as $delimiter) {
            $count = substr_count($firstLine, $delimiter);

            if ($count > $bestCount) {
                $bestCount = $count;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }

    private function cleanHeader(string $header): string
    {
        return trim(str_replace("\xEF\xBB\xBF", '', $header));
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower($this->cleanHeader($header));
        $header = str_replace(['-', '_'], ' ', $header);

        return preg_replace('/\s+/u', ' ', $header) ?: $header;
    }

    private function lookupKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function nullableLowerString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : mb_strtolower($value);
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'ไฟล์มีขนาดใหญ่เกินกำหนด',
            UPLOAD_ERR_PARTIAL => 'อัปโหลดไฟล์ไม่สมบูรณ์',
            UPLOAD_ERR_NO_FILE => 'กรุณาเลือกไฟล์',
            UPLOAD_ERR_NO_TMP_DIR => 'ไม่พบโฟลเดอร์ชั่วคราว',
            UPLOAD_ERR_CANT_WRITE => 'ไม่สามารถเขียนไฟล์ได้',
            UPLOAD_ERR_EXTENSION => 'ส่วนขยาย PHP หยุดการอัปโหลด',
            default => 'เกิดข้อผิดพลาดระหว่างอัปโหลดไฟล์',
        };
    }

    private function temporaryDirectory(): string
    {
        return rtrim(
            (string) ($this->config['temp_path'] ?? sys_get_temp_dir()),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . 'connectpro-import-export';
    }

    private function ensureDirectory(string $directory): void
    {
        if (
            !is_dir($directory)
            && !mkdir($directory, 0750, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException('Unable to create temporary directory.');
        }
    }

    /** @return array<string, mixed> */
    private function safeLogFilters(array $filters): array
    {
        return array_intersect_key($filters, array_flip([
            'search', 'department_id', 'location_id', 'status', 'sort',
        ]));
    }

    private function writeActivityLog(
        int $actorUserId,
        string $action,
        string $description,
        array $metadata
    ): void {
        if (empty($this->config['activity_log_enabled'])) {
            return;
        }

        $sql = <<<SQL
            INSERT INTO activity_logs (
                user_id, action, entity_type, description, new_values,
                ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :action, 'contact', :description, :metadata,
                :ip_address, :user_agent, CURRENT_TIMESTAMP
            )
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $actorUserId,
            'action' => $action,
            'description' => $description,
            'metadata' => json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            ),
            'ip_address' => $this->resolveClientIp(),
            'user_agent' => mb_substr(
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                500
            ),
        ]);
    }

    private function resolveClientIp(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }

    private function bindValues(PDOStatement $statement, array $values): void
    {
        foreach ($values as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue(
                ':' . ltrim((string) $key, ':'),
                $value,
                $type
            );
        }
    }
}
