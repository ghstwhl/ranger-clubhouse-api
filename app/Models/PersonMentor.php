<?php

namespace App\Models;

use App\Models\ApiModel;
use Illuminate\Support\Facades\DB;

class PersonMentor extends ApiModel
{
    protected $table = 'person_mentor';

    protected $fillable = [
        'person_id',
        'mentor_id',
        'mentor_year',
        'status',
        'notes'
    ];

    public function mentor()
    {
        return $this->belongsTo(Person::class);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public static function haveMentees($personId)
    {
        return self::where('mentor_id', $personId)->limit(1)->exists();
    }

    public static function retrieveAllForPerson($personId)
    {
        $ids = PersonMentor::where('mentor_id', $personId)
                ->groupBy('person_id')
                ->pluck('person_id')
                ->toArray();

        if (empty($ids)) {
            return [];
        }

        $rows = DB::select(
            'SELECT
            person_mentor.person_id,
            person.callsign,
            person.status,
            person.user_authorized,
            person.formerly_known_as,
            person.user_authorized,
            mentor.callsign as mentor_callsign,
            person_mentor.mentor_id,
            person_mentor.status as mentor_status,
            person_mentor.mentor_year,
            IFNULL(alert_person.use_email,1) as use_email,
            (SELECT DATE(sent_at) FROM contact_log WHERE person.id=contact_log.recipient_person_id AND mentor.id=contact_log.sender_person_id AND contact_log.action=:type ORDER BY contact_log.sent_at desc LIMIT 1) as last_contact_date
            FROM person_mentor
            JOIN person ON person.id=person_mentor.person_id
            LEFT JOIN person as mentor ON mentor.id=person_mentor.mentor_id
            LEFT JOIN alert_person ON alert_person.person_id=person_mentor.person_id AND
                    alert_person.alert_id=:alert_id
            WHERE person_mentor.person_id IN ('.implode(',', $ids).') AND
                EXISTS (SELECT 1 FROM person_mentor pm WHERE pm.mentor_id=:person_id AND pm.person_id=person_mentor.person_id AND pm.mentor_year=person_mentor.mentor_year LIMIT 1)

            ORDER BY person_mentor.mentor_year desc, person.callsign, mentor.callsign',
            [
                'person_id' => $personId,
                'alert_id'  => Alert::MENTOR_CONTACT,
                'type'      => 'mentee-contact',
            ]
        );

        $years = [];
        foreach ($rows as $row) {
            $personId = $row->person_id;
            $year = $row->mentor_year;

            if (!isset($years[$year])) {
                $years[$year] = [];
            }

            if (!isset($years[$year][$personId])) {
                /*
                 * sanitze the status. A disabled account, or status that is not
                 * active or inactive is marked 'not active'.
                 */

                $status = $row->status;
                if (!$row->user_authorized
                || ($status != 'active' && $status != 'inactive')) {
                    $status = 'not active';
                    $canContact = 'none';
                } else {
                    $canContact = $row->use_email ? 'allow' : 'block';
                }

                $years[$year][$personId] = [
                    'person_id'         => $personId,
                    'callsign'          => $row->callsign,
                    'status'            => $status,
                    'formerly_known_as' => $row->formerly_known_as,
                    'contact_status'    => $canContact,
                    'mentor_status'     => $row->mentor_status,
                    'mentors' => []
                ];
            }

            $years[$year][$personId]['mentors'][] = [
                'callsign'  => $row->mentor_callsign,
                'person_id' => $row->mentor_id,
            ];

            if ($row->mentor_id == $personId) {
                $years[$year][$personId]['last_contact'] = $row->last_contact_date;
            }
        }

        $result = [];

        foreach ($years as $year => $mentees) {
            $people = [];
            $passed = 0;
            $bonked = 0;
            foreach ($mentees as $menteeId => $mentee) {
                $people[] = $mentee;

                if ($mentee['mentor_status'] == 'pass') {
                    $passed++;
                } else {
                    $bonked++;
                }
            }
            $result[] = [
                'year'    => $year,
                'mentees' => $people,
                'passed'  => $passed,
                'bonked'  => $bonked
            ];
        }

        return $result;
    }

    /*
     * Find the mentors for a person
     */

    public static function retrieveMentorHistory($personId)
    {
        $history =  PersonMentor::with([ 'mentor:id,callsign' ])
                   ->where('person_id', $personId)
                   ->get()
                   ->sortBy('mentor.callsign', SORT_NATURAL|SORT_FLAG_CASE)
                   ->groupBy('mentor_year');


        $summary = [];
        foreach ($history as $year => $mentors) {
            $people = [];
            foreach ($mentors as $mentor) {
                $people[] = [
                   'id'    => $mentor->mentor_id,
                   'callsign' => $mentor->mentor->callsign,
                   'person_mentor_id' => $mentor->id
               ];
            }

            $summary[] = [
               'year'    => $year,
               'status' => $mentors[0]->status,
               'mentors' => $people
           ];
        }

        return $summary;
    }

    /*
     * Find all mentees for year
     */

    public static function findMenteesForYear($year, $includeEmail)
    {
        $personColumns = 'person:id,callsign,first_name,last_name,status';
        if ($includeEmail) {
            $personColumns .= ',email';
        }

        $personGroups = PersonMentor::where('mentor_year', $year)
                ->with([$personColumns, 'mentor:id,callsign' ])
                ->get()
                ->groupBy('person_id');

        $people = [];
        foreach ($personGroups as $personId => $group) {
            $first = $group[0];
            if ($first->person == null) {
                $first->person = (object) [
                    'callsign'   => "!Deleted #{$first->person_id}",
                    'first_name' => 'n/a',
                    'last_name'  => 'n/a',
                    'status'     => 'deleted',
                ];
            }

            $mentors = $group->map(function ($row) {
                return [
                    'id'       => $row->mentor->id,
                    'callsign' => $row->mentor->callsign,
                ];
            })->sortBy('callsign', SORT_NATURAL|SORT_FLAG_CASE)->values();


            $person =  [
                'id'            => $first->person_id,
                'callsign'      => $first->person->callsign,
                'first_name'    => $first->person->first_name,
                'last_name'     => $first->person->last_name,
                'status'        => $first->person->status,
                'mentor_status' => $first->status,
                'mentors'       => $mentors
            ];

            if ($includeEmail) {
                $person['email'] = $first->person->email;
            }

            $people[] = $person;
        }

        usort($people, function ($a, $b) {
            return strcasecmp($a['callsign'], $b['callsign']);
        });

        return $people;
    }

    /*
     * Find the year a person passed
     */

    public static function retrieveYearPassed($personId)
    {
        $row = DB::table('person_mentor')
                ->where('person_id', $personId)
                ->where('status', 'pass')
                ->orderBy('mentor_year', 'desc')
                ->first();

        return $row ? $row->mentor_year : null;
    }
}
