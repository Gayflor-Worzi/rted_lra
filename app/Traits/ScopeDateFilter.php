<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Global multi-dimensional temporal query engine (Prompt 22).
 *
 * Supported filters on any dated column:
 *   ?filter=today            ?date=YYYY-MM-DD
 *   ?filter=this_week
 *   ?filter=this_month       ?month=MM-YYYY
 *   ?filter=quarter          ?quarter=Q1..Q4 & ?year=YYYY
 *   ?filter=yearly           ?year=YYYY
 *   ?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 */
trait ScopeDateFilter
{
    public function applyDateFilter(Request $request, Builder $query, string $column = 'created_at'): Builder
    {
        $filter = $request->query('filter');
        $now = now();

        return match ($filter) {
            'today' => $request->filled('date')
                ? $query->whereDate($column, $request->query('date'))
                : $query->whereDate($column, $now->toDateString()),

            'this_week' => $query->whereBetween($column, [$now->startOfWeek()->copy(), $now->endOfWeek()->copy()]),

            'this_month' => $request->filled('month')
                ? $this->applyMonth($query, $column, $request->query('month'))
                : $query->whereMonth($column, $now->month)->whereYear($column, $now->year),

            'quarter' => $this->applyQuarter($query, $column, $request->query('quarter'), $request->query('year', $now->year)),

            'yearly' => $query->whereYear($column, $request->query('year', $now->year)),

            default => $this->applyCustomRange($query, $column, $request),
        };
    }

    private function applyMonth(Builder $query, string $column, ?string $month): Builder
    {
        if (! $month || ! preg_match('/^(\d{2})-(\d{4})$/', $month, $m)) {
            return $query;
        }

        return $query->whereMonth($column, (int) $m[1])->whereYear($column, (int) $m[2]);
    }

    private function applyQuarter(Builder $query, string $column, ?string $quarter, $year): Builder
    {
        if (! $quarter || ! preg_match('/^Q([1-4])$/i', $quarter, $q)) {
            return $query;
        }

        $year = (int) $year;
        $startMonth = ((int) $q[1] - 1) * 3 + 1;
        $start = now()->setDate($year, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addMonths(3)->subSecond();

        return $query->whereBetween($column, [$start, $end]);
    }

    private function applyCustomRange(Builder $query, string $column, Request $request): Builder
    {
        if ($request->filled('start_date')) {
            $query->whereDate($column, '>=', $request->query('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate($column, '<=', $request->query('end_date'));
        }

        return $query;
    }
}
