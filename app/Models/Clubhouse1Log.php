<?php

namespace App\Models;

use App\Models\Person;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Clubhouse1Log extends Model
{
    protected $table = 'log';

    const PAGE_SIZE_DEFAULT = 50;

    protected $dates = [
        'occurred'
    ];

    public function user_person()
    {
        return $this->belongsTo(Person::class);
    }

    public function current_person()
    {
        return $this->belongsTo(Person::class);
    }

    public static function findForQuery($query)
    {
        $personId = $query['person_id'] ?? null;
        $page = $query['page'] ?? 1;
        $pageSize = $query['page_size'] ?? self::PAGE_SIZE_DEFAULT;
        $events = $query['events'] ?? [ ];
        $sort = $query['sort'] ?? 'desc';
        $startTime = $query['start_time'] ?? null;
        $endTime = $query['end_time'] ?? null;
        $lastDay = $query['lastday'] ?? false;
        $eventText = $query['event_text'] ?? null;

        $sql = self::query();

        if ($personId) {
            $sql->where(function ($q) use ($personId) {
                $q->where('current_person_id', $personId)
                    ->orWhere('user_person_id', $personId);
            });
        }

        if (!empty($events)) {
            $events = explode(',', $events);
            $sql->where(function ($query) use ($events) {
                foreach ($events as $event) {
                    $query->orWhere('event', 'LIKE', $event.'%');
                }
            });
        }

        if (!empty($eventText)) {
            $sql->where('event', 'LIKE', '%'.$eventText.'%');
        }

        if ($startTime) {
            $sql->where('occurred', '>=', $startTime);
        }

        if ($endTime) {
            $sql->where('occurred', '<=', $endTime);
        }

        if ($lastDay) {
            $sql->where('occurred', '>=', DB::raw('DATE_SUB(NOW(), INTERVAL 25 HOUR)'));
        }

        // How many total for the query
        $total = $sql->count();

        if (!$total) {
            // Nada.. don't bother
            return [ 'logs' => [ ], 'page' => 0, 'total' => 0, 'total_pages' => 0 ];
        }

        // Results sort 'asc' or 'desc'
        $sql->orderBy('occurred', ($sort == 'asc' ? 'asc' : 'desc'));

        // Figure out pagination
        $page = $page - 1;
        if ($page < 0) {
            $page = 0;
        }

        $sql->offset($page * $pageSize)->limit($pageSize);

        // .. and go get it!
        $rows = $sql->with([ 'current_person:id,callsign', 'user_person:id,callsign'])->get();

        return [
            'logs'        => $rows,
            'total'       => $total,
            'total_pages' => (int) (($total + ($pageSize - 1))/$pageSize),
            'page_size'   => $pageSize,
            'page'        => $page + 1,
         ];
    }
}
