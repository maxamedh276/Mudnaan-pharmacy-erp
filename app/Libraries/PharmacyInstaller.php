<?php
namespace App\Libraries;

/** Fresh installation only. Existing databases are always refused. */
final class PharmacyInstaller
{
    public function install(string $name): void
    {
        if (!preg_match('/^pharmacy_(main|test_[a-f0-9]{12})$/D', $name)) throw new \InvalidArgumentException('Use pharmacy_main or a disposable pharmacy_test_* database.');
        $config = config(\Config\Database::class);
        $settings = $config->default;
        $admin = new \mysqli($settings['hostname'], $settings['username'], $settings['password'], '', (int)$settings['port']);
        $result = $admin->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" . $admin->real_escape_string($name) . "'");
        if ($result->num_rows !== 0) throw new \RuntimeException('Database already exists; installer will not overwrite it. Use reviewed migrations to upgrade.');
        $files = ['01_tenant_database.sql','02_tenant_accounting.sql','03_permissions_seed.sql','04_financial_management.sql','05_financial_controls.sql','06_inventory_accounting.sql'];
        foreach ($files as $file) {
            $sql = str_replace('{{DB_NAME}}', $name, file_get_contents(APPPATH . 'Database/TenantSchema/' . $file));
            $admin->multi_query($sql);
            do { if ($rows = $admin->store_result()) $rows->free(); } while ($admin->more_results() && $admin->next_result());
        }
        $admin->close();
        $config->default['database'] = $name;
        $db = db_connect();
        if ($db->getDatabase() !== $name) $db->setDatabase($name);
        service('migrations')->setNamespace('App')->latest();
        $db->table('branches')->where('id', 1)->update(['name' => 'Mudnaan Pharmacy']);
        $setting = new \App\Models\SettingModel();
        $setting->saveBatchAssoc(['business_name' => 'Mudnaan Pharmacy', 'business_type' => 'pharmacy', 'uses_batches' => '1', 'uses_serials' => '0', 'currency' => 'USD']);
        foreach (['Analgesics', 'Antibiotics', 'Vitamins & supplements', 'Digestive health', 'Dermatology', 'Eye & ear care', 'Medical supplies'] as $category) $db->table('categories')->insert(['name' => $category, 'is_active' => 1]);
    }
}
