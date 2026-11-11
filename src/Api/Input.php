<?php

namespace ErnestDefoe\Answers\Api;

use ErnestDefoe\Answers\Question;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ServerRequestInterface;

/** Reading what the browser sends, and finding what it names (only what the viewer may see). */
final class Input
{
    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    public static function id(ServerRequestInterface $request, string $key = 'id'): int
    {
        return (int) ($request->getAttribute('routeParameters')[$key] ?? 0);
    }

    public static function question(ServerRequestInterface $request, User $actor): Discussion
    {
        $discussion = Discussion::query()->whereVisibleTo($actor)->find(self::id($request));
        if (! $discussion || ! Question::is($discussion)) {
            throw new ModelNotFoundException();
        }

        return $discussion;
    }

    public static function post(ServerRequestInterface $request, User $actor): Post
    {
        $post = Post::query()->whereVisibleTo($actor)->where('type', 'comment')->find(self::id($request));
        if (! $post || ! Question::is($post->discussion)) {
            throw new ModelNotFoundException();
        }

        return $post;
    }

    /** Post text: control characters out (newlines kept), trimmed. */
    public static function text(mixed $text, int $max): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", is_string($text) ? $text : '');
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);

        return mb_substr(trim($text), 0, $max);
    }
}
