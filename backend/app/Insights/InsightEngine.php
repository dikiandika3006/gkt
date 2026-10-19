<?php

namespace App\Insights;

use App\Insights\Contracts\Insight;
use App\Services\BudgetService;
use App\Services\FinanceReportService;
use App\Services\RecurringExpenseService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Menjalankan semua insight yang terdaftar di config/insights.php. */
class InsightEngine
{
    public function __construct(
        private Container $container,
        private FinanceReportService $report,
        private RecurringExpenseService $recurring,
        private BudgetService $budgets,
    ) {}

    /** @return Collection<int, InsightResult> diurutkan dari yang paling butuh perhatian */
    public function run(?Carbon $now = null): Collection
    {
        $context = new InsightContext($now ?? now(), $this->report, $this->recurring, $this->budgets);
        $results = collect();

        foreach (config('insights.rules', []) as $class) {
            try {
                $insight = $this->container->make($class);
                if (! $insight instanceof Insight) {
                    throw new \LogicException("{$class} harus mengimplementasikan ".Insight::class);
                }
                foreach ($insight->evaluate($context) as $result) {
                    $results->push($result);
                }
            } catch (Throwable $e) {
                // Satu insight bermasalah tidak boleh menjatuhkan dashboard.
                Log::warning("Insight {$class} gagal: {$e->getMessage()}", ['exception' => $e]);
            }
        }

        return $results->sortBy(fn (InsightResult $r) => $r->rank())->values();
    }
}
