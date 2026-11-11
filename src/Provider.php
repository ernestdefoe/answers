<?php

namespace ErnestDefoe\Answers;

use ErnestDefoe\Answers\Api\Batch;
use Flarum\Foundation\AbstractServiceProvider;

/** One of each per process: the memos and the pending revision summary are shared state. */
class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Reputation::class);
        $this->container->singleton(Revisions::class);
        $this->container->singleton(Batch::class);
    }
}
