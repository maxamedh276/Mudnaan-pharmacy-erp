<?php

namespace App\Services;

use InvalidArgumentException;

/** Credit notes, refunds, stock and the general ledger commit together or roll back together. */
class DocumentReturnService
{
    public function __construct(
        private readonly AccountingService $accounting = new AccountingService(),
        private readonly InventoryService $inventory = new InventoryService(),
    ) {}

    public function listing(string $kind, int $branch): array
    {
        $this->kind($kind);
        return db_connect()->table('document_returns')->where('kind', $kind)->where('branch_id', $branch)
            ->orderBy('id', 'DESC')->get()->getResultArray();
    }

    public function find(string $kind, int $id, int $branch): array
    {
        $this->kind($kind);
        $row = db_connect()->table('document_returns')->where('id', $id)->where('kind', $kind)->where('branch_id', $branch)->get()->getRowArray();
        if (!$row) throw new InvalidArgumentException('Return not found in this branch.');
        $row['items'] = db_connect()->table('document_return_items')->where('return_id', $id)->get()->getResultArray();
        return $row;
    }

    public function sources(string $kind, int $branch): array
    {
        $this->kind($kind);
        if ($kind === 'sale') {
            return db_connect()->query("SELECT s.id,s.invoice_no reference,COALESCE(c.name,'Walk-in customer') party FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.branch_id=? AND s.status IN ('completed','partial') AND EXISTS (SELECT 1 FROM sale_items i WHERE i.sale_id=s.id AND i.quantity>COALESCE((SELECT SUM(ri.quantity) FROM document_return_items ri JOIN document_returns r ON r.id=ri.return_id WHERE r.kind='sale' AND r.source_id=s.id AND ri.source_item_id=i.id),0)) ORDER BY s.id DESC", [$branch])->getResultArray();
        }
        return db_connect()->query("SELECT p.id,COALESCE(NULLIF(p.reference_no,''),CONCAT('PO-',p.id)) reference,COALESCE(s.name,'Unassigned supplier') party FROM purchases p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE p.branch_id=? AND p.status IN ('received','partially_received') AND EXISTS (SELECT 1 FROM purchase_items i WHERE i.purchase_id=p.id AND i.quantity_received>COALESCE((SELECT SUM(ri.quantity) FROM document_return_items ri JOIN document_returns r ON r.id=ri.return_id WHERE r.kind='purchase' AND r.source_id=p.id AND ri.source_item_id=i.id),0)) ORDER BY p.id DESC", [$branch])->getResultArray();
    }

