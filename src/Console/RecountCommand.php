<?php

namespace ErnestDefoe\Answers\Console;

use ErnestDefoe\Answers\Question;
use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;

/** Rebuilds every question's flag and counts, and the reputation totals from their log. */
class RecountCommand extends AbstractCommand
{
    public function __construct(private readonly Question $questions, private readonly ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('answers:recount')->setDescription('Rebuild Answers question counts and reputation totals');
    }

    protected function fire(): int
    {
        foreach ($this->db->table('tags')->where('answers_enabled', true)->pluck('id') as $tagId) {
            $this->questions->refreshTag((int) $tagId);
        }

        $this->db->transaction(function () {
            $this->db->table('answers_reputation')->delete();
            $this->db->table('answers_reputation_log')
                ->selectRaw('user_id, tag_id, sum(points) as points')->groupBy('user_id', 'tag_id')
                ->orderBy('user_id')->get()
                ->chunk(500)->each(fn ($rows) => $this->db->table('answers_reputation')->insert(
                    $rows->map(fn ($r) => ['user_id' => $r->user_id, 'tag_id' => $r->tag_id, 'points' => (int) $r->points])->all()
                ));
        });
        $this->info('Recounted.');

        return 0;
    }
}
