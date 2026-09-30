<?php
/**
 * Generic machinery behind imports/*.php: reading an uploaded spreadsheet
 * into plain rows, running a registered importer's validate/commit
 * callbacks over them, and the import_batches/import_batch_rows
 * bookkeeping. Entity-specific logic lives in includes/importers.php —
 * this file knows nothing about any particular entity type.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads the first sheet of an uploaded spreadsheet into an array of
 * associative rows keyed by the header row's cell values. Blank rows
 * (every cell empty) are skipped. Throws on an unreadable file.
 *
 * @return array{headers: string[], rows: array<int, array<string, mixed>>}
 */
function import_read_spreadsheet(string $path): array
{
    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $grid = $sheet->toArray(null, true, true, false);

    if (!$grid) {
        return ['headers' => [], 'rows' => []];
    }

    $headerRow = array_shift($grid);
    $headers = [];
    foreach ($headerRow as $cell) {
        // Our own generated templates mark a required column as "Name *" —
        // strip that trailing marker so the key matches the plain column
        // key (e.g. "Name") validate_row looks up, or a file downloaded
        // from our own template and re-uploaded unchanged would fail every
        // required-column row.
        $headers[] = preg_replace('/\s*\*$/', '', trim((string)$cell));
    }

    $rows = [];
    foreach ($grid as $line) {
        $row = [];
        foreach ($headers as $i => $h) {
            if ($h === '') continue;
            $row[$h] = $line[$i] ?? '';
        }
        if (!importer_row_is_blank($row)) {
            $rows[] = $row;
        }
    }

    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Streams a .xlsx template for the given importer straight to the browser
 * and exits. $sampleLimit controls how many rows of real existing data are
 * included below the header, straight from the entity's own table (via the
 * importer's optional 'sample_rows' callback) so the file doubles as a
 * "here's what this looks like" example or a bulk-edit starting point:
 *   0     -> header only (a blank template)
 *   5/50  -> that many of the most recently created records
 *   null  -> every existing record, no limit
 * An importer with no 'sample_rows' callback always gets a blank header
 * regardless of $sampleLimit.
 */
function import_send_template(array $importer, string $entityType, ?int $sampleLimit = 0): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $col = 1;
    foreach ($importer['columns'] as $c) {
        $cellRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '1';
        $label = $c['key'] . ($c['required'] ? ' *' : '');
        $sheet->setCellValue($cellRef, $label);
        $sheet->getStyle($cellRef)->getFont()->setBold(true);
        $sheet->getColumnDimensionByColumn($col)->setWidth(max(14, strlen($label) + 4));
        if (!empty($c['hint'])) {
            $sheet->getComment($cellRef)->getText()->createTextRun($c['hint']);
        }
        $col++;
    }

    $suffix = '-import-template';
    if (($sampleLimit !== 0) && !empty($importer['sample_rows'])) {
        $sampleFn = $importer['sample_rows'];
        $rows = $sampleFn($sampleLimit);
        $rowNum = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach ($importer['columns'] as $c) {
                $cellRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $rowNum;
                $sheet->setCellValue($cellRef, $row[$c['key']] ?? '');
                $col++;
            }
            $rowNum++;
        }
        $suffix = $sampleLimit === null ? '-all-records' : "-sample-$sampleLimit";
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $entityType . $suffix . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

/** Creates the import_batches row and its import_batch_rows children (status 'pending') for every parsed row. */
function import_create_batch(string $entityType, string $fileName, string $storedPath, array $rows): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO import_batches (entity_type, file_name, stored_path, status, total_rows, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$entityType, $fileName, $storedPath, 'pending', count($rows), current_user()['id']]);
        $batchId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO import_batch_rows (import_batch_id, row_num, status, raw_data) VALUES (?,?,?,?)');
        foreach ($rows as $i => $row) {
            $stmt->execute([$batchId, $i + 1, 'pending', json_encode($row, JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->commit();
        return $batchId;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Splits rows (each ['id'=>batch_row_id,'row_num'=>n,'raw'=>data]) into
 * groups sharing the same $groupKey column value, preserving row order.
 * A row with a blank group value is its own single-row group (so ungrouped
 * documents — one product per order — don't need the column filled in).
 */
function import_group_rows(array $rows, string $groupKey): array
{
    $groups = [];
    foreach ($rows as $r) {
        $ref = trim((string)($r['raw'][$groupKey] ?? ''));
        $key = $ref !== '' ? 'ref:' . $ref : 'row:' . $r['id'];
        $groups[$key][] = $r;
    }
    return array_values($groups);
}

/**
 * Runs the importer's validate_row (or, for a grouped importer, one
 * validate_group call per group of rows sharing the same group_key value)
 * over the whole batch and stores per-row pass/fail, without writing
 * anything to the entity's own tables. Marks the batch 'validated'.
 */
function import_validate_batch(array $importer, int $batchId): array
{
    $pdo = db();
    $rowStmt = $pdo->prepare('SELECT * FROM import_batch_rows WHERE import_batch_id = ? ORDER BY row_num');
    $rowStmt->execute([$batchId]);
    $rawRows = $rowStmt->fetchAll();

    $errorCount = 0;
    $results = [];
    $update = $pdo->prepare('UPDATE import_batch_rows SET status = ?, message = ? WHERE id = ?');

    if (!empty($importer['grouped'])) {
        $rows = array_map(fn($r) => ['id' => $r['id'], 'row_num' => (int)$r['row_num'], 'raw' => json_decode($r['raw_data'], true) ?: []], $rawRows);
        $validateGroupFn = $importer['validate_group'];
        foreach (import_group_rows($rows, $importer['group_key']) as $group) {
            $result = $validateGroupFn($group);
            $status = $result['ok'] ? 'pending' : 'error';
            $message = $result['ok'] ? $result['summary'] ?? null : implode(' ', $result['errors']);
            if (!$result['ok']) $errorCount += count($group);
            foreach ($group as $r) {
                $update->execute([$status, $message, $r['id']]);
                $results[] = ['row_num' => $r['row_num'], 'raw' => $r['raw'], 'ok' => $result['ok'], 'errors' => $result['errors'] ?? []];
            }
        }
    } else {
        $validateFn = $importer['validate_row'];
        foreach ($rawRows as $r) {
            $data = json_decode($r['raw_data'], true) ?: [];
            $result = $validateFn($data);
            $status = $result['ok'] ? 'pending' : 'error';
            $message = $result['ok'] ? null : implode(' ', $result['errors']);
            if (!$result['ok']) $errorCount++;
            $update->execute([$status, $message, $r['id']]);
            $results[] = ['row_num' => (int)$r['row_num'], 'raw' => $data, 'ok' => $result['ok'], 'errors' => $result['errors']];
        }
    }

    $pdo->prepare('UPDATE import_batches SET status = ?, error_count = ? WHERE id = ?')->execute(['validated', $errorCount, $batchId]);

    return $results;
}

/**
 * Re-validates and commits every still-pending row of the batch (rows
 * already marked 'error' at preview time are skipped, not retried).
 *
 * Plain importers: each row runs in its own transaction, so one bad row
 * can't roll back the rest.
 *
 * Grouped importers (multi-line documents like Purchase/Sales Orders):
 * each GROUP of rows (one document) runs in a single transaction, so a
 * document is never left half-written with only some of its lines saved —
 * either the whole document commits or none of it does. Every row in the
 * group is stamped with the same outcome and the same created_record_id
 * (the document's id).
 *
 * Marks the batch 'completed'.
 */
function import_commit_batch(array $importer, int $batchId): array
{
    $pdo = db();
    $rowStmt = $pdo->prepare("SELECT * FROM import_batch_rows WHERE import_batch_id = ? AND status = 'pending' ORDER BY row_num");
    $rowStmt->execute([$batchId]);
    $rawRows = $rowStmt->fetchAll();

    $successCount = 0;
    $errorCount = 0;
    $results = [];
    $update = $pdo->prepare('UPDATE import_batch_rows SET status = ?, message = ?, created_record_id = ? WHERE id = ?');

    if (!empty($importer['grouped'])) {
        $rows = array_map(fn($r) => ['id' => $r['id'], 'row_num' => (int)$r['row_num'], 'raw' => json_decode($r['raw_data'], true) ?: []], $rawRows);
        $validateGroupFn = $importer['validate_group'];
        $commitGroupFn = $importer['commit_group'];

        foreach (import_group_rows($rows, $importer['group_key']) as $group) {
            $validated = $validateGroupFn($group);
            if (!$validated['ok']) {
                $message = implode(' ', $validated['errors']);
                foreach ($group as $r) {
                    $update->execute(['error', $message, null, $r['id']]);
                    $errorCount++;
                    $results[] = ['row_num' => $r['row_num'], 'status' => 'error', 'message' => $message];
                }
                continue;
            }

            $pdo->beginTransaction();
            try {
                $outcome = $commitGroupFn($group, $validated['data']);
                $pdo->commit();
                foreach ($group as $r) {
                    $update->execute([$outcome['status'], $outcome['message'], $outcome['record_id'] ?? null, $r['id']]);
                    $results[] = ['row_num' => $r['row_num'], 'status' => $outcome['status'], 'message' => $outcome['message']];
                }
                if ($outcome['status'] === 'success') {
                    $successCount += count($group);
                } else {
                    $errorCount += count($group);
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                foreach ($group as $r) {
                    $update->execute(['error', 'Could not save this document.', null, $r['id']]);
                    $errorCount++;
                    $results[] = ['row_num' => $r['row_num'], 'status' => 'error', 'message' => 'Could not save this document.'];
                }
            }
        }
    } else {
        $validateFn = $importer['validate_row'];
        $commitFn = $importer['commit_row'];

        foreach ($rawRows as $r) {
            $data = json_decode($r['raw_data'], true) ?: [];
            $validated = $validateFn($data);
            if (!$validated['ok']) {
                $update->execute(['error', implode(' ', $validated['errors']), null, $r['id']]);
                $errorCount++;
                $results[] = ['row_num' => (int)$r['row_num'], 'status' => 'error', 'message' => implode(' ', $validated['errors'])];
                continue;
            }

            $pdo->beginTransaction();
            try {
                $outcome = $commitFn($validated['data']);
                $pdo->commit();
                $update->execute([$outcome['status'], $outcome['message'], $outcome['record_id'] ?? null, $r['id']]);
                if ($outcome['status'] === 'success') {
                    $successCount++;
                } else {
                    $errorCount++;
                }
                $results[] = ['row_num' => (int)$r['row_num'], 'status' => $outcome['status'], 'message' => $outcome['message']];
            } catch (Exception $e) {
                $pdo->rollBack();
                $update->execute(['error', 'Could not save this row.', null, $r['id']]);
                $errorCount++;
                $results[] = ['row_num' => (int)$r['row_num'], 'status' => 'error', 'message' => 'Could not save this row.'];
            }
        }
    }

    $pdo->prepare('UPDATE import_batches SET status = ?, success_count = success_count + ?, error_count = error_count + ?, completed_at = NOW() WHERE id = ?')
        ->execute(['completed', $successCount, $errorCount, $batchId]);

    return $results;
}
