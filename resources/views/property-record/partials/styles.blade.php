{{--
    The document's stylesheet, shared by the PDF and browser renderers.

    Fixed rather than fluid on purpose: the PDF renderer cannot measure text,
    and a box that auto-grows silently paints over whatever follows it in a
    fixed-position renderer. The browser tolerates the same constraints, so
    one sheet of CSS serves both outputs and they stay visually identical.

    @page is honoured by browsers as well as Dompdf, so this single rule is what
    gives both outputs A4 landscape.
--}}

    <style>
        @page {
            size: A4 landscape;
            margin: 11mm 11mm 16mm 11mm;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8.2pt;
            color: #111827;
        }

        /* ---- masthead ------------------------------------------------- */
        .masthead {
            display: table;
            width: 100%;
            border-bottom: 2px solid #0f2d4a;
            padding-bottom: 3mm;
            margin-bottom: 3mm;
        }
        .masthead-left  { display: table-cell; width: 46%; vertical-align: middle; }
        .masthead-right { display: table-cell; vertical-align: middle; text-align: right; }

        .agency {
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 0.3pt;
            color: #0f2d4a;
            line-height: 1.25;
        }
        .agency-sub { font-size: 7.4pt; color: #475569; margin-top: 1mm; }

        .doc-title {
            font-size: 11pt;
            font-weight: bold;
            color: #0f2d4a;
            line-height: 1.2;
        }
        .doc-badge {
            display: inline-block;
            margin-top: 1.5mm;
            padding: 1mm 2.5mm;
            border: 1px solid #0f2d4a;
            font-size: 6.6pt;
            font-weight: bold;
            letter-spacing: 0.6pt;
            color: #0f2d4a;
        }
        .doc-ref {
            margin-top: 1.5mm;
            font-size: 8pt;
            font-weight: bold;
        }

        /* ---- section headings ----------------------------------------- */
        h2.section {
            font-size: 7.4pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.7pt;
            color: #0f2d4a;
            border-bottom: 1px solid #94a3b8;
            padding-bottom: 0.8mm;
            margin: 0 0 1.8mm 0;
        }

        /* ---- generic two-column table ---------------------------------- */
        table.grid {
            width: 100%;
            border-collapse: collapse;
        }
        table.grid td {
            border: 1px solid #cbd5e1;
            padding: 1.1mm 1.6mm;
            vertical-align: top;
        }
        td.k {
            width: 32%;
            background: #f1f5f9;
            font-weight: bold;
            color: #334155;
        }
        td.v { width: 68%; }
        td.v.span { width: auto; padding: 0; }

        /* ---- repeatability sub-table (property description) ------------ */
        /* Widths are declared on the cells rather than left to the auto algorithm.
        Dompdf distributes an auto table by content, which put the money columns at
        whatever width their longest figure happened to need and left the narrow ones
        ragged - so the columns did not line up down the page, which is the one thing
        a table is for. */
        table.desc {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.2pt;
            /* The declared widths below are the widths. Measured against a deliberately
            hostile row - a 65-character description, a seven-figure amount, a five-digit
            area - both layouts render at exactly the 275mm printable width, so this is
            a bound rather than a fix: it keeps a future unbreakable value from being
            allowed to widen a column and quietly turn the table back into prose. */
            table-layout: fixed;
        }
        table.desc th {
            background: #e8eef5;
            border: 1px solid #94a3b8;
            padding: 1.1mm 1.2mm;
            text-align: left;
            font-size: 6.6pt;
            letter-spacing: 0.2pt;
            text-transform: uppercase;
            color: #0f2d4a;
            white-space: nowrap;
        }
        table.desc td {
            border: 1px solid #cbd5e1;
            padding: 1.1mm 1.2mm;
            vertical-align: top;
        }
        table.desc td.r { text-align: right; white-space: nowrap; }
        table.desc td.l { text-align: left; }
        table.desc tfoot td {
            background: #f8fafc;
            border-top: 1.5px solid #0f2d4a;
            font-weight: bold;
        }
        table.desc tfoot td.strong { color: #0f2d4a; }

        table.desc th:nth-child(1), table.desc td:nth-child(1) { width: 4%; }
        table.desc th:nth-child(2), table.desc td:nth-child(2) { width: 22%; }
        table.desc th:nth-child(3), table.desc td:nth-child(3) { width: 14%; }
        table.desc th:nth-child(4), table.desc td:nth-child(4) { width: 12%; }
        table.desc th:nth-child(5), table.desc td:nth-child(5) { width: 7%; }
        table.desc th:nth-child(6), table.desc td:nth-child(6) { width: 5%; }
        table.desc th:nth-child(7), table.desc td:nth-child(7) { width: 13%; }
        table.desc th:nth-child(8), table.desc td:nth-child(8) { width: 8%; }
        table.desc th:nth-child(9), table.desc td:nth-child(9) { width: 15%; }

        /* ---- multi-column information area ---------------------------- */
        .cols { display: table; width: 100%; border-spacing: 2.2mm 0; margin-left: -2.2mm; }
        .col  { display: table-cell; width: 33.33%; vertical-align: top; }
        .col + .col { padding-left: 2.2mm; }

        .block { margin-bottom: 2.6mm; }

        /* ---- photographs ---------------------------------------------- */
        .photo-grid { display: table; width: 100%; border-spacing: 2mm 0; margin-left: -2mm; }
        .photo-grid + .photo-grid { margin-top: 2mm; }
        .photo-cell { display: table-cell; width: 33.33%; vertical-align: top; padding-left: 2mm; }

        figure.photo {
            margin: 0;
            border: 1px solid #cbd5e1;
            padding: 1.4mm;
            text-align: center;
        }

    /* The placeholder adapts to whatever page it is printed on, which is what these
       two maxima buy. The photograph keeps its own proportions and is scaled DOWN to
       whichever limit binds first, so no pixel size and no aspect ratio needs
       measuring in advance.

       On a true A4 landscape page the millimetres set inline on each image already fit
       inside the cell, and these change nothing - verified by rendering the real record
       with and without this rule and getting a byte-identical PDF. Printed on anything
       narrower, A4 portrait being the obvious one, the cell shrinks and these clamp the
       image to it rather than letting the row spill across the page.

       object-fit says the same thing more directly, but Dompdf does not implement it,
       and with width:100% against a fixed height it stretches the image to fill the
       box - a wide frontage arrives as a smear, and a record sheet that distorts the
       evidence it carries is worse than one that omits it. */
        figure.photo img {
            display: block;
            margin: 0 auto;
            max-width: 100%;
            max-height: 46mm;
        }

        figcaption {
            margin-top: 1.1mm;
            font-size: 6.2pt;
            color: #475569;
            line-height: 1.3;
        }
        .photo-ref { font-weight: bold; color: #0f2d4a; }
        .missing {
            height: 46mm;
            border: 1px dashed #94a3b8;
            color: #64748b;
            font-size: 6.4pt;
            padding: 2mm;
            line-height: 1.35;
        }
        .none {
            border: 1px dashed #cbd5e1;
            color: #64748b;
            font-size: 6.6pt;
            padding: 1.8mm;
        }

        /* ---- coordinate locator --------------------------------------- */
        .locator-wrap { display: table; width: 100%; }
        .locator-map  { display: table-cell; width: 52mm; vertical-align: top; padding-right: 2.5mm; }
        .locator-data { display: table-cell; vertical-align: top; }

        .locator-map svg {
            width: 52mm;
            height: 52mm;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
        }

        /* ---- workflow ladder ------------------------------------------ */
        .ladder { display: table; width: 100%; border-collapse: separate; border-spacing: 0.8mm 0; }
        .stage {
            display: table-cell;
            width: 14.28%;
            border: 1px solid #cbd5e1;
            border-top: 2px solid #cbd5e1;
            padding: 1.2mm 1mm;
            font-size: 5.9pt;
            line-height: 1.25;
            text-align: center;
            color: #64748b;
        }
        .stage.done { border-top-color: #15803d; color: #15803d; }
        .stage.current {
            border: 1px solid #0f2d4a;
            border-top: 2px solid #0f2d4a;
            background: #e0e7ff;
            color: #0f2d4a;
            font-weight: bold;
        }

        /* ---- prose field (fixed multi-line block) --------------------- */
        .prose {
            border: 1px solid #cbd5e1;
            padding: 1.4mm 1.6mm;
            min-height: 11mm;
            font-size: 7.4pt;
            line-height: 1.45;
            white-space: pre-line;
        }

        /* ---- signature block ------------------------------------------ */
        .signatures { display: table; width: 100%; border-spacing: 2.5mm 0; }
        .sign { display: table-cell; width: 33.33%; }
        .sign-line {
            border-bottom: 1px solid #334155;
            height: 11mm;
        }
        .sign-label { font-size: 6.2pt; color: #475569; padding-top: 1mm; }

        /* ---- footer --------------------------------------------------- */
        .footer {
            position: fixed;
            bottom: -11mm;
            left: 0;
            right: 0;
            border-top: 1px solid #cbd5e1;
            padding-top: 1.2mm;
            font-size: 6pt;
            color: #64748b;
        }
        .footer-table { width: 100%; }
        .footer-table td { border: none; padding: 0; }

        .status-pill {
            display: inline-block;
            padding: 0.6mm 2mm;
            background: #e2e8f0;
            font-size: 6.6pt;
            font-weight: bold;
            color: #1e293b;
        }
    </style>
