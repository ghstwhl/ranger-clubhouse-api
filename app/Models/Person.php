<?php

/*
 * NOTE: when adding new columns to the person table, there are three
 * places the column should be added to:
 *
 * - The $fillable array in this file
 * - in app/Http/Filters/PersonFilter.php
 * - on the frontend app/models/person.js
 */

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use Tymon\JWTAuth\Contracts\JWTSubject;

use App\Models\Alert;
use App\Models\ApiModel;
use App\Models\PersonRole;
use App\Models\Role;
use App\Models\PersonPosition;
use App\Helpers\SqlHelper;

use Carbon\Carbon;

class Person extends ApiModel implements JWTSubject, AuthenticatableContract, AuthorizableContract
{
    use Authenticatable, Authorizable, Notifiable;

    const RESET_PASSWORD_EXPIRE = (3600 * 48);

    // For resetting and adding roles & positions for new users
    const REMOVE_ALL = 0;
    const ADD_NEW_USER = 1;

    const PROSPECTIVE = 'prospective';
    const PASTPROSPECTIVE = 'past prospective';
    const PROSPECTIVE_WAITLIST = 'prospective waitlist';
    const ALPHA = 'alpha';
    const BONKED = 'bonked';
    const ACTIVE = 'active';
    const INACTIVE = 'inactive';
    const INACTIVE_EXTENSION = 'inactive extension';
    const RETIRED = 'retired';
    const UBERBONKED = 'uberbonked';
    const DISMISSED = 'dismissed';
    const DECEASED = 'deceased';
    const AUDITOR = 'auditor';
    const RESIGNED = 'resigned';
    const NON_RANGER = 'non ranger';

    /**
     * The database table name.
     * @var string
     */
    protected $table = 'person';

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'active_next_event'           => 'boolean',
        'asset_authorized'            => 'boolean',
        'callsign_approved'           => 'boolean',
        'has_note_on_file'            => 'boolean',
        'on_site'                     => 'boolean',
        'user_authorized'             => 'boolean',
        'vehicle_blacklisted'         => 'boolean',
        'vehicle_insurance_paperwork' => 'boolean',
        'vehicle_paperwork'           => 'boolean',


