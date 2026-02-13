<?php
/* DNS helper class (c) 2018 justinas@eofnet.lt
*/
namespace Acelle\Library;

use Acelle\Library\Log as MailLog;
use SendGrid\Mail;

//use Aws\Ec2\Exception\Ec2Exception;


class CloudFlareHelper
{
    protected $api_url = 'https://api.cloudflare.com/client/v4';
    protected $forward_email = 'newsletter@linacrm.com';
    protected $api_email;
    protected $api_key;
    protected $host_ip;
    protected $max_pages;

    public function __construct()
    {
        $this->api_email = \Config::get('app.cloudflare_email');
        $this->api_key = \Config::get('app.cloudflare_apikey');
        //$this->host_ip = gethostbyname($_SERVER['SERVER_NAME']);
        // TEMP BUGFIX
        $this->host_ip = $this->GetIP();
        $this->max_pages = 20;

    }


// clear A dns records and set manual ip for manually proxied domains
public function manually_set($domain,$ip) {
$this->delete_records($domain,$domain,'A');
$domains = $this->get_domains($domain);
    foreach ($domains as $zone) {
      if (isset($zone->id) && $zone->name == $domain) {
          // check if dns zone record already exists, if not add it
          $dnsas = array('type' => 'A', 'name' => '@', 'content' => $ip);
          $this->createDNSRecord($zone->id, $dnsas);
      }
    }
}

    private function GetIP() {
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,'http://ip.eofnet.lt');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        return $result;
    }


    public function recursive_array_search($needle,$arr) {
        if (is_array($arr)) {
            foreach ($arr as $key => $value) {
                $current_key=$key;
                if($needle===$value OR (is_array($value) && $this->recursive_array_search($needle,$value) !== false)) {
                    return $current_key;
                }
            }
        }
        return false;
    }

    public function returnbl() {
        $assoc = array();             // The array we're going to be returning
//try {
//        $db_user = "ses_remote";
//        $db_host = "78.46.73.84";
//        $db_db = "trackingas";
//        $db_pass = "bGh9CaF897q";
//        $db = new \mysqli($db_host, $db_user, $db_pass, $db_db);
//        $dbqr = $db->query("SELECT name,dnsbl from dnsbl_rbl");
//        while($row = $dbqr->fetch_array(MYSQLI_ASSOC)) {
//            $assoc[] = $row;
//        }
//        /* free result set */
//        $dbqr->close();
//
//        /* close connection */
//        $db->close();
//} catch (\Exception $ex) {
//
//}
        return $assoc;

    }

    public function get_domains($match = "") {
        $DOMAIN_PAGE_LIMIT = 20;

        // 2019.04.10 update to support paginated lists, up to 20 pages...
        // some hack to detect all domains in the paginated list (what a dumb api...)
$salyga = "any";
if ($match != "") {
    $salyga = "all";
    $ch = curl_init();
    $headers = array(
        'X-Auth-Email: '.$this->api_email,
        'X-Auth-Key: '.$this->api_key,
        'Content-Type: application/json',
    );
    curl_setopt($ch, CURLOPT_URL, $this->api_url . "/zones?name=" . $match . "&status=active,pending&page=1&per_page=50&order=status&direction=desc&match=" . $salyga);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);
    $r = json_decode($response);
    $result = @$r->result;
    /*          foreach ($result as $zone) {

                if (isset($zone->id))
                {
                    MailLog::info("Domenas yra: " . $zone->name);
                }
            } */
    return $result;
} else {
    $result = null;
    // we will count total domain pages here
    $ch = curl_init();
    $headers = array(
        'X-Auth-Email: '.$this->api_email,
        'X-Auth-Key: '.$this->api_key,
        'Content-Type: application/json',
    );
    curl_setopt($ch, CURLOPT_URL, $this->api_url . "/zones?name=" . $match . "&status=active,pending&page=1&per_page=50&order=status&direction=desc&match=" . $salyga);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);
    $r = json_decode($response);
    $result = (object)array_merge(
        (array)$result, (array)@$r->result);
    unset($ch);
    $DOMAIN_PAGE_LIMIT = $r->result_info->total_pages;
    if ($DOMAIN_PAGE_LIMIT > 1) {
        foreach (range(2, $DOMAIN_PAGE_LIMIT) as $number) {
            $ch = curl_init();
            $headers = array(
                'X-Auth-Email: ' . $this->api_email,
                'X-Auth-Key: ' . $this->api_key,
                'Content-Type: application/json',
            );
            curl_setopt($ch, CURLOPT_URL, $this->api_url . "/zones?name=" . $match . "&status=active,pending&page=" . $number . "&per_page=50&order=status&direction=desc&match=" . $salyga);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($ch);
            curl_close($ch);
            $r = json_decode($response);

            $result = (object)array_merge(
                (array)$result, (array)@$r->result);
            unset($ch);
        }
    }

    return $result;

}

    }


    public function import_domains($domains)
    {
        foreach ($domains as $domain) {
            $ch = curl_init($this->api_url . "/zones");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'X-Auth-Email: ' . $this->api_email,
                'X-Auth-Key: ' . $this->api_key,
                'Content-Type: application/json'
            ));
            $data_string = json_encode(array('name' => $domain, 'jump_start' => false));
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($ch);
            curl_close($ch);
            $r = json_decode($response);
            MailLog::info("Cloudflare added domain $domain: " . print_r($r, true));
