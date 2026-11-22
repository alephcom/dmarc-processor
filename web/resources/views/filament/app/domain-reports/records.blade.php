{{-- Inline styles: panels use Filament's prebuilt CSS, so arbitrary Tailwind utilities aren't available here. --}}
@php
    $published = $report->policy_published ?? [];
    $muted = 'color: var(--gray-500);';
    $cell = 'padding: 0.5rem 0.75rem; vertical-align: top;';
@endphp
<div style="display: flex; flex-direction: column; gap: 1rem; font-size: 0.875rem;">
    <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0.25rem 1.5rem; margin: 0;">
        <div><dt style="{{ $muted }}">Reporter</dt><dd style="margin: 0; font-weight: 500;">{{ $report->reporter_org }}</dd></div>
        <div><dt style="{{ $muted }}">Contact</dt><dd style="margin: 0;">{{ $report->reporter_email ?? '—' }}</dd></div>
        <div><dt style="{{ $muted }}">Report ID</dt><dd style="margin: 0; word-break: break-all;">{{ $report->report_id }}</dd></div>
        <div><dt style="{{ $muted }}">Policy the reporter saw</dt><dd style="margin: 0;">p={{ $published['p'] ?? '?' }}@isset($published['pct']) ({{ $published['pct'] }}%)@endisset</dd></div>
    </dl>

    <div class="fi-ta-ctn" style="overflow-x: auto;">
        <table class="fi-ta-table" style="width: 100%; text-align: left; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 1px solid var(--gray-200);">
                    <th style="{{ $cell }} font-weight: 600;">Source IP</th>
                    <th style="{{ $cell }} font-weight: 600; text-align: right;">Messages</th>
                    <th style="{{ $cell }} font-weight: 600;">Disposition</th>
                    <th style="{{ $cell }} font-weight: 600;">DKIM</th>
                    <th style="{{ $cell }} font-weight: 600;">SPF</th>
                    <th style="{{ $cell }} font-weight: 600;">Header from</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $rec)
                    <tr style="border-bottom: 1px solid var(--gray-100);">
                        <td style="{{ $cell }} font-family: ui-monospace, monospace;">{{ $rec->source_ip }}</td>
                        <td style="{{ $cell }} text-align: right; font-variant-numeric: tabular-nums;">{{ number_format($rec->count) }}</td>
                        <td style="{{ $cell }}">{{ $rec->disposition }}</td>
                        @foreach ([['dkim_aligned', $rec->dkim_results ?? [], true], ['spf_aligned', $rec->spf_results ?? [], false]] as [$field, $results, $isDkim])
                            <td style="{{ $cell }}">
                                <x-filament::badge :color="$rec->{$field} ? 'success' : 'danger'" style="display: inline-flex;">
                                    {{ $rec->{$field} ? 'Aligned' : 'Not aligned' }}
                                </x-filament::badge>
                                @foreach ($results as $x)
                                    <div style="{{ $muted }} font-size: 0.75rem; margin-top: 0.25rem;">
                                        {{ $x['domain'] ?? '?' }}@if ($isDkim && ! empty($x['selector'])) ({{ $x['selector'] }})@endif: {{ $x['result'] ?? '?' }}
                                    </div>
                                @endforeach
                            </td>
                        @endforeach
                        <td style="{{ $cell }}">{{ $rec->header_from }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="{{ $cell }} {{ $muted }} text-align: center;">This report has no records.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($total > $records->count())
        <p style="{{ $muted }} margin: 0;">Showing the {{ $records->count() }} largest of {{ number_format($total) }} records. Download the XML to see all of them.</p>
    @endif
</div>
