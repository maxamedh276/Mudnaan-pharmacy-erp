<?php

namespace App\Services\Web;

/** Builds complete paths and descendant sets without assuming a maximum category depth. */
class CategoryHierarchy
{
    public function flatten(array $categories): array
    {
        $categoriesById = array_column($categories, null, 'id');
        $hierarchy = [];
        foreach ($categories as $category) {
            $pathIds = [];
            $pathNames = [];
            $cursor = $category;
            $valid = true;
            $active = true;
            while ($cursor) {
                $cursorId = (int) $cursor['id'];
                if (in_array($cursorId, $pathIds, true)) { $valid = false; break; }
                $pathIds[] = $cursorId;
                $pathNames[] = $cursor['name'];
                $active = $active && (bool) $cursor['is_active'];
                $parentId = (int) ($cursor['parent_id'] ?? 0);
                if (!$parentId) break;
                if (!isset($categoriesById[$parentId])) { $valid = false; break; }
                $cursor = $categoriesById[$parentId];
            }
            $category['id'] = (int) $category['id'];
            $category['parent_id'] = $category['parent_id'] === null ? null : (int) $category['parent_id'];
            $category['path_ids'] = array_reverse($pathIds);
            $category['path'] = implode(' › ', array_reverse($pathNames));
            $category['depth'] = count($pathIds);
            $category['selectable'] = $valid && $active;
            $category['hierarchy_valid'] = $valid;
            $category['product_count'] = (int) ($category['product_count'] ?? 0);
            $category['total_product_count'] = 0;
            $hierarchy[$category['id']] = $category;
        }
        foreach ($hierarchy as $category) {
            foreach ($category['path_ids'] as $ancestorId) {
                if (isset($hierarchy[$ancestorId])) $hierarchy[$ancestorId]['total_product_count'] += $category['product_count'];
            }
        }
        uasort($hierarchy, static fn ($left, $right) => strnatcasecmp($left['path'], $right['path']) ?: $left['id'] <=> $right['id']);
        return array_values($hierarchy);
    }

    public function descendantIds(array $hierarchy, int $categoryId): array
    {
        return array_values(array_map(static fn ($category) => $category['id'], array_filter($hierarchy, static fn ($category) => $category['hierarchy_valid'] && in_array($categoryId, $category['path_ids'], true))));
    }
}
