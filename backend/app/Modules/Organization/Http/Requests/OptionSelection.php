<?php

namespace App\Modules\Organization\Http\Requests;

use Illuminate\Http\Request;

final class OptionSelection
{
    public static function prioritize(Request $request, $query, string $column): void
    {
        $data = $request->validate(['selected' => ['nullable', 'string', 'max:2100', 'regex:/^[1-9][0-9]*(,[1-9][0-9]*)*$/']]);
        if (empty($data['selected'])) {
            return;
        }
        $ids = array_values(array_unique(explode(',', $data['selected'])));
        abort_if(count($ids) > 100, 422, 'Too many selected values.');
        foreach ($ids as $id) {
            abort_if(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false, 422, 'Invalid selected ID.');
        }
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $query->orderByRaw("CASE WHEN {$column} IN ({$slots}) THEN 0 ELSE 1 END", $ids);
    }
}
