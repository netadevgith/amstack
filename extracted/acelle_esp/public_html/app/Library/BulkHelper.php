<?php
/* DNS helper class (c) 2018 justinas@eofnet.lt
*/
namespace Acelle\Library;

use Acelle\Library\Log as MailLog;


class BulkHelper
{
    protected $use_proxy = false;

    public function __construct()
    {
       // $this->dns_api = \Config::get('app.dns');

    }

    public function raw_to_array($raw) {
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $credentials = array();
        foreach ($lines as $line) {
         $data = preg_split('/:|;|\|/',$line);
         if ($data[0] != "" && $data[1] != "")
            $credentials[] = ['username' => trim(preg_replace('/\s\s+/', ' ', $data[0])),'password' => trim(preg_replace('/\s\s+/', ' ', $data[1]))];
        }
        MailLog::info("DEBUG: ".print_r($credentials,true));
        return $credentials;
    }

    public function check_imap($host,$username,$password) {
        try {
            $mailbox = '{imap.'.$host.':993/ssl/novalidate-cert}';
          //  MailLog::info("Trying to auth to $mailbox using $username / $password");
            if ($mbox = \imap_open($mailbox,$username, $password)) {
                imap_close($mbox);
                MailLog::info("We found the good mailbox: $username");
                return true;
            } else {
                return false;
            }
        } catch (\Exception $ex) {
MailLog::error("Check imap failed: ".print_r(imap_last_error(),true));
        }
        return false;
    }

    public function resolve_host($username) {
        $data = preg_split('/@/',$username);
        return $data[1];
    }


    

}
