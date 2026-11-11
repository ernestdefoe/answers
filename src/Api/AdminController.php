<?php

namespace ErnestDefoe\Answers\Api;

use ErnestDefoe\Answers\Import;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /api/answers/import: the FoF Best Answer import, for admins (also `php flarum answers:import-best-answer`). */
class AdminController implements RequestHandlerInterface
{
    public function __construct(private readonly Import $import)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        @set_time_limit(600);

        return new JsonResponse($this->import->run());
    }
}