    /** Original quantities and values are immutable; remaining entitlement comes from credit notes. */
    public function source(string $kind, int $id, int $branch): array
    {
        $this->kind($kind);
        $db = db_connect();
        $sale = $kind === 'sale';
        $table = $sale ? 'sales' : 'purchases';
        $source = $db->table($table)->where('id', $id)->where('branch_id', $branch)->get()->getRowArray();
        if (!$source || !in_array($source['status'], $sale ? ['completed', 'partial'] : ['received', 'partially_received'], true)) {
            throw new InvalidArgumentException('Select an eligible original order from this branch.');
        }
        if (substr($source[$sale ? 'sold_at' : 'purchased_at'], 0, 10) > date('Y-m-d')) throw new InvalidArgumentException('Future-dated orders cannot be returned before their transaction date.');
        if (!$sale && $db->table('purchase_receipts')->where('purchase_id', $id)->where('received_at >=', date('Y-m-d', strtotime('+1 day')))->countAllResults()) throw new InvalidArgumentException('Correct future-dated goods receipts before returning this purchase.');
        $document = $db->table($sale ? 'customer_invoices' : 'supplier_bills')->where($sale ? 'sale_id' : 'purchase_id', $id)
            ->where('branch_id', $branch)->where('deleted_at', null)->get()->getRowArray();
        if ($document && $document['status'] === 'cancelled') throw new InvalidArgumentException('The linked financial document is cancelled.');
        if (!$sale && !$document) throw new InvalidArgumentException('The received purchase has no supplier bill.');
        $partyId = $source[$sale ? 'customer_id' : 'supplier_id'];
        $party = $partyId ? $db->table($sale ? 'customers' : 'suppliers')->where('id', $partyId)->get()->getRowArray() : null;
        $previous = $db->query('SELECT i.source_item_id,SUM(i.quantity) quantity FROM document_return_items i JOIN document_returns r ON r.id=i.return_id WHERE r.kind=? AND r.source_id=? AND r.branch_id=? GROUP BY i.source_item_id', [$kind, $id, $branch])->getResultArray();
        $returned = array_column($previous, 'quantity', 'source_item_id');
        $items = $db->query($sale
            ? 'SELECT i.*,p.cost_price current_cost,p.name product_name FROM sale_items i LEFT JOIN products p ON p.id=i.product_id WHERE i.sale_id=? ORDER BY i.id'
            : 'SELECT i.*,p.cost_price current_cost,p.name product_name FROM purchase_items i LEFT JOIN products p ON p.id=i.product_id WHERE i.purchase_id=? ORDER BY i.id', [$id])->getResultArray();
        $totals = $taxes = $costs = [];
        if ($sale) {
            $weights = array_column($items, 'line_total', 'id');
            if (array_sum($weights) <= 0) $weights = array_column($items, 'quantity', 'id');
            // Order-level discount and shipping are spread over the original sold lines.
            $totals = ReturnCalculation::allocate($this->cents($source['grand_total']), $weights);
            foreach ($items as $item) {
                $net = round((float) $item['quantity'] * (float) $item['unit_price'] - (float) $item['discount'], 2);
                $taxes[$item['id']] = max(0, $this->cents($item['line_total']) - $this->cents($net));
                $costs[$item['id']] = $item['cost_total'] === null ? null : $this->cents($item['cost_total']);
            }
            $taxes = ReturnCalculation::allocate($this->cents($source['tax_total']), $taxes);
            if (in_array(null, $costs, true)) {
                // Legacy receipts did not snapshot line cost. Recover the exact original posted COGS,
                // apportioned using catalog cost weights; Basic legacy sales fall back to catalog cost.
                $postedCost = $db->query("SELECT SUM(l.debit-l.credit) value FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.journal_entry_id JOIN accounts a ON a.id=l.account_id WHERE e.branch_id=? AND e.source_type='sale' AND e.source_id=? AND e.status='posted' AND a.code=?", [$branch, $id, AccountingService::COGS])->getRow('value');
                $weights = [];
                foreach ($items as $item) $weights[$item['id']] = (float) $item['quantity'] * (float) $item['current_cost'];
                if (array_sum($weights) <= 0) $weights = array_column($items, 'quantity', 'id');
                $costs = ReturnCalculation::allocate($postedCost === null ? $this->cents(array_sum(array_map(static fn($i) => round((float)$i['quantity'] * (float)$i['current_cost'], 2), $items))) : $this->cents($postedCost), $weights);
            }
        } else {
            $receivedValues = $db->query('SELECT i.purchase_item_id,SUM(i.line_total) value FROM purchase_receipt_items i JOIN purchase_receipts r ON r.id=i.receipt_id WHERE r.purchase_id=? AND r.branch_id=? GROUP BY i.purchase_item_id', [$id, $branch])->getResultArray();
            $totals = array_map(fn($value) => $this->cents($value), array_column($receivedValues, 'value', 'purchase_item_id'));
        }
        $lines = [];
        foreach ($items as $item) {
            $lineId = (int) $item['id'];
            $quantity = (int) round((float) $item[$sale ? 'quantity' : 'quantity_received'] * 1000);
            if ($quantity <= 0) continue;
            $prior = (int) round((float) ($returned[$lineId] ?? 0) * 1000);
            $lines[] = [
                'id' => $lineId, 'product_id' => (int)$item['product_id'],
                'description' => ($item[$sale ? 'description' : 'product_name'] ?? 'Removed product') . (!$sale && !empty($item['unit_label']) ? ' (' . $item['unit_label'] . ')' : ''),
                'stock_factor' => (float)($item['stock_factor'] ?? 1), 'stock_mode' => $item['stock_mode'] ?? 'loose',
                'unit_label' => $item['unit_label'] ?? '',
                'quantity' => $quantity / 1000, 'returned' => $prior / 1000, 'remaining' => ($quantity - $prior) / 1000,
                'value_cents' => $totals[$lineId] ?? $this->cents((float)$item['quantity_received'] * (float)$item['unit_cost']),
                'tax_cents' => $taxes[$lineId] ?? 0, 'cost_cents' => $costs[$lineId] ?? 0,
                'current_cost' => (float)$item['current_cost'], 'cost_estimated' => $sale && $item['cost_total'] === null,
                'on_hand' => $this->inventory->currentQuantity($branch, (int)$item['product_id']),
            ];
        }
        $waived = 0;
        if ($sale && $document) {
            $waived = $this->cents($db->query("SELECT COALESCE(SUM(CASE WHEN a.id IS NULL THEN r.discount ELSE a.discount END),0) value FROM customer_receipts r LEFT JOIN customer_receipt_allocations a ON a.receipt_id=r.id WHERE COALESCE(a.invoice_id,r.invoice_id)=? AND r.status='active'", [$document['id']])->getRow('value')) - $this->cents($document['return_discount_total']);
        }
        return [
            'branch_id' => $branch, 'id' => $id, 'kind' => $kind, 'reference' => $sale ? $source['invoice_no'] : ($source['reference_no'] ?: 'PO-' . $id),
            'party_id' => $partyId, 'party' => $party['name'] ?? ($sale ? 'Walk-in customer' : 'Unassigned supplier'),
            'document_id' => $document['id'] ?? null,
            'due_cents' => $document ? max(0, $this->cents($document['total']) - $this->cents($document['paid_total'])) : 0,
            'paid_cents' => $this->cents($document['paid_total'] ?? $source['paid_total'] ?? 0),
            'waived_cents' => max(0, $waived), 'lines' => $lines,
        ];
    }

