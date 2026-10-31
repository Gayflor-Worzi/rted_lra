<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Valuation;
use App\Models\ValuationPropertyDescription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Property Description section of the Valuation Assessment form.
 *
 * Land is the first, mandatory row and is never depreciated; every other row is
 * priced from area × TAR rate × quantity and depreciated at 2% per year of
 * building age. Amount, depreciation and value are RETD's figures, not the
 * officer's: the server derives them from the inputs so a crafted request cannot
 * put different numbers on the record than the form would.
 */
class ValuationPropertyDescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function officer(): User
    {
        return User::where('email', 'val.officer@test.lra')->firstOrFail();
    }

    private function valuation(): Valuation
    {
        return Valuation::create([
            'valuation_type' => 'new_property',
            'status' => Valuation::STATUS_ASSIGNED,
            'owner_name' => 'Description Owner',
            'owner_contact' => '0770-100-110',
            'tin' => 'TIN-DESC-0001',
            'property_address' => 'Description Way',
            'property_classification' => 'Residential',
            'gps_coordinate' => '6.315611, -10.807407',
            'assessment_date' => now()->toDateString(),
            'photos' => 'proof://VAL-DESC-0001',
            'valuation_officer_id' => $this->officer()->id,
        ]);
    }

    private function saveRows(Valuation $valuation, array $rows): void
    {
        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", ['descriptions' => $rows])
            ->assertOk();
    }

    public function test_land_amount_is_the_market_value_per_lot_times_the_number_of_lots(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [[
            'description' => 'Land',
            'level' => 'Land',
            'tar' => 5000,
            'quantity' => 3,
        ]]);

        $land = $valuation->descriptions()->firstOrFail();

        // 5,000 a lot for three lots — quantity is applied once, inside the Amount.
        $this->assertEqualsWithDelta(15000, (float) $land->amount, 0.001);
        $this->assertEqualsWithDelta(0, (float) $land->depreciation_pct, 0.001);
        $this->assertEqualsWithDelta(15000, (float) $land->value, 0.001);
    }

    public function test_land_is_never_depreciated_even_when_an_age_is_supplied(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [[
            'description' => 'Land',
            'level' => 'Land',
            'tar' => 2000,
            'quantity' => 1,
            'building_age' => 40,
            'depreciation_pct' => 80,
        ]]);

        $land = $valuation->descriptions()->firstOrFail();

        $this->assertEqualsWithDelta(0, (float) $land->depreciation_pct, 0.001);
        $this->assertEqualsWithDelta(2000, (float) $land->value, 0.001);
    }

    public function test_a_building_row_is_area_times_rate_times_quantity_less_two_percent_a_year(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [
            ['description' => 'Land', 'level' => 'Land', 'tar' => 5000, 'quantity' => 1],
            [
                'description' => 'Two-storey house',
                'level' => 'Ground Floor',
                'area_sqft' => 1000,
                'tar' => 25,
                'quantity' => 2,
                'building_age' => 10,
            ],
        ]);

        $building = $valuation->descriptions()->where('level', 'Ground Floor')->firstOrFail();

        // 1,000 sq ft × 25 × 2 = 50,000; ten years at 2% is 20%; 50,000 less 20% = 40,000.
        $this->assertEqualsWithDelta(50000, (float) $building->amount, 0.001);
        $this->assertEqualsWithDelta(20, (float) $building->depreciation_pct, 0.001);
        $this->assertEqualsWithDelta(40000, (float) $building->value, 0.001);
    }

    public function test_depreciation_is_straight_line_and_cannot_exceed_one_hundred_percent(): void
    {
        $this->assertSame(0.0, ValuationPropertyDescription::computeDepreciationPct('Ground Floor', null));
        $this->assertSame(10.0, ValuationPropertyDescription::computeDepreciationPct('Ground Floor', 5));
        $this->assertSame(40.0, ValuationPropertyDescription::computeDepreciationPct('Ground Floor', 20));
        $this->assertSame(100.0, ValuationPropertyDescription::computeDepreciationPct('Ground Floor', 80));
        $this->assertSame(0.0, ValuationPropertyDescription::computeDepreciationPct('Land', 20));
    }

    public function test_the_server_ignores_an_amount_and_depreciation_sent_by_the_client(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [
            ['description' => 'Land', 'level' => 'Land', 'tar' => 5000, 'quantity' => 2, 'amount' => 999999, 'depreciation_pct' => 90],
            [
                'description' => 'Shop',
                'level' => 'Ground Floor',
                'area_sqft' => 100,
                'tar' => 10,
                'quantity' => 1,
                'building_age' => 5,
                'amount' => 1,
                'depreciation_pct' => 0,
            ],
        ]);

        $land = $valuation->descriptions()->where('level', 'Land')->firstOrFail();
        $shop = $valuation->descriptions()->where('level', 'Ground Floor')->firstOrFail();

        $this->assertEqualsWithDelta(10000, (float) $land->amount, 0.001);
        $this->assertEqualsWithDelta(1000, (float) $shop->amount, 0.001);
        $this->assertEqualsWithDelta(10, (float) $shop->depreciation_pct, 0.001);
        $this->assertEqualsWithDelta(900, (float) $shop->value, 0.001);
    }

    public function test_land_is_stored_as_the_first_row_whatever_order_it_arrives_in(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [
            ['description' => 'Fence', 'level' => 'Exterior', 'area_sqft' => 100, 'tar' => 5, 'quantity' => 1, 'building_age' => 2],
            ['description' => 'Land', 'level' => 'Land', 'tar' => 5000, 'quantity' => 1],
        ]);

        $this->assertSame('Land', $valuation->descriptions()->orderBy('seq')->first()->level);
        $this->assertSame(1, (int) $valuation->descriptions()->orderBy('seq')->first()->seq);
    }

    public function test_a_missing_input_is_not_priced_as_zero(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [
            ['description' => 'Land', 'level' => 'Land', 'quantity' => 1],
            ['description' => 'House', 'level' => 'Ground Floor', 'area_sqft' => 500, 'quantity' => 1],
        ]);

        foreach ($valuation->descriptions as $row) {
            $this->assertNull($row->amount, 'A row with no rate must not carry an amount.');
            $this->assertNull($row->value, 'A row with no rate must not carry a value.');
        }
    }

    public function test_negative_inputs_are_refused(): void
    {
        $valuation = $this->valuation();

        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => -1,
                'quantity' => 1,
            ]],
        ])->assertStatus(422);

        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'House',
                'level' => 'Ground Floor',
                'area_sqft' => -5,
                'tar' => 10,
                'quantity' => 1,
            ]],
        ])->assertStatus(422);

        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'House',
                'level' => 'Ground Floor',
                'area_sqft' => 5,
                'tar' => 10,
                'quantity' => 1,
                'building_age' => -3,
            ]],
        ])->assertStatus(422);
    }

    public function test_a_valuation_without_a_land_row_cannot_be_submitted(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [[
            'description' => 'House',
            'level' => 'Ground Floor',
            'area_sqft' => 500,
            'tar' => 10,
            'quantity' => 1,
            'building_age' => 5,
        ]]);

        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 4500,
            'applicable_tax_rate' => 1.5,
        ])->assertStatus(422)
            ->assertJsonFragment(['Valuation cannot be submitted — missing required fields: the mandatory Land row (first row of Property Description).']);
    }

    public function test_a_valuation_whose_land_has_no_market_value_cannot_be_submitted(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [[
            'description' => 'Land',
            'level' => 'Land',
            'quantity' => 2,
        ]]);

        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 4500,
            'applicable_tax_rate' => 1.5,
        ])->assertStatus(422)
            ->assertJsonFragment(['Valuation cannot be submitted — missing required fields: the Land market value per lot.']);
    }

    public function test_a_building_row_with_no_area_cannot_be_submitted(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [
            ['description' => 'Land', 'level' => 'Land', 'tar' => 5000, 'quantity' => 1],
            ['description' => 'House', 'level' => 'Ground Floor', 'tar' => 10, 'quantity' => 1],
        ]);

        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 4500,
            'applicable_tax_rate' => 1.5,
        ])->assertStatus(422)
            ->assertJsonFragment(['Valuation cannot be submitted — missing required fields: Area (sq ft) and TAR rate on every building row.']);
    }

    public function test_a_complete_section_submits_and_its_total_carries_land_and_every_other_row(): void
    {
        $valuation = $this->valuation();

        $this->saveRows($valuation, [
            ['description' => 'Land', 'level' => 'Land', 'tar' => 5000, 'quantity' => 1],
            [
                'description' => 'House',
                'level' => 'Ground Floor',
                'area_sqft' => 1000,
                'tar' => 20,
                'quantity' => 1,
                'building_age' => 5,
            ],
        ]);

        Sanctum::actingAs($this->officer());
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'reassessed_value' => 23000,
            'applicable_tax_rate' => 1.5,
        ])->assertOk();

        // Land 5,000 + house 20,000 less 10% (18,000) = 23,000.
        $this->assertEqualsWithDelta(23000, (float) $valuation->fresh()->total_property_value, 0.01);
    }
}
