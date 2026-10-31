{{--
    The Property Record itself: masthead, information area, record-specific
    sections, photographs, evidence register, workflow trail and attestation.

    This is the single source of the sheet's content. The PDF renderer
    (property-record.document) and the browser print view
    (property-record.print) both include it, so a downloaded file and a printed
    page cannot drift apart in wording, section order or field set.
--}}

@php
    $identity = $record['identity'];
    $gps = $record['gps'];
    $workflow = $record['workflow'];
    $photoCount = count($record['photos']);
@endphp

{{-- ================= masthead ================= --}}
<div class="masthead">
    <div class="masthead-left">
        <div class="agency">{{ $agency ?? 'RETD' }}</div>
        <div class="agency-sub">Ministry of Finance &amp; Revenue Authority &middot; Liberia</div>
    </div>
    <div class="masthead-right">
        <div class="doc-title">{{ $record['title'] }}</div>
        <div class="doc-badge">COPY OF RECORD</div>
        <div class="doc-ref">
            {{ $identity['reference'] ?? 'Unnumbered' }}
            @if ($identity['status'])
                &middot; <span class="status-pill">{{ $identity['status'] }}</span>
            @endif
        </div>
    </div>
</div>

{{-- ================= information area ================= --}}
<div class="cols">

    {{-- column 1: identification --}}
    <div class="col">
        <div class="block">
            <h2 class="section">1 &middot; Property identification</h2>
            <table class="grid">
                @foreach ([
                    'Record reference' => $identity['reference'],
                    'TIN' => $identity['tin'],
                    'Property ID' => $identity['property_id'],
                    'Property type' => $identity['property_type'],
                    'Classification' => $identity['classification'],
                    'Document number' => $identity['document_number'],
                ] as $label => $value)
                    @if ($value)
                        <tr>
                            <td class="k">{{ $label }}</td>
                            <td class="v">{{ $value }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
        </div>

        <div class="block">
            <h2 class="section">2 &middot; Owner</h2>
            <table class="grid">
                @forelse (array_filter($record['owner']) as $label => $value)
                    <tr>
                        <td class="k">{{ $label === 'name' ? 'Owner name' : 'Contact' }}</td>
                        <td class="v">{{ $value }}</td>
                    </tr>
                @empty
                    <tr><td class="k">Owner name</td><td class="v">Not recorded</td></tr>
                @endforelse
                <tr>
                    <td class="k">Property address</td>
                    <td class="v">
                        {{ $record['address']['full'] ?? ($record['address']['parts'] ?? 'Not recorded') }}
                    </td>
                </tr>
            </table>
        </div>
    </div>

    {{-- column 2: value --}}
    <div class="col">
        <div class="block">
            <h2 class="section">3 &middot; Value as recorded</h2>
            @if (count($record['value']))
                <table class="grid">
                    @foreach ($record['value'] as $row)
                        <tr>
                            <td class="k">{{ $row['label'] }}</td>
                            <td class="v">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @else
                <div class="none">No value figures recorded against this property.</div>
            @endif
        </div>

        <div class="block">
            <h2 class="section">4 &middot; Property particulars</h2>
            @if (count($record['facts']))
                <table class="grid">
                    @foreach ($record['facts'] as $row)
                        <tr>
                            <td class="k">{{ $row['label'] }}</td>
                            <td class="v">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @else
                <div class="none">No particulars recorded.</div>
            @endif
        </div>
    </div>

    {{-- column 3: coordinate locator --}}
    <div class="col">
        <div class="block">
            <h2 class="section">5 &middot; Coordinate locator</h2>
            <div class="locator-wrap">
                <div class="locator-map">
                    @if ($gps['has_fix'])
                        @include('property-record.partials.locator', ['gps' => $gps])
                    @else
                        <div class="missing">
                            No usable GPS fix is recorded for this property, so no coordinate
                            locator can be drawn. The absence is stated rather than filled
                            with an approximate position.
                        </div>
                    @endif
                </div>
                <div class="locator-data">
                    <table class="grid">
                        <tr>
                            <td class="k">Coordinates</td>
                            <td class="v">
                                @if ($gps['has_fix'])
                                    {{ number_format($gps['latitude'], 6) }},
                                    {{ number_format($gps['longitude'], 6) }}
                                @else
                                    Not recorded
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="k">Datum / source</td>
                            <td class="v">{{ $gps['source'] ?? 'Not recorded' }}</td>
                        </tr>
                        <tr>
                            <td class="k">Accuracy</td>
                            <td class="v">
                                {{ $gps['accuracy'] !== null ? rtrim(rtrim(number_format($gps['accuracy'], 2, '.', ''), '0'), '.').' m' : 'Not recorded' }}
                            </td>
                        </tr>
                        <tr>
                            <td class="k">Captured</td>
                            <td class="v">
                                {{ $gps['captured_at'] ?? 'Not recorded' }}
                                @if ($gps['captured_by'])
                                    <br>by {{ $gps['captured_by'] }}
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="k">Status</td>
                            <td class="v">{{ $gps['certification'] }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="block">
            <h2 class="section">6 &middot; Record provenance</h2>
            <table class="grid">
                <tr>
                    <td class="k">Generated by</td>
                    <td class="v">{{ $record['generated']['by'] }}</td>
                </tr>
                <tr>
                    <td class="k">Generated at</td>
                    <td class="v">
                        {{ $record['generated']['at'] }}
                        <br>{{ $record['generated']['timezone'] }}
                    </td>
                </tr>
                <tr>
                    <td class="k">Record version</td>
                    <td class="v">{{ $record['generated']['record_version'] ?? $identity['reference'] }}</td>
                </tr>
            </table>
        </div>
    </div>
</div>

{{-- ================= record-specific sections ================= --}}
@php $sectionNo = 6; @endphp

@foreach ($record['record_sections'] as $heading => $rows)
    @if (count($rows))
        @php $sectionNo++; @endphp
        <div class="block">
            <h2 class="section">{{ $sectionNo }} &middot; {{ $heading }}</h2>
            <table class="grid">
                @foreach ($rows as $row)
                    @if (! empty($row['table']))
                        {{-- A repeatability sub-table, which needs the full width to line
                        its columns up. Given half the page it would be no more readable
                        than the string it replaced. --}}
                        <tr>
                            <td class="v span" colspan="2">
                                <table class="desc">
                                    <thead>
                                        <tr>
                                            @foreach ($row['table']['columns'] as $column)
                                                <th>{{ $column }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($row['table']['rows'] as $line)
                                            <tr>
                                                @foreach (['seq', 'description', 'level', 'area', 'tar', 'quantity', 'amount', 'depreciation', 'value'] as $key)
                                                    <td class="{{ $key === 'description' || $key === 'level' ? 'l' : 'r' }}">{{ $line[$key] ?? '' }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    @if (! empty($row['table']['total']))
                                        <tfoot>
                                            <tr>
                                                <td class="l" colspan="{{ count($row['table']['columns']) - 2 }}">{{ $row['table']['total']['label'] }}</td>
                                                <td class="r strong" colspan="2">{{ $row['table']['total']['value'] }}</td>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </td>
                        </tr>
                    @else
                        <tr>
                            <td class="k">{{ $row['label'] }}</td>
                            <td class="v">{{ $row['value'] }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
        </div>
    @endif
@endforeach

{{-- free-text remarks, given an explicit block so they cannot overrun --}}
@if (! empty($record['remarks']))
    @php $sectionNo++; @endphp
    <div class="block">
        <h2 class="section">{{ $sectionNo }} &middot; Remarks</h2>
        <div class="prose">{{ $record['remarks'] }}</div>
    </div>
@endif

{{-- ================= photographs ================= --}}
@php
    $sectionNo++;

    /* The photograph box, in millimetres, that each image is fitted inside.
       A4 landscape at 11mm side margins leaves 275mm of content width, shared
       three ways, less the cell padding and the figure's own border. */
    $photoBoxW = 87.0;
    $photoBoxH = 46.0;
@endphp
<div class="block">
    <h2 class="section">{{ $sectionNo }} &middot; Property photographs ({{ $photoCount }})</h2>

    @if ($photoCount === 0)
        <div class="none">
            No property photograph is attached to this record. This is stated on the
            face of the document so that an absent image is never read as a property
            without photographs.
        </div>
    @else
        {{-- One grid per row of three, not one grid holding every row.

             The grid is a display:table box, and the browser will happily slice such a
             box across a page break *between its cells*. Because each cell is atomic
             it cannot itself be torn, so the observed result was the first photograph
             alone at the foot of one page and the remainder stranded on the next. A
             row that is its own element can instead be refused a break as a unit, and
             then all three move together. Dompdf paginates the same way. --}}
        @foreach (array_chunk($record['photos'], 3) as $group)
            <div class="photo-grid">
                @foreach ($group as $photo)
                    <div class="photo-cell">
                        <figure class="photo">
                            @if (! empty($photo['data_uri']))
                                {{-- Sized here, in millimetres, from the image's own pixel
                                     dimensions, so any photograph at any size or aspect
                                     ratio is fitted rather than stretched.

                                     The stylesheet puts max-width and max-height on top of
                                     this. Those are what make the placeholder dynamic: they
                                     hold the photograph inside its cell on a page narrower
                                     than the one these millimetres assume. This layer
                                     measures the image; that layer clamps it. --}}
                                @php
                                    $pxW = (int) ($photo['width'] ?? 0);
                                    $pxH = (int) ($photo['height'] ?? 0);
                                    if ($pxW > 0 && $pxH > 0) {
                                        $fit = min($photoBoxW / $pxW, $photoBoxH / $pxH);
                                        $imgW = $pxW * $fit;
                                        $imgH = $pxH * $fit;
                                    } else {
                                        // Dimensions unknown: fall back to the whole box.
                                        $imgW = $photoBoxW;
                                        $imgH = $photoBoxH;
                                    }
                                @endphp
                                <img src="{{ $photo['data_uri'] }}" alt="Property photograph"
                                     style="width: {{ round($imgW, 1) }}mm; height: {{ round($imgH, 1) }}mm;">
                                <figcaption>
                                    @if (! empty($photo['reference']))
                                        <span class="photo-ref">{{ $photo['reference'] }}</span><br>
                                    @endif
                                    {{ $photo['caption'] ?? '' }}
                                    @if (! empty($photo['captured_at']))
                                        <br>{{ $photo['captured_at'] }}
                                    @endif
                                </figcaption>
                            @else
                                <div class="missing">
                                    {{ $photo['caption'] ?? 'Photograph' }}<br><br>
                                    {{ $photo['note'] ?? 'The file for this photograph could not be included.' }}
                                </div>
                            @endif
                        </figure>
                    </div>
                @endforeach
                @if (count($group) < 3)
                    @for ($i = count($group); $i < 3; $i++)
                        <div class="photo-cell"></div>
                    @endfor
                @endif
            </div>
        @endforeach
    @endif
</div>

{{-- ================= evidence register ================= --}}
@if (count($record['evidence']))
    @php $sectionNo++; @endphp
    <div class="block">
        <h2 class="section">{{ $sectionNo }} &middot; Documentary evidence register</h2>
        <table class="grid">
            <tr>
                <td class="k" style="width:22%">Document</td>
                <td class="v" style="width:34%">
                    {{ $record['evidence'][0]['name'] ?? '' }}
                    @if (count($record['evidence']) > 1)
                        <br>and {{ count($record['evidence']) - 1 }} further document(s)
                    @endif
                </td>
                <td class="k" style="width:14%">Type</td>
                <td class="v" style="width:30%">
                    {{ $record['evidence'][0]['type'] ?? '' }}
                </td>
            </tr>
        </table>
        <table class="grid" style="margin-top:1.5mm">
            <tr>
                <td class="k" style="width:12%">#</td>
                <td class="k" style="width:40%">Document</td>
                <td class="k" style="width:18%">Captured</td>
                <td class="k" style="width:18%">Uploaded by</td>
                <td class="k" style="width:12%">Size</td>
            </tr>
            @foreach ($record['evidence'] as $i => $item)
                <tr>
                    <td class="v">{{ $i + 1 }}</td>
                    <td class="v">{{ $item['name'] ?? 'â€”' }}</td>
                    <td class="v">{{ $item['captured_at'] ?? 'â€”' }}</td>
                    <td class="v">{{ $item['uploaded_by'] ?? 'â€”' }}</td>
                    <td class="v">{{ $item['size'] ?? 'â€”' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

{{-- ================= workflow ================= --}}
@php $sectionNo++; @endphp
<div class="block">
    <h2 class="section">{{ $sectionNo }} &middot; Record workflow &amp; history</h2>

    <table class="ladder">
        @php
            $stages = collect($workflow['stages'])->values();
            $currentIndex = $stages->search(fn ($s) => $s === $workflow['current']);
        @endphp
        @foreach ($stages as $i => $stage)
            <div class="stage {{ $currentIndex !== false && $i < $currentIndex ? 'done' : '' }} {{ $stage === $workflow['current'] ? 'current' : '' }}">
                {{ str_replace('_', ' ', $stage) }}
            </div>
        @endforeach
    </table>

    @if (count($workflow['history']))
        <table class="grid" style="margin-top:2mm">
            <tr>
                <td class="k" style="width:50%">Recorded step</td>
                <td class="k" style="width:50%">Date</td>
            </tr>
            @foreach ($workflow['history'] as $row)
                <tr>
                    <td class="v">{{ $row['label'] }}</td>
                    <td class="v">{{ $row['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>

{{-- ================= attestation ================= --}}
<div class="block">
    <h2 class="section">Attestation</h2>
    <div class="signatures">
        <div class="sign">
            <div class="sign-line"></div>
            <div class="sign-label">Prepared / generated by<br>{{ $record['generated']['by'] }}</div>
        </div>
        <div class="sign">
            <div class="sign-line"></div>
            <div class="sign-label">Reviewed by / date</div>
        </div>
        <div class="sign">
            <div class="sign-line"></div>
            <div class="sign-label">Received by / date</div>
        </div>
    </div>
</div>
