<?php

namespace App\Controllers\Accounting;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\AccountingService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Journal entries (Premium) — the transactions list, transaction
 * details, and manual journal creation.
 */
class JournalController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $builder = db_connect()->table('journal_entries')
            ->select('id, uuid, entry_no, entry_date, description,
                      source_type, source_id, total_debit, total_credit, status')
            ->where('branch_id', $this->branchId());

        if ($sourceType = $this->request->getGet('source_type')) {
            $builder->where('source_type', $sourceType);
        }

        if ($dateFrom = $this->request->getGet('date_from')) {
            $builder->where('entry_date >=', $dateFrom);
        }

        if ($dateTo = $this->request->getGet('date_to')) {
            $builder->where('entry_date <=', $dateTo);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id']           = (int) $row['id'];
            $row['source_id']    = $row['source_id'] === null ? null : (int) $row['source_id'];
            $row['total_debit']  = (float) $row['total_debit'];
            $row['total_credit'] = (float) $row['total_credit'];
        }

        return $this->respondData($rows, 200, $this->paginationMeta($page, $perPage, $total));
    }

    public function show(int $entryId): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.view')) {
            return $denied;
        }

        $database = db_connect();

        $entry = $database->table('journal_entries')
            ->where('id', $entryId)
            ->where('branch_id', $this->branchId())
            ->get()
            ->getRowArray();

        if ($entry === null) {
            return $this->respondNotFound('Journal entry');
        }

        $entry['id']           = (int) $entry['id'];
        $entry['source_id']    = $entry['source_id'] === null ? null : (int) $entry['source_id'];
        $entry['total_debit']  = (float) $entry['total_debit'];
        $entry['total_credit'] = (float) $entry['total_credit'];

        $entry['lines'] = array_map(static function (array $line): array {
            $line['id']         = (int) $line['id'];
            $line['account_id'] = (int) $line['account_id'];
            $line['debit']      = (float) $line['debit'];
            $line['credit']     = (float) $line['credit'];

            return $line;
        }, $database->table('journal_entry_lines')
            ->select('journal_entry_lines.id, journal_entry_lines.account_id,
                      accounts.code AS account_code, accounts.name AS account_name,
                      journal_entry_lines.debit, journal_entry_lines.credit,
                      journal_entry_lines.description')
            ->join('accounts', 'accounts.id = journal_entry_lines.account_id')
            ->where('journal_entry_lines.journal_entry_id', $entryId)
            ->get()
            ->getResultArray());

        return $this->respondData($entry);
    }

    /**
     * POST /api/v1/journal-entries — a manual entry.
     * Body: { entry_date, description?, lines: [{account_id, debit?, credit?, description?}] }
     */
    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('accounting.manage')) {
            return $denied;
        }

        $body  = $this->body();
        $lines = $body['lines'] ?? [];

        if (! is_array($lines) || count($lines) < 2) {
            return $this->respondValidationErrors(['lines' => 'A journal entry needs at least two lines.']);
        }

        $database = db_connect();
        $database->transException(true)->transStart();

        try {
            $entryId = (new AccountingService())->createEntry(
                branchId: $this->branchId(),
                entryDate: $body['entry_date'] ?? date('Y-m-d'),
                description: $body['description'] ?? null,
                sourceType: 'manual',
                sourceId: null,
                lines: $lines,
                userId: AuthContext::userId(),
            );
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $database->transRollback();

            if ($exception->getMessage() === 'PERIOD_CLOSED') {
                return $this->respondError(
                    'PERIOD_CLOSED',
                    'That date falls in a closed financial period.',
                    422,
                );
            }

            return $this->respondError('UNBALANCED_ENTRY', $exception->getMessage(), 422);
        }

        $database->transComplete();

        return $this->show($entryId)->setStatusCode(201);
    }
}
