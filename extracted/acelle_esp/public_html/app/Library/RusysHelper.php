<?php

/**
 * Log Class.
 *
 * Main class for campaign sending logging
 *
 * LICENSE: This product includes software developed at
 * the Acelle Co., Ltd. (http://acellemail.com/).
 *
 * @category   Acelle Library
 *
 * @author     N. Pham <n.pham@acellemail.com>
 * @author     L. Pham <l.pham@acellemail.com>
 * @copyright  Acelle Co., Ltd
 * @license    Acelle Co., Ltd
 *
 * @version    1.0
 *
 * @link       http://acellemail.com
 */

namespace Acelle\Library;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Acelle\Library\Log as MailLog;


class RusysHelper
{
    const hours = 1;
    const server_prefix = 'smtp';
    protected $mta;
    protected $deployment;

    public function __construct()
    {
        $this->mta = \Config::get('app.mta');
        $this->deployment = \Config::get('app.deployment');
    }

    public function deliver_email_from_campaign($uid,$servas) {
        $default_fitness = \DB::table('nustatymai')->where('id', 2)->first()->reiksm;
        if (!isset($servas->fitness)) $servas->fitness = $default_fitness;
        if ($servas->fitness < 1000000) $servas->fitness = 2000000;
        MailLog::info("BACKGROUND QUEUE - \$HOME/gosender --send --campuid $uid --smtphost $servas->host --smtpport $servas->smtp_port --smtpspeed $servas->fitness --app $this->deployment > /dev/null 2>&1");
        MailLog::info('BACKGROUND QUEUE - Waiting for sender to complete...');
        exec("\$HOME/gosender --send --campuid $uid --smtphost $servas->host --smtpport $servas->smtp_port --smtpspeed $servas->fitness --app $this->deployment > /dev/null 2>&1");
        MailLog::info('BACKGROUND QUEUE - Sending to the group of emails finished...');
    }


    private function getrandomserver() {
         $server = \DB::select(\DB::raw("SELECT host,smtp_port FROM sending_servers WHERE name like '%".self::server_prefix."%' ORDER BY RAND() LIMIT 1"))[0];
         MailLog::info('BACKGROUND QUEUE - random server assigned: '.$server->host);
         return $server;

    }

    private function return_server($host) {
        $server = \Acelle\Model\SendingServer::where('host','=',$host)->where('name','like','%'.self::server_prefix.'%')->get()->first();
        if (isset($server)) {
            return $server;
        } else {
            MailLog::info("BACKGROUND QUEUE - selected server $host is not one of the mass mailing servers, trying another random server");
            return $this->getrandomserver();
        }
    }

    public function check_cancel($uid) {
        if (\Redis::exists($uid.'_canceled')) {
            \Redis::del($uid.'_canceled');
            return true;
        } else {
            return false;
        }

    }

    public function getserverbydomain($type,$domain)
    {
        $hours = self::hours;
        $url = $this->mta . "/api.php?status=" . $type . "&type=domain&val=" . $domain . "&hours=" . self::hours . "&limit=1";
        // we should increase hours in exponention way if the result is null
        try {
            while (true) {
               // MailLog::info("BACKGROUND QUEUE - Trying to fetch result from api with increased $hours hours");
                $url = $this->mta . "/api.php?status=" . $type . "&type=domain&val=" . $domain . "&hours=" . $hours . "&limit=1";
                $server = json_decode(file_get_contents($url));
                if (count($server) != 0) {
                    MailLog::info("BACKGROUND QUEUE - We found the result with $hours hours");
                    break;
                }
                if ($hours > 29) {
                    // we did not found any server within last 900 hours, lets select the random server
                    MailLog::info("BACKGROUND QUEUE - We did not found any server within $hours hours time interval from log database, selecting the random server");
                   // $server[0] = $this->getrandomserver();
                    throw new \Exception();
                    break;
                }
                $hours++;
                sleep(1);

            }
            return $this->return_server($server[0]->instance);
        }
        catch (\Exception $ex) {
            // we could use change the sending information techique here if no server was found, we can change the links and so on
            MailLog::error("BACKGROUND QUEUE - got problem in server selection, going to select some random server");
            return $this->getrandomserver();
            }
    }
    

}
