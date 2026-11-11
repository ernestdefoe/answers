<?php

namespace ErnestDefoe\Answers\Console;

use ErnestDefoe\Answers\Bounties;
use Flarum\Console\AbstractCommand;

/** Pays or closes bounties whose time is up (scheduled every ten minutes). */
class BountiesCommand extends AbstractCommand
{
    public function __construct(private readonly Bounties $bounties)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('answers:bounties')->setDescription('Pay or close Answers bounties whose time is up');
    }

    protected function fire(): int
    {
        $this->info($this->bounties->expire().' bounties closed.');

        return 0;
    }
}
