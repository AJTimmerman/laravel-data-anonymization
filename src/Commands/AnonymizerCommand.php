<?php

namespace Outsidaz\LaravelDataAnonymization\Commands;

use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Outsidaz\LaravelDataAnonymization\Anonymizer;

class AnonymizerCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'db:anonymize {--model=*}';

    public $description = 'Anonymize specific data of the database';

    private Anonymizer $service;

    public function handle(): int
    {
        $this->service = new Anonymizer();

        if (
            ! $this->confirmToProceed('Environment "' . config('app.env') . '" blocked.', function () {
                return $this->service->isBlockedEnvironment();
            })
        ) {
            return 0;
        }

        $specified = $this->option('model');

        $anonymizationStart = microtime(true);

        $anonymizableClasses = $this->service->getAnonymizableClasses();

        if ($specified) {
            $anonymizableClasses = array_filter(
                $anonymizableClasses,
                fn ($class) => in_array($class, $specified, true)
            );
        }

        $this->warn('Anonymization started');

        $anonymizableClassesOrdered = collect(config('anonymizer.ordered_models') ?? [])
            ->map(fn ($ac) => '\\' . $ac);

        $anonymizableClasses = collect($anonymizableClasses)
            ->diff($anonymizableClassesOrdered)
            ->toArray();

        if (! empty($anonymizableClassesOrdered)) {
            $this->warn('Order dependent models anonymizing.');
        }
        foreach ($anonymizableClassesOrdered as $anonymizableClass) {
            $this->anonymizeTable(
                new $anonymizableClass()
            );
        }

        if (! empty($anonymizableClassesOrdered)) {
            $this->warn('Remaining models anonymizing.');
        }
        $anonymizableClasses = collect($anonymizableClasses)->diff($anonymizableClassesOrdered)->all();
        foreach ($anonymizableClasses as $anonymizableClass) {
            $this->anonymizeTable(
                new $anonymizableClass()
            );
        }

        $this->warn(
            'Anonymization done in ' . CarbonInterval::seconds(microtime(true) - $anonymizationStart)
                ->cascade()
                ->forHumans(['parts' => 3, 'short' => true])
        );

        return self::SUCCESS;
    }

    private function anonymizeTable(Model $model): void
    {
        $start = microtime(true);

        $this->info('Anonymizing data of ' . $model->getTable() . ' table');

        $progressBar = $this->output->createProgressBar($this->service->getCount($model));

        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%');

        if ($this->service->getCount($model) > 0) {
            $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | Remaining: %remaining:6s%');
        }

        $this->service->getChunk($model, function (Collection $chunkItems) use ($progressBar) {
            DB::beginTransaction();
            $chunkItems->each(fn (Model $model) => $this->service->changeData($model));
            DB::commit();
            $progressBar->advance($chunkItems->count());
        });

        $progressBar->finish();

        $this->info(
            ' - Done in ' . CarbonInterval::seconds(microtime(true) - $start)->cascade()->forHumans(
                ['parts' => 3, 'short' => true]
            )
        );
    }
}
