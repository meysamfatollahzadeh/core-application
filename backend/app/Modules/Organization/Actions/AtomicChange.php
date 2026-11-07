<?php

namespace App\Modules\Organization\Actions;

use PDOException;
use Illuminate\Support\Facades\DB;

final class AtomicChange
{
    public static function run(callable $change): mixed
    {
        try {
            return DB::transaction(function () use ($change) {
                DB::statement('SELECT pg_advisory_xact_lock(7261107)');

                return $change();
            });
        } catch (PDOException $e) {
            if (in_array($e->errorInfo[0] ?? '', ['23503', '23505', '23514', '23502', '40001', '40P01'], true)) {
                abort(409, 'The change conflicts with existing assignments or organizational rules.');
            }
            throw $e;
        }
    }
}
