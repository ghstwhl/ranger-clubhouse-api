<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\ApiModel;
use App\Helpers\DateHelper;

class PersonMessage extends ApiModel
{
    /**
     * The database table name.
     * @var string
     */
    protected $table = 'person_message';

    /*
     * The following are readonly fields returned for queries:
     * creator_callsign: person.callsign looked up from creator_person_id
     * sender_person_id: person.id looked up from message_from
     *
     * The following are used for message creation:
     * recipient_callsign: used during creation to setup person_message.person_id
     * sender_callsign: used to set message_from during validation
     */

    protected $fillable = [
        'recipient_callsign',
        'message_from',
        'subject',
        'body',
    ];

    protected $appends = [
        'sent_at',
    ];

    public $recipient_callsign;
    public $sender_callsign;

    protected $casts = [
        'delivered' => 'bool',
        'timestamp' => 'datetime'
    ];

    protected $createRules = [
        'recipient_callsign'    => 'required',
        'message_from'          => 'required',
        'subject'               => 'required',
        'body'                  => 'required',
    ];

    public static function findForPerson($personId) {
        return self::where('person_id', $personId)
            ->leftJoin('person as creator', 'creator.id', '=', 'person_message.creator_person_id')
            ->leftJoin('person as sender', 'sender.callsign', '=', 'person_message.message_from')
            ->orderBy('person_message.timestamp', 'desc')
            ->get(['person_message.*', 'creator.callsign as creator_callsign', 'sender.id as sender_person_id']);
    }

    public static function countUnread($personId)
    {
        return PersonMessage::where('person_id', $personId)->where('delivered', false)->count();
    }

    /*
     * validate does triple duty here.
     * - validate the required columns are present
     * - make sure the recipient & sender callsigns are present
     * - setup appropriate fields based on the callsigns
     *
     * @param array $rules array to override class $rules
     * @param return bool true if model is valid
     */

    public function validate($rules = null, $throwOnFailure = false): bool {
        if (!parent::validate($rules, $throwOnFailure)) {
            return false;
        }

        if (!$this->exists) {
            /* Find callsigns and verify contents */

            $recipient = Person::findByCallsign($this->recipient_callsign);
            if (!$recipient) {
                if ($throwOnFailure) {
                    throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Callsign $this->recipient_callsign does not exist");
                }
                $this->addError('recipient_callsign', 'Callsign does not exist');
                return false;
            }

            $this->person_id = $recipient->id;
        }

        return true;
    }

    /*
     * Mark a message as read
     */

     public function markRead() {
         $this->delivered = true;
         return $this->saveWithoutValidation();
     }

     public function setRecipientCallsignAttribute($value) {
         $this->recipient_callsign = $value;
     }

     /*
      * Timestamp is in UTC, need to send back back with the right
      * format with timezone offset.
      */

      public function getSentAtAttribute() {
          return $this->timestamp ? $this->timestamp->toIso8601String() : '';
      }

/*      public function getSenderPersonIdAttribute() {
          return isset($this->attributes['sender_person_id']) ? $this->attributes['sender_person_id'] : null;
      }

      public function getSenderPersonIdAttribute() {
          return isset($this->attributes['']) ? $this->attributes['sender_person_id'] : null;
      }

      public function getRecipientCallsign() {
          return $this->recipient_callsign;
      }*/
}
