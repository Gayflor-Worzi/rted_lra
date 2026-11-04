<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\SystemAdministratorFixture;
use Tests\TestCase;

class AuditCsvExportSecurityTest extends TestCase
{
    use RefreshDatabase;
    use SystemAdministratorFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_audit_export_neutralizes_formula_cells_and_preserves_csv_values_and_shape(): void
    {
        $exporter = $this->systemAdministratorFixture();
        $actor = User::where('email', 'account.officer@test.lra')->firstOrFail();
        $actor->update(['full_name' => '@Audit Actor']);

        foreach (['=EQUAL', '+PLUS', '-MINUS', '@AT'] as $index => $action) {
            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => $action,
                'auditable_type' => $action,
                'auditable_id' => 100 + $index,
                'new_values' => ['value' => '=DETAIL'],
                'ip_address' => $action,
                'hash' => $action.'-HASH',
                'created_at' => now()->addSeconds($index),
            ]);
        }

        AuditLog::create([
            'actor_id' => $actor->id,
            'action' => 'Ordinary action',
            'auditable_type' => 'App\\Models\\PropertyBill',
            'auditable_id' => 12345,
            'new_values' => ['comment' => "ordinary, \"quoted\"\nline"],
            'ip_address' => "192.0.2.1, \"quoted\"\nline",
            'hash' => str_repeat('a', 64),
            'created_at' => now()->addSeconds(10),
        ]);

        Sanctum::actingAs($exporter);
        $csv = $this->get('/api/v1/audit-logs/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $this->assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        $this->assertSame(['Audit Trail — All records'], $rows[0]);
        $this->assertSame([null], $rows[2]);
        $headers = ['ID', 'Action', 'Actor', 'Auditable', 'Auditable ID', 'IP Address', 'Hash', 'Details', 'Created At'];
        $this->assertSame($headers, $rows[3]);

        $exported = collect(array_slice($rows, 4))->keyBy(fn ($row) => $row[1]);
        foreach (['=EQUAL', '+PLUS', '-MINUS', '@AT'] as $action) {
            $row = $exported->get("'".$action);
            $this->assertNotNull($row, "Expected neutralized action value for {$action}.");
            $this->assertSame("'".$action, $row[3]);
            $this->assertSame("'@Audit Actor", $row[2]);
            $this->assertSame("'".$action, $row[5]);
            $this->assertSame("'".$action.'-HASH', $row[6]);
            $this->assertSame('{"value":"=DETAIL"}', $row[7]);
        }

        $quotedRow = collect(array_slice($rows, 4))->first(fn ($row) => $row[1] === 'Ordinary action');
        $this->assertNotNull($quotedRow);
        $this->assertSame('PropertyBill', $quotedRow[3]);
        $this->assertSame(12345, (int) $quotedRow[4]);
        $this->assertSame("ordinary, \"quoted\"\nline", json_decode($quotedRow[7], true)['comment']);
        $this->assertSame("192.0.2.1, \"quoted\"\nline", $quotedRow[5]);
    }
}
