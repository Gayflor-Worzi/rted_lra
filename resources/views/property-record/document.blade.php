{{--
    Property Record, PDF renderer (spec §8).

    This file is a shell only: page setup, the shared stylesheet, the shared body
    partial, and the page-numbered footer. All of the record's content lives in
    property-record.partials.body, which the browser print view also includes, so
    a downloaded file and a printed page state the same facts in the same order.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $record['title'] }} — {{ $record['identity']['reference'] ?? 'Unnumbered' }}</title>
    @include('property-record.partials.styles')
</head>
<body>
    @include('property-record.partials.body')

    {{-- ================= footer ================= --}}
    {{-- Kept out of the shared body: Dompdf substitutes real page numbers into
         .pageNumber/.totalPages, which the browser would print as two empty spans.
         The browser view carries its own simpler footer instead. --}}
    <div class="footer">
        <table class="footer-table">
            <tr>
                <td style="text-align:left">
                    {{ $record['title'] }} &middot; {{ $record['identity']['reference'] ?? 'Unnumbered' }}
                </td>
                <td style="text-align:center">
                    Copy of Record &middot; {{ $record['generated']['at'] }} {{ $record['generated']['timezone'] }}
                </td>
                <td style="text-align:right">
                    Page <span class="pageNumber"></span> of <span class="totalPages"></span>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
