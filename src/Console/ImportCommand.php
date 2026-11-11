<?php

namespace ErnestDefoe\Answers\Console;

use ErnestDefoe\Answers\Import;
use Flarum\Console\AbstractCommand;

/** Imports FoF Best Answer's Q&A tags and best answers. Safe to run again. */
class ImportCommand extends AbstractCommand
{
    public function __construct(private readonly Import $import)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('answers:import-best-answer')->setDescription('Import Q&A tags and best answers from FoF Best Answer');
    }

    protected function fire(): int
    {
        $result = $this->import->run();
        $this->info("{$result['tags']} Q&A tags, {$result['questions']} questions, {$result['accepted']} answers accepted.");

        return 0;
    }
}
