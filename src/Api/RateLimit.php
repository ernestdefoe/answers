<?php

namespace ErnestDefoe\Answers\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Flarum\Post\Exception\FloodingException;

/** Things one member may do so many times a minute. */
class RateLimit
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function check(User $actor, string $kind, int $perMinute): void
    {
        $key = 'ernestdefoe-answers.rate.'.$kind.':'.$actor->id.':'.intdiv(time(), 60);
        $this->cache->add($key, 0, 120);
        if ((int) $this->cache->increment($key) > $perMinute) {
            throw new FloodingException();
        }
    }
}
