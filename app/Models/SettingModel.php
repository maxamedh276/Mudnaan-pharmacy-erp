<?php

namespace App\Models;

use CodeIgniter\Model;

class SettingModel extends Model
{
    protected $table            = 'settings';
    protected $primaryKey       = 'id';
    protected $allowedFields    = ['key', 'value'];
    protected $useTimestamps    = false;

    /**
     * Get all settings as key => value associative array
     */
    public function allAssoc(): array
    {
        $rows = $this->findAll();
        $assoc = [];
        foreach ($rows as $row) {
            $assoc[$row['key']] = $row['value'];
        }
        return $assoc;
    }

    /**
     * Get single setting value by key
     */
    public function getVal(string $key, ?string $default = null): ?string
    {
        $row = $this->where('key', $key)->first();
        return $row['value'] ?? $default;
    }

    /**
     * Set setting value by key (updates or inserts)
     */
    public function setVal(string $key, ?string $value): bool
    {
        $existing = $this->where('key', $key)->first();
        if ($existing) {
            return $this->update($existing['id'], ['value' => $value]);
        }
        return (bool) $this->insert(['key' => $key, 'value' => $value]);
    }

    /**
     * Bulk save key => value pairs
     */
    public function saveBatchAssoc(array $pairs): void
    {
        foreach ($pairs as $key => $val) {
            $this->setVal($key, (string) $val);
        }
    }
}
