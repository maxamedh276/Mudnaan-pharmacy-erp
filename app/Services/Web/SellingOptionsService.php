<?php
namespace App\Services\Web;
use InvalidArgumentException;

/** Shared quantity names; prices always come from the selected product. */
class SellingOptionsService
{
    public function all(): array
    {
        $rows = db_connect()->table('selling_option_groups')->orderBy('name')->get()->getResultArray();
        foreach ($rows as &$row) $row['options'] = json_decode($row['options_json'], true) ?? [];
        return $rows;
    }
    public function save(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $unit = trim((string)($input['unit'] ?? ''));
        $options = $input['options'] ?? null;
        if ($name === '' || mb_strlen($name) > 100 || !in_array($unit, ['kg','litre','pcs','g','ml','box','pack','cup'], true)) throw new InvalidArgumentException('Enter a group name and a valid unit.');
        if (!is_array($options) || !$options || count($options) > 20) throw new InvalidArgumentException('Add between 1 and 20 quantity shortcuts.');
        $clean = []; $names = [];
        foreach ($options as $option) {
            $label = trim((string)($option['label'] ?? ''));
            $qty = $option['quantity'] ?? null;
            if ($label === '' || mb_strlen($label) > 40 || isset($names[mb_strtolower($label)]) || !is_numeric($qty) || !is_finite((float)$qty) || (float)$qty <= 0 || (float)$qty > 999999999.999 || abs((float)$qty - round((float)$qty,3)) > 0.0000001) throw new InvalidArgumentException('Use unique labels and positive quantities with up to three decimals.');
            $names[mb_strtolower($label)] = true;
            $clean[] = ['label'=>$label, 'quantity'=>round((float)$qty,3)];
        }
        return \App\Services\FinancialTransaction::run(function ($db) use ($input, $name, $unit, $clean) {
            $id = (int)($input['id'] ?? 0);
            if ($id) {
                $old = $db->query('SELECT * FROM selling_option_groups WHERE id=? FOR UPDATE', [$id])->getRowArray();
                if (!$old) throw new InvalidArgumentException('Selling option group not found.');
                if ($old['unit'] !== $unit && $db->table('products')->where('selling_group_id',$id)->countAllResults()) throw new InvalidArgumentException('This group is assigned to products. Keep its unit or create another group.');
            }
            $fields = ['name'=>$name,'unit'=>$unit,'options_json'=>json_encode($clean),'is_active'=>empty($input['is_active']) ? 0 : 1];
            if ($id) $db->table('selling_option_groups')->where('id',$id)->update($fields);
            else { $db->table('selling_option_groups')->insert($fields); $id = (int)$db->insertID(); }
            \App\Services\AuditService::log('selling_options.save','inventory','selling_option_groups',$id,after:$fields);
            return $db->table('selling_option_groups')->where('id',$id)->get()->getRowArray();
        });
    }
    public function forProduct(array $product): array
    {
        if (empty($product['selling_group_id'])) return [];
        $group = db_connect()->table('selling_option_groups')->where('id',$product['selling_group_id'])->where('is_active',1)->get()->getRowArray();
        return $group && $group['unit'] === $product['unit'] ? (json_decode($group['options_json'],true) ?? []) : [];
    }
}
