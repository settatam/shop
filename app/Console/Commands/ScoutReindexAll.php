<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScoutReindexAll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:reindex-all
                            {--flush : Flush indexes before importing}
                            {--model=* : Specific model(s) to reindex}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reindex all Scout searchable models';

    /**
     * All searchable models in the application.
     *
     * @var array<int, class-string>
     */
    protected array $searchableModels = [
        \App\Models\Category::class,
        \App\Models\Customer::class,
        \App\Models\Memo::class,
        \App\Models\Order::class,
        \App\Models\Product::class,
        \App\Models\ProductTemplate::class,
        \App\Models\Repair::class,
        \App\Models\Transaction::class,
        \App\Models\TransactionItem::class,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $models = $this->option('model') ?: $this->searchableModels;
        $shouldFlush = $this->option('flush');

        if (is_string($models)) {
            $models = [$models];
        }

        $this->info('Starting Scout reindex for '.count($models).' model(s)...');
        $this->newLine();

        foreach ($models as $model) {
            $shortName = class_basename($model);

            if ($shouldFlush) {
                $this->components->task("Flushing {$shortName}", function () use ($model) {
                    $this->callSilently('scout:flush', ['model' => $model]);

                    return true;
                });
            }

            $this->components->task("Importing {$shortName}", function () use ($model) {
                $this->callSilently('scout:import', ['model' => $model]);

                return true;
            });
        }

        $this->newLine();
        $this->info('Scout reindex complete!');

        return Command::SUCCESS;
    }
}