//            usleep(400);
//            if ($this->set_tracking_domain($domain)) {
//                return true;
//            } else {
//                return false;
//            }

        }
    }

    public function domain_exists($domain) {

    }



    public function delete_domain($domain) {

    }

    public function ListAllDNSRecords($domain) {
        $result = null;
        $ch = curl_init($this->api_url . "/zones/" . $domain->id . "/dns_records?&page=1&per_page=50");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-Auth-Email: ' . $this->api_email,
            'X-Auth-Key: ' . $this->api_key,
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);
        $r = json_decode($response);
        $dnsrecs = $r->result;
        $result = (object)array_merge(
            (array)$result, (array)@$r->result);
        unset($ch);
        $DOMAIN_PAGE_LIMIT = $r->result_info->total_pages;
        if ($DOMAIN_PAGE_LIMIT > 1) {
            foreach (range(2, $DOMAIN_PAGE_LIMIT) as $number) {
                $ch = curl_init($this->api_url . "/zones/" . $domain->id . "/dns_records?&page=" . $number . "&per_page=50");
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'X-Auth-Email: ' . $this->api_email,
                    'X-Auth-Key: ' . $this->api_key,
                    'Content-Type: application/json'
                ));
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $response = curl_exec($ch);
                curl_close($ch);
                $r = json_decode($response);
                $dnsrecs = $r->result;
                $result = (object)array_merge(
                    (array)$result, (array)@$r->result);
                unset($ch);
            }
        }

        return $result;
    }

    public function UpdateDnsRecordv3($domain,$record) {
        $ch = curl_init($this->api_url."/zones/".$domain->id."/dns_records/".$record->id);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-Auth-Email: '.$this->api_email,
            'X-Auth-Key: '.$this->api_key,
            'Content-Type: application/json'
        ));

        $data_string = json_encode($record);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);
        $r = json_decode($response);
        if ($r->success == 1) {
            return true;
        } else { return false; }
     }

    public function updateDNSRecord($dns)
    {
        $ch = curl_init($this->api_url."/zones/".$dns->zone_id."/dns_records/".$dns->id);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-Auth-Email: '.$this->api_email,
            'X-Auth-Key: '.$this->api_key,
            'Content-Type: application/json'
        ));

        $data_string = json_encode($dns);
        MailLog::info("updateDNSRecord DEBUG: ".$data_string);
        //echo "JSON_DATA_STRING: $data_string";
        //echo "<br />\n";

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);
        $r = json_decode($response);
        //MailLog::info("teeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeest");
        MailLog::info("updateDNSRecord Cloudflaree set dns response: ".print_r($r,true));
        if ($r->success == 1) {
            MailLog::info("GREAT SUCCESS!");
            return true;
        } else { return false; }
    }

    public function createDNSRecord($zoneid,$dns) {
        $ch = curl_init($this->api_url."/zones/".$zoneid."/dns_records");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-Auth-Email: '.$this->api_email,
            'X-Auth-Key: '.$this->api_key,
            'Content-Type: application/json'
        ));
        $data_string = json_encode($dns);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);
        $r = json_decode($response);
      //  MailLog::info("Cloudflaree create dns response: ".print_r($r,true));
        if ($r->success == 1) {
            //MailLog::info("LABAI PAVYKO su nauju record");
            return true;
        } else { return false; }

    }


    public function post_to_improvmx($domain) {
        $post = [
            'domain' => $domain,
            'default_email' => $this->forward_email,
        ];

        $ch = curl_init('https://improvmx.com/forwarding/cloudflare/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $response = curl_exec($ch);
        curl_close($ch);
        MailLog::info("Posted to the form of impovmx and got response: ".$response);

    }

    public function list_servers() {
        $string_limit_min = 200;
        $string_limit_max = 243;
        // mazesnis negu 243 bet didesnis negu 200
        $data = \Acelle\Model\SendingServer::getAll()->where('id','>','1')->get();
        $servers_string = "";
        $servers = array();
        foreach ($data as $server) {
            $servers_string .= " ip4:".$server->host."/32";
            if (strlen($servers_string) <= $string_limit_max&&strlen($servers_string) >= $string_limit_min) {
                $servers[] = $servers_string;
                $servers_string = "";
            }
        }
        if (!empty($servers_string)) $servers[] = $servers_string;

      return $servers;
    }


    public function deleteDNSRecord($dns) {
        $ch = curl_init($this->api_url."/zones/".$dns->zone_id."/dns_records/".$dns->id);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-Auth-Email: '.$this->api_email,
            'X-Auth-Key: '.$this->api_key,
            'Content-Type: application/json'
        ));

        $data_string = json_encode($dns);
        //echo "JSON_DATA_STRING: $data_string";
        //echo "<br />\n";

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
     //   curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);
        $r = json_decode($response);
        //MailLog::info("Cloudflaree delete dns response: ".print_r($r,true));
        if ($r->success == 1) {
            //MailLog::info("DNS DEL GREAT SUCCESS!");
            return true;
        } else { return false; }

}

    public function delete_records($domain,$record,$type) {
        $DNS_PAGE_LIMIT = 20;
        $domains = $this->get_domains($domain);
        foreach ($domains as $zone) {
            if (isset($zone->id) && $zone->name == $domain) {
                MailLog::info("Found domain $domain for delete records...");
                $zoneid = $zone->id;
                $zonename = $zone->name;
                // List all DNS records for this domain
                $result = null;
                $ch = curl_init($this->api_url . "/zones/$zoneid/dns_records?page=1&per_page=50");
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'X-Auth-Email: ' . $this->api_email,
                    'X-Auth-Key: ' . $this->api_key,
                    'Content-Type: application/json'
                ));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $response = curl_exec($ch);
                curl_close($ch);
                $r = json_decode($response);
                $result = (object) array_merge(
                    (array) $result, (array) @$r->result);
                unset($ch);
                $DOMAIN_PAGE_LIMIT = $r->result_info->total_pages;
                if ($DOMAIN_PAGE_LIMIT > 1) {
                    foreach (range(2, $DOMAIN_PAGE_LIMIT) as $number) {
                        $ch = curl_init($this->api_url . "/zones/$zoneid/dns_records?page=" . $number . "&per_page=50");
                        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                            'X-Auth-Email: ' . $this->api_email,
                            'X-Auth-Key: ' . $this->api_key,
                            'Content-Type: application/json'
                        ));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        $response = curl_exec($ch);
                        curl_close($ch);
                        $r = json_decode($response);
                        $result = (object)array_merge(
                            (array)$result, (array)@$r->result);
                        unset($ch);
                    }
                }


                // remove point if necessary ?
                if ((strpos($record, ".") > -1)) $record = explode(".", $record)[0];
                foreach ($result as $dns) {
                    if (strpos($dns->name, $record) !== false&&$dns->type == $type) {
                      MailLog::info("Found dns record: ".print_r($dns,true));
                      $this->deleteDNSRecord($dns);
                      MailLog::info("Deleting DNS Record $record");
                    }
                }

               return true;
            }
        }
    }

    public function set_spf($zone,$content, $name = "")
    {
                if (empty($name)) {
                    $dnsas = array('type' => 'TXT', 'name' => $zone->name, 'content' => $content);
                    $this->createDNSRecord($zone->id, $dnsas);
                } else {
                    $dnsas = array('type' => 'TXT', 'name' => $name, 'content' => $content);
                    $this->createDNSRecord($zone->id, $dnsas);
                }
    }

