<?php
/* DNS helper class (c) 2018 justinas@eofnet.lt
*/
namespace Acelle\Library;

use Acelle\Library\Log as MailLog;
//use Aws\Ec2\Exception\Ec2Exception;


class StorageHelper
{
    protected $storage_enabled;
    protected $storurl;
    protected $storkey;

    public function __construct()
    {
        $this->storage_enabled = \Config::get('app.storage');
        $this->storurl = \Config::get('app.storurl');
        $this->storkey = \Config::get('app.storkey');
    }

    public function Is_Enabled() {
        if ($this->storage_enabled == true) return true;
        else return false;
    }

    private function StorageGetPing($key, $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , 'Authorization: '.$key));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return array($httpcode,$response);
    }

    private function StoragePost($key, $url, $data,$reason = "") {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , 'Authorization: '.$key,'Reason: '.$reason));
        // if there will be ssl someday :D
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return array($httpcode,$response);
    }

    public function SubmitDelivery($email,$campuid,$server_ip,$mx,$deployment,$status) {
        if ($email != "") {
           // $records = json_encode(array($email));
            $created_date = date("Y-m-d H:i:s");
            $records = json_encode(array(array('email' => $email,'campaign' => $campuid,'server_ip'=>$server_ip,'mx' => $mx,'deployment'=>$deployment,'status'=>$status,'date_added'=>$created_date)));
            $url = $this->storurl."api/v1/deliveries/submit";
            list($code,$res) = $this->StoragePost($this->storkey,$url,$records);
                if ($code == 200) {
                    return 1;
                } else {
                    MailLog::info("Error accoured then posting single delivery to storage backend: ".$res);
                    return 0;
                }
        }
    }

    public function SubmitOpener($email,$ip_address,$server_ip,$server_ptr,$user_agent,$location,$deployment,$domain,$campaign,$maillist)
    {
        if ($email != "") {
            $date_added = date("Y-m-d H:i:s");
            $records = json_encode(array(array('email' => $email,'ip_address'=>$ip_address,'server_ip'=>$server_ip,'server_ptr'=>$server_ptr,'user_agent'=>$user_agent,'location'=>$location,'date_added'=>$date_added,'deployment'=>$deployment,'domain'=>$domain,'campaign'=>$campaign,'maillist'=>$maillist)));
            $url = $this->storurl."api/v1/openers/submit";
            list($code,$res) = $this->StoragePost($this->storkey,$url,$records);
            if ($code == 200) {
                return 1;
            } else {
                MailLog::info("Error accoured then posting single opener to storage backend: ".$res);
                return 0;
            }
        }
    }

    public function SubmitClicker($email,$ip_address,$server_ip,$server_ptr,$user_agent,$location,$deployment,$domain,$campaign,$maillist)
    {
        if ($email != "") {
            $date_added = date("Y-m-d H:i:s");
            $records = json_encode(array(array('email' => $email,'ip_address'=>$ip_address,'server_ip'=>$server_ip,'server_ptr'=>$server_ptr,'user_agent'=>$user_agent,'location'=>$location,'date_added'=>$date_added,'deployment'=>$deployment,'domain'=>$domain,'campaign'=>$campaign,'maillist'=>$maillist)));
            $url = $this->storurl."api/v1/clickers/submit";
            list($code,$res) = $this->StoragePost($this->storkey,$url,$records);
            if ($code == 200) {
                return 1;
            } else {
                MailLog::info("Error accoured then posting single opener to storage backend: ".$res);
                return 0;
            }
        }
    }

    public function SubmitEmail($email,$type,$reason) {
        if ($email != "") {
            $records = json_encode(array($email));
            $url = $this->storurl."api/v1/blacklists/set/".$type;
            list($code,$res) = $this->StoragePost($this->storkey,$url,$records,$reason);
            if ($type > 0) {
                if ($code == 200) {
                    return 1;
                } else {
                    MailLog::info("Error accoured then posting single email to storage backend: ".$res);
                    return 0;
                }
            } else {
                return 0;
            }
        }
    }

    public function AddToSorage($text,$type,$reason) {
        // old implementation
        //$clean_text = preg_replace(array('/\n/', '/\r/'), '#PH#', $text."\n");
        // new implementation
        $tmp_arr = preg_split("/\r\n|\n|\r/", $text."\n");
        // remove empty elements
        $tmp_arr = array_filter($tmp_arr, function($a) {return $a !== "";});
        $records = json_encode($tmp_arr);
        //MailLog::info("We got these records: ".print_r($records,true));
        $url = $this->storurl."api/v1/blacklists/set/".$type;
        list($code,$res) = $this->StoragePost($this->storkey,$url,$records,$reason);
        MailLog::info("Posting to storage api $text, $type, $reason");
        if ($type > -5) {
            if ($code == 200) {
                return "ok";
            } else {
                return "Some error accoured: $code $res";
            }
        } elseif ($type == -5) {
            // we should return the list with marked as blacklisted emails here...
            $json = json_decode($res);
            $return_text = "";
            if (count($json) >0) {
                foreach ($json as $item) {
                    $return_text .= "\n" . $item->email;
                    if ($item->blacklisted == true) $return_text .= " -> BL: " . $item->blacklisted . " type: " . $item->bl_type;
                }
            } else {
                MailLog::info(print_r($json,true));
            }
            //MailLog::info("we got return: ".print_r(json_decode($res),true));
            return $return_text;
        }
    }


    public function pingpond() {
        $randstr = rand(5, 15);
        $url = $this->storurl."api/v1/ping/".$randstr;
        if ($this->Is_Enabled() == false) {
            return json_encode(['online'=> 0, 'answer' => 'Not enabled in configuration']);
        }
        try {
            $online = 0;
            $answer = 'Service is down';
            $code = '';
            list($response_code,$response) = $this->StorageGetPing($this->storkey,$url);
            if ($response_code == 200&& json_decode($response)->answer == $randstr) {
                $online = 1;
                $answer = 'Running';
            }
            $code = $response_code;
            return json_encode(['online' => $online, 'answer'=> $answer, 'code' => $code ]);
        } catch (\Exception $ex) {
            MailLog::error('Got problem in StorageHelper::pingpond, cannot get contents from the remote API server'.$ex);
            return json_encode(['online'=> 0, 'answer' => 'Microservice is down']);
            return null;
        }
    }

    public function IsOnline() {
        try {
            $json = json_decode($this->pingpond());
            if ($json->online == 1) return true;
            else
                return false;
        } catch (\Exception $ex) {
            MailLog::error("Failed in function IsOnline at StorageHelper.php");
            return false;
        }
    }

    // --- links support
    public function ImageHashExists($hash) {
        $url = $this->storurl."api/v1/links/get/".$hash;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , 'Authorization: '.$this->storkey));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpcode == 200) {
           return true;
        } else {
            return false;
        }
    }

    public function ImageFilenameFromHash($hash) {
        $url = $this->storurl."api/v1/links/get/".$hash;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , 'Authorization: '.$this->storkey));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpcode == 200) {
            return $response;
        } else {
            return false;
        }
    }

    public function ImageTypeExtension($image) {
        $type = exif_imagetype($image);
        return image_type_to_extension($type);
    }

    public function ImageUpload($image) {
        // save image at first
        $local_path = public_path('source/tmp/');
        $baze = uniqid(rand(), true);
        if (!file_exists(public_path() . '/source/tmp')) mkdir(public_path() . '/source/tmp');
        $result = file_put_contents($local_path . $baze,$image);
        if (!$result) {
            MailLog::error('Error: problem writing file to disk');
            return false;
        }
        $ext = $this->ImageTypeExtension($local_path . $baze);
        $newfilename = $local_path . $baze . $ext;
        rename($local_path . $baze, $newfilename);
        $cfile = new \CURLFile($newfilename);
        $url = $this->storurl."api/v1/links/postimg";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data' , 'Authorization: '.$this->storkey));
        curl_setopt($ch,CURLOPT_POSTFIELDS,
            array(
                'img' => $cfile,
            ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpcode == 200) {
           // MailLog::info("DEBUG RESPONSE: " . print_r($response, true));
           // MailLog::info("DEBUG CODE: " . print_r($httpcode, true));
            return $response;
        } else {
            return false;
        }

    }

    public function SubmitCampaignInfo($campaign) {
        try {
            if (is_object($campaign)) {
                $info = json_encode($campaign);
               // MailLog::info("Posting campaign data: ".$info);
                $url = $this->storurl . "api/v1/links/postcamp";
                list($code, $res) = $this->StoragePost($this->storkey, $url, $info);
                if ($code == 200) {
                    return true;
                } else {
                    MailLog::info("Error accoured then posting campaign info for the links to storage backend: " . $res);
                    return false;
                }
            } else {
                return false;
            }
        } catch (\Exception $ex) {
            MailLog::error("Got exception in StorageHelper::SubmitCampaignInfo: ".$ex);
            return false;
        }
        }




    

}
