<?php

namespace App\Modules\Organization\Services;

use Illuminate\Support\Facades\DB;

final class PositionPaths
{
    /** @return array<int, string> */
    public function labels(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(<<<SQL
WITH RECURSIVE ancestry AS (
    SELECT id AS requested_id, id, company_id, parent_position_id,
        title::text AS path, ARRAY[id] AS visited
    FROM positions WHERE id IN ($slots)
    UNION ALL
    SELECT a.requested_id, p.id, p.company_id, p.parent_position_id,
        p.title || ' / ' || a.path, a.visited || p.id
    FROM ancestry a JOIN positions p ON p.id = a.parent_position_id AND p.company_id = a.company_id
    WHERE NOT p.id = ANY(a.visited)
)
SELECT a.requested_id, c.title || ' / ' || a.path AS label
FROM ancestry a JOIN companies c ON c.id = a.company_id
WHERE a.parent_position_id IS NULL
SQL, $ids);

        return collect($rows)->pluck('label', 'requested_id')->all();
    }
}
