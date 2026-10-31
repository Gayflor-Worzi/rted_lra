{{--
    Coordinate locator (spec §5).

    Drawn entirely from the record's own GPS fix. No tile server, no street map and
    no external request: the sheet shows where the property is *recorded* to be, in
    relation to its own coordinate frame, rather than borrowing a third party's map
    that may be wrong, out of date, or simply unavailable offline.

    The graticule is centred on the fix and its extent is chosen so that a
    recorded accuracy radius is actually visible on the page. The scale bar is
    computed from the real angular span, so it stays truthful whatever extent was
    chosen.
--}}
@php
    $lat = (float) $gps['latitude'];
    $lng = (float) $gps['longitude'];
    $accuracy = $gps['accuracy'];

    // Ground metres per degree at this latitude. Longitude degrees are shorter
    // away from the equator, so the divisor carries a cos(lat) term; Liberia sits
    // near 5 degrees north, far from the singularity, but the guard is kept so a
    // malformed fix cannot produce a division by ~0.
    $cosLat = max(0.1, abs(cos(deg2rad($lat))));
    $metresPerLatDeg = 111320.0;
    $metresPerLngDeg = 111320.0 * $cosLat;

    // Half-extent in degrees. A known accuracy radius is the natural unit here: the
    // point of the locator is to show how precisely the fix was taken, so the frame
    // is sized to that radius. Without an accuracy figure a 100 m frame is used and
    // the scale bar, not the circle, carries the precision.
    $halfMetres = ($accuracy !== null && $accuracy > 0) ? $accuracy * 2.2 : 100.0;
    $halfMetres = min(max($halfMetres, 15.0), 2000.0);

    $halfLat = $halfMetres / $metresPerLatDeg;
    $halfLng = $halfMetres / $metresPerLngDeg;

    $minLat = $lat - $halfLat;
    $maxLat = $lat + $halfLat;
    $minLng = $lng - $halfLng;
    $maxLng = $lng + $halfLng;

    // A "nice" graticule step in degrees, targeting four intervals per side.
    $rawStep = max($halfLat, $halfLng) / 4;
    $magnitude = 10 ** floor(log10(max($rawStep, 1e-9)));
    $normalised = $rawStep / $magnitude;
    $step = ($normalised < 1.5 ? 1 : ($normalised < 3.5 ? 2 : ($normalised < 7.5 ? 5 : 10))) * $magnitude;

    // Canvas is 100 x 100 user units; the marker sits at its centre.
    $cx = 50.0;
    $cy = 50.0;
    $toX = fn (float $lon) => $cx + (($lon - $lng) / ($maxLng - $minLng)) * 100;
    $toY = fn (float $latitude) => $cy - (($latitude - $lat) / ($maxLat - $minLat)) * 100;

    // Graticule lines, snapped outward to the step so the frame is labelled on round
    // numbers rather than on the record's own arbitrary offset.
    $latLines = [];
    for ($v = floor($minLat / $step) * $step; $v <= $maxLat + 1e-12; $v += $step) {
        $latLines[] = $v;
    }
    $lngLines = [];
    for ($v = floor($minLng / $step) * $step; $v <= $maxLng + 1e-12; $v += $step) {
        $lngLines[] = $v;
    }

    // Accuracy radius in user units, measured against the same projection.
    $radiusUnits = null;
    if ($accuracy !== null && $accuracy > 0) {
        $radiusUnits = ($accuracy / $metresPerLatDeg) / ($maxLat - $minLat) * 100;
    }

    // Scale bar: a round ground distance occupying about a quarter of the width.
    $spanMetres = ($maxLng - $minLng) * $metresPerLngDeg;
    $barMetres = $spanMetres / 4;
    $barUnits = 50.0;
    $barLabel = $barMetres >= 1000
        ? rtrim(rtrim(number_format($barMetres / 1000, 2, '.', ''), '0'), '.').' km'
        : rtrim(rtrim(number_format($barMetres, 1, '.', ''), '0'), '.').' m';

    // Graticule labels need enough precision to distinguish adjacent lines.
    $decimals = $step >= 1 ? 0 : ($step >= 0.1 ? 1 : ($step >= 0.01 ? 2 : ($step >= 0.001 ? 3 : 4)));
@endphp
<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img"
     aria-label="Coordinate locator for {{ number_format($lat, 6) }}, {{ number_format($lng, 6) }}">
    {{-- graticule --}}
    @foreach ($latLines as $v)
        @php $y = $toY($v); @endphp
        <line x1="0" y1="{{ number_format($y, 3, '.', '') }}" x2="100" y2="{{ number_format($y, 3, '.', '') }}"
              stroke="#cbd5e1" stroke-width="0.4"/>
        <text x="1.5" y="{{ number_format($y - 1.1, 3, '.', '') }}" font-size="2.6" fill="#64748b">{{ number_format(abs($v), $decimals) }}{{ $v >= 0 ? 'N' : 'S' }}</text>
    @endforeach

    @foreach ($lngLines as $v)
        @php $x = $toX($v); @endphp
        <line x1="{{ number_format($x, 3, '.', '') }}" y1="0" x2="{{ number_format($x, 3, '.', '') }}" y2="100"
              stroke="#cbd5e1" stroke-width="0.4"/>
        <text x="{{ number_format($x + 1, 3, '.', '') }}" y="98.4" font-size="2.6" fill="#64748b">{{ number_format(abs($v), $decimals) }}{{ $v >= 0 ? 'E' : 'W' }}</text>
    @endforeach

    {{-- accuracy radius --}}
    @if ($radiusUnits !== null)
        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ number_format($radiusUnits, 3, '.', '') }}"
                fill="none" stroke="#0f2d4a" stroke-width="0.5" stroke-dasharray="2 1.4"/>
    @endif

    {{-- crosshair --}}
    <line x1="{{ $cx }}" y1="2" x2="{{ $cx }}" y2="98" stroke="#94a3b8" stroke-width="0.3"/>
    <line x1="2" y1="{{ $cy }}" x2="98" y2="{{ $cy }}" stroke="#94a3b8" stroke-width="0.3"/>

    {{-- the property: a pin, so the eye lands on the fix and not on the frame --}}
    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="6.4" fill="#0f2d4a" opacity="0.12"/>
    <path d="M {{ $cx }} {{ $cy - 5.2 }}
             c 1.75 0 3.15 1.4 3.15 3.15
             c 0 2.3 -3.15 5.9 -3.15 5.9
             c 0 0 -3.15 -3.6 -3.15 -5.9
             c 0 -1.75 1.4 -3.15 3.15 -3.15 z"
          fill="#0f2d4a"/>
    <circle cx="{{ $cx }}" cy="{{ $cy - 2.05 }}" r="1.15" fill="#ffffff"/>

    {{-- scale bar --}}
    <g>
        <line x1="6" y1="90" x2="{{ 6 + $barUnits }}" y2="90" stroke="#334155" stroke-width="0.8"/>
        <line x1="6" y1="88.2" x2="6" y2="91.8" stroke="#334155" stroke-width="0.8"/>
        <line x1="{{ 6 + $barUnits }}" y1="88.2" x2="{{ 6 + $barUnits }}" y2="91.8" stroke="#334155" stroke-width="0.8"/>
        <text x="{{ 6 + $barUnits + 1.4 }}" y="91" font-size="2.8" fill="#334155">{{ $barLabel }}</text>
    </g>
</svg>
