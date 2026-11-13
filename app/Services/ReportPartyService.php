<?php

namespace App\Services;

/** Branch-scoped document drilldowns. Payment allocations, rather than mutable paid totals,
 * reconstruct settlements at the selected closing date, including later reversals. */
class ReportPartyService
{
    public function saleSettlements(int $branch, string $to): array
    {
        $db = db_connect();
        $cutoff = $to . ' 23:59:59';
        $result = [];
        $byInvoice = [];
        $byReceipt = [];
        $returns = $this->returnAdjustments($branch, $to);
        $receipts = $db->table('customer_receipts')->where('branch_id', $branch)->get()->getResultArray();
        $allocations = $db->query('SELECT a.* FROM customer_receipt_allocations a JOIN customer_receipts r ON r.id=a.receipt_id WHERE r.branch_id=?', [$branch])->getResultArray();
        foreach ($allocations as $allocation) {
            $byReceipt[(int) $allocation['receipt_id']][] = $allocation;
        }
        foreach ($receipts as $receipt) {
            $parts = $byReceipt[(int) $receipt['id']] ?? ($receipt['invoice_id'] ? [['invoice_id' => $receipt['invoice_id'], 'amount' => $receipt['amount'], 'discount' => $receipt['discount']]] : []);
            foreach ($parts as $part) {
                $byInvoice[(int) $part['invoice_id']][] = array_replace($receipt, ['amount' => $part['amount'], 'discount' => $part['discount']]);
            }
        }
        $invoices = $db->table('customer_invoices')->where('branch_id', $branch)->where('deleted_at', null)->where('status !=', 'cancelled')->where('sale_id !=', null)->get()->getResultArray();
        foreach ($invoices as $invoice) {
            $r = $returns['sale'][(int)$invoice['sale_id']] ?? [];
            $result[(int) $invoice['sale_id']] = round($this->settled($invoice, $byInvoice[(int) $invoice['id']] ?? [], 'received_at', $cutoff) - ($r['refund'] ?? 0) - ($r['discount_reversal'] ?? 0), 2);
        }
        foreach ($db->table('sales')->where('branch_id', $branch)->where('sold_at <=', $cutoff)->get()->getResultArray() as $sale) {
            if (isset($result[(int)$sale['id']])) continue;
            $r = $returns['sale'][(int)$sale['id']] ?? [];
            $result[(int)$sale['id']] = round((float)$sale['paid_total'] + (float)$sale['refunded_total'] + (float)$sale['return_discount_total'] - ($r['refund'] ?? 0) - ($r['discount_reversal'] ?? 0), 2);
        }
        return $result;
    }