    public function quote(string $kind, int $id, array $data, int $branch): array
    {
        return $this->calculate($this->source($kind, $id, $branch), $data['items'] ?? null);
    }

    private function calculate(array $source, mixed $items): array
    {
        if (!is_array($items) || !$items || count($items) > 500) throw new InvalidArgumentException('Select at least one item to return.');
        $available = array_column($source['lines'], null, 'id');
        $seen = $stock = $rows = [];
        $total = $tax = $cost = 0;
        foreach ($items as $input) {
            $id = is_array($input) ? filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if (!$id || isset($seen[$id]) || !isset($available[$id])) throw new InvalidArgumentException('Select each original order line only once.');
            $seen[$id] = true;
            $line = $available[$id];
            $qty = ReturnCalculation::quantity($input['quantity'] ?? null);
            $original = (int) round($line['quantity'] * 1000);
            $prior = (int) round($line['returned'] * 1000);
            $value = ReturnCalculation::portion($line['value_cents'], $original, $prior, $qty);
            $lineTax = ReturnCalculation::portion($line['tax_cents'], $original, $prior, $qty);
            $lineCost = $source['kind'] === 'sale' ? ReturnCalculation::portion($line['cost_cents'], $original, $prior, $qty) : $this->cents($qty / 1000 * $line['stock_factor'] * $line['current_cost']);
            $product = $line['product_id'];
            $stock[$product] = ($stock[$product] ?? 0) + (int)round($qty * $line['stock_factor']);
            if ($line['stock_mode'] === 'sealed' && $qty % 1000 !== 0) throw new InvalidArgumentException('Return sealed packages as whole numbers.');
            if ($source['kind'] === 'purchase') {
                $poolKey = $product . ':' . $line['stock_mode'];
                $stock[$poolKey] = ($stock[$poolKey] ?? 0) + $qty / 1000;
                $productRow = db_connect()->table('products')->where('id', $product)->get()->getRowArray();
                (new PackageInventoryService())->requireStock($source['branch_id'], $productRow, $line['stock_mode'], $stock[$poolKey]);
            }
            if ($source['kind'] === 'purchase' && $stock[$product] > (int)round($line['on_hand'] * 1000)) throw new InvalidArgumentException('Not enough stock on hand to return these goods to the supplier.');
            $rows[] = ['source_item_id' => $id, 'product_id' => $product, 'description' => $line['description'], 'quantity' => $qty / 1000, 'total' => $value / 100, 'tax_total' => $lineTax / 100, 'stock_value' => $lineCost / 100];
            $total += $value;
            $tax += $lineTax;
            $cost += $lineCost;
        }
        $debt = min($total, $source['due_cents']);
        $excess = $total - $debt;
        // A settlement discount was waived, never collected. Reverse it before issuing cash.
        $discount = min($excess, $source['waived_cents']);
        $refund = $excess - $discount;
        if ($excess > $source['paid_cents']) throw new InvalidArgumentException('The original order balance is inconsistent. Review its payments before returning.');
        $token = hash('sha256', json_encode([$rows, $total, $tax, $cost, $debt, $refund, $discount]));
        return ['source' => $source, 'items' => $rows, 'total' => $total / 100.0, 'tax_total' => $tax / 100.0, 'stock_value' => $cost / 100.0, 'debt_reduction' => $debt / 100.0, 'refund' => $refund / 100.0, 'discount_reversal' => $discount / 100.0, 'quote_token' => $token];
    }

