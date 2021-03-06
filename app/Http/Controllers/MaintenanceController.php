<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\DB;

use App\Models\AccessDocument;
use App\Models\AccessDocumentChanges;
use App\Models\ActionLog;
use App\Models\BMID;
use App\Models\Broadcast;
use App\Models\ErrorLog;
use App\Models\LambasePhoto;
use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\PersonPosition;
use App\Models\PersonSlot;
use App\Models\Photo;
use App\Models\Position;
use App\Models\Role;
use App\Models\Slot;
use App\Models\Timesheet;

use App\Mail\DailyReportMail;

class MaintenanceController extends ApiController
{
    public function photoSync()
    {
        ini_set('max_execution_time', 300);

        $this->checkMaintenanceToken();

        list($photos, $errors) = LambasePhoto::retrieveAllStatuses();
        $results = [];

        foreach ($photos as $photo) {
            if (!$photo->person_id) {
                continue;
            }

            $pm = PersonPhoto::find($photo->person_id);
            // A lack of PersonPhoto record OR change in status or approval date
            // reconstruct the record
            // TODO: Ask Ice about adding a image url column to the retrieve all request
            if (!$pm
                || $pm->status != $photo->status
                || (string) $pm->lambase_date != $photo->date) {
                $person = Person::find($photo->person_id);

                if ($person) {
                    $results[] = Photo::retrieveInfo($person, true);
                }
            }
        }

        return response()->json([ 'results' => $results, 'errors' => $errors ]);
    }

    public function dailyReport()
    {
        $this->checkMaintenanceToken();

        $failedBroadcasts = Broadcast::findLogs([ 'lastday' => true, 'failed' => true]);
        $errorLogs = ErrorLog::findForQuery([ 'lastday' => true, 'page_size' => 1000 ])['logs'];

        $roleLogs = ActionLog::findForQuery([ 'lastday' => 'true', 'page_size' => 1000, 'events' => [ 'person-role-add', 'person-role-remove' ] ], false)['logs'];
        $statusLogs = ActionLog::findForQuery([ 'lastday' => 'true', 'page_size' => 1000, 'events' => [ 'person-status-change', 'person-status-change' ] ], false)['logs'];
        foreach ($statusLogs as $log) {
            $json = json_decode($log->data);
            $log->oldStatus = $json->status[0];
            $log->newStatus = $json->status[1];
        }

        mail_to(explode(',', setting('DailyReportEmail')), new DailyReportMail($failedBroadcasts, $errorLogs, $roleLogs, $statusLogs));
        return $this->success();
    }

    private function checkMaintenanceToken()
    {
        $token = setting('MaintenanceToken');

        if (empty($token)) {
            throw new \RuntimeException("Maintenance token not set -- cannot authorzie request");
        }

        $ourToken = request()->input('token');

        if ($token != $ourToken) {
            $this->notPermitted("Not authorized.");
        }
    }

    /*
     * Add any missing positions  to "active" Rangers, and to Prospectives
     *
     * TODO: Fold this into the Position Sanity Checker
     */

    public function updatePositions()
    {
        $this->checkForAdmin();

        $allRangersPositions = Position::where('all_rangers', true)
                    ->orderBy('title')
                    ->get()
                    ->keyBy('id');

        /*
         * Only look at those Rangers who meet all of the following criteria:
         * - Are a "live" status (active, inactive, retired)
         * - Already have a dirt positon assigned
         * - Do not have ALL of the "All Rangers" positions
         */

        $actives = Person::select('id', 'callsign', 'status')
                    ->with('person_position')
                    ->whereIn('person.status', Person::ACTIVE_STATUSES)
                    ->whereRaw('EXISTS (SELECT 1 FROM person_position WHERE person_id=person.id AND position_id=? LIMIT 1)', [ Position::DIRT])
                    ->whereRaw('(SELECT COUNT(*) FROM person_position WHERE person_id=person.id AND position_id IN ('.implode(',', $allRangersPositions->pluck('id')->toArray()).')) != '.$allRangersPositions->count())
                    ->orderBy('callsign')
                    ->get();

        $activePeople = [];
        foreach ($actives as $person) {
            $addPositions = [];
            $ids = [];
            $haveIds = $person->person_position->pluck('position_id')->toArray();

            foreach ($allRangersPositions as $position) {
                if (!in_array($position->id, $haveIds)) {
                    $addPositions[] = [
                        'id'    => $position->id,
                        'title' => $position->title,
                    ];
                    $ids[] = $position->id;
                }
            }

            if (!empty($addPositions)) {
                $activePeople[] = [
                    'id'            => $person->id,
                    'callsign'      => $person->callsign,
                    'status'        => $person->status,
                    'positions_add' => $addPositions
                ];

                PersonPosition::addIdsToPerson($person->id, $ids, 'update position maintenance');
            }
        }

        $newUserPositions = Position::where('new_user_eligible', true)
                            ->orderBy('title')
                            ->get();

        $prospectives = Person::where('status', Person::PROSPECTIVE)
                        ->with([ 'person_position','person_position.position' ])
                        ->orderBy('callsign')
                        ->get();

        $prospectivePeople = [];
        $newUserPositionIds = $newUserPositions->pluck('id')->toArray();

        foreach ($prospectives as $person) {
            $addPositions = [];
            $haveIds = $person->person_position->pluck('position_id')->toArray();
            $addIds = [];
            foreach ($newUserPositions as $position) {
                if (!in_array($position->id, $haveIds)) {
                    $addPositions[] = [
                        'id'    => $position->id,
                        'title' => $position->title,
                    ];
                    $addIds[] = $position->id;
                }
            }

            $removePositions = [];
            $removeIds = [];
            foreach ($person->person_position as $pp) {
                if (!in_array($pp->position_id, $newUserPositionIds)) {
                    $position = $pp->position;
                    $removePositions[] = [
                        'id'    => $position->id,
                        'title' => $position->title,
                    ];
                    $removeIds[] = $position->id;
                }
            }

            if (!empty($addPositions) || !empty($removePositions)) {
                $prospectivePeople[] = [
                    'id'               => $person->id,
                    'callsign'         => $person->callsign,
                    'status'           => $person->status,
                    'positions_add'    => $addPositions,
                    'positions_remove' => $removePositions
                ];

                if (!empty($addPositions)) {
                    PersonPosition::addIdsToPerson($person->id, $addIds, 'maintenance - update position');
                }

                if (!empty($removePositions)) {
                    PersonPosition::removeIdsFromPerson($person->id, $removePositions, 'maintenance - update position');
                }
            }
        }

        return response()->json([
            'actives'      => $activePeople,
            'prospectives' => $prospectivePeople,
        ]);
    }

