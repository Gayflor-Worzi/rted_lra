<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyDiscovery;
use App\Models\Valuation;
use App\Services\AuditService;
use App\Services\PropertyRecordBuilder;
use Barryvdh\DomPDF\PDF as DomPdfFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Produce an official copy of a Property Record (spec §7/§9).
 *
 * A Property Record is a single official sheet carrying the property's identity,
 * ownership, value, photographs, coordinate locator and documentary evidence. Two
 * properties of the output are treated as requirements rather than styling:
 *
 *  - It is A4 *landscape* with 11 mm margins, so the information blocks and the
 *    photograph grid fit a physical page without scaling.
 *  - Both outputs render the same shared body partial, so a printed sheet and a
 *    downloaded file cannot disagree about wording, fields or section order.
 *
 * Two outputs are offered, and they are deliberately not two implementations:
 *
 *  - pdf()   hands back a real PDF file, for filing and for sending onward.
 *  - print() returns the same sheet as a web page for the browser's own print
 *            dialogue, which is the only way an operator can choose a printer,
 *            print a range, or file a copy without leaving the system.
 *
 * Authorization is enforced here, independently of the frontend. The frontend
 * hides the actions unless the user holds property.export, but that is a
 * convenience, not a control: both endpoints re-check the permission, then
 * re-derive record scope through the same PropertyRecordAccess rules the
 * Property View uses. Substituting another record's id in the URL therefore
 * subjects the request to *that* record's own access decision, never a copy of the
 * first one.
 */
class PropertyRecordExportController extends Controller
{
    private const TYPES = ['discovery', 'valuation'];

    public function __construct(
        private PropertyRecordBuilder $records,
        private AuditService $audit,
    ) {}