    /** Credit notes effective at the closing date, independent of original order dates. */
    public function returnAdjustments(int $branch, string $to): array
    {
        $rows = db_connect()->query('SELECT kind,source_id,SUM(total) total,SUM(refund) refund,SUM(discount_reversal) discount_reversal FROM document_returns WHERE branch_id=? AND returned_at<=? GROUP BY kind,source_id', [$branch, $to . ' 23:59:59'])->getResultArray();
        $result = ['sale' => [], 'purchase' => []];
        foreach ($rows as $row) $result[$row['kind']][(int)$row['source_id']] = array_map('floatval', array_intersect_key($row, array_flip(['total', 'refund', 'discount_reversal'])));
        return $result;
    }
    public function build(string $type, int $branch, string $from, string $to, string $search, ?int $party): array
    {
        return $type === 'suppliers' ? $this->suppliers($branch, $from, $to, $search, $party) : $this->customers($branch, $from, $to, $search, $party);
    }
    private function suppliers(int $branch, string $from, string $to, string $search, ?int $party): array
    {
        $db = db_connect();
        $cutoff = $to . ' 23:59:59';
        $returns = $this->returnAdjustments($branch, $to)['purchase'];
        $returnHistory = $this->returnHistory('purchase', $branch, $to, 'source_id');
        $suppliers = $db->table('suppliers')->select('id,name,phone,deleted_at')->orderBy('name')->get()->getResultArray();
        $parties = array_map(static fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], $suppliers);
        $parties[] = ['id' => 0, 'name' => 'Unassigned supplier'];
        $names = array_column($suppliers, 'name', 'id');
        $payments = $db->table('supplier_payments')->where('branch_id', $branch)->orderBy('paid_at')->get()->getResultArray();
        $byBill = [];
        $credits = [];
        foreach ($payments as $payment) {
            if ($payment['bill_id']) {
                $byBill[(int) $payment['bill_id']][] = $payment;
            } elseif ($this->effective($payment, 'paid_at', $cutoff)) {
                $credits[(int) $payment['supplier_id']] = ($credits[(int) $payment['supplier_id']] ?? 0) + (float) $payment['amount'];
            }
        }
        $laterReceipts = $db->query('SELECT r.purchase_id,SUM(i.line_total) amount FROM purchase_receipts r JOIN purchase_receipt_items i ON i.receipt_id=r.id WHERE r.branch_id=? AND r.received_at>? GROUP BY r.purchase_id', [$branch, $cutoff])->getResultArray();
        $laterValues = array_column($laterReceipts, 'amount', 'purchase_id');
        $bills = $db->table('supplier_bills')->where('branch_id', $branch)->where('deleted_at', null)->where('status !=', 'cancelled')->where('bill_date <=', $to)->get()->getResultArray();
        $balances = [];
        $billsByPurchase = [];
        foreach ($bills as &$bill) {
            // A purchase bill grows with each goods receipt. Exclude goods received later.
            if ($bill['purchase_id']) {
                $r = $returns[(int)$bill['purchase_id']] ?? [];
                $bill['total'] = round((float) $bill['total'] + (float)$bill['returned_total'] - ($r['total'] ?? 0) - (float) ($laterValues[(int) $bill['purchase_id']] ?? 0), 2);
            }
            $r = $returns[(int)$bill['purchase_id']] ?? [];
            $bill['settled'] = round($this->settled($bill, $byBill[(int) $bill['id']] ?? [], 'paid_at', $cutoff) - ($r['refund'] ?? 0), 2);
            $bill['due'] = round((float) $bill['total'] - $bill['settled'], 2);
            $id = (int) $bill['supplier_id'];
            $balances[$id] = ($balances[$id] ?? 0) + $bill['due'];
            if ($bill['purchase_id']) {
                $billsByPurchase[(int) $bill['purchase_id']][] = $bill;
            }
        }
        unset($bill);
        foreach ($credits as $id => $amount) {
            $balances[$id] = ($balances[$id] ?? 0) - $amount;
        }
        $purchases = $db->table('purchases')->where('branch_id', $branch)->where('purchased_at >=', $from)->where('purchased_at <=', $cutoff)->orderBy('purchased_at')->orderBy('id')->get()->getResultArray();
        $items = $db->query('SELECT i.*,p.name product FROM purchase_items i JOIN purchases o ON o.id=i.purchase_id LEFT JOIN products p ON p.id=i.product_id WHERE o.branch_id=?', [$branch])->getResultArray();
        $itemsByPurchase = [];
        foreach ($items as $item) {
            $itemsByPurchase[(int) $item['purchase_id']][] = $item;
        }
        $receiving = $db->query('SELECT r.purchase_id,r.receipt_no,r.received_at,i.purchase_item_id,i.quantity,i.unit_cost,i.line_total,p.name product FROM purchase_receipts r JOIN purchase_receipt_items i ON i.receipt_id=r.id LEFT JOIN products p ON p.id=i.product_id WHERE r.branch_id=? ORDER BY r.received_at,r.id,i.id', [$branch])->getResultArray();
        $receipts = [];
        $received = [];
        $hasHistory = [];
        foreach ($receiving as $r) {
            $hasHistory[(int) $r['purchase_id']] = true;
            if ($r['received_at'] > $cutoff) {
                continue;
            }
            $receipts[(int) $r['purchase_id']][] = $r;
            $received[(int) $r['purchase_item_id']] = ($received[(int) $r['purchase_item_id']] ?? 0) + (float) $r['quantity'];
        }
        $rows = [];
        $keys = [];
        $details = [];
        $ordered = 0;
        $billed = 0;
        $settled = 0;
        foreach ($purchases as $purchase) {
            $supplier = (int) $purchase['supplier_id'];
            if ($party !== null && $party !== $supplier) {
                continue;
            }
            $id = (int) $purchase['id'];
            $linked = $billsByPurchase[$id] ?? [];
            $total = array_sum(array_column($linked, 'total'));
            $paid = array_sum(array_column($linked, 'settled'));
            $due = array_sum(array_column($linked, 'due'));
            $itemRows = [];
            foreach ($itemsByPurchase[$id] ?? [] as $item) {
                $qty = isset($hasHistory[$id]) ? $received[(int) $item['id']] ?? 0 : (float) $item['quantity_received'];
                $itemRows[] = [$item['product'] ?? 'Removed product', (float) $item['quantity'], $qty, max(0, round((float) $item['quantity'] - $qty, 3)), (float) $item['unit_cost'], round($qty * (float) $item['unit_cost'], 2)];
            }
            $receiptRows = array_map(static fn($r) => [$r['receipt_no'], $r['received_at'], $r['product'] ?? 'Removed product', (float) $r['quantity'], (float) $r['unit_cost'], (float) $r['line_total']], $receipts[$id] ?? []);
            $billRows = [];
            $paymentRows = [];
            foreach ($linked as $bill) {
                $billRows[] = [$bill['bill_no'], $bill['bill_date'], (float) $bill['total'], $bill['settled'], $bill['due'], $this->paymentStatus((float) $bill['total'], $bill['settled'])];
                foreach ($byBill[(int) $bill['id']] ?? [] as $payment) {
                    if ($payment['paid_at'] <= $cutoff) {
                        $paymentRows[] = [$payment['payment_no'], $payment['paid_at'], $payment['method'], (float) $payment['amount'], $this->effective($payment, 'paid_at', $cutoff) ? 'active' : 'voided'];
                    }
                }
            }
            $row = [$purchase['purchased_at'], $purchase['reference_no'] ?: 'PO-' . $id, $names[$supplier] ?? 'Unassigned supplier', (float) $purchase['total'], $total, $paid, $due, $purchase['status'], $linked ? $this->paymentStatus($total, $paid) : 'not_billed'];
            $detail = ['title' => 'Purchase ' . ($purchase['reference_no'] ?: 'PO-' . $id), 'tables' => [$this->table('Purchase items', ['Product', 'Ordered qty', 'Received qty', 'Pending qty', 'Unit cost', 'Received value'], $itemRows), $this->table('Receiving history', ['Receipt', 'Date received', 'Product', 'Quantity', 'Unit cost', 'Amount received'], $receiptRows), $this->table('Bills as of ' . $to, ['Bill', 'Date', 'Total', 'Settled', 'Due', 'Payment status'], $billRows), $this->table('Payments as of ' . $to, ['Payment', 'Date', 'Method', 'Amount', 'Status'], $paymentRows)]];
            $detail['tables'][] = $this->table('Purchase returns through ' . $to, ['Return', 'Date', 'Original order', 'Reason', 'Return value', 'Debt reduced', 'Refund received', 'Discount reversed'], $returnHistory[$id] ?? []);
            $ordered += (float) $purchase['total'];
            $billed += $total;
            $settled += $paid;
            if (!$this->matches($row, $detail, $search)) {
                continue;
            }
            $key = 'purchase-' . $id;
            $rows[] = $row;
            $keys[] = $key;
            $details[$key] = $detail;
        }
        // Service bills have no stock purchase and must remain visible in the supplier report.
        foreach ($bills as $bill) {
            $id = (int) $bill['supplier_id'];
            if ($bill['purchase_id'] || $bill['bill_date'] < $from || $party !== null && $party !== $id) {
                continue;
            }
            $row = [$bill['bill_date'], $bill['bill_no'], $names[$id] ?? 'Supplier', 0.0, (float) $bill['total'], $bill['settled'], $bill['due'], 'service_bill', $this->paymentStatus((float) $bill['total'], $bill['settled'])];
            $billItems = $db->table('supplier_bill_items')->where('bill_id', (int) $bill['id'])->get()->getResultArray();
            $detail = ['title' => 'Bill ' . $bill['bill_no'], 'tables' => [$this->table('Bill items', ['Description', 'Quantity', 'Unit cost', 'Total'], array_map(static fn($i) => [$i['description'], (float) $i['quantity'], (float) $i['unit_cost'], (float) $i['line_total']], $billItems)), $this->table('Payments', ['Payment', 'Date', 'Method', 'Amount', 'Status'], array_values(array_map(fn($p) => [$p['payment_no'], $p['paid_at'], $p['method'], (float) $p['amount'], $this->effective($p, 'paid_at', $cutoff) ? 'active' : 'voided'], array_filter($byBill[(int) $bill['id']] ?? [], static fn($p) => $p['paid_at'] <= $cutoff))))]];
            $billed += (float) $bill['total'];
            $settled += $bill['settled'];
            if (!$this->matches($row, $detail, $search)) {
                continue;
            }
            $key = 'bill-' . $bill['id'];
            $rows[] = $row;
            $keys[] = $key;
            $details[$key] = $detail;
        }
        $payable = $party === null ? array_sum($balances) : $balances[$party] ?? 0;
        return ['columns' => ['Date', 'Purchase / bill', 'Supplier', 'Ordered total', 'Billed total', 'Settled', 'Due', 'Order status', 'Bill status'], 'rows' => $rows, 'rowKeys' => $keys, 'details' => $details, 'parties' => $parties, 'summary' => ['Ordered in period' => $ordered, 'Billed for listed documents' => $billed, 'Settled for listed documents' => $settled, 'Net payable' => round($payable, 2)], 'notice' => 'Purchases are selected by order date; service bills by bill date. Open an eye button for items, receiving dates, bills and payments. Net payable includes all bills and on-account payments through ' . $to . ', including older purchases. Pending orders without bills are not payable. Order status is current; settlement figures are as of the closing date.'];
    }
    private function customers(int $branch, string $from, string $to, string $search, ?int $party): array
    {
        $db = db_connect();
        $cutoff = $to . ' 23:59:59';
        $returns = $this->returnAdjustments($branch, $to)['sale'];
        $returnHistory = $this->returnHistory('sale', $branch, $to, 'party_id');
        $rows = [];
        $keys = [];
        $details = [];
        $orders = [];
        $balances = [];
        $credits = [];
        $customers = $db->table('customers')->select('id,name,phone,deleted_at')->orderBy('name')->get()->getResultArray();
        $customers[] = ['id' => 0, 'name' => 'Walk-in / unassigned', 'phone' => '', 'deleted_at' => null];
        $parties = array_map(static fn($p) => ['id' => (int) $p['id'], 'name' => $p['name']], $customers);
        $receipts = $db->table('customer_receipts')->where('branch_id', $branch)->orderBy('received_at')->get()->getResultArray();
        $allocations = $db->query('SELECT a.* FROM customer_receipt_allocations a JOIN customer_receipts r ON r.id=a.receipt_id WHERE r.branch_id=?', [$branch])->getResultArray();
        $byReceipt = [];
        foreach ($allocations as $allocation) {
            $byReceipt[(int) $allocation['receipt_id']][] = $allocation;
        }
        $byInvoice = [];
        $partyReceipts = [];
        foreach ($receipts as $receipt) {
            $partyReceipts[(int) $receipt['customer_id']][] = $receipt;
            $parts = $byReceipt[(int) $receipt['id']] ?? ($receipt['invoice_id'] ? [['invoice_id' => $receipt['invoice_id'], 'amount' => $receipt['amount'], 'discount' => $receipt['discount']]] : []);
            foreach ($parts as $part) {
                $byInvoice[(int) $part['invoice_id']][] = array_replace($receipt, ['amount' => $part['amount'], 'discount' => $part['discount']]);
            }
            if (!$parts && $this->effective($receipt, 'received_at', $cutoff)) {
                $credits[(int) $receipt['customer_id']] = ($credits[(int) $receipt['customer_id']] ?? 0) + (float) $receipt['amount'] + (float) $receipt['discount'];
            }
        }
        $invoices = $db->table('customer_invoices')->where('branch_id', $branch)->where('deleted_at', null)->where('status !=', 'cancelled')->where('invoice_date <=', $to)->get()->getResultArray();
        $invoiceBySale = [];
        foreach ($invoices as &$invoice) {
            $r = $returns[(int)$invoice['sale_id']] ?? [];
            $invoice['total'] = round((float)$invoice['total'] + (float)$invoice['returned_total'] - ($r['total'] ?? 0), 2);
            $invoice['settled'] = round($this->settled($invoice, $byInvoice[(int) $invoice['id']] ?? [], 'received_at', $cutoff) - ($r['refund'] ?? 0) - ($r['discount_reversal'] ?? 0), 2);
            $invoice['due'] = round((float) $invoice['total'] - $invoice['settled'], 2);
            $id = (int) $invoice['customer_id'];
            $balances[$id] = ($balances[$id] ?? 0) + $invoice['due'];
            if ($invoice['sale_id']) {
                $invoiceBySale[(int) $invoice['sale_id']] = $invoice;
            }
        }
        unset($invoice);
        $invoiceById = array_column($invoices, null, 'id');
        $deposits = $db->table('customer_deposits')->where('branch_id', $branch)->where('deposited_at <=', $cutoff)->get()->getResultArray();
        $partyDeposits = [];
        foreach ($deposits as $deposit) {
            $id = (int) $deposit['customer_id'];
            if ($deposit['status'] === 'refunded' && $deposit['updated_at'] <= $cutoff) {
                continue;
            }
            $credits[$id] = ($credits[$id] ?? 0) + (float) $deposit['amount'];
            $partyDeposits[$id][] = $deposit;
        }
        foreach ($credits as $id => $amount) {
            $balances[$id] = ($balances[$id] ?? 0) - $amount;
        }
        $sales = $db->table('sales')->where('branch_id', $branch)->where('sold_at <=', $cutoff)->whereIn('status', ['completed', 'partial'])->orderBy('sold_at')->get()->getResultArray();
        $saleItems = $db->query('SELECT i.* FROM sale_items i JOIN sales s ON s.id=i.sale_id WHERE s.branch_id=?', [$branch])->getResultArray();
        $bySaleItems = [];
        foreach ($saleItems as $item) {
            $bySaleItems[(int) $item['sale_id']][] = $item;
        }
        $invoiceItems = $db->query('SELECT i.* FROM customer_invoice_items i JOIN customer_invoices c ON c.id=i.invoice_id WHERE c.branch_id=?', [$branch])->getResultArray();
        $byInvoiceItems = [];
        foreach ($invoiceItems as $item) {
            $byInvoiceItems[(int) $item['invoice_id']][] = $item;
        }
        foreach ($sales as $sale) {
            $id = (int) $sale['customer_id'];
            $invoice = $invoiceBySale[(int) $sale['id']] ?? null;
            $r = $returns[(int)$sale['id']] ?? [];
            $sale['grand_total'] = round((float)$sale['grand_total'] - ($r['total'] ?? 0), 2);
            $paid = $invoice ? $invoice['settled'] : round((float)$sale['paid_total'] + (float)$sale['refunded_total'] + (float)$sale['return_discount_total'] - ($r['refund'] ?? 0) - ($r['discount_reversal'] ?? 0), 2);
            $due = round((float) $sale['grand_total'] - $paid, 2);
            $orders[$id][] = ['row' => [$sale['sold_at'], $sale['invoice_no'], (float) $sale['grand_total'], $paid, $due, $this->paymentStatus((float) $sale['grand_total'], $paid)], 'items' => $bySaleItems[(int) $sale['id']] ?? []];
        }
        foreach ($invoices as $invoice) {
            if (!$invoice['sale_id']) {
                $orders[(int) $invoice['customer_id']][] = ['row' => [$invoice['invoice_date'], $invoice['invoice_no'], (float) $invoice['total'], $invoice['settled'], $invoice['due'], $this->paymentStatus((float) $invoice['total'], $invoice['settled'])], 'items' => $byInvoiceItems[(int) $invoice['id']] ?? []];
            }
        }
        $totalOrders = 0;
        $totalSales = 0;
        $totalSettled = 0;
        foreach ($customers as $customer) {
            $id = (int) $customer['id'];
            if ($party !== null && $party !== $id) {
                continue;
            }
            $all = $orders[$id] ?? [];
            $period = array_values(array_filter($all, static fn($o) => substr($o['row'][0], 0, 10) >= $from));
            $balance = round($balances[$id] ?? 0, 2);
            if (!$period && abs($balance) < 0.001) {
                continue;
            }
            $periodRows = array_column($period, 'row');
            $total = round(array_sum(array_column($periodRows, 2)), 2);
            $paid = round(array_sum(array_column($periodRows, 3)), 2);
            $orderRows = [];
            $itemRows = [];
            foreach ($all as $order) {
                $inPeriod = substr($order['row'][0], 0, 10) >= $from;
                if (!$inPeriod && abs($order['row'][4]) < 0.001) {
                    continue;
                }
                $orderRows[] = [...$order['row'], $inPeriod ? 'selected_period' : 'balance_brought_forward'];
                foreach ($order['items'] as $item) {
                    $itemRows[] = [$order['row'][1], $item['description'], (float) $item['quantity'], (float) $item['unit_price'], (float) $item['line_total']];
                }
            }
            usort($orderRows, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $collectionRows = [];
            foreach ($partyReceipts[$id] ?? [] as $receipt) {
                if ($receipt['received_at'] > $cutoff) {
                    continue;
                }
                $parts = $byReceipt[(int) $receipt['id']] ?? [];
                $refs = [];
                foreach ($parts as $part) {
                    $invoice = $invoiceById[(int) $part['invoice_id']] ?? null;
                    if ($invoice) {
                        $refs[] = $invoice['invoice_no'] . ' (' . number_format((float) $part['amount'] + (float) $part['discount'], 2) . ')';
                    }
                }
                if (!$refs && isset($invoiceById[(int) $receipt['invoice_id']])) {
                    $refs[] = $invoiceById[(int) $receipt['invoice_id']]['invoice_no'];
                }
                $collectionRows[] = [$receipt['receipt_no'], $receipt['received_at'], implode(', ', $refs) ?: 'On account', (float) $receipt['amount'], (float) $receipt['discount'], $this->effective($receipt, 'received_at', $cutoff) ? 'active' : 'voided'];
            }
            $row = [$customer['name'], $customer['phone'], count($period), $total, $paid, $balance];
            $detail = ['title' => $customer['name'] . ' — orders & collections', 'tables' => [$this->table('Orders in period and older outstanding orders', ['Date', 'Order', 'Total', 'Settled', 'Due', 'Payment status', 'Scope'], $orderRows), $this->table('Order items', ['Order', 'Description', 'Quantity', 'Unit price', 'Line total'], $itemRows), $this->table('Customer receipts through ' . $to, ['Receipt', 'Date', 'Applied to orders', 'Collected', 'Discount', 'Status'], $collectionRows), $this->table('Advance deposits / customer credit', ['Deposit', 'Date', 'Amount'], array_map(static fn($d) => [$d['deposit_no'], $d['deposited_at'], (float) $d['amount']], $partyDeposits[$id] ?? []))]];
            $detail['tables'][] = $this->table('Sales returns through ' . $to, ['Return', 'Date', 'Original order', 'Reason', 'Return value', 'Debt reduced', 'Refunded', 'Discount reversed'], $returnHistory[$id] ?? []);
            $totalOrders += count($period);
            $totalSales += $total;
            $totalSettled += $paid;
            if (!$this->matches($row, $detail, $search)) {
                continue;
            }
            $key = 'customer-' . $id;
            $rows[] = $row;
            $keys[] = $key;
            $details[$key] = $detail;
        }
        $outstanding = $party === null ? array_sum($balances) : $balances[$party] ?? 0;
        return ['columns' => ['Customer', 'Phone', 'Orders in period', 'Order total', 'Settled', 'Closing balance'], 'rows' => $rows, 'rowKeys' => $keys, 'details' => $details, 'parties' => $parties, 'summary' => ['Orders in period' => $totalOrders, 'Order total' => $totalSales, 'Settled for period orders' => $totalSettled, 'Net outstanding' => round($outstanding, 2)], 'notice' => 'Order totals cover the selected period. Closing balances include all invoices, receipts and deposits through ' . $to . '. Older unpaid orders appear in the details. Negative balances are customer credit. Settled includes discounts; deposits and on-account credits remain separate until allocated.'];
    }
    private function effective(array $payment, string $date, string $cutoff): bool
    {
        return $payment[$date] <= $cutoff && ($payment['status'] === 'active' || !empty($payment['voided_at']) && $payment['voided_at'] > $cutoff);
    }
    private function returnHistory(string $kind, int $branch, string $to, string $group): array
    {
        $result = [];
        foreach (db_connect()->table('document_returns')->where('kind', $kind)->where('branch_id', $branch)->where('returned_at <=', $to . ' 23:59:59')->orderBy('returned_at')->get()->getResultArray() as $row) {
            $result[(int)$row[$group]][] = [$row['return_no'], $row['returned_at'], $row['source_no'], $row['reason'], (float)$row['total'], (float)$row['debt_reduction'], (float)$row['refund'], (float)$row['discount_reversal']];
        }
        return $result;
    }
    private function settled(array $document, array $payments, string $date, string $cutoff): float
    {
        $initial = (float) $document['paid_total'] + (float)($document['refunded_total'] ?? 0) + (float)($document['return_discount_total'] ?? 0);
        $asOf = 0;
        foreach ($payments as $payment) {
            $amount = (float) $payment['amount'] + (float) ($payment['discount'] ?? 0);
            if ($payment['status'] === 'active') {
                $initial -= $amount;
            }
            if ($this->effective($payment, $date, $cutoff)) {
                $asOf += $amount;
            }
        }
        return round($initial + $asOf, 2);
    }
    private function paymentStatus(float $total, float $paid): string
    {
        return $paid >= $total - 0.001 ? 'paid' : ($paid > 0.001 ? 'partial' : 'unpaid');
    }
    private function table(string $title, array $columns, array $rows): array
    {
        return compact('title', 'columns', 'rows');
    }
    private function matches(array $row, array $detail, string $search): bool
    {
        if ($search === '') {
            return true;
        }
        $text = implode(' ', array_map('strval', $row));
        foreach ($detail['tables'] as $table) {
            foreach ($table['rows'] as $values) {
                $text .= ' ' . implode(' ', array_map('strval', $values));
            }
        }
        return str_contains(mb_strtolower($text), mb_strtolower($search));
    }
}