    /*
     * Mark everyone who is on site as off site.
     */

    public function markOffSite()
    {
        $this->checkForAdmin();

        // Grab the folks who are going to be marked as off site
        $people = Person::select('id', 'callsign')->where('on_site', true)->get();

        // .. and actually mark them. (would be nice if Eloquent could have a get() then update() feature)
        Person::where('on_site', true)->update([ 'on_site' => false ]);

        // Log what was done
        foreach ($people as $person) {
            $this->log('person-update', 'maintenance - marked off site', [ 'on_site' => [ true, false ] ], $person->id);
        }

        return response()->json([ 'count' => $people->count() ]);
    }

    /*
     * Deauthorize all assets, motorpolicy, Sandman Affidavit, etc
     */

    public function deauthorizeAssets()
    {
        $this->checkForAdmin();

        /*
         * Grab the folks who are:
         * - asset_authorized
         * - signed motorpool agreement (vehicle_paperwork)
         * - have Motor Vehicle Record aka BM Insurance (vehicle_insurance_paperwork)
         * - signed the Sandman Affividat
        */

        $people = Person::where('asset_authorized', true)
                ->orWhere('vehicle_paperwork', true)
                ->orWhere('vehicle_insurance_paperwork', true)
                ->orWhere('sandman_affidavit', true)
                ->get();

        // Clear the logs, and log the action
        foreach ($people as $person) {
            $person->asset_authorized = false;
            $person->vehicle_paperwork = false;
            $person->vehicle_insurance_paperwork = false;
            $person->sandman_affidavit = false;
            $changes = $person->getChangedValues();
            if ($person->saveWithoutValidation()) {
                $this->log('person-update', 'maintenance - deauthorized assets', $changes, $person->id);
            }
        }

        return response()->json([ 'count' => $people->count() ]);
    }

    /*
     * Reset all PNVs (Alphas/Bonks/Prospecitves) to Past Prospective status, reset callsign, and marked unapproved.
     */

    public function resetPNVs()
    {
        $this->checkForAdmin();

        $pnvs = Person::whereIn('status', [ Person::ALPHA, Person::BONKED, Person::PROSPECTIVE, Person::PROSPECTIVE_WAITLIST ])
                ->orderBy('callsign')
                ->get();

        $people = [];
        foreach ($pnvs as $person) {
            $result = [
                'id'       => $person->id,
                'callsign' => $person->callsign,
                'status'   => $person->status
            ];

            if ($person->resetCallsign()) {
                $person->callsign_approved = false;
                $person->status = Person::PAST_PROSPECTIVE;
                $changes = $person->getChangedValues();
                $person->saveWithoutValidation();
                $this->log('person-update', 'maintenance - pnv reset', $changes, $person->id);
                $result['callsign_reset'] = $person->callsign;
            } else {
                $result['error'] = 'Cannot reset callsign';
            }
            $people[] = $result;
        }

        return response()->json([ 'people' => $people ]);
    }

    /*
     * Reset all Past Prospectives with approved callsigns to LastFirstYY and mark the callsign as unapproved.
     */

    public function resetPassProspectives()
    {
        $this->checkForAdmin();

        $pp = Person::where('status', Person::PAST_PROSPECTIVE)
                ->where('callsign_approved', true)
                ->orderBy('callsign')
                ->get();

        $people = [];
        foreach ($pp as $person) {
            $result = [
                'id'       => $person->id,
                'callsign' => $person->callsign,
                'status'   => $person->status
            ];

            if ($person->resetCallsign()) {
                $person->callsign_approved = false;
                $changes = $person->getChangedValues();
                $person->saveWithoutValidation();
                $this->log('person-update', 'maintenance - past prospective reset', $changes, $person->id);
                $result['callsign_reset'] = $person->callsign;
            } else {
                $result['error'] = 'Cannot reset callsign';
            }
            $people[] = $result;
        }

        return response()->json([ 'people' => $people ]);
    }

    /*
     * Archive Clubhouse messages
     */

    public function archiveMessages()
    {
        $this->checkForAdmin();

        $year = current_year();
        $prevYear = $year - 1;
        $table = "person_message_$prevYear";

        $exists = count(DB::select("SHOW TABLES LIKE '$table'"));

        if ($exists) {
            return response()->json([ 'status' => 'archive-exists', 'year' => $prevYear ]);
        }

        DB::statement("CREATE TABLE $table AS SELECT * FROM person_message");
        DB::statement("DELETE FROM person_message WHERE YEAR(timestamp) < $year");

        return response()->json([ 'status' => 'success', 'year' => $prevYear ]);
    }



    private function checkForAdmin()
    {
        if (!$this->userHasRole(Role::ADMIN)) {
            $this->notPermitted('User is not admin');
        }
    }
}