    public function create(string $kind, int $id, array $data, int $branch, int $user, string $plan): array
    {
        $this->kind($kind);
        if (!is_string($data['uuid'] ?? null) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $data['uuid'])) throw new InvalidArgumentException('A valid return request ID is required.');
        if (!is_string($data['reason'] ?? null) || trim($data['reason']) === '' || mb_strlen($data['reason']) > 500) throw new InvalidArgumentException('Enter a return reason of up to 500 characters.');
        $account = $data['account_id'] ?? null;
        if ($account !== null && $account !== '' && !filter_var($account, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new InvalidArgumentException('Select a valid refund cash account.');
        $hash = hash('sha256', json_encode([$kind, $id, $data['items'] ?? null, (int)$account, trim($data['reason'])]));
        // Read the party hint before BEGIN. Inside MySQL REPEATABLE READ, an early
        // plain SELECT would freeze stale balances even after waiting for row locks.
        $sourceTable = $kind === 'sale' ? 'sales' : 'purchases';
        $hint = db_connect()->table($sourceTable)->where('id', $id)->where('branch_id', $branch)->get()->getRowArray();
        if (!$hint) throw new InvalidArgumentException('Original order not found in this branch.');
        return FinancialTransaction::run(function ($db) use ($kind, $id, $data, $branch, $user, $plan, $account, $hash, $sourceTable, $hint) {
            // Use the same customer -> invoice -> sale lock order as collection/receipt reversal.
            if ($kind === 'sale' && $hint['customer_id']) $db->query('SELECT id FROM customers WHERE id=? FOR UPDATE', [$hint['customer_id']]);
            $documentTable = $kind === 'sale' ? 'customer_invoices' : 'supplier_bills';
            // Purchase receipt also locks the order before its bill.
            if ($kind === 'purchase') $db->query('SELECT id FROM purchases WHERE id=? FOR UPDATE', [$id]);
            $db->query('SELECT id FROM ' . $documentTable . ' WHERE ' . ($kind === 'sale' ? 'sale_id' : 'purchase_id') . '=? AND branch_id=? FOR UPDATE', [$id, $branch]);
            if ($kind === 'sale') $db->query('SELECT id FROM sales WHERE id=? FOR UPDATE', [$id]);
            $productIds = $db->query('SELECT product_id FROM ' . ($kind === 'sale' ? 'sale_items WHERE sale_id' : 'purchase_items WHERE purchase_id') . '=? FOR UPDATE', [$id])->getResultArray();
            $productIds = array_unique(array_map('intval', array_column($productIds, 'product_id')));
            sort($productIds);
            foreach ($productIds as $productId) $db->query('SELECT id FROM products WHERE id=? FOR UPDATE', [$productId]);
            if ($account) $db->query('SELECT id FROM accounts WHERE id=? FOR UPDATE', [(int)$account]);
            // The first consistent read occurs only after all relevant writers are excluded.
            $existing = $db->table('document_returns')->where('uuid', $data['uuid'])->get()->getRowArray();
            if ($existing) {
                if ((int)$existing['branch_id'] !== $branch || !hash_equals($existing['request_hash'], $hash)) throw new InvalidArgumentException('This request ID was already used for a different return.');
                return $this->find($kind, (int)$existing['id'], $branch);
            }
            $source = $db->table($sourceTable)->where('id', $id)->get()->getRowArray();
            if ($db->table('financial_periods')->where('status', 'closed')->where('start_date <=', date('Y-m-d'))->where('end_date >=', date('Y-m-d'))->countAllResults()) throw new InvalidArgumentException('PERIOD_CLOSED');
            $quote = $this->quote($kind, $id, $data, $branch);
            if (isset($data['quote_token']) && (!is_string($data['quote_token']) || !hash_equals($quote['quote_token'], $data['quote_token']))) throw new InvalidArgumentException('The order changed after the preview. Re-select the order and review the updated return.');
            $sourceInfo = $quote['source'];
            if ($kind === 'sale') {
                // Freeze recovered legacy costs so later catalog edits cannot change partial returns.
                foreach ($sourceInfo['lines'] as $line) {
                    if ($line['cost_estimated']) $db->table('sale_items')->where('id', $line['id'])->update(['cost_total' => $line['cost_cents'] / 100]);
                }
            }
            $accountId = null;
            if ($quote['refund'] > 0) {
                $accounts = (new ReceiptCashAccountService())->list();
                if (!in_array((int)$account, array_map('intval', array_column($accounts, 'id')), true)) throw new InvalidArgumentException('Select an active cash account for the refund.');
                $accountId = (int)$account;
                $db->query('SELECT id FROM accounts WHERE id=? FOR UPDATE', [$accountId]);
                // Recheck after locking so concurrent account deactivation cannot slip through.
                if (!$db->table('accounts')->where('id', $accountId)->where('is_active', 1)->where('is_postable', 1)->get()->getRowArray()) throw new InvalidArgumentException('The selected cash account is inactive or not postable.');
            }
            $header = array_intersect_key($quote, array_flip(['total', 'tax_total', 'stock_value', 'debt_reduction', 'refund', 'discount_reversal']));
            $db->table('document_returns')->insert($header + [
                'uuid' => $data['uuid'], 'request_hash' => $hash, 'kind' => $kind, 'branch_id' => $branch, 'source_id' => $id,
                'document_id' => $sourceInfo['document_id'], 'party_id' => $sourceInfo['party_id'], 'party_name' => $sourceInfo['party'],
                'source_no' => $sourceInfo['reference'], 'reason' => trim($data['reason']), 'account_id' => $accountId, 'created_by' => $user,
                'returned_at' => date('Y-m-d H:i:s'),
            ]);
            $returnId = (int)$db->insertID();
            $number = ($kind === 'sale' ? 'SR-' : 'PR-') . str_pad((string)$returnId, 5, '0', STR_PAD_LEFT);
            $db->table('document_returns')->where('id', $returnId)->update(['return_no' => $number]);
            foreach ($quote['items'] as $line) {
                $db->table('document_return_items')->insert($line + ['return_id' => $returnId]);
                $originalLine = array_column($sourceInfo['lines'], null, 'id')[$line['source_item_id']];
                $baseLine = $line;
                $baseLine['quantity'] = round($line['quantity'] * $originalLine['stock_factor'], 3);
                if ($kind === 'sale') $this->restoreCost($branch, $baseLine);
                if ($originalLine['stock_mode'] === 'sealed') (new PackageInventoryService())->record($branch, (int)$line['product_id'], ($kind === 'sale' ? 1 : -1) * (int)$line['quantity'], $originalLine['stock_factor'], $kind . '_return', $returnId, $user);
                $this->inventory->recordMovement($branch, $line['product_id'], ($kind === 'sale' ? 1 : -1) * $baseLine['quantity'], 'return', $kind . '_return', $returnId, $user);
            }
            $paidReduction = round($quote['refund'] + $quote['discount_reversal'], 2);
            if ($sourceInfo['document_id']) {
                $document = $db->table($documentTable)->where('id', $sourceInfo['document_id'])->get()->getRowArray();
                $newTotal = round((float)$document['total'] - $quote['total'], 2);
                $newPaid = round((float)$document['paid_total'] - $paidReduction, 2);
                if ($newTotal < 0 || $newPaid < 0 || $newPaid > $newTotal) throw new InvalidArgumentException('The linked document balance is inconsistent.');
                $db->table($documentTable)->where('id', $document['id'])->update([
                    'total' => $newTotal, 'paid_total' => $newPaid, 'status' => $newPaid >= $newTotal ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid'),
                    'returned_total' => round((float)$document['returned_total'] + $quote['total'], 2),
                    'refunded_total' => round((float)$document['refunded_total'] + $quote['refund'], 2),
                    'return_discount_total' => round((float)$document['return_discount_total'] + $quote['discount_reversal'], 2),
                ]);
            }
            if ($kind === 'sale') {
                $newReturned = round((float)$source['returned_total'] + $quote['total'], 2);
                $newPaid = round((float)$source['paid_total'] - $paidReduction, 2);
                $db->table('sales')->where('id', $id)->update([
                    'returned_total' => $newReturned, 'refunded_total' => round((float)$source['refunded_total'] + $quote['refund'], 2),
                    'return_discount_total' => round((float)$source['return_discount_total'] + $quote['discount_reversal'], 2),
                    'paid_total' => $newPaid, 'status' => $newPaid >= round((float)$source['grand_total'] - $newReturned, 2) ? 'completed' : 'partial',
                ]);
                if ($sourceInfo['party_id']) $db->table('customers')->where('id', $sourceInfo['party_id'])->set('balance', 'balance + ' . $quote['debt_reduction'], false)->update();
            }
            // A plan downgrade must not leave an originally posted transaction unreversed.
            $originalPosted = $kind === 'sale'
                ? $db->table('journal_entries')->where('branch_id', $branch)->where('source_type', 'sale')->where('source_id', $id)->where('status', 'posted')->countAllResults() > 0
                : (bool)$db->query("SELECT e.id FROM journal_entries e JOIN purchase_receipts r ON r.id=e.source_id WHERE e.branch_id=? AND e.source_type='purchase_receipt' AND e.status='posted' AND r.purchase_id=? LIMIT 1", [$branch, $id])->getRowArray();
            if (($plan === 'premium' || $originalPosted) && ($quote['total'] > 0 || $quote['stock_value'] > 0)) $this->post($kind, $returnId, $number, $quote, $accountId, $branch, $user);
            AuditService::log($kind . '_return.create', $kind === 'sale' ? 'sales' : 'purchases', 'document_returns', $returnId, after: $header, details: ['source_id' => $id, 'reason' => trim($data['reason'])]);
            return $this->find($kind, $returnId, $branch);
        });
    }

    private function restoreCost(int $branch, array $line): void
    {
        $db = db_connect();
        $product = $db->table('products')->where('id', $line['product_id'])->get()->getRowArray();
        if (!$product) throw new InvalidArgumentException('The original product no longer exists.');
        $onHand = $this->inventory->currentQuantity($branch, $line['product_id']);
        $newQty = $onHand + $line['quantity'];
        if ($newQty > 0) $db->table('products')->where('id', $line['product_id'])->update(['cost_price' => round(($onHand * (float)$product['cost_price'] + $line['stock_value']) / $newQty, 6)]);
    }

    private function post(string $kind, int $id, string $number, array $q, ?int $account, int $branch, int $user): void
    {
        $lines = [];
        $add = static function (string|int $code, float $value) use (&$lines): void {
            if ($value == 0) return;
            $lines[] = (is_int($code) ? ['account_id' => $code] : ['code' => $code]) + ($value > 0 ? ['debit' => $value] : ['credit' => -$value]);
        };
        if ($kind === 'sale') {
            $add(AccountingService::REVENUE, round($q['total'] - $q['tax_total'], 2));
            $add(AccountingService::TAX_PAYABLE, $q['tax_total']);
            $add(AccountingService::RECEIVABLE, -$q['debt_reduction']);
            if ($account) $add($account, -$q['refund']);
            $add(AccountingService::SALES_DISCOUNT, -$q['discount_reversal']);
            $add(AccountingService::INVENTORY, $q['stock_value']);
            $add(AccountingService::COGS, -$q['stock_value']);
        } else {
            $add(AccountingService::PAYABLE, $q['debt_reduction']);
            if ($account) $add($account, $q['refund']);
            $add(AccountingService::INVENTORY, -$q['stock_value']);
            // Goods leave at current average cost; the supplier credit uses their original cost.
            $add(AccountingService::INVENTORY_ADJ, round($q['stock_value'] - $q['total'], 2));
        }
        $this->accounting->createEntry($branch, date('Y-m-d'), $number . ' — ' . $q['source']['reference'], $kind . '_return', $id, $lines, $user);
    }

    private function cents(mixed $value): int { return (int)round((float)$value * 100); }
    private function kind(string $kind): void
    {
        if (!in_array($kind, ['sale', 'purchase'], true)) throw new InvalidArgumentException('Unknown return type.');
    }
}
