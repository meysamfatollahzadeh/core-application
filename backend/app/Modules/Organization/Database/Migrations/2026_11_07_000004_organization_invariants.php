<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION organization_write_lock() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(7261107);
    RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION organization_validate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM buildings b WHERE (SELECT count(*) FROM building_areas a WHERE a.building_id=b.id AND a.is_default) <> 1) THEN
        RAISE EXCEPTION 'Every building requires one default area' USING ERRCODE='23514';
    END IF;
    IF EXISTS (SELECT 1 FROM building_areas WHERE is_default AND title <> 'نامشخص') THEN
        RAISE EXCEPTION 'Default area title is protected' USING ERRCODE='23514';
    END IF;
    IF EXISTS (SELECT 1 FROM user_position_reports r JOIN user_positions u ON u.id=r.user_position_id WHERE r.report_to_position_id=u.position_id) THEN
        RAISE EXCEPTION 'An assignment cannot report to its own position' USING ERRCODE='23514';
    END IF;
    IF EXISTS (SELECT 1 FROM user_positions u JOIN positions p ON p.id=u.position_id WHERE p.parent_position_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM user_position_reports r WHERE r.user_position_id=u.id)) THEN
        RAISE EXCEPTION 'Nonroot assignments require reporting targets' USING ERRCODE='23514';
    END IF;
    IF EXISTS (
        WITH RECURSIVE chart AS (
            SELECT id, parent_position_id, ARRAY[id] AS path, false AS cycle FROM positions
            UNION ALL
            SELECT p.id, p.parent_position_id, c.path || p.id, p.id=ANY(c.path)
            FROM chart c JOIN positions p ON p.id=c.parent_position_id WHERE NOT c.cycle
        ) SELECT 1 FROM chart WHERE cycle
    ) THEN
        RAISE EXCEPTION 'Position chart contains a cycle' USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION organization_protect_position() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.company_id <> NEW.company_id THEN RAISE EXCEPTION 'Position company is immutable' USING ERRCODE='23514'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER protect_position_company BEFORE UPDATE ON positions FOR EACH ROW EXECUTE FUNCTION organization_protect_position();
SQL);
        foreach (['buildings', 'building_areas', 'positions', 'user_positions', 'user_position_reports'] as $table) {
            DB::unprepared("CREATE TRIGGER org_lock BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION organization_write_lock(); CREATE CONSTRAINT TRIGGER org_validate AFTER INSERT OR UPDATE OR DELETE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION organization_validate();");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION organization_write_lock() CASCADE; DROP FUNCTION organization_validate() CASCADE; DROP FUNCTION organization_protect_position() CASCADE;');
    }
};