        'create_date'                 => 'datetime',
        'date_verified'               => 'date',
        'status_date'                 => 'date',
        'timestamp'                   => 'timestamp',
    ];

    /*
     * Do not forget to add the column name to PersonFilter as well.
     */

    protected $fillable = [
        'first_name',
        'mi',
        'last_name',
        'gender',

        'callsign',
        'callsign_approved',
        'formerly_known_as',

        'barcode',
        'status',
        'status_date',
        'timestamp',
        'user_authorized',


        'date_verified',
        'create_date',
        'email',
        'street1',
        'street2',
        'apt',
        'city',
        'state',
        'zip',
        'country',

        'birthdate',

        'home_phone',
        'alt_phone',

        'camp_location',
        'on_site',

        'longsleeveshirt_size_style',
        'teeshirt_size_style',
        'emergency_contact',

        'em_first_name',
        'em_mi',
        'em_last_name',
        'em_handle',

        'em_home_phone',
        'em_alt_phone',
        'em_email',
        'em_camp_location',
        'asset_authorized',

        'vehicle_blacklisted',
        'vehicle_paperwork',
        'vehicle_insurance_paperwork',

        'lam_status',
        'bpguid',
        'sfuid',

        'active_next_event',
        'has_note_on_file',
        'mentors_flag',
        'mentors_flag_note',
        'mentors_notes',

        // 'meta' objects
       'languages',

       // SMS fields
       'sms_on_playa',
       'sms_off_playa',
       'sms_on_playa_verified',
       'sms_off_playa_verified',
       'sms_on_playa_stopped',
       'sms_off_playa_stopped',
       'sms_on_playa_code',
       'sms_off_playa_code',
    ];

    const SEARCH_FIELDS = [
        'email',
        'name',
        'first_name',
        'last_name',
        'barcode',
        'callsign',
        'formerly_known_as'
    ];

    protected $appends = [
        'roles',
    ];

    protected $rules = [
        'callsign'   => 'required|string',
        'first_name' => 'required|string',
        'last_name'  => 'required|string',
        'email'      => 'required|string',
        'status'     => 'required|string',
    ];

    /*
     * The roles the person holds
     * @var array
     */

    public $roles;

    /*
     * The languages the person speaks. (handled thru class PersonLanguage)
     * @var string
     */

    public $languages;

    /**
      * Get the identifier that will be stored in the subject claim of the JWT.
      *
      * @return mixed
      */
    public function getJWTIdentifier(): string
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function person_position() {
        return $this->hasMany(PersonPosition::class);
    }

    public static function findEmailOrFail(string $email)
    {
        return self::where('email', $email)->firstOrFail();
    }

    public static function findByCallsign(string $callsign)
    {
        return self::where('callsign', $callsign)->first();
    }

    public static function findIdByCallsign(string $callsign)
    {
        $row = self::select('id')->where('callsign', $callsign)->first();
        if ($row) {
            return $row->id;
        }

        return null;
    }

    public static function findForQuery($query)
    {
        if (isset($query['query'])) {
            // remove duplicate spaces
            $q = preg_replace('/\s+/', ' ', $query['query']);

            if (substr($q, 0,1) == '+') {
                // Search by number
                $q = ltrim('+', $q);
                $person = self::find(intval($q));

                if ($person) {
                    $total = $limit = 1;
                } else {
                    $total = $limit = 0;
                }
                return [
                    'people'   => [ $person ],
                    'total'    => $total,
                    'limit'    => $limit
                ];
            } else {
                $string = '%'.$q.'%';

                if (isset($query['search_fields'])) {
                    $fields = explode(',', $query['search_fields']);

                    $sql = self::where(function ($cond) use ($fields, $string, $q) {
                        foreach ($fields as $field) {
                            if (!in_array($field, self::SEARCH_FIELDS)) {
                                throw new \InvalidArgumentException("Search field '$field' is not allowed.");
                            }

                            if ($field == 'name') {
                                $cond = $cond->orWhere('first_name', 'like', $string);
                                $cond = $cond->orWhere('last_name', 'like', $string);

                                if (strpos($q, ' ') !== false) {
                                    $name = explode(' ',$q);
                                    $cond = $cond->orWhere(function ($cond) use ($name) {
                                        $cond->where([
                                            [ 'first_name', 'like', '%'.$name[0].'%' ],
                                            [ 'last_name', 'like', '%'.$name[1].'%' ]
                                        ]);
                                    });
                                }
                            } else {
                                $cond = $cond->orWhere($field, 'like', $string);
                            }
                        }
                    });
                } else {
                    $sql = self::where('callsign', 'like', $string);
                }
            }
        } else {
            $sql = DB::table('person');
        }

        if (isset($query['statuses'])) {
            $sql = $sql->whereIn('status', explode(',', $query['statuses']));
        }

        if (isset($query['exclude_statuses'])) {
            $sql = $sql->whereNotIn('status', explode(',', $query['exclude_statuses']));
        }


        if (isset($query['limit'])) {
            $limit = $query['limit'];
        } else {
            $limit = 50;
        }

        if (isset($query['offset'])) {
            $sql = $sql->offset($query['offset']);
        }

        $total = $sql->count();
        $sql = $sql->limit($limit)->orderBy('callsign');

        return [
            'people'   => $sql->get(),
            'total'    => $total,
            'limit'    => $limit
        ];
    }

    /**
     * Search for matching callsigns
     *
     *
     * @param string $query string to match against callsigns
     * @param string $type callsign search type
     * @return array person id & callsigns which match
     */

    public static function searchCallsigns($query, $type, $limit)
    {
        $like = '%'.$query.'%';
        $sql = DB::table('person')
                ->where(function ($q) use ($query, $like) {
                    $q->where('callsign', 'like', $like);
                    $q->orWhereRaw('SOUNDEX(callsign)=soundex(?)', [ $query ]);
                })->limit($limit);

        switch ($type) {
            case 'contact':
                return $sql->select('person.id', 'callsign', DB::raw('IFNULL(alert_person.use_email,1) as allow_contact'))
                        ->whereIn('status', [ 'active', 'inactive' ])
                        ->where('user_authorized', true)
                        ->leftJoin('alert_person', function ($join) {
                            $join->whereRaw('alert_person.person_id=person.id');
                            $join->where('alert_person.alert_id', '=', Alert::RANGER_CONTACT);
                        })->get()->toArray();

            // Trying to send a clubhouse message
            case 'message':
                return $sql->whereIn('status', [ 'active', 'inactive', 'alpha' ])->get(['id', 'callsign']);
                break;
        }

        throw new \InvalidArgumentException("Unknown type [$type]");
    }

    public function isValidPassword(string $password): bool
    {
        if (self::passwordMatch($this->password, $password)) {
            return true;
        }

        if ($this->tpassword_expire < time()) {
            return false;
        }

        return self::passwordMatch($this->tpassword, $password);
    }

    public static function passwordMatch($encyptedPw, $password): bool
    {
        list($salt, $sha) = explode(':', $encyptedPw);
        $hashedPw = sha1($salt.$password);

        return ($hashedPw == $sha);
    }

    public function changePassword(string $password): bool
    {
        $salt = self::generateRandomString();
        $sha = sha1($salt.$password);

        $this->password = "$salt:$sha";
        $this->tpassword = '';
        $this->tpassword_expire = 1;
        return $this->save();
    }

    public function createResetPassword(): string
    {
        $resetPassword = self::generateRandomString();
        $salt = self::generateRandomString();
        $sha = sha1($salt.$resetPassword);

        $this->tpassword = "$salt:$sha";
        $this->tpassword_expire = time() + self::RESET_PASSWORD_EXPIRE;
        $this->save();

        return $resetPassword;
    }

    public static function findForAuthentication(array $credentials)
    {
        $person = Person::where('email', $credentials['identification'])->first();

        if ($person && $person->isValidPassword($credentials['password'])) {
            return $person;
        }

        return false;
    }

    public function getRolesAttribute() {
        return $this->roles;
    }

    public function retrieveRoles(): void
    {
        $this->roles = PersonRole::findRoleIdsForPerson($this->id);
    }

    public function hasRole($role): bool
    {
        if ($this->roles === null) {
            $this->retrieveRoles();
        }

        if (is_array($role)) {
            foreach ($role as $r) {
                if (in_array($r, $this->roles)) {
                    return true;
                }
            }
        } else {
            return in_array($role, $this->roles);
        }

//     if ($role != Role::ADMIN)
//        return in_array(Role::ADMIN, $this->roles);

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    /*
     * Normalize the country
     */

    public function setCountryAttribute($country)
    {
        $c = strtoupper($country);
        $c = str_replace('.', '', $c);

        switch ($c) {
            case "US":
            case "USA":
            case "UNITED STATES":
            case "UNITED STATES OF AMERICA":
                $country = "USA";
                break;

            case "CA":
                $country = "Canada";
                break;

            case "FR":
                $country = "France";
                break;

            case "GB":
            case "UK":
                $country = "United Kingdom";
                break;
        }

        $this->attributes['country'] = $country;
    }

    /*
     * creates a random string by calling random.org, and falls back on a home-rolled.
     * @return the string.
     */

    public static function generateRandomString(): string
    {
        $length = 20;
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max = strlen($characters)-1;
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $characters[mt_rand(0, $max)];
        }

        return $token;
    }

    public function getLanguagesAttribute() {
        return $this->languages;
    }

    public function setLanguagesAttribute($value) {
        $this->languages = $value;
    }

    /*
     * Account created prior to 2010 have a 0000-00-00 date. Return null if that's
     * the case
     */

    public function getCreateDateAttribute() {
        $date = Carbon::parse($this->attributes['create_date']);

        if ($date->year <= 0) {
            return null;
        }

        return $date;
    }

    /**
     * Change the status.
     * TODO figure out a better way to do this.
     *
     */
    public function changeStatus($newStatus, $oldStatus, $reason) {
        if ($newStatus == $oldStatus) {
            return;
        }

        $personId = $this->id;
        $this->status_date = SqlHelper::now();

        ActionLog::record(Auth::user(), 'status-change', $reason, [ 'old_status' => $oldStatus, 'new_status' => $newStatus ], $personId);

        $changeReason = $reason . " new status $newStatus";

        switch($newStatus) {
        case Person::ACTIVE:
            // grant the new ranger all the basic positions
            $addIds = Position::where('all_rangers', true)->pluck('id');
            PersonPosition::addIdsToPerson($personId, $addIds, $changeReason);

            // Add login role
            $addIds = Role::where('new_user_eligible', true)->pluck('id');
            PersonRole::addIdsToPerson($personId, $addIds, $changeReason);
            break;

        case Person::ALPHA:
            // grant the alpha the alpha position
            PersonPosition::addIdsToPerson($personId, [ Position::ALPHA ], $changeReason);
            break;

        case Person::UBERBONKED:
        case Person::DECEASED:
        case Person::DISMISSED:
        case Person::RESIGNED:
            // Remove all positions
            PersonPosition::resetPositions($personId, $changeReason, Person::REMOVE_ALL);

            // Remove all roles
            PersonRole::resetRoles($personId, $changeReason, Person::REMOVE_ALL);

            // Remove asset authorization and lock user out of system
            $this->asset_authorized = 0;
            $this->user_authorized = 0;
            break;

        case Person::BONKED:
            // Remove all positions
            PersonPosition::resetPositions($personId, $changeReason, Person::REMOVE_ALL);
            break;

        // Note that it used to be that changing status to INACTIVE
        // removed all of your positions other than "Training."  We decided
        // in 2015 not to do this anymore because we lose too much historical
        // information.

        // If you are one of the below, the only role you get is login
        // and position is Training
        case Person::RETIRED:
        case Person::AUDITOR:
        case Person::PROSPECTIVE:
        case Person::PROSPECTIVE_WAITLIST:
        case Person::PASTPROSPECTIVE:
            // Remove all roles, and reset back to the default roles
            PersonRole::resetRoles($personId, $changeReason, Person::ADD_NEW_USER);

            // Remove all positions, and reset back to the default positions
            PersonPosition::resetPositions($personId, $changeReason, Person::ADD_NEW_USER);
            break;
        }

        if ($oldStatus == Person::ALPHA) {
            // if you're no longer an alpha, you can't sign up for alpha shifts
            PersonPosition::removeIdsFromPerson($personId, [ Position::ALPHA ], $reason . ' no longer alpha');
        }
    }
}