    /** Download the record as a PDF file. */
    public function pdf(Request $request, string $type, int $id)
    {
        ['record' => $record, 'auditable' => $auditable] = $this->authorized($request, $type, $id);

        try {
            $html = view('property-record.document', [
                'record' => $record,
                'agency' => config('app.name'),
            ])->render();

            // The renderer is resolved per document rather than injected, and the
            // reason is not tidiness. Illuminate\Routing\Route memoizes the
            // controller instance for the life of the process, so anything held as
            // a constructor property is shared by every later request handled by the
            // same route. Dompdf keeps the parsed frame tree on the instance and a
            // second document rendered through a used one dies in the block reflower
            // with a null parent frame - and a PDF download is exactly the request
            // that is asked for twice in a row. A classic PHP-FPM worker dies
            // between requests and never notices; a queue worker, an Octane worker, or
            // the test suite does. Resolving here guarantees a clean renderer per
            // document regardless of how long the process lives.
            $pdf = app(DomPdfFactory::class)->loadHTML($html);
            $pdf->setPaper('a4', 'landscape');
            $pdf->setOptions([
                // Page margins are declared once, in the shared stylesheet's @page
                // rule, so the PDF and the browser print layout cannot disagree about
                // them. These values restate that rule for the renderer's defaults.
                'margin_top' => 11,
                'margin_right' => 11,
                // The bottom margin is deeper than the rest because the footer sits
                // inside it; see the @page rule in property-record.partials.styles.
                'margin_bottom' => 16,
                'margin_left' => 11,
                // The document is self-contained by design: photographs are inlined
                // as data URIs and the coordinate locator is drawn server-side.
                // Refusing remote assets means the renderer can never reach out for a
                // map tile or any other external resource, whatever happens to be
                // stored in an evidence filename.
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                'isFontSubsettingEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
                'defaultPaperSize' => 'a4',
                'defaultPaperOrientation' => 'landscape',
                'logOutputFile' => false,
            ]);

            $output = $pdf->output();

            $this->audited($request, $auditable, 'property_record.exported', $record, 'pdf', strlen($output));

            return response($output, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Length' => (string) strlen($output),
                'Content-Disposition' => 'attachment; filename="'.$record['filename'].'"',
                // A property record carries a taxpayer's identity, value and
                // photographs. It must not be held by an intermediary cache.
                'Cache-Control' => 'private, no-store, max-age=0',
            ]);
        } catch (Throwable $e) {
            // A renderer failure is a genuine incident: the user asked for a
            // document and did not get one. Detail is kept server-side and the
            // response reveals nothing about the record or the fault.
            Log::error('Property record PDF render failed', [
                'record_type' => $type,
                'record_id' => $id,
                'actor_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);

            return response()->json([
                'message' => 'The property record could not be rendered. Please try again.',
            ], 500);
        }
    }

    /**
     * The same record as a web page, for the browser's own print dialogue.
     *
     * This is an official copy too, so it is authorized and audited exactly like the
     * download, and it carries the same no-store directive. It is served as a page
     * rather than a file so the browser can paginate it, count its own pages, and
     * give the operator the printer and page-range choices that a downloaded PDF
     * hides behind the system's own print dialogue.
     */
    public function print(Request $request, string $type, int $id)
    {
        ['record' => $record, 'auditable' => $auditable] = $this->authorized($request, $type, $id);

        try {
            $html = view('property-record.print', [
                'record' => $record,
                'agency' => config('app.name'),
            ])->render();
        } catch (Throwable $e) {
            Log::error('Property record print view failed', [
                'record_type' => $type,
                'record_id' => $id,
                'actor_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);

            return response()->json([
                'message' => 'The property record could not be prepared for printing. Please try again.',
            ], 500);
        }

        $this->audited($request, $auditable, 'property_record.printed', $record, 'print', strlen($html));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => (string) strlen($html),
            'Cache-Control' => 'private, no-store, max-age=0',
            // The page is opened as a top-level document by the operator, never
            // embedded, so it is locked out of framing. Its own inline script is
            // permitted because the sheet has to be able to raise the print
            // dialogue; nothing external is, and the stylesheet is inlined.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Authorize the request and return the record, or refuse it.
     *
     * The builder aborts 403/404 on a failed permission or scope check, so an
     * unauthorized request never reaches a renderer. abort() raises Laravel's
     * HttpException, so the base Symfony type is caught rather than its individual
     * subclasses: any HTTP-level refusal from the builder is, by construction, a
     * denial that belongs in the audit trail.
     *
     * A denial is expected traffic, not an incident. It is still audited as a blocked
     * attempt, because a pattern of these is exactly what an audit exists to reveal,
     * but it is not logged as an application error.
     *
     * @return array{record: array, auditable: Model}
     */
    private function authorized(Request $request, string $type, int $id): array
    {
        abort_unless(in_array($type, self::TYPES, true), 404, 'Unknown property record type.');

        try {
            return $this->records->buildWithRecord($type, $id, $request->user());
        } catch (HttpException $e) {
            $this->audit->append([
                'actor_id' => $request->user()?->id,
                // Both outputs are exports of the same official copy, so both
                // refusals are recorded under one action; the successes below
                // distinguish how a copy actually left the system.
                'action' => 'property_record.export_blocked',
                // The model was never loaded, so the morph class is resolved from the
                // route type to keep the blocked row traceable against the same
                // auditable_type as a successful export.
                'auditable_type' => $type === 'discovery' ? PropertyDiscovery::class : Valuation::class,
                'auditable_id' => $id,
                'new_values' => [
                    'record_type' => $type,
                    'reason' => $e->getStatusCode() === 403 ? 'forbidden' : 'not_found',
                ],
                'ip_address' => $request->ip(),
            ]);

            throw $e;
        }
    }

    /**
     * Record that a copy of the record was produced.
     *
     * `output` says whether it left as a file or as a printed sheet. Without it the
     * two would be indistinguishable in the trail, and "who printed what" is a
     * question an audit is asked often enough to be worth answering exactly.
     */
    private function audited(
        Request $request,
        Model $auditable,
        string $action,
        array $record,
        string $output,
        int $bytes,
    ): void {
        $this->audit->record($auditable, $action, $request->user()->id, [], [
            'record_type' => $record['record_type'] ?? null,
            'record_reference' => $record['identity']['reference'],
            'filename' => $record['filename'],
            'output' => $output,
            'bytes' => $bytes,
            'generated_at' => $record['generated']['at'],
            'timezone' => $record['generated']['timezone'],
        ], $request->ip());
    }
}
