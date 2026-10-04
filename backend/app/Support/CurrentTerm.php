<?php

namespace App\Support;

use App\Models\Term;
use Illuminate\Support\Collection;

/**
 * Which term "now" belongs to, in one place.
 *
 * `terms.is_current` is never written by the product, so it cannot be
 * trusted on its own. The term today falls inside wins; then whatever an
 * admin pinned; then — in the long vacation, when today is inside no term —
 * the term that most recently started, because a school on holiday is
 * finishing that term's results, not preparing the next.
 */
class CurrentTerm
{
    public static function for(int $schoolId): ?Term
    {
        return self::pick(Term::withoutGlobalScopes()->where('school_id', $schoolId)->orderByDesc('start_date')->get());
    }

    /** @param  Collection<int, Term>  $terms  ordered by start_date descending */
    public static function pick(Collection $terms): ?Term
    {
        $today = now()->startOfDay();

        $containing = $terms->first(
            fn (Term $term) => $term->start_date && $term->end_date
                && $today->gte($term->start_date) && $today->lte($term->end_date)
        );

        return $containing
            ?? $terms->firstWhere('is_current', true)
            ?? $terms->first(fn (Term $term) => $term->start_date && $term->start_date->lte($today));
    }
}
