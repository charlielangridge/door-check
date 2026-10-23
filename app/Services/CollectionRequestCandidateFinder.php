<?php

namespace App\Services;

use App\CollectionRequestMatchPerson;
use App\Models\Adult;
use App\Models\Child;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CollectionRequestCandidateFinder
{
    /** @return array{candidates: list<array{id: int, name: string, similarity: float}>, truncated: bool} */
    public function find(CollectionRequestMatchPerson $person, string $name): array
    {
        $name = Str::lower(Str::squish($name));

        if ($name === '' || ! preg_match('/[\pL\pN]/u', $name)) {
            return ['candidates' => [], 'truncated' => false];
        }

        throw_if(DB::getDriverName() !== 'pgsql', RuntimeException::class, 'Collection request candidate discovery requires PostgreSQL.');

        $variants = $this->variants($name);
        $limit = max(1, (int) config('collection-matching.candidate_limit'));

        return DB::transaction(function () use ($person, $variants, $limit): array {
            $threshold = (string) config('collection-matching.candidate_similarity_threshold');
            DB::select("SELECT set_config('pg_trgm.similarity_threshold', ?, true), set_config('pg_trgm.word_similarity_threshold', ?, true)", [$threshold, $threshold]);

            $scores = [];
            $bindings = [];

            foreach ($variants as $variant) {
                $scores[] = 'similarity(lower(name), ?)';
                $scores[] = 'word_similarity(?, lower(name))';
                array_push($bindings, $variant, $variant);
            }

            $results = $person->modelClass()::query()
                ->select(['id', 'name'])
                ->selectRaw('greatest('.implode(', ', $scores).') AS similarity', $bindings)
                ->where(function (Builder $query) use ($variants): void {
                    foreach ($variants as $variant) {
                        $query->orWhereRaw('(lower(name) % ? OR ? <% lower(name))', [$variant, $variant]);
                    }
                })
                ->orderByDesc('similarity')
                ->orderBy('id')
                ->limit($limit + 1)
                ->get();

            return [
                'candidates' => array_values($results->take($limit)->map(fn (Adult|Child $model): array => [
                    'id' => (int) $model->getKey(),
                    'name' => $model->name,
                    'similarity' => (float) $model->getAttribute('similarity'),
                ])->all()),
                'truncated' => $results->count() > $limit,
            ];
        });
    }

    /** @return list<string> */
    private function variants(string $name): array
    {
        $variants = [$name];
        $words = preg_split('/[\s\pP]+/u', $name, flags: PREG_SPLIT_NO_EMPTY);

        throw_if($words === false, RuntimeException::class, 'The submitted name is not valid UTF-8.');

        foreach ($words as $position => $word) {
            foreach (config('collection-matching.nickname_groups') as $group) {
                if (in_array($word, $group, true)) {
                    foreach ($group as $nickname) {
                        $replacement = $words;
                        $replacement[$position] = $nickname;
                        $variants[] = implode(' ', $replacement);
                    }
                }
            }
        }

        return array_values(array_unique($variants));
    }
}