// FULLY WORKING FUNCTION as of 2021 (required by the servers replacement)
    public function delete_spf_records($domain) {
        // scan all txt records for SPF sign and delete them...
        $zone = $this->GetDomainZone($domain);
        $dnses = $this->GetAllDnsForZone($zone->id);
        MailLog::info("Zoneid: $zone->id for domain $domain");
        $records = 0;
        foreach ($dnses as $dns) {
                if ($dns->type == "TXT") {
                   if(strpos($dns->content, "DKIM") !== false){
                     continue;
                   }
                   if(strpos($dns->content, "spf") !== false){
                     $records++;
                     MailLog::info("Possible SPF Record: ".$dns->content);
                     $this->deleteDNSRecord($dns);
                   }
                   if(strpos($dns->name, "spf") !== false){
                    $records++;
                    MailLog::info("Possible SPF Record: ".$dns->content);
                    $this->deleteDNSRecord($dns);
                   }
                }
        }
       return $records;
}

    public function add_spf_records($domain) {
        $servers = @$this->list_servers();
        $zoneid = $this->GetDomainZone($domain);

        $spf_count = 0;
        $main_spf = "v=spf1";
        $records = 0;

        foreach ($servers as $server_rec) {
            $spf_count++;
            $main_spf .= " include:spf".$spf_count.'.'.$domain;
            $dns_value = "v=spf1".$server_rec." ~all";
            MailLog::info("Adding spf record: ".$dns_value);
            $this->set_spf($zoneid,$dns_value,'spf'.$spf_count);
            $records++;
            // add new spf records
        }
        if ($spf_count > 0) {
            // set the main spf here
            $main_spf .= " ~all";
            $this->set_spf($zoneid,$main_spf);
            $records++;
        }
        return $records;
    }

    public function set_tracking_domain($domain) {
     $DNS_PAGE_LIMIT = 20;
     $domains = $this->get_domains($domain);
        foreach ($domains as $zone) {
            if (isset($zone->id) && $zone->name == $domain) {
                MailLog::info("Found domain $domain for set it as main tracking domain...");
                $zoneid = $zone->id;
                $zonename = $zone->name;
                // List all DNS records for this domain
                $result = null;
                $ch = curl_init($this->api_url . "/zones/$zoneid/dns_records?page=1&per_page=50");
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'X-Auth-Email: ' . $this->api_email,
                    'X-Auth-Key: ' . $this->api_key,
                    'Content-Type: application/json'
                ));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $response = curl_exec($ch);
                curl_close($ch);
                $r = json_decode($response);
                $result = (object)array_merge(
                    (array)$result, (array)@$r->result);
                unset($ch);
                $DOMAIN_PAGE_LIMIT = $r->result_info->total_pages;
                if ($DOMAIN_PAGE_LIMIT > 1) {
                    foreach (range(1, $DNS_PAGE_LIMIT) as $number) {
                        MailLog::info("Going to dns page nr: " . $number);
                        $ch = curl_init($this->api_url . "/zones/$zoneid/dns_records?page=" . $number . "&per_page=50");
                        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                            'X-Auth-Email: ' . $this->api_email,
                            'X-Auth-Key: ' . $this->api_key,
                            'Content-Type: application/json'
                        ));

                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        $response = curl_exec($ch);
                        curl_close($ch);
                        $r = json_decode($response);

                        $result = (object)array_merge(
                            (array)$result, (array)@$r->result);
                        unset($ch);
                    }
                }

