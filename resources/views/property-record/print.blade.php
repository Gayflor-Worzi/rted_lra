{{--
    Property Record, browser print view (spec §8).

    Opens in its own tab so the browser's own print dialogue can be used, and so the
    operator can read the sheet on screen first. The record's content comes from
    property-record.partials.body, the very same partial the PDF renderer includes,
    which is what makes the two outputs trustworthy copies of each other.

    Differences from the PDF, and only these:
      - a screen-only toolbar, so the operator can print, close, or go back
      - page numbers taken from CSS counters instead of Dompdf's substitution, since
        the browser already paginates for us and knows the real page count
      - on-screen presentation (centred sheet on a grey ground), which is removed
        again by @media print
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $record['title'] }} — {{ $record['identity']['reference'] ?? 'Unnumbered' }}</title>
    @include('property-record.partials.styles')
    <style>
        /* ---- on screen: present the sheet as a sheet -------------------- */
        html { background: #cbd5e1; }
        body {
            width: 297mm;
            min-height: 210mm;
            margin: 0 auto;
            padding: 11mm 11mm 16mm 11mm;
            background: #ffffff;
            box-shadow: 0 2px 18px rgba(15, 23, 42, .28);
        }

        /* A footer is pinned to the paper while printing, so it must not also be
           pinned to the viewport on screen. */
        body .footer { position: static; }

        /* There is no page count to report before the browser paginates, so the
           page cell is withheld on screen rather than showing a hollow "Page of". */
        .footer .page-cell { display: none; }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 5;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: -11mm -11mm 6mm;
            padding: 10px 14px;
            background: #0f172a;
            color: #e2e8f0;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 11px;
        }
        .toolbar .spacer { flex: 1; }
        .toolbar strong { letter-spacing: .04em; }
        .toolbar button {
            padding: 7px 14px;
            border: 0;
            border-radius: 6px;
            background: #ffffff;
            color: #0f172a;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .toolbar button:hover { background: #e2e8f0; }
        .toolbar .hint { color: #94a3b8; }

        /* ---- printing: the sheet alone, nothing else --------------------- */
        @media print {
            html { background: #ffffff; }
            body {
                width: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
            .toolbar { display: none !important; }

            /* Real page numbering, which the shared .footer cannot provide here
               because its counters are Dompdf substitutions. The cell is hidden on
               screen, where there is no page count to report, and appears only when
               the browser is doing the paginating. */
            body .footer { position: fixed; bottom: 0; left: 0; right: 0; }
            .footer .page-cell {
                display: table-cell;
                text-align: right;
            }
            .footer .page-number::after { content: counter(page); }
            .footer .page-total::after { content: counter(pages); }

            /* Photographs are the heaviest ink on the sheet. Print them in colour
               so the operator is not handed a grid of empty grey boxes. */
            .photo img { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

            /* Keep a section heading and its first row together, and stop a table
               row being torn across a page break. */
            .section-title, .info-table th, .sign { break-inside: avoid; page-break-inside: avoid; }
            tr { break-inside: avoid; page-break-inside: avoid; }

            /* The photograph grid is a display:table built from divs, so the tr rule
               above never reaches it and the browser is free to slice it between cells
               at a page break - which showed up as one photograph at the foot of a page
               and the other two overleaf. Each row is its own .photo-grid element, so
               refusing a break on the grid keeps a whole row of three together. */
            .photo-grid, .photo-cell, figure.photo { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>{{ strtoupper($record['title']) }}</strong>
        <span>{{ $record['identity']['reference'] ?? 'Unnumbered' }}</span>
        <span class="spacer"></span>
        <span class="hint">Print, then choose A4 and Landscape if the dialogue does not already</span>
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>

    @include('property-record.partials.body')

    {{-- The shared .footer markup carries Dompdf's counter placeholders, which the
         browser prints as nothing. The page cell below is withheld on screen and
         filled from CSS counters when printing; the identity and timestamp cells are
         plain text and correct in both renderers. --}}
    <div class="footer">
        <table class="footer-table">
            <tr>
                <td style="text-align:left">
                    {{ $record['title'] }} &middot; {{ $record['identity']['reference'] ?? 'Unnumbered' }}
                </td>
                <td style="text-align:center">
                    Copy of Record &middot; {{ $record['generated']['at'] }} {{ $record['generated']['timezone'] }}
                </td>
                <td class="page-cell">
                    Page <span class="page-number"></span> of <span class="page-total"></span>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
