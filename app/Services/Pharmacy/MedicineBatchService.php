<?php
namespace App\Services\Pharmacy;

use InvalidArgumentException;

/** Pharmacy stock uses the ERP movement ledger and product-row transaction locks. */
final class MedicineBatchService
{
    public function isMedicine(int $product): bool
    {
        return db_connect()->tableExists('pharmacy_batch_states') && db_connect()->table('pharmacy_medicines')->where('product_id', $product)->countAllResults() > 0;
    }

    public function batches(int $branch, ?int $product = null, ?array $productIds = null): array
    {
        if ($productIds===[]) return [];
        $query = db_connect()->table('product_batches b')->select("b.*, p.name, p.unit, COALESCE(s.status, 'unverified') AS status, s.reason, COALESCE(SUM(m.qty_change),0) AS quantity, COALESCE(MAX(m.id),0) AS latest_movement_id")
            ->join('products p', 'p.id=b.product_id')->join('pharmacy_medicines medicine', 'medicine.product_id=p.id')
            ->join('pharmacy_batch_states s', 's.batch_id=b.id', 'left')
            ->join('stock_movements m', 'm.batch_id=b.id AND m.branch_id=b.branch_id AND m.product_id=b.product_id', 'left')
            ->where('b.branch_id', $branch)->groupBy('b.id')->orderBy('b.expiry_date')->orderBy('b.id');
        if ($product !== null) $query->where('b.product_id', $product);
        if ($productIds!==null) $query->whereIn('b.product_id',$productIds);
        if (db_connect()->tableExists('pharmacy_opened_packs')) $query->select("o.id AS opened_pack_id,o.opened_at,o.use_by AS opened_use_by,o.source_batch_id,COALESCE(ps.status,'unverified') AS source_status")->join('pharmacy_opened_packs o','o.batch_id=b.id','left')->join('pharmacy_batch_states ps','ps.batch_id=o.source_batch_id','left');
        $rows=$query->get()->getResultArray();
        // An opened child must never outlive a quarantine/recall of its original lot.
        foreach ($rows as &$row) if (!empty($row['opened_pack_id']) && $row['source_status']!=='active') $row['status']='source_blocked';unset($row);
        return $rows;
    }

    public function create(int $branch, int $product, array $input): int
    {
        $number = PharmacyInput::text($input, 'batch_no', 80, true);
        $expiry = PharmacyInput::date($input, 'expiry_date', true);
        $manufactured = PharmacyInput::date($input, 'mfg_date');
        if ($expiry < date('Y-m-d') || ($manufactured !== null && ($manufactured > date('Y-m-d') || $manufactured > $expiry))) throw new InvalidArgumentException('Enter valid manufacturing and expiry dates.');
        $db = db_connect();
        $existing = $db->table('product_batches')->where('branch_id', $branch)->where('product_id', $product)->where('batch_no', $number)->get()->getRowArray();
        if ($existing) {
            if ($existing['expiry_date'] !== $expiry || $existing['mfg_date'] !== $manufactured) throw new InvalidArgumentException('This batch number already has different dates.');
            $state = $db->table('pharmacy_batch_states')->where('batch_id', $existing['id'])->get()->getRowArray();
            if (!$state || $state['status'] !== 'active') throw new InvalidArgumentException('This batch is not available for receiving.');
            return (int)$existing['id'];
        }
        $db->table('product_batches')->insert(['branch_id' => $branch, 'product_id' => $product, 'batch_no' => $number, 'expiry_date' => $expiry, 'mfg_date' => $manufactured]);
        $id = (int)$db->insertID();
        $db->table('pharmacy_batch_states')->insert(['batch_id' => $id, 'status' => 'active']);
        return $id;
    }

    public static function allocate(array $batches, float $quantity, string $today): array
    {
        $remaining = (int)round(PharmacyInput::quantity($quantity) * 1000);
        usort($batches, static fn ($a, $b) => [$a['opened_use_by'] ?? (($a['expiry_date'] ?? '9999-12-31').' 23:59:59'), (int)$a['id']] <=> [$b['opened_use_by'] ?? (($b['expiry_date'] ?? '9999-12-31').' 23:59:59'), (int)$b['id']]);
        $allocations = [];
        foreach ($batches as $batch) {
            if (!self::sellable($batch,$today)) continue;
            $take = min($remaining, max(0, (int)round((float)$batch['quantity'] * 1000)));
            if ($take > 0) $allocations[] = ['batch_id' => (int)$batch['id'], 'quantity' => $take / 1000];
            $remaining -= $take;
            if ($remaining === 0) return $allocations;
        }
        throw new InvalidArgumentException('Insufficient unexpired, released batch stock. Reconcile unallocated inventory before selling it.');
    }

    public function validateMovement(int $branch, int $product, int $batch, float $change, bool $selling): void
    {
        $rows = array_column($this->batches($branch, $product), null, 'id');
        $row = $rows[$batch] ?? null;
        if (!$row) throw new InvalidArgumentException('Batch does not belong to this medicine and pharmacy location.');
        if ($selling && !self::sellable($row,date('Y-m-d'))) throw new InvalidArgumentException('Expired, quarantined, recalled or past-use-by batches cannot be sold.');
        if ($change < 0 && round((float)$row['quantity'] + $change, 3) < 0) throw new InvalidArgumentException('Insufficient stock in the selected batch.');
    }

    public static function sellable(array $batch, string $today): bool
    {
        return ($batch['status']??'')==='active' && !empty($batch['expiry_date']) && $batch['expiry_date'] >= $today
            && (empty($batch['opened_use_by']) || $batch['opened_use_by'] > date('Y-m-d H:i:s'));
    }

    public function supplierReturnAllocations(int $branch, int $product, int $return, float $quantity): array
    {
        $source = db_connect()->table('document_returns')->where('id', $return)->where('branch_id', $branch)->where('kind', 'purchase')->get()->getRowArray();
        if (!$source) throw new InvalidArgumentException('Supplier return document not found.');
        $receipts = db_connect()->table('purchase_receipt_items i')->select('i.batch_id')->join('purchase_receipts r', 'r.id=i.receipt_id')
            ->where('r.purchase_id', $source['source_id'])->where('r.branch_id', $branch)->where('i.product_id', $product)->get()->getResultArray();
        $ids = array_map('intval', array_column($receipts, 'batch_id'));
        $rows = array_values(array_filter($this->batches($branch, $product), static fn ($row) => in_array((int)$row['id'], $ids, true)));
        // Returns may remove expired or recalled stock, but only from the original purchase.
        foreach ($rows as &$row) { $row['status'] = 'active'; $row['expiry_date'] = '9999-12-31'; }
        unset($row);
        return self::allocate($rows, $quantity, date('Y-m-d'));
    }
}
