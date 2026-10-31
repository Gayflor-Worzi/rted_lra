<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Permanent property identity (Source Reference ID).
 *
 * A property is discovered once, then assessed, registered and reassessed many
 * times. Until now the only cross-section clue was the source system's
 * property_id, which stays blank until registration, so an in-flight discovery
 * and its valuation had no shared identifier at all.
 *
 * `properties` is an identity registry, not a second copy of the property
 * record: it holds the permanent PRP-YYYY-##### reference and, once the source
 * system issues one, that property_id. Business data (owner, address,
 * classification, value) stays on the discovery/valuation/bill rows, which
 * remain the records the workflow reads and writes.
 *
 * Backfill links only on reliable evidence: the source-system property_id, or
 * an existing discovery/valuation/bill relation. It never merges records on
 * similar names, addresses or TINs.
 */
return new class extends Migration
{
    private const TABLES = ['property_discoveries', 'valuations', 'property_bills'];

    public function up(): void
    {
        if (! Schema::hasTable('properties')) {
            Schema::create('properties', function (Blueprint $table) {
                $table->id();
                $table->string('source_reference')->unique();
                // Source-system property ID. Nullable until the property is
                // registered; unique once it exists so one source property can
                // never resolve to two RETD identities.
                $table->string('property_id', 60)->nullable()->unique();
                $table->timestamps();
            });
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'property_ref_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    // Indexed, deliberately NOT a foreign key: SQLite cannot add a
                    // constraint in place, so Laravel rebuilds the table — and that
                    // rebuild drops property_bills' stored `assessment_key` column
                    // and its unique index, which is the duplicate-bill backstop.
                    // Identity rows are never deleted anyway; a null link simply
                    // means the record predates the registry.
                    $blueprint->unsignedBigInteger('property_ref_id')->nullable()->index();
                });
            }
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'property_ref_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('property_ref_id');
                });
            }
        }

        Schema::dropIfExists('properties');
    }

    /**
     * Link existing records to one identity each, using only verified keys.
     * Ambiguous records are not merged: an unlinked record simply receives its
     * own identity, which is the truthful state for an unregistered property.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('properties')) {
            return;
        }

        $year = now()->format('Y');

        // A. One identity per distinct source-system property_id.
        $bySourceId = [];

        foreach (self::TABLES as $table) {
            if (! $this->linkable($table)) {
                continue;
            }

            DB::table($table)
                ->whereNotNull('property_id')
                ->where('property_id', '!=', '')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (&$bySourceId, $table, $year) {
                    foreach ($rows as $row) {
                        $raw = trim((string) $row->property_id);
                        if ($raw === '') {
                            continue;
                        }

                        $key = mb_strtoupper($raw);
                        if (! isset($bySourceId[$key])) {
                            $bySourceId[$key] = $this->createIdentity($raw, $year);
                        }

                        DB::table($table)
                            ->where('id', $row->id)
                            ->whereNull('property_ref_id')
                            ->update(['property_ref_id' => $bySourceId[$key]]);
                    }
                });
        }

        // B. Unregistered discoveries are their own property.
        $this->giveOwnIdentity('property_discoveries', $year);

        // C. A valuation follows its discovery, else the bill it reassesses,
        //    else it stands alone.
        $this->linkVia('valuations', 'discovery_id', 'property_discoveries');
        $this->linkVia('valuations', 'bill_id', 'property_bills');
        $this->giveOwnIdentity('valuations', $year);

        // D. A bill follows the valuation that produced it, else stands alone.
        $this->linkBillsToValuations();
        $this->giveOwnIdentity('property_bills', $year);
    }

    private function linkable(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'property_ref_id');
    }

    private function createIdentity(?string $propertyId, string $year): int
    {
        $id = DB::table('properties')->insertGetId([
            'source_reference' => 'PRP-'.$year.'-'.Str::upper(Str::random(8)),
            'property_id' => $propertyId !== null && trim($propertyId) !== '' ? trim($propertyId) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('properties')->where('id', $id)->update([
            'source_reference' => sprintf('PRP-%s-%05d', $year, $id),
        ]);

        return $id;
    }

    /**
     * Copy the identity of the related row onto $table.$foreignKey.
     * $localColumn defaults to the same name as the foreign key.
     */
    private function linkVia(string $table, string $foreignKey, string $relatedTable, ?string $localColumn = null): void
    {
        if (! $this->linkable($table) || ! $this->linkable($relatedTable)) {
            return;
        }

        $localColumn ??= $foreignKey;

        if (! Schema::hasColumn($table, $localColumn)) {
            return;
        }

        DB::table($table)
            ->whereNull('property_ref_id')
            ->whereNotNull($localColumn)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $relatedTable, $localColumn) {
                foreach ($rows as $row) {
                    $ref = DB::table($relatedTable)
                        ->where('id', $row->{$localColumn})
                        ->value('property_ref_id');

                    if ($ref) {
                        DB::table($table)->where('id', $row->id)->update(['property_ref_id' => $ref]);
                    }
                }
            });
    }

    /** Every remaining row is an identity of its own. */
    private function giveOwnIdentity(string $table, string $year): void
    {
        if (! $this->linkable($table)) {
            return;
        }

        DB::table($table)
            ->whereNull('property_ref_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $year) {
                foreach ($rows as $row) {
                    $id = $this->createIdentity($row->property_id ?? null, $year);
                    DB::table($table)->where('id', $row->id)->update(['property_ref_id' => $id]);
                }
            });
    }

    /**
     * A bill belongs to the property of the valuation that produced it. The
     * lookup runs the other way round from linkVia(): valuations carry the
     * bill_id, so the related row is found by bill_id, not by primary key.
     */
    private function linkBillsToValuations(): void
    {
        if (! $this->linkable('property_bills') || ! $this->linkable('valuations')) {
            return;
        }

        DB::table('property_bills')
            ->whereNull('property_ref_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $ref = DB::table('valuations')
                        ->where('bill_id', $row->id)
                        ->whereNotNull('property_ref_id')
                        ->orderBy('id')
                        ->value('property_ref_id');

                    if ($ref) {
                        DB::table('property_bills')->where('id', $row->id)->update(['property_ref_id' => $ref]);
                    }
                }
            });
    }
};
