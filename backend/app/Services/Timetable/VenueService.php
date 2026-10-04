<?php

namespace App\Services\Timetable;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where each lesson is (TASK 07). A lesson with no venue of its own is in its
 * class's home room, if the class has one. A venue is double-booked when two
 * lessons overlap in it, unless the venue is shared (a field, an assembly hall).
 */
class VenueService
{
    /** Each entry's standing venue id: its own, or its class's home room. */
    public function standingVenues(Collection $entries, int $schoolId): array
    {
        $home = DB::table('venues')->where('school_id', $schoolId)->where('active', true)->whereNotNull('class_id')->pluck('id', 'class_id');

        return $entries->mapWithKeys(fn ($e) => [$e->id => $e->venue_id ? (int) $e->venue_id : (isset($home[$e->school_class_id]) ? (int) $home[$e->school_class_id] : null)])->all();
    }

    /** Venue double-bookings in a timetable version, in the clash report's shape. */
    public function clashes(int $versionId, int $schoolId): array
    {
        $entries = $this->entries($versionId);
        $venueOf = $this->standingVenues($entries, $schoolId);
        $venues = DB::table('venues')->where('school_id', $schoolId)->get()->keyBy('id');

        $out = [];
        $byPlace = $entries->filter(fn ($e) => $venueOf[$e->id] && ! ($venues[$venueOf[$e->id]]->shared ?? false))
            ->groupBy(fn ($e) => $venueOf[$e->id].'|'.$e->day_of_week);
        foreach ($byPlace as $group) {
            $list = $group->values();
            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    if ($this->overlaps($list[$i], $list[$j])) {
                        $out[] = ['type' => 'venue', 'venue' => $venues[$venueOf[$list[$i]->id]]->name, 'teacher' => null, 'day' => $list[$i]->day_of_week,
                            'slot' => $list[$i]->slot_name, 'classes' => [$list[$i]->class, $list[$j]->class]];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Who else is in that venue when this lesson runs — every week, or on one
     * date if given (counting that day's moves).
     */
    public function conflictFor(object $entry, int $venueId, int $schoolId, ?string $date = null): ?object
    {
        $venue = DB::table('venues')->where('school_id', $schoolId)->find($venueId);
        if (! $venue || $venue->shared) {
            return null;
        }

        $others = $this->entries((int) $entry->timetable_version_id)->where('day_of_week', $entry->day_of_week)->where('id', '!=', $entry->id);
        $venueOf = $this->standingVenues($others, $schoolId);
        if ($date) {
            $moves = DB::table('timetable_venue_changes')->where('school_id', $schoolId)->whereDate('date', $date)->whereIn('entry_id', $others->pluck('id'))->pluck('venue_id', 'entry_id');
            foreach ($moves as $entryId => $v) {
                $venueOf[$entryId] = (int) $v;
            }
        }

        return $others->first(fn ($o) => ($venueOf[$o->id] ?? null) === $venueId && $this->overlaps($entry, $o));
    }

    /** Venue names for entries on a day: the one-day move wins over the standing venue. */
    public function namesFor(Collection $entries, int $schoolId, ?string $date = null): array
    {
        $venueOf = $this->standingVenues($entries, $schoolId);
        if ($date) {
            foreach (DB::table('timetable_venue_changes')->whereDate('date', $date)->whereIn('entry_id', $entries->pluck('id'))->pluck('venue_id', 'entry_id') as $entryId => $v) {
                $venueOf[$entryId] = (int) $v;
            }
        }
        $names = DB::table('venues')->whereIn('id', array_filter($venueOf))->pluck('name', 'id');

        return array_map(fn ($v) => $v ? ($names[$v] ?? null) : null, $venueOf);
    }

    /** Venues free for a lesson's time, every week or on a date. */
    public function freeFor(object $entry, int $schoolId, ?string $date = null): Collection
    {
        return DB::table('venues')->where('school_id', $schoolId)->where('active', true)->orderBy('name')->get(['id', 'name', 'kind', 'capacity', 'shared'])
            ->filter(fn ($v) => ! $this->conflictFor($entry, (int) $v->id, $schoolId, $date))->values();
    }

    private function entries(int $versionId): Collection
    {
        return DB::table('timetable_entries as e')->leftJoin('classes as c', 'c.id', '=', 'e.school_class_id')
            ->where('e.timetable_version_id', $versionId)->where('e.is_break', false)
            ->get(['e.id', 'e.timetable_version_id', 'e.school_class_id', 'e.venue_id', 'e.day_of_week', 'e.slot_name', 'e.start_time', 'e.end_time', 'c.name as class']);
    }

    private function overlaps(object $a, object $b): bool
    {
        if ($a->start_time && $a->end_time && $b->start_time && $b->end_time) {
            return $a->start_time < $b->end_time && $b->start_time < $a->end_time;
        }

        return $a->slot_name === $b->slot_name;
    }

    /** The weekday name a date falls on, as the timetable writes it. */
    public static function dayOf(string $date): string
    {
        return Carbon::parse($date)->format('l');
    }
}
