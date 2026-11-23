<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\PolicyInsights;
use App\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The termly report for leadership, with policy suggestions; printable and exportable. */
class InsightsController extends Controller
{
    public function show(Request $request, PolicyInsights $insights, SettingsRepository $settings): View
    {
        [$from, $to, $label] = $this->period($request, $insights);

        return view('admin.insights', ['report' => $insights->report($from, $to), 'label' => $label, 'school' => $settings->get('school.name')]);
    }

    public function csv(Request $request, PolicyInsights $insights): StreamedResponse
    {
        [$from, $to] = $this->period($request, $insights);
        $rows = $insights->csv($from, $to);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                // Guard against spreadsheet formulas in user-typed text.
                fputcsv($out, array_map(fn (string $value) => preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value, $row));
            }
            fclose($out);
        }, 'website-requests-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function period(Request $request, PolicyInsights $insights): array
    {
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        if (filled($validated['from'] ?? null) && filled($validated['to'] ?? null)) {
            $from = CarbonImmutable::parse($validated['from'])->startOfDay();
            $to = CarbonImmutable::parse($validated['to'])->endOfDay();

            return [$from, $to, $from->format('j M Y').' – '.$to->format('j M Y')];
        }
        $default = $insights->defaultPeriod();

        return [$default['from'], $default['to'], $default['label']];
    }
}
