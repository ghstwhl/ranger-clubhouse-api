<?php
/*
 * Global Helpers used everywhere throughout the application.
 */

use App\Helpers\SqlHelper;

use App\Models\ErrorLog;
use App\Models\Setting;

use Illuminate\Support\Facades\Mail;

/**
 * Retrieve a configuration variable possibly stored in the database.
 * Alias for Setting::get().
 *
 * @param string $name - setting name
 * @return mixed setting value
 */

if (!function_exists('setting')) {
    function setting($name)
    {
        return Setting::get($name);
    }
}

/**
 * Send an email. Alias for Mail:to()->send() with exception handling.
 *
 * @param mixed $email string or string array of email addresses to send
 * @param Mailable $message the message to send
 * @return boolean true if mail was successfully queued, false if an exception happened.
 */

if (!function_exists('mail_to')) {
    function mail_to($email, $message)
    {
        try {
            Mail::to($email)->send($message);
            return true;
        } catch (\Swift_TransportException $e) {
            ErrorLog::recordException($e, 'email-exception', [
                    'type'    => 'mail-to',
                    'email'   => $email,
                    'message' => $message
             ]);

             return false;
        }
    }
}

/**
 * Retrieve the current year.
 *
 * Support for groundhog day server. When the GroundhogDayServer configuration
 * variable is true, use the database year. otherwise use the system year.
 *
 * @return integer the current year (either real or simulated)
 */

if (!function_exists('current_year')) {
    function current_year()
    {
        static $year;

        if (config('clubhouse.GroundhogDayServer')) {
            if ($year) {
                return $year;
            }

            $year = SqlHelper::now()->year;
            return $year;
        }
        return date('Y');
    }
}