//                @$this->delete_spf_records($domain);
//                @$this->add_spf_records($domain);
               

                foreach ($result as $dns) {
                    if ($dns->name == $domain&&$dns->type == "A") {
                        MailLog::info("Found dns record $dns->name: ".print_r($dns,true));
                        $dns->content = $this->host_ip;
                        if ($this->updateDNSRecord($dns)) {
                            // update mx records and post to the https://improvmx.com/
                          //  @$this->createDNSRecord($zoneid,array('type'=>'MX','name' => $zone->name, 'content' => 'mx1.improvmx.com' , 'priority' => 10));
                          //  @$this->createDNSRecord($zoneid,array('type'=>'MX','name' => $zone->name, 'content' => 'mx2.improvmx.com' , 'priority' => 20));
                           // sleep(5);
                           // @$this-$this->post_to_improvmx($zone->name);
                            return true;
                        }
                        else return false;
                    }
                }
                // we have checked all the records but the main @ was not found, so we need to add new one
                $dnsas = array('type'=> 'A','name' => $zone->name,'content' => $this->host_ip,'proxied' => true);
                if ($this->createDNSRecord($zoneid,$dnsas)) {
                    // create mx records and post to the https://improvmx.com/
                    //@$this->createDNSRecord($zoneid,array('tyoe'=>'MX','name' => $zone->name, 'content' => 'mx1.improvmx.com' , 'priority' => 10));
                    //@$this->createDNSRecord($zoneid,array('type'=>'MX','name' => $zone->name, 'content' => 'mx2.improvmx.com' , 'priority' => 20));
                    //sleep(5);
                    //@$this-$this->post_to_improvmx($zone->name);
                    return true;
                }
                else return false;


            } else continue;
        }

    }


