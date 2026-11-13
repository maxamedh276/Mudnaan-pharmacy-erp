<?php
namespace App\Services\Web;
use App\Libraries\AuthContext;
use App\Services\AccountingService;
use App\Services\InventoryService;
use App\Services\PackageInventoryService;
use InvalidArgumentException;

/** Initial on-hand stock, entered once during product creation in the same transaction. */
class OpeningStockService
{
    public function create(array $product, array $input, bool $validateOnly = false): void
    {
        $sealed = $input['opening_sealed'] ?? 0;
        $opened = $input['opening_quantity'] ?? 0;
        foreach ([$sealed,$opened] as $qty) {
            if (!is_numeric($qty) || !is_finite((float)$qty) || (float)$qty < 0 || (float)$qty > 999999999.999 || abs((float)$qty-round((float)$qty,3)) > 0.0000001) throw new InvalidArgumentException('Enter non-negative opening quantities with up to three decimals.');
        }
        if ((float)$sealed != floor((float)$sealed) || ((float)$sealed > 0 && empty($product['package_unit']))) throw new InvalidArgumentException('Opening sealed stock must be whole packages of a sealed product.');
        $total = round((float)$sealed * (float)($product['package_size'] ?? 0) + (float)$opened,3);
        if ($total == 0) return;
        if ($total > 999999999.999) throw new InvalidArgumentException('Opening stock is too large.');
        if (!AuthContext::can('stock.adjust')) throw new InvalidArgumentException('You need stock adjustment permission to enter opening stock.');
        $cost = $input['opening_unit_cost'] ?? null;
        if (!is_numeric($cost) || !is_finite((float)$cost) || (float)$cost < 0 || (float)$cost > 9999999999.99) throw new InvalidArgumentException('Enter the opening cost per unit shown in the form.');
        $baseCost = round((float)$cost / (!empty($product['package_unit']) ? (float)$product['package_size'] : 1),6);
        $value = round($total*$baseCost,2);
        if ($value > 999999999999.99) throw new InvalidArgumentException('Opening stock value is too large.');
        $branch = (int)(AuthContext::$user->branch_id ?? 0); $user = (int)AuthContext::userId();
        if (!$branch || !$user) throw new InvalidArgumentException('Sign in before entering opening stock.');
        if ($validateOnly) return;
        $db = db_connect();
        $db->table('product_opening_stocks')->insert(['product_id'=>$product['id'],'branch_id'=>$branch,'sealed_quantity'=>(int)$sealed,'opened_quantity'=>(float)$opened,'base_unit_cost'=>$baseCost,'total_value'=>$value,'created_by'=>$user]);
        $openingId = (int)$db->insertID();
        $db->table('products')->where('id',$product['id'])->update(['cost_price'=>$baseCost]);
        (new InventoryService())->recordMovement($branch,(int)$product['id'],$total,'opening','opening_stock',$openingId,$user);
        if ((int)$sealed) (new PackageInventoryService())->record($branch,(int)$product['id'],(int)$sealed,(float)$product['package_size'],'opening_stock',$openingId,$user);
        if (AuthContext::isPremium()) (new AccountingService())->postOpeningStock($branch,$value,(int)$product['id'],$user);
        \App\Services\AuditService::log('stock.opening','inventory','product_opening_stocks',$openingId,after:['product_id'=>$product['id'],'sealed_quantity'=>(int)$sealed,'opened_quantity'=>(float)$opened,'value'=>$value]);
    }
}
