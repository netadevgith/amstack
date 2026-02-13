<?php
/* DNS helper class (c) 2018 justinas@eofnet.lt
*/
namespace Acelle\Library;

use Acelle\Library\Log as MailLog;
//use Aws\Ec2\Exception\Ec2Exception;


class TrackHash
{
    protected $algo;
    protected $dns_api_key;
    protected $proxy_ip;

    public function __construct()
    {
        $this->algo = $this->GetAlgo();
    }

    public function GetUrlbyId($id) {
        try {
            $url = \Redis::hget("campaigns_links", $id);
            return $url;
        } catch (\Exception $ex) {
            MailLog::error("Unable to get link for id: ".$id);
            return "";
        }
    }


    public function HashIt($string)
    {
        $return = '';
        $hashing = str_split($string, 1);
        foreach ($hashing as $part)
        {
            $return .= $this->algo[$part];
        }
        return rtrim($return);
    }

    public function spliturl($hash) {
        list($track, $urlid) = explode("u", $hash);
        return array($track,$urlid);
    }

    public function UnhashIt($hash)
    {
        $return = '';
        $unhash = str_split($hash);
        foreach ($unhash as $value)
        {
            $find = array_search($value, $this->algo);
            if (FALSE !== $find)
                $return .= $find;
        }
 //       MailLog::info("Word part: ".$return);
        return $return;
    }

    public function ShowAlgo() {
        return $this->GetAlgo();
    }

    private function GetAlgo()
    {
        return json_decode(\Config::get('app.gosender_algorithm'),true);
   }

}
