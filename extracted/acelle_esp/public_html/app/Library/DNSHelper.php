<?php
/* DNS helper class (c) 2018 justinas@eofnet.lt
*/
namespace Acelle\Library;

use Acelle\Library\Log as MailLog;
//use Aws\Ec2\Exception\Ec2Exception;


class DNSHelper
{
    protected $dns_api;
    protected $dns_api_key;
    protected $proxy_ip;

    public function __construct()
    {
        $this->dns_api = \Config::get('app.dns');
        $this->dns_api_key = \Config::get('app.dns_key');
        $this->proxy_ip = \Redis::get("proxy_default") ?? "";
    }

    public function Is_Enabled() {
        if ($this->dns_api != "") return true;
        else return false;
    }

    public function get_domains() {
        $url = $this->dns_api . "/api/index.php?auth=" . $this->dns_api_key . "&do=list";
        try {
            $domains = \json_decode(file_get_contents($url));
            return $domains;
        } catch (\Exception $ex) {
            MailLog::error('Got problem in DNSHelper::get_domains, cannot get contents from the remote DNS server'.$ex);
            return null;
        }
    }

    public function import_domains($domains) {
        $url = $this->dns_api . "/api/index.php?auth=" . $this->dns_api_key . "&do=import";
        foreach ($domains as $domain) {
            if(substr($domain, -1) !== '.') $domain = $domain.".";
            if (strlen($domain) >2) {
                $res = @json_decode(file_get_contents($url . '&domain=' . $domain));
                usleep(400);
                $this->set_tracking_domain($domain);
            }
        }
    }

    public function domain_exists($domain) {
        try {
            $data = @file_get_contents($this->dns_api . "/api/index.php?auth=" . $this->dns_api_key . "&do=list");
           if (isset($data)) {
               $json = json_decode($data);
               if (isset($json)) {
                   foreach ($json as $doms) {
                       if ($doms->name == $domain) {
                           MailLog::info("Domain $domain that user tried to update already exists on the system and will be updated ASAP");
                           return true;
                           break;
                       }
                   }

               } else {
                   return false;
               }
           } else {
               return false;
           }

        } catch (\Exception $ex) {
            return false;
        }

    }

    public function get_proxyip() {
        return $this->proxy_ip;
    }

    public function delete_domain($domain) {
        if(substr($domain, -1) !== '.') $domain = $domain.".";
        try {
            $url = $this->dns_api . "/api/index.php?auth=" . $this->dns_api_key . "&do=delete&domain=" . $domain;
            return file_get_contents($url);
        } catch (\Exception $ex) {
            MailLog::error('Got problem in DNSHelper::delete_domain, cannot get contents from the remote DNS server'.$ex);
            return;
        }
    }


    public function set_tracking_domain($domain) {
        if(substr($domain, -1) !== '.') $domain = $domain.".";
        $url = $this->dns_api . "/api/index.php?auth=" . $this->dns_api_key . "&do=update&domain=".$domain."&ip=".$this->proxy_ip;
        try {
            $res = @json_decode(file_get_contents($url));
            if (isset($res->status)&&$res->status == "ok" ?? "error") return true;
            else return false;
        } catch (\Exception $ex) {
            MailLog::error('Got problem in DNSHelper::set_tracking_domain, cannot get contents from the remote DNS server'.$ex);
            return false;
        }
    }
    

}