public function GetDomainZone($domain) {
$domains = $this->get_domains($domain);
  foreach ($domains as $zone) {
     if (isset($zone->id) && $zone->name == $domain) {
        return $zone;
     }
  }
}


public function GetAllDnsForZone($zoneid) {
// List all DNS records for domain
                $result = null;
                $ch = curl_init($this->api_url . "/zones/$zoneid/dns_records?page=1&per_page=50");
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'X-Auth-Email: ' . $this->api_email,
                    'X-Auth-Key: ' . $this->api_key,
                    'Content-Type: application/json'
                ));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $response = curl_exec($ch);
                curl_close($ch);
                $r = json_decode($response);
                $result = (object) array_merge(
                    (array) $result, (array) @$r->result);
                unset($ch);
                $DOMAIN_PAGE_LIMIT = $r->result_info->total_pages;
                if ($DOMAIN_PAGE_LIMIT > 1) {
                    foreach (range(2, $DOMAIN_PAGE_LIMIT) as $number) {
                        $ch = curl_init($this->api_url . "/zones/$zoneid/dns_records?page=" . $number . "&per_page=50");
                        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                            'X-Auth-Email: ' . $this->api_email,
                            'X-Auth-Key: ' . $this->api_key,
                            'Content-Type: application/json'
                        ));
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        $response = curl_exec($ch);
                        curl_close($ch);
                                                $r = json_decode($response);
                        $result = (object)array_merge(
                            (array)$result, (array)@$r->result);
                        unset($ch);
                    }
                }
               return $result;
}


}

