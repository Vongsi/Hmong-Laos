<?php

namespace App\Search;

use Statamic\Search\Comb\Index;

/**
 * Statamic's local search, where every word typed must match (in any of the index's fields): "Nou Yang" finds Nou
 * Yang, not every Nou; "020 7123 4567" finds that number, not every 020 one. Statamic's own local search counts any
 * word. Driver "local_all_words" (AppServiceProvider), for the Orders and WhatsApp messages lists.
 */
class AllWordsIndex extends Index
{
    public function lookup($query)
    {
        // Each word required (Comb's "+word"); characters that mean something to the search itself are dropped.
        $words = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}@._-]+/u', ' ', (string) $query), -1, PREG_SPLIT_NO_EMPTY);

        return $words ? parent::lookup(implode(' ', array_map(fn (string $word) => '+'.$word, $words))) : collect();
    }
}
