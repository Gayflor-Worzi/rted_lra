<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EvidencePhoto;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\PropertyDiscoveryEvidence;
use App\Models\User;
use App\Models\Valuation;
use App\Models\ValuationPropertyDescription;
use App\Services\PropertyRecordBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

/**
 * The Property Record is the one screen in RETD that leaves the building.
 *
 * Everything else in the system is a workspace: a queue, a form, a dashboard, all
 * temporary and all revisable. This one is a single official sheet carrying a
 * taxpayer's identity, their property's value and their photographs, on paper, in
 * a folder. It will be read as a *copy of record* - a statement that this is what
 * the system held, on this date, about this property.
 *
 * That framing sets what these tests assert. The dangerous failure is not a crash,
 * it is a document that is wrong in a way nobody notices: another taxpayer's TIN on
 * a sheet, a photograph missing with nothing saying so, a coordinate that looks
 * precise but is not. So the tests check who may take a copy (authorization is
 * re-derived server-side, never inherited from the button that was clicked), that
 * the copy describes exactly one record, that absence is stated rather than blank,
 * and that a blocked attempt leaves a trace.
 *
 * The map is deliberately not a map: no tile server is contacted, and the coordinate
 * locator is drawn from the record's own fix.
 */
class PropertyRecordExportTest extends TestCase
{
    use LocalDemoUsers, RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function act(string $email): void
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());
    }

    private function url(string $type, int $id): string
    {
        return "/api/v1/property-record/{$type}/{$id}/pdf";
    }

    private function printUrl(string $type, int $id): string
    {
        return "/api/v1/property-record/{$type}/{$id}/print";
    }

    /**
     * A PDF download is the request most likely to be asked for twice in a row, and
     * Dompdf cannot render a second document through an instance that already
     * rendered one: it keeps the first document's frame tree and the second render
     * dies in the block reflower with a null parent frame.
     *
     * The failure is invisible under PHP-FPM, which starts a fresh process per
     * request. It is not invisible under a queue worker, an Octane worker, or this
     * test suite, all of which serve many exports from one process - and the
     * controller is the sort of object that gets memoized for the life of the
     * process, so a renderer held as a constructor property would be shared.
     */
    public function test_two_exports_in_one_process_both_succeed(): void
    {
        $first = $this->aValuationOwnedBy($this->theOfficer(), 'TWOFIRST');
        $second = $this->aValuationOwnedBy($this->theOfficer(), 'TWOSECOND');

        $this->act('val.manager@test.lra');

        $a = $this->get($this->url('valuation', $first->id))->assertOk();
        $b = $this->get($this->url('valuation', $second->id))->assertOk();

        $this->assertStringStartsWith('%PDF-', $a->getContent());
        $this->assertStringStartsWith('%PDF-', $b->getContent());
        $this->assertNotSame(
            $a->headers->get('content-disposition'),
            $b->headers->get('content-disposition'),
            'Both responses claimed the same filename, so the second render served the first document.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  The print view */
    /* ------------------------------------------------------------------ */

    /**
     * The point of the print view is that it is the same sheet, not a second
     * document. Both renderers include one shared body partial, so every section
     * present in one has to be present in the other; a future edit that adds a
     * section to only one of them fails here rather than in an operator's hands.
     */
    public function test_the_printed_page_carries_the_same_sections_as_the_pdf(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'BOTH');

        $this->act('val.manager@test.lra');
        $html = $this->get($this->printUrl('valuation', $valuation->id))->assertOk()->getContent();

        $sections = [
            'Property identification',
            'Owner',
            'Value as recorded',
            'Property particulars',
            'Coordinate locator',
            'Record provenance',
            'Property photographs',
            'Record workflow',
            'Attestation',
        ];

        $pdfHtml = $this->renderDocument('valuation', $valuation->id);

        foreach ($sections as $section) {
            $this->assertStringContainsString($section, $html, "The print view omits: {$section}");
            $this->assertStringContainsString($section, $pdfHtml, "The PDF omits: {$section}");
        }
    }

    public function test_the_print_view_is_a_page_and_not_a_download(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'PRINTVIEW');

        $this->act('val.manager@test.lra');
        $response = $this->get($this->printUrl('valuation', $valuation->id))->assertOk();

        $this->assertStringStartsWith('text/html', $response->headers->get('content-type'));
        $this->assertNull(
            $response->headers->get('content-disposition'),
            'The print view must open in the browser, not download as a file.'
        );
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));

        $html = $response->getContent();

        $this->assertStringContainsString('window.print()', $html);

        // The sheet's own @page rule is what makes the browser choose A4 landscape,
        // rather than leaving the operator to discover it in the print dialogue.
        $this->assertMatchesRegularExpression(
            '/@page\s*\{[^}]*size:\s*A4 landscape/i',
            $html,
            'The print view does not declare A4 landscape to the browser.'
        );

        // Page numbers must come from the browser's own counters, since the browser
        // is what paginates here. Dompdf's placeholders would print as blanks, so
        // their absence from this view is the point, not a gap.
        $this->assertStringNotContainsString(
            'class="pageNumber"',
            $html,
            'A Dompdf counter placeholder leaked into the browser view, where it prints as nothing.'
        );
        $this->assertStringContainsString('counter(page)', $html);
        $this->assertStringContainsString('counter(pages)', $html);
        $this->assertMatchesRegularExpression(
            '/\.footer \.page-cell\s*\{\s*display:\s*none/i',
            $html,
            'The page cell is shown on screen, where there is no page count to report.'
        );

        // Screen-only chrome has to actually disappear on paper.
        $this->assertMatchesRegularExpression(
            '/@media print\s*\{[^@]*\.toolbar\s*\{\s*display:\s*none/i',
            $html,
            'The on-screen toolbar would print onto the sheet.'
        );
    }

    /**
     * The record carries a taxpayer's identity, value and photographs, so the sheet
     * is refused equally firmly however it is asked for. The print view is a second
     * door into the same data, and a door that skips the scope check is the whole
     * breach.
     */
    public function test_the_print_view_enforces_permission_and_scope(): void
    {
        $theirs = $this->aValuationOwnedBy($this->theOtherOfficer(), 'THEIRSPRINT');

        $this->act('account.manager@test.lra');
        $this->get($this->printUrl('valuation', $theirs->id))->assertForbidden();

        $this->act('enf.officer@test.lra');
        $this->get($this->printUrl('valuation', $theirs->id))->assertForbidden();

        $this->act('val.manager@test.lra');
        $this->get($this->printUrl('valuation', 99999999))->assertNotFound();
        $this->get($this->printUrl('enrollment', $theirs->id))->assertNotFound();
    }

    public function test_a_printed_sheet_is_audited_as_a_print_and_not_as_a_download(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'PRINTAUDIT');

        $this->act('val.manager@test.lra');
        $this->get($this->printUrl('valuation', $valuation->id))->assertOk();

        $log = AuditLog::where('action', 'property_record.printed')
            ->where('auditable_type', Valuation::class)
            ->where('auditable_id', $valuation->id)
            ->firstOrFail();

        $this->assertSame('print', $log->new_values['output']);
        $this->assertSame(
            0,
            AuditLog::where('action', 'property_record.exported')
                ->where('auditable_id', $valuation->id)
                ->count(),
            'A printed sheet was also recorded as a file download.'
        );
    }

    /** A refused attempt to print is still a refused attempt, and is still recorded. */
    public function test_a_refused_print_is_audited_as_a_blocked_export(): void
    {
        $theirs = $this->aValuationOwnedBy($this->theOtherOfficer(), 'BLOCKEDPRINT');

        $this->act('account.manager@test.lra');
        $this->get($this->printUrl('valuation', $theirs->id))->assertForbidden();

        $log = AuditLog::where('action', 'property_record.export_blocked')
            ->where('auditable_type', Valuation::class)
            ->where('auditable_id', $theirs->id)
            ->firstOrFail();

        $this->assertSame('forbidden', $log->new_values['reason']);
    }

    /* ------------------------------------------------------------------ */
    /*  Photographs */
    /* ------------------------------------------------------------------ */

    /**
     * A photograph is the reason a property record leaves the building. This asserts
     * the image is genuinely *in* the file - a real JPEG image object in the PDF's
     * content - rather than present as a caption over an empty frame, which is what a
     * broken path, an unsupported format or a lost file would leave behind.
     */
    public function test_a_photograph_is_embedded_in_the_downloaded_pdf(): void
    {
        Storage::fake('local');
        $path = $this->storePhoto('discovery-evidence/ND-PDF/photo.jpg', 1200, 600);

        $discovery = $this->aDiscovery('PDFPHOTO');
        $discovery->evidence()->create([
            'evidence_type' => 'PROPERTY_PHOTO',
            'sequence_no' => 1,
            'file_path' => $path,
            'original_name' => 'Front elevation.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->act('account.manager@test.lra');
        $pdf = $this->get($this->url('discovery', $discovery->id))->assertOk()->getContent();

        // An embedded JPEG travels as a DCTDecode image stream.
        $this->assertStringContainsString(
            'DCTDecode',
            $pdf,
            'The downloaded file contains no JPEG image data, so the photograph was not embedded.'
        );
        $this->assertMatchesRegularExpression(
            '/\/Subtype\s*\/Image/',
            $pdf,
            'The downloaded file contains no image object, so the photograph did not survive rendering.'
        );

        // The JPEG is written uncompressed as a stream, so the sheet must be far
        // larger than a text-only document of the same length.
        $this->assertGreaterThan(
            20000,
            strlen($pdf),
            'The file is too small to contain an embedded photograph.'
        );
    }

    public function test_a_photograph_is_embedded_in_the_printed_page(): void
    {
        Storage::fake('local');
        $path = $this->storePhoto('discovery-evidence/ND-PRINT/photo.jpg', 1200, 600);

        $discovery = $this->aDiscovery('PRINTPHOTO');
        $discovery->evidence()->create([
            'evidence_type' => 'PROPERTY_PHOTO',
            'sequence_no' => 1,
            'file_path' => $path,
            'original_name' => 'Front elevation.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->act('account.manager@test.lra');
        $html = $this->get($this->printUrl('discovery', $discovery->id))->assertOk()->getContent();

        $this->assertStringContainsString('src="data:image/jpeg;base64,', $html);
        $this->assertStringContainsString('Front elevation', $html);
        $this->assertStringContainsString('Property photographs (1)', $html);

        // The photo must not reach the page as a server path: the print view is
        // opened as a standalone document, so anything it references has to be
        // carried inside it.
        $this->assertStringNotContainsString(
            'src="/api',
            $html,
            'The print view references a photo over HTTP instead of embedding it.'
        );
    }

    /**
     * A record sheet distorts the evidence it carries, and distortion is worse than
     * omission: a squashed roof line or a leaning door reads as a fact about the
     * building rather than about the printer.
     *
     * The renderer has no object-fit, so proportions cannot be left to CSS. Each
     * photograph is sized in millimetres from its own dimensions, and this pins that
     * for both extremes - a wide frontage, which is width-constrained, and a tall
     * one, which is height-constrained.
     */
    public function test_photographs_keep_their_proportions(): void
    {
        Storage::fake('local');
        $wide = $this->storePhoto('discovery-evidence/ND-PROPORTION/wide.jpg', 1200, 300);
        $tall = $this->storePhoto('discovery-evidence/ND-PROPORTION/tall.jpg', 300, 1200);

        $discovery = $this->aDiscovery('PROPORTION');
        foreach ([[$wide, 1, 'Wide frontage.jpg'], [$tall, 2, 'Tall gable.jpg']] as [$path, $sequence, $name]) {
            $discovery->evidence()->create([
                'evidence_type' => 'PROPERTY_PHOTO',
                'sequence_no' => $sequence,
                'file_path' => $path,
                'original_name' => $name,
                'mime_type' => 'image/jpeg',
            ]);
        }

        $this->act('account.manager@test.lra');
        $html = $this->get($this->printUrl('discovery', $discovery->id))->assertOk()->getContent();

        $boxes = $this->imageBoxesIn($html);
        $this->assertCount(2, $boxes, 'Expected one rendered photograph per attachment.');

        // Order of attachment: wide (4:1) then tall (1:4).
        [$wideW, $wideH] = $boxes[0];
        [$tallW, $tallH] = $boxes[1];

        $this->assertEqualsWithDelta(4.0, $wideW / $wideH, 0.05, 'The wide photograph is distorted.');
        $this->assertEqualsWithDelta(0.25, $tallW / $tallH, 0.02, 'The tall photograph is distorted.');

        // Both are fitted inside the box rather than spilling out of it.
        foreach ([[$wideW, $wideH], [$tallW, $tallH]] as [$w, $h]) {
            $this->assertLessThanOrEqual(87.1, $w, 'A photograph is wider than its cell.');
            $this->assertLessThanOrEqual(46.1, $h, 'A photograph is taller than its cell.');
        }
    }

    /**
     * Photographs are laid out three to a row, and each row has to be its own element.
     *
     * The grid is a display:table, and a renderer is free to slice such a box across a
     * page break between its cells. The cells are atomic, so nothing is torn inside
     * one - the first photograph simply stays at the foot of a page and the rest go
     * overleaf, which is what a printed sheet looked like with one of three pictures
     * showing. Only a row that is its own element can be refused a break whole, so this
     * pins the grouping as well as the padding of the short final row.
     */
    public function test_photographs_are_grouped_into_rows_of_three(): void
    {
        Storage::fake('local');

        $discovery = $this->aDiscovery('PHOTOROWS');
        foreach (range(1, 5) as $sequence) {
            $discovery->evidence()->create([
                'evidence_type' => 'PROPERTY_PHOTO',
                'sequence_no' => $sequence,
                'file_path' => $this->storePhoto("discovery-evidence/ND-PHOTOROWS/photo{$sequence}.jpg", 1200, 800),
                'original_name' => "Photograph {$sequence}.jpg",
                'mime_type' => 'image/jpeg',
            ]);
        }

        $this->act('account.manager@test.lra');
        $html = $this->get($this->printUrl('discovery', $discovery->id))->assertOk()->getContent();

        // Five photographs: a full row of three, then a row of two padded out to three
        // so the last pair keeps the same width as the row above it. Both rows count as
        // photo cells, the padding one being an empty cell of the same class.
        $this->assertSame(2, substr_count($html, '<div class="photo-grid">'), 'Each row of three must be its own grid.');
        $this->assertSame(6, substr_count($html, '<div class="photo-cell">'), 'A short final row must be padded to three cells.');
        $this->assertCount(5, $this->imageBoxesIn($html), 'Every photograph must be rendered exactly once.');
        $this->assertStringContainsString('Property photographs (5)', $html);
    }

    /**
     * A valuation's photographs are those of the property it values.
     *
     * Nothing ever attaches a photograph to a valuation: the valuation officer does
     * not carry a camera, and DiscoveryController is the only thing in the
     * application that writes a property photograph. So a valuation's pictures live
     * on the discovery it was raised from, and a builder that reads only the
     * valuation's own photo table reports that no photograph exists on a property
     * that has three on file. That claim is the one an official record must never
     * make, so the real shape is pinned here rather than the legacy table.
     */
    public function test_a_valuation_inherits_the_photographs_of_its_source_discovery(): void
    {
        Storage::fake('local');

        $this->act('account.officer@test.lra');
        $discovery = $this->aDiscovery('VALINHERIT');
        $this->attachDiscoveryPhotos($discovery, [[1200, 800], [1200, 800], [1200, 800]]);

        $valuation = $this->aValuationRaisedFrom($discovery, $this->theOfficer(), 'VALINHERIT');

        $this->act('val.manager@test.lra');
        $pdf = $this->get($this->url('valuation', $valuation->id))->assertOk()->getContent();
        $html = $this->get($this->printUrl('valuation', $valuation->id))->assertOk()->getContent();

        $this->assertStringContainsString('Property photographs (3)', $html);
        $this->assertStringContainsString('DCTDecode', $pdf, 'The inherited photographs are not in the PDF.');

        foreach (['site-1.jpg', 'site-2.jpg', 'site-3.jpg'] as $name) {
            $this->assertStringContainsString($name, $html, "{$name} is missing from the record sheet.");
        }

        // The sheet must not imply the assessment itself took the pictures.
        $this->assertStringContainsString('from discovery '.$discovery->discovery_reference, $html);
    }

    /**
     * Where a photograph came from, stated on the record.
     *
     * Without this the reader sees three pictures on an assessment and reasonably
     * concludes they document that assessment, when they document the earlier site
     * visit. For an enforcement record the difference matters, so the source
     * discovery is named rather than left to be inferred.
     */
    public function test_a_valuation_photograph_names_its_source_discovery(): void
    {
        Storage::fake('local');

        $this->act('account.officer@test.lra');
        $discovery = $this->aDiscovery('VALSOURCE');
        $this->attachDiscoveryPhotos($discovery, [[1200, 800]]);

        $valuation = $this->aValuationRaisedFrom($discovery, $this->theOfficer(), 'VALSOURCE');

        $this->act('val.manager@test.lra');
        $html = $this->get($this->printUrl('valuation', $valuation->id))->assertOk()->getContent();

        $this->assertStringContainsString('site-1.jpg · from discovery '.$discovery->discovery_reference, $html);
    }

    /**
     * The legacy valuation-photo table is still read, and read first.
     *
     * Nothing populates it today, but if a flow ever attaches a photograph to the
     * assessment itself, that photograph is the more specific statement about it
     * and belongs first. The test stays so that the branch is not deleted as dead
     * code by someone who has not run the application.
     */
    public function test_a_valuation_own_photograph_precedes_the_inherited_one_and_is_not_duplicated(): void
    {
        Storage::fake('local');

        $this->act('account.officer@test.lra');
        $discovery = $this->aDiscovery('VALORDER');
        $this->attachDiscoveryPhotos($discovery, [[1200, 800], [1200, 800]]);

        $valuation = $this->aValuationRaisedFrom($discovery, $this->theOfficer(), 'VALORDER');

        // The first discovery photograph is also attached to the valuation itself.
        // It is one photograph reached twice, and it must be printed once - under the
        // valuation's own caption, which is the more specific description.
        $shared = 'discovery-evidence/'.$discovery->discovery_reference.'/site-1.jpg';
        EvidencePhoto::create([
            'photo_reference' => 'VP-'.$valuation->id,
            'photo_type' => 'PROPERTY_FULL_VIEW',
            'valuation_id' => $valuation->id,
            'file_path' => $shared,
            'original_name' => 'Assessment view.jpg',
            'mime' => 'image/jpeg',
        ]);

        $this->act('val.manager@test.lra');
        $html = $this->get($this->printUrl('valuation', $valuation->id))->assertOk()->getContent();

        // Two distinct files, not three rows.
        $this->assertStringContainsString('Property photographs (2)', $html, 'The shared photograph is listed twice.');

        $this->assertStringContainsString('Assessment view.jpg', $html);
        $this->assertStringNotContainsString('site-1.jpg', $html, 'The shared photograph kept its discovery caption as well.');

        // The valuation's own photograph leads.
        $this->assertLessThan(
            strpos($html, 'site-2.jpg'),
            strpos($html, 'Assessment view.jpg'),
            'The assessment\'s own photograph is not listed before the inherited one.'
        );
    }

    /**
     * The photographs must come from the linked discovery, not an unrelated one.
     *
     * Two discoveries of the same property are both plausible evidence, and a bug
     * that reads "the property's most recent discovery" instead of "the discovery
     * this assessment came from" would print pictures of a later visit onto a record
     * describing an earlier one. The linked discovery is the only defensible source.
     */
    public function test_a_valuation_uses_its_linked_discovery_and_not_the_latest_one(): void
    {
        Storage::fake('local');

        $this->act('account.officer@test.lra');
        $linked = $this->aDiscovery('VALLINKED');
        $this->attachDiscoveryPhotos($linked, [[1200, 800]]);

        $valuation = $this->aValuationRaisedFrom($linked, $this->theOfficer(), 'VALLINKED');

        // A later discovery of the same property, photographed differently.
        $later = PropertyDiscovery::create([
            'property_id' => $linked->property_id,
            'owner_name' => $linked->owner_name,
            'property_address' => $linked->property_address,
            'county' => $linked->county,
            'district' => $linked->district,
            'property_classification' => $linked->property_classification,
            'discovery_date' => now()->addDays(5)->toDateString(),
            'status' => PropertyDiscovery::STATUS_DRAFT,
        ]);
        $this->attachDiscoveryPhotos($later, [[1200, 800], [1200, 800], [1200, 800]]);

        $this->act('val.manager@test.lra');
        $html = $this->get($this->printUrl('valuation', $valuation->id))->assertOk()->getContent();

        $this->assertStringContainsString('Property photographs (1)', $html, 'The later discovery\'s photographs were used.');
        $this->assertStringContainsString('from discovery '.$linked->discovery_reference, $html);
    }

    /**
     * A stored path that leaves the evidence store is refused, whatever it looks like.
     *
     * The photograph roots are an allowlist, but an allowlist on a string is not a
     * security boundary on its own, so the resolved file is also required to sit
     * inside the disk root. These are the spellings of escape worth refusing, and
     * the record sheet must say so in words rather than quietly omitting the image -
     * an unexplained blank is indistinguishable from a withheld photograph.
     */
    public function test_a_photo_path_escaping_the_evidence_store_is_refused(): void
    {
        Storage::fake('local');
        $this->storePhoto('discovery-evidence/ND-SECURE/ok.jpg', 400, 300);

        $this->act('account.officer@test.lra');
        $discovery = $this->aDiscovery('SECURE');

        // One genuine photograph, to prove the guard refuses the escapes without
        // refusing everything.
        $discovery->evidence()->create([
            'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
            'sequence_no' => 1,
            'file_path' => 'discovery-evidence/ND-SECURE/ok.jpg',
            'original_name' => 'ok.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        foreach ([
            '../secrets.jpg',
            'discovery-evidence/../../secrets.jpg',
            'discovery-evidence/ok.jpg/../../../secrets.jpg',
            '/etc/passwd',
            'C:\\Windows\\win.ini',
        ] as $i => $path) {
            PropertyDiscoveryEvidence::create([
                'discovery_id' => $discovery->id,
                'property_id' => $discovery->property_id,
                'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
                'sequence_no' => $i + 2,
                'file_path' => $path,
                'original_name' => 'escape-'.$i.'.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 10,
            ]);
        }

        $this->act('val.manager@test.lra');
        $html = $this->get($this->printUrl('discovery', $discovery->id))->assertOk()->getContent();

        // One accepted photograph, five refusals - and all six are listed, because an
        // official record must account for every photograph it was given rather than
        // dropping the ones it dislikes.
        $this->assertStringContainsString('Property photographs (6)', $html);
        $this->assertStringContainsString('src="data:image/jpeg;base64,', $html, 'The one genuine photograph was refused too.');
        $this->assertStringContainsString('outside the evidence store', $html);
        $this->assertSame(5, substr_count($html, 'outside the evidence store'));
    }

    /**
     * Render a JPEG of a given size into the faked evidence store, and return the
     * path it was written to.
     *
     * The path is given in full, exactly as production writes it, and returned
     * unaltered so a test cannot point an evidence row somewhere the file was not
     * written. That mistake is invisible: a missing file is reported as a note, so
     * the test quietly stops testing photographs and starts testing what happens
     * when a photograph is absent, which already had its own test.
     */
    private function storePhoto(string $path, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 60, 110));
        // An asymmetric mark, so a distortion is visible in the rendered result and
        // not just in the numbers.
        imagefilledrectangle($image, 0, 0, (int) ($width / 3), $height, imagecolorallocate($image, 220, 70, 70));
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    /** Every rendered photograph's width/height, in millimetres, in document order. */
    private function imageBoxesIn(string $html): array
    {
        preg_match_all('/<img[^>]*style="width:\s*([\d.]+)mm;\s*height:\s*([\d.]+)mm;"[^>]*>/i', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => [(float) $x[1], (float) $x[2]], $m);
    }

    /* ------------------------------------------------------------------ */
    /*  The document's own content */
    /* ------------------------------------------------------------------ */

    /**
     * The section headings are the contract with whoever reads the printed sheet, so
     * a template edit that drops one is a real defect rather than a cosmetic one.
     *
     * The assertions are made against the rendered template rather than the PDF
     * because Dompdf's font subsetting encodes text as glyph indices, which leaves
     * nothing greppable in the output stream. The PDF-level tests above establish
     * that this HTML is what actually gets rendered onto A4 landscape paper.
     */
    public function test_a_valuation_record_states_every_required_section(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'SECTIONS');
        $valuation->update(['gps_coordinate' => '6.310556,-10.804444', 'gps_accuracy' => 8.5]);

        $this->act('val.manager@test.lra');
        $html = $this->renderDocument('valuation', $valuation->id);

        foreach ([
            'PROPERTY VALUATION ASSESSMENT RECORD',
            'COPY OF RECORD',
            'Property identification',
            'Owner',
            'Value as recorded',
            'Property particulars',
            'Coordinate locator',
            'Record provenance',
            'Property photographs',
            'Record workflow',
            'Attestation',
        ] as $required) {
            $this->assertStringContainsString(
                $required,
                $html,
                "The document omits the required section: {$required}"
            );
        }

        $this->assertStringContainsString('6.310556', $html);
        $this->assertStringContainsString('-10.804444', $html);
    }

    /**
     * The property description is a repeatability sub-table, and must print as one.
     *
     * It used to be one string: every attribute of every item joined with a middle
     * dot, and all the items joined again with line breaks into a single cell. Nothing
     * lined up, so the amounts could not be read down a column and a total had to be
     * done by the reader. The test pins the column structure, because "still readable"
     * is exactly the judgement that lets a table quietly decay back into prose.
     */
    public function test_the_property_description_prints_as_a_table_of_columns(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'DESCTABLE');
        $this->addDescriptions($valuation, [
            ['Land (2 Acre)', 'Other', 280, 25, 1, 12000, 5],
            ['House (3 Bead Room)', 'Ground Floor', 80, 8, 2, 20000, 5],
            ['Fence', 'Exterior', 110, 7, 1, 28000, 3],
        ]);

        $this->act('val.manager@test.lra');
        $html = $this->renderDocument('valuation', $valuation->id);

        // A real table with a header row, not a run-on string in one cell.
        $this->assertStringContainsString('<table class="desc">', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringNotContainsString('Area: 280 sq ft', $html, 'The run-on description string is back.');

        foreach (['#', 'Description', 'Level', 'Area', 'TAR', 'Qty', 'Amount (US$)', 'Dep.', 'Value (US$)'] as $column) {
            $this->assertStringContainsString('<th>'.$column.'</th>', $html, "The {$column} column is missing.");
        }

        $this->assertSame(9, substr_count($html, '<th>'), 'The description table has the wrong number of columns.');

        // One row per item, each attribute in its own cell.
        foreach (['Land (2 Acre)', 'Other', '280 sq ft', '25%', '12,000.00', '5%', '11,400.00'] as $cell) {
            $this->assertStringContainsString($cell, $html, "The description table omits {$cell}.");
        }

        // The computed values are printed with the inputs they came from, plus a total,
        // so the arithmetic on the record can be checked rather than taken on trust.
        // Value = Amount less depreciation: quantity is already inside the Amount, so
        // the 20,000.00 row at 5% prints as 19,000.00, not 38,000.00.
        $this->assertStringContainsString('19,000.00', $html);
        $this->assertStringContainsString('27,160.00', $html);
        $this->assertStringContainsString('<tfoot>', $html);
        $this->assertStringContainsString('57,560.00', $html, 'The description total is missing or wrong.');
    }

    /**
     * A description with most fields left blank must still line up.
     *
     * The sub-table is optional field by field - only the description, quantity and
     * depreciation are required by the schema - and a blank printed as a literal
     * placeholder, or a cell omitted altogether, would push every later column out of
     * alignment. That is the same defect the table was introduced to remove, so it is
     * pinned for the sparse case and not only the full one.
     */
    public function test_a_partly_filled_description_row_keeps_the_table_aligned(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'DESCPART');
        $this->addDescriptions($valuation, [
            ['Bare land', null, null, null, 1, null, 0],
        ]);

        $this->act('val.manager@test.lra');
        $html = $this->renderDocument('valuation', $valuation->id);

        $this->assertStringContainsString('<table class="desc">', $html);
        $this->assertStringContainsString('Bare land', $html);
        $this->assertSame(9, substr_count($html, '<th>'), 'A blank field changed the column count.');

        // Every row still carries one cell per column, blanks included - that is what
        // keeps the columns aligned.
        $body = substr($html, strpos($html, '<table class="desc">'));
        $row = substr($body, strpos($body, '<tbody>'), strpos($body, '</tbody>') - strpos($body, '<tbody>'));
        $this->assertSame(9, substr_count($row, '<td'), 'The sparse row does not have one cell per column.');

        // No literal null leaking into an official document.
        $this->assertStringNotContainsString('>null<', $html);

        // A field with no figure is left empty rather than filled with a zero, because
        // "not stated" and "zero" are different facts on a rate-bearing assessment.
        $this->assertStringContainsString('<td class="r"></td>', $row, 'A blank cell was filled in with something.');
        $this->assertStringNotContainsString('0.00', $row, 'A blank cell was given a figure.');
    }

    /**
     * The same table must reach the print view and the downloaded file.
     *
     * The two renderers share the body partial, so this fails if the table is ever
     * built for one output only - which is how a printed page and a downloaded copy
     * end up stating different figures for the same property.
     *
     * The PDF's own text cannot be read: Dompdf writes it as subsetted glyph indices,
     * not characters. So the file is checked by its shape instead - a valid PDF, large
     * enough to hold a table with nine bordered columns and its figures. The figures
     * themselves are asserted on the print markup, which is generated from the same
     * shared body partial, so nothing here is taken on trust.
     */
    public function test_the_property_description_table_is_in_both_outputs(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'DESCBOTH');
        $this->addDescriptions($valuation, [
            ['Land (2 Acre)', 'Other', 280, 25, 1, 12000, 5],
        ]);

        $this->act('val.manager@test.lra');
        $pdf = $this->get($this->url('valuation', $valuation->id))->assertOk()->getContent();
        $print = $this->get($this->printUrl('valuation', $valuation->id))->assertOk()->getContent();

        $this->assertStringContainsString('<table class="desc">', $print);
        foreach (['Land (2 Acre)', 'Other', '280 sq ft', '25%', '12,000.00', '11,400.00'] as $cell) {
            $this->assertStringContainsString($cell, $print, "The print page omits {$cell}.");
        }

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(
            20000,
            strlen($pdf),
            'The PDF is too small to contain a description table, its borders and its figures.'
        );
    }

    /**
     * A record with no description lines shows no empty table.
     */
    public function test_the_description_table_is_omitted_when_there_is_nothing_to_tabulate(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'DESCNONE');

        $this->act('val.manager@test.lra');
        $html = $this->renderDocument('valuation', $valuation->id);

        $this->assertStringNotContainsString('<table class="desc">', $html);
        $this->assertStringNotContainsString('Description of property', $html);
    }

    /**
     * A record with no fix must say so in words. An omitted locator would be read as
     * a property whose location simply was not printed.
     */
    public function test_the_document_states_when_there_is_no_gps_fix(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'NOFIXDOC');
        $valuation->update(['gps_coordinate' => null, 'gps_accuracy' => null]);

        $this->act('val.manager@test.lra');
        $html = $this->renderDocument('valuation', $valuation->id);

        $this->assertStringContainsString('No usable GPS fix', $html);
        $this->assertStringNotContainsString('<svg', $html, 'A locator was drawn for a record with no fix.');
    }

    /**
     * Likewise for photographs: the sheet must not read as though the property simply
     * has none when the record holds one that cannot be read.
     */
    public function test_the_document_states_when_no_photograph_is_attached(): void
    {
        Storage::fake('local');

        $discovery = $this->aDiscovery('NOPHOTODOC');

        $this->act('account.manager@test.lra');
        $html = $this->renderDocument('discovery', $discovery->id);

        $this->assertStringContainsString('No property photograph is attached', $html);
    }

    public function test_an_unreadable_photograph_is_named_on_the_document(): void
    {
        Storage::fake('local');

        $discovery = $this->aDiscovery('BADPHOTODOC');
        $discovery->evidence()->create([
            'evidence_type' => 'PROPERTY_PHOTO',
            'sequence_no' => 1,
            'file_path' => 'evidence/print-test/missing.png',
            'original_name' => 'Roof elevation.png',
            'mime_type' => 'image/png',
        ]);

        $this->act('account.manager@test.lra');
        $html = $this->renderDocument('discovery', $discovery->id);

        $this->assertStringContainsString('Roof elevation', $html);
        $this->assertStringContainsString('File is no longer available', $html);
    }

    public function test_the_document_labels_itself_by_record_type(): void
    {
        $discovery = $this->aDiscovery('LABEL');
        $assessment = $this->aValuationOwnedBy($this->theOfficer(), 'LABELNEW', 'new_property');
        $reassessment = $this->aValuationOwnedBy($this->theOfficer(), 'LABELRE', 'reassessment');

        $this->act('account.manager@test.lra');
        $this->assertStringContainsString(
            'PROPERTY DISCOVERY RECORD',
            $this->renderDocument('discovery', $discovery->id)
        );

        $this->act('val.manager@test.lra');
        $this->assertStringContainsString(
            'PROPERTY VALUATION ASSESSMENT RECORD',
            $this->renderDocument('valuation', $assessment->id)
        );
        $this->assertStringContainsString(
            'PROPERTY REASSESSMENT RECORD',
            $this->renderDocument('valuation', $reassessment->id)
        );
    }

    /** The exact HTML the endpoint hands to the renderer. */
    private function renderDocument(string $type, int $id): string
    {
        $record = app(PropertyRecordBuilder::class)->build($type, $id, auth()->user());

        return view('property-record.document', [
            'record' => $record,
            'agency' => config('app.name'),
        ])->render();
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function discoveryPayload(string $suffix): array
    {
        return [
            'owner_name' => 'Owner '.$suffix,
            'owner_contact' => '0770-100-110',
            'tin' => 'TIN-OWN-'.$suffix,
            'property_address' => 'Address '.$suffix.', Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'discovery_date' => now()->toDateString(),
        ];
    }

    /** A discovery created through the real API by the officer who owns it. */
    private function aDiscovery(string $suffix): PropertyDiscovery
    {
        $this->act('account.officer@test.lra');
        $id = $this->postJson('/api/v1/discoveries', $this->discoveryPayload($suffix))
            ->assertCreated()->json('data.id');

        return PropertyDiscovery::findOrFail($id);
    }

    /** A valuation sitting at Assigned, owned by a named officer. */
    private function aValuationOwnedBy(User $officer, string $suffix, string $type = 'new_property'): Valuation
    {
        return Valuation::create([
            'property_id' => 'P-'.$suffix,
            'document_number' => 'DOC-'.$suffix,
            'valuation_type' => $type,
            'status' => Valuation::STATUS_ASSIGNED,
            'owner_name' => 'Owner '.$suffix,
            'owner_contact' => '0770-100-110',
            'tin' => 'TIN-OWN-'.$suffix,
            'property_address' => 'Address '.$suffix.', Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Residential',
            'valuation_officer_id' => $officer->id,
            'declared_value' => 90000,
            'assessed_value' => 100000,
            'annual_tax' => 1000,
        ]);
    }

    /**
     * A photograph reached through a link out of the evidence store is refused.
     *
     * The spelling checks catch every `..` and every rooted path, so the containment
     * check is unreachable through the database alone - it exists for the case a
     * string cannot see: a directory link inside the evidence store that points
     * somewhere else entirely. Without it, a file outside the store would be read and
     * embedded in an official document just because some directory under
     * `discovery-evidence/` happened to be a link.
     *
     * The link is made with whatever the platform allows - a symlink where the process
     * may create one, a directory junction on Windows, which needs no elevation. Where
     * neither works the test is skipped rather than quietly passing, because a test
     * that asserts nothing while appearing to is worse than no test.
     */
    public function test_a_photo_reached_through_a_link_out_of_the_store_is_refused(): void
    {
        Storage::fake('local');
        $root = Storage::disk('local')->path('');

        // Deliberately outside the store. A link pointing at another directory inside
        // the store is not an escape, and testing that would pass for the wrong reason.
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'retd-outside-'.uniqid();
        File::ensureDirectoryExists($outside);
        File::put($outside.DIRECTORY_SEPARATOR.'secret.jpg', 'not a photograph');

        File::ensureDirectoryExists($root.'discovery-evidence');

        // Unique, because a junction outlives the test and a leftover one from an
        // earlier run would make the next run fail to create it - and then skip, which
        // looks like a pass.
        $folder = 'LINKED-'.uniqid();
        $link = $root.'discovery-evidence/'.$folder;

        try {
            if (! $this->linkDirectory($outside, $link)) {
                $this->markTestSkipped('This platform will not let the test process create a directory link.');
            }

            $this->act('account.officer@test.lra');
            $discovery = $this->aDiscovery('LINKED');
            $discovery->evidence()->create([
                'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
                'sequence_no' => 1,
                'file_path' => 'discovery-evidence/'.$folder.'/secret.jpg',
                'original_name' => 'secret.jpg',
                'mime_type' => 'image/jpeg',
            ]);

            $this->act('val.manager@test.lra');
            $html = $this->get($this->printUrl('discovery', $discovery->id))->assertOk()->getContent();

            $this->assertStringContainsString('outside the evidence store', $html);
            $this->assertStringNotContainsString('data:image/jpeg;base64,', $html, 'A file outside the evidence store was embedded.');
        } finally {
            if (PHP_OS_FAMILY === 'Windows' && is_dir($link)) {
                // A junction is removed with rmdir, not by deleting through it.
                exec(sprintf('rmdir "%s" 2>&1', $link));
            }
            File::deleteDirectory($outside);
        }
    }

    /**
     * Point one directory at another, using whichever mechanism this platform allows.
     */
    private function linkDirectory(string $target, string $link): bool
    {
        if (@symlink($target, $link)) {
            return true;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows refuses symlink() to an unprivileged process, but a directory
            // junction requires no elevation and realpath() follows it identically.
            exec(sprintf('mklink /J "%s" "%s" 2>&1', $link, $target), $output, $code);

            return $code === 0 && is_dir($link);
        }

        return false;
    }

    /**
     * Attach description lines to a valuation the way the valuation form does, with
     * the value RETD computes from them.
     *
     * Only the columns the schema allows to be blank are left blank, so the fixture
     * stays a record the application could actually have produced.
     */
    private function addDescriptions(Valuation $valuation, array $lines): void
    {
        foreach ($lines as $i => [$description, $level, $area, $tar, $qty, $amount, $depreciation]) {
            $valuation->descriptions()->create([
                'seq' => $i + 1,
                'description' => $description,
                'level' => $level,
                'area_sqft' => $area,
                'tar' => $tar,
                'quantity' => $qty,
                'amount' => $amount,
                'depreciation_pct' => $depreciation ?? 0,
                'value' => $amount === null
                    ? null
                    : ValuationPropertyDescription::computeValue($amount, $depreciation ?? 0),
            ]);
        }
    }

    private function theOfficer(string $email = 'val.officer@test.lra'): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /**
     * A valuation raised from a discovery, as the workflow actually does it.
     *
     * The link is the point of this fixture. A valuation that is not linked to a
     * discovery cannot be used to test photograph inheritance, and the previous
     * valuation fixture had no link - which is how a record sheet came to deny
     * three photographs that had been sitting on file for months.
     */
    private function aValuationRaisedFrom(PropertyDiscovery $discovery, User $officer, string $suffix): Valuation
    {
        $valuation = $this->aValuationOwnedBy($officer, $suffix);

        $valuation->forceFill([
            'discovery_id' => $discovery->id,
            // The real record carries neither a property_id nor a bill_id: a discovery
            // is what identifies the subject, and the document number stands alone.
            'property_id' => null,
        ])->save();

        return $valuation->refresh();
    }

    /**
     * Attach photographs to a discovery the way DiscoveryController does, storing
     * them under `discovery-evidence/<reference>/`.
     */
    private function attachDiscoveryPhotos(PropertyDiscovery $discovery, array $files): void
    {
        foreach ($files as $index => [$width, $height]) {
            $name = 'site-'.($index + 1).'.jpg';
            $path = $this->storePhoto(
                'discovery-evidence/'.$discovery->discovery_reference.'/'.$name,
                $width,
                $height
            );

            PropertyDiscoveryEvidence::create([
                'discovery_id' => $discovery->id,
                'property_id' => $discovery->property_id,
                'evidence_type' => PropertyDiscoveryEvidence::TYPE_PROPERTY_PHOTO,
                'sequence_no' => $index + 1,
                'file_path' => $path,
                'original_name' => $name,
                'mime_type' => 'image/jpeg',
                'size_bytes' => Storage::disk('local')->size($path),
            ]);
        }
    }

    /**
     * A second valuation officer, so a record can be made somebody else's.
     *
     * Built the way the seeder builds one, because the point of these tests is that
     * a valuation officer's scope is their own caseload: the refusal has to come from
     * a genuine second officer, not from a missing user row.
     */
    private function theOtherOfficer(): User
    {
        return $this->localRoleUser('val.officer2@test.lra', 'Valuation Officer', 'VAL');
    }

    /** Grant a single extra permission to a user's role, via the model's own API. */
    private function grantExportTo(string $email): void
    {
        User::where('email', $email)->firstOrFail()->role->grant('property.export');
    }

    /* ------------------------------------------------------------------ */
    /*  Authorization */
    /* ------------------------------------------------------------------ */

    /**
     * The button is hidden in the UI, but the endpoint must not depend on that. A
     * Valuation Officer can open valuations all day and still must not be able to
     * walk away with a paper copy of one.
     */
    public function test_a_user_without_the_export_permission_is_refused(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'NOPERM');

        $this->act('val.officer@test.lra');
        $this->getJson($this->url('valuation', $valuation->id))->assertForbidden();
    }

    public function test_an_authenticated_user_must_be_logged_in(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'ANON');

        $this->getJson($this->url('valuation', $valuation->id))->assertUnauthorized();
    }

    /**
     * Holding property.export is necessary but not sufficient, and the decision must
     * be re-derived for the record named in the URL. The officer below is granted the
     * permission so that a scope refusal - rather than a permission refusal - is what
     * actually answers.
     */
    public function test_scope_is_re_derived_for_the_record_in_the_url(): void
    {
        $colleague = $this->localRoleUser('val.officer2@test.lra', 'Valuation Officer', 'VAL');

        $mine = $this->aValuationOwnedBy($this->theOfficer(), 'MINE');
        $theirs = $this->aValuationOwnedBy($colleague, 'THEIRS');

        $this->grantExportTo('val.officer@test.lra');

        $this->act('val.officer@test.lra');
        $this->getJson($this->url('valuation', $theirs->id))->assertForbidden();
        $this->getJson($this->url('valuation', $mine->id))->assertOk();
    }

    public function test_a_discovery_outside_scope_is_refused(): void
    {
        $discovery = $this->aDiscovery('OUTSCOPE');

        // The Enforcement Manager holds property.export but has no business seeing
        // the Account & Records discovery queue.
        $this->act('enf.officer@test.lra');
        $this->getJson($this->url('discovery', $discovery->id))->assertForbidden();
    }

    public function test_an_unknown_record_type_is_not_routed(): void
    {
        $this->act('val.manager@test.lra');

        $this->getJson('/api/v1/property-record/bogus/1/pdf')->assertNotFound();
    }

    public function test_a_missing_record_is_a_404_not_a_crash(): void
    {
        $this->act('val.manager@test.lra');

        $this->getJson($this->url('valuation', 999999))->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  The document itself */
    /* ------------------------------------------------------------------ */

    public function test_a_valuation_record_downloads_as_a_real_pdf(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'PDF');

        $this->act('val.manager@test.lra');
        $response = $this->get($this->url('valuation', $valuation->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /**
     * A4 landscape is a requirement, not a preference: the information blocks and the
     * photograph grid are sized against it, and on A4 portrait the sheet reflows into
     * an unreadable strip. 297 x 210 mm is 841.89 x 595.28 pt.
     */
    public function test_the_pdf_page_is_a4_landscape(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'A4');

        $this->act('val.manager@test.lra');
        $body = $this->get($this->url('valuation', $valuation->id))->getContent();

        $this->assertMatchesRegularExpression(
            '/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+(\d+\.?\d*)\s+(\d+\.?\d*)\s*\]/',
            $body,
            'The rendered PDF exposed no MediaBox, so the page geometry cannot be asserted.'
        );

        preg_match('/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+(\d+\.?\d*)\s+(\d+\.?\d*)\s*\]/', $body, $m);
        $width = (float) $m[1];
        $height = (float) $m[2];

        $this->assertGreaterThan($height, $width, 'The page is not landscape.');
        $this->assertEqualsWithDelta(841.89, $width, 2.0, 'Page width is not A4 (297 mm).');
        $this->assertEqualsWithDelta(595.28, $height, 2.0, 'Page height is not A4 (210 mm).');
    }

    public function test_a_property_record_response_is_never_cached(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'NOCACHE');

        $this->act('val.manager@test.lra');
        $cacheControl = $this->get($this->url('valuation', $valuation->id))
            ->headers->get('cache-control');

        // A property record carries a taxpayer's identity, value and photographs, so
        // no intermediary or browser cache may retain a copy. The directives are
        // asserted individually because the response layer reorders them.
        foreach (['no-store', 'private'] as $directive) {
            $this->assertStringContainsString(
                $directive,
                $cacheControl,
                "Cache-Control is missing {$directive}: {$cacheControl}"
            );
        }
    }

    public function test_the_filename_identifies_the_record_and_is_filesystem_safe(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'FNAME');

        // The reference is generated from the row id and is permanent, so the filename
        // is asserted against the record's real reference rather than a value the
        // fixture tries to dictate.
        $this->assertMatchesRegularExpression('/^VAL-\d{4}-\d{5}$/', $valuation->valuation_reference);

        $this->act('val.manager@test.lra');
        $disposition = $this->get($this->url('valuation', $valuation->id))
            ->headers->get('content-disposition');

        $this->assertMatchesRegularExpression(
            '/filename="RETD_Valuation_Assessment_'.preg_quote($valuation->valuation_reference, '/')
            .'_TIN-OWN-FNAME_P-FNAME\.pdf"/',
            $disposition
        );
    }

    public function test_the_document_states_the_generating_user_and_time(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'GEN');

        $this->act('val.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('valuation', $valuation->id, auth()->user());

        $this->assertSame('Valuation Manager', $payload['generated']['by']);
        $this->assertSame('Africa/Monrovia', $payload['generated']['timezone']);
        $this->assertNotEmpty($payload['generated']['at']);
    }

    /* ------------------------------------------------------------------ */
    /*  One record, and only one record */
    /* ------------------------------------------------------------------ */

    /**
     * The most damaging possible bug in this feature: a sheet carrying the right
     * values *and* a neighbour's. The payload is asserted directly, because once
     * Dompdf compresses the page content the text is not greppable and a passing
     * string search would prove nothing.
     */
    public function test_the_payload_describes_only_the_requested_record(): void
    {
        $wanted = $this->aValuationOwnedBy($this->theOfficer(), 'WANTED');
        $other = $this->aValuationOwnedBy($this->theOfficer(), 'OTHER');

        $this->act('val.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('valuation', $wanted->id, auth()->user());

        $this->assertSame($wanted->valuation_reference, $payload['identity']['reference']);
        $this->assertSame('TIN-OWN-WANTED', $payload['identity']['tin']);

        $serialized = json_encode($payload);
        $this->assertStringNotContainsString($other->valuation_reference, $serialized);
        $this->assertStringNotContainsString('TIN-OWN-OTHER', $serialized);
        $this->assertStringNotContainsString('Owner OTHER', $serialized);
    }

    /* ------------------------------------------------------------------ */
    /*  Record-type identity */
    /* ------------------------------------------------------------------ */

    public function test_a_new_property_valuation_is_titled_as_a_valuation_assessment(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'NEWPROP', 'new_property');

        $this->act('val.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('valuation', $valuation->id, auth()->user());

        $this->assertSame('valuation_assessment', $payload['record_type']);
        $this->assertSame('PROPERTY VALUATION ASSESSMENT RECORD', $payload['title']);
        $this->assertFalse($payload['is_reassessment']);
    }

    public function test_a_reassessment_compares_against_the_value_on_the_register(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'REASSESS', 'reassessment');
        $valuation->update(['assessed_value' => 250000]);

        $bill = PropertyBill::create([
            'property_id' => $valuation->property_id,
            'document_number' => 'DOC-ONREGISTER',
            'taxpayer_name' => 'Owner REASSESS',
            'tin' => 'TIN-OWN-REASSESS',
            'property_address' => 'Address REASSESS, Monrovia',
            'assessed_value' => 200000,
            'tax_period' => '2025',
            'total_tax_due' => 2000,
            'date_logged' => now()->toDateString(),
        ]);
        $valuation->update(['bill_id' => $bill->id]);

        $this->act('val.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('valuation', $valuation->id, auth()->user());

        $this->assertSame('reassessment', $payload['record_type']);
        $this->assertSame('PROPERTY REASSESSMENT RECORD', $payload['title']);
        $this->assertTrue($payload['is_reassessment']);

        $rows = collect($payload['value'])->keyBy('label');
        $this->assertSame('US$ 200,000.00', $rows['Previous assessment']['value']);
        $this->assertSame('US$ 250,000.00', $rows['Current assessment']['value']);
        $this->assertSame('+US$ 50,000.00', $rows['Change in value']['value']);
    }

    /**
     * With nothing on the register to compare against, no previous value is claimed.
     * Inventing one would put a fabricated figure on an official sheet.
     */
    public function test_a_reassessment_with_no_bill_makes_no_previous_value_claim(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'NOBILL', 'reassessment');

        $this->act('val.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('valuation', $valuation->id, auth()->user());

        $rows = collect($payload['value'])->keyBy('label');
        $this->assertFalse($rows->has('Previous assessment'));
        $this->assertFalse($rows->has('Change in value'));
    }

    public function test_a_discovery_record_is_titled_as_a_property_discovery_record(): void
    {
        $discovery = $this->aDiscovery('DISC');

        $this->act('account.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('discovery', $discovery->id, auth()->user());

        $this->assertSame('discovery', $payload['record_type']);
        $this->assertSame('PROPERTY DISCOVERY RECORD', $payload['title']);
        $this->assertNotEmpty($payload['identity']['reference']);
        $this->assertSame('TIN-OWN-DISC', $payload['identity']['tin']);
    }

    public function test_a_discovery_record_also_downloads_as_a_pdf(): void
    {
        $discovery = $this->aDiscovery('DISCPDF');

        $this->act('account.manager@test.lra');
        $response = $this->get($this->url('discovery', $discovery->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /* ------------------------------------------------------------------ */
    /*  Photographs */
    /* ------------------------------------------------------------------ */

    /**
     * A photograph on the sheet is the point of the sheet, so it must be a real
     * embedded image - a data URI, not a link to an endpoint the reader cannot
     * authenticate to, and not a placeholder box.
     */
    public function test_an_attached_photograph_is_embedded_as_a_real_image(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('evidence/print-test/photo.png', base64_decode(self::PNG_1X1));

        $discovery = $this->aDiscovery('PHOTO');
        $discovery->evidence()->create([
            'evidence_type' => 'PROPERTY_PHOTO',
            'sequence_no' => 1,
            'file_path' => 'evidence/print-test/photo.png',
            'original_name' => 'Front elevation.png',
            'mime_type' => 'image/png',
            'size_bytes' => 68,
            'capture_source' => 'CAMERA',
            'captured_at' => now(),
        ]);

        $this->act('account.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('discovery', $discovery->id, auth()->user());

        $this->assertCount(1, $payload['photos']);
        $photo = $payload['photos'][0];
        $this->assertStringStartsWith('data:image/jpeg;base64,', $photo['data_uri']);
        $this->assertNotNull($photo['width']);
        $this->assertNotNull($photo['height']);
        $this->assertNull($photo['note']);
        $this->assertStringContainsString('Front elevation', $photo['caption']);
    }

    /**
     * A missing file must be stated on the face of the document. Dropping the entry
     * silently would leave a sheet that looks complete but shows fewer photographs
     * than the record holds - which on an official copy is indistinguishable from
     * having none at all.
     */
    public function test_a_missing_photograph_is_reported_rather_than_dropped(): void
    {
        Storage::fake('local');

        $discovery = $this->aDiscovery('GONE');
        $discovery->evidence()->create([
            'evidence_type' => 'PROPERTY_PHOTO',
            'sequence_no' => 1,
            'file_path' => 'evidence/print-test/gone.png',
            'original_name' => 'Vanished.png',
            'mime_type' => 'image/png',
            'size_bytes' => 68,
            'capture_source' => 'CAMERA',
        ]);

        $this->act('account.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('discovery', $discovery->id, auth()->user());

        $this->assertCount(1, $payload['photos']);
        $this->assertNull($payload['photos'][0]['data_uri']);
        $this->assertSame('File is no longer available.', $payload['photos'][0]['note']);
    }

    /**
     * A stored path pointing outside the evidence store must never be read. A crafted
     * path is how a file-disclosure bug starts, so the attempt is refused at the door
     * and named on the sheet.
     */
    public function test_a_photograph_path_outside_the_evidence_store_is_refused(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('secret/notes.txt', 'do not include me');

        $discovery = $this->aDiscovery('TRAVERSAL');
        $discovery->evidence()->create([
            'evidence_type' => 'PROPERTY_PHOTO',
            'sequence_no' => 1,
            'file_path' => '../secret/notes.txt',
            'original_name' => 'Traversal.png',
            'mime_type' => 'image/png',
        ]);

        $this->act('account.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('discovery', $discovery->id, auth()->user());

        $this->assertNull($payload['photos'][0]['data_uri']);
        $this->assertStringNotContainsString('do not include me', json_encode($payload));
    }

    /* ------------------------------------------------------------------ */
    /*  GPS */
    /* ------------------------------------------------------------------ */

    public function test_a_recorded_fix_yields_drawable_coordinates(): void
    {
        $discovery = $this->aDiscovery('GPS');
        $discovery->update(['gps_coordinate' => '6.313000,-10.807400']);

        $this->act('account.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('discovery', $discovery->id, auth()->user());

        $this->assertTrue($payload['gps']['has_fix']);
        $this->assertEqualsWithDelta(6.313, $payload['gps']['latitude'], 0.000001);
        $this->assertEqualsWithDelta(-10.8074, $payload['gps']['longitude'], 0.000001);
    }

    /**
     * No fix means no locator, and the sheet must say so. Silently omitting the
     * section would let a reader assume a location that was never established.
     */
    public function test_a_record_without_a_fix_reports_no_coordinates(): void
    {
        $discovery = $this->aDiscovery('NOFIX');
        $discovery->update(['gps_coordinate' => null, 'gps_accuracy' => null]);

        $this->act('account.manager@test.lra');
        $payload = app(PropertyRecordBuilder::class)->build('discovery', $discovery->id, auth()->user());

        $this->assertFalse($payload['gps']['has_fix']);
        $this->assertNull($payload['gps']['latitude']);
    }

    /* ------------------------------------------------------------------ */
    /*  Audit */
    /* ------------------------------------------------------------------ */

    public function test_a_successful_export_is_audited_with_the_generating_user(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'AUDIT');

        $this->act('val.manager@test.lra');
        $this->get($this->url('valuation', $valuation->id))->assertOk();

        $entry = AuditLog::where('action', 'property_record.exported')->latest('id')->firstOrFail();

        $this->assertSame(Valuation::class, $entry->auditable_type);
        $this->assertSame($valuation->id, $entry->auditable_id);
        $this->assertSame(User::where('email', 'val.manager@test.lra')->value('id'), $entry->actor_id);
        $this->assertNotEmpty($entry->new_values['filename']);
        $this->assertGreaterThan(0, $entry->new_values['bytes']);
        $this->assertSame('Africa/Monrovia', $entry->new_values['timezone']);
    }

    /**
     * A refusal leaves a trace. If a series of blocked attempts can be read back
     * later, the audit is doing its job; if they vanish, the endpoint is a blind spot
     * in the accountability the rest of the system maintains.
     */
    public function test_a_blocked_export_is_audited_as_a_blocked_attempt(): void
    {
        $valuation = $this->aValuationOwnedBy($this->theOfficer(), 'BLOCKED');

        $this->act('val.officer@test.lra');
        $this->getJson($this->url('valuation', $valuation->id))->assertForbidden();

        $entry = AuditLog::where('action', 'property_record.export_blocked')->latest('id')->firstOrFail();

        $this->assertSame('forbidden', $entry->new_values['reason']);
        $this->assertSame('valuation', $entry->new_values['record_type']);
        $this->assertSame(Valuation::class, $entry->auditable_type);
        $this->assertSame($valuation->id, $entry->auditable_id);
    }
}
