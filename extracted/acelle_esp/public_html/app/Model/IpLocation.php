<?php

/**
 * IpLocation class.
 *
 * Model class for IP Locations
 *
 * LICENSE: This product includes software developed at
 * the Acelle Co., Ltd. (http://acellemail.com/).
 *
 * @category   MVC Model
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

namespace Acelle\Model;

//use Acelle\Library\IpLocator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log as LaravelLog;
use GeoIp2\Database\Reader;

class IpLocation extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'country_code', 'country_name', 'region_code',
        'region_name', 'city', 'zipcode',
        'latitude', 'longitude', 'metro_code', 'areacode',
    ];

    /*
     * Hacky function to return real location formated in the Country City format
     */
    public static function returnloc($ip) {
        $location = new self();
        $location->ip_address = $ip;
        try {
            $reader = new Reader('/usr/share/GeoIP/GeoLite2-City.mmdb');
            $record = $reader->city($ip);
            $geobj = array('country_name' => $record->country->name, 'country_code' => $record->country->isoCode,
                'region_code' => $record->mostSpecificSubdivision->isoCode,'region_name' => $record->mostSpecificSubdivision->name,
                'zipcode' => $record->postal->code, 'latitude' => $record->location->latitude, 'longitude' => $record->location->longitude,
                'city'=> $record->city->name);
            $location->fill($geobj);
        } catch (\Exception $e) {
            LaravelLog::warning('Cannot get IP location info: ' . $e->getMessage());
        }
        $lok_long = "";
        try {
            $locobj = json_decode($location);
            $lok = $locobj->country_name ?? "Unknown";
            $lok3 = $locobj->city ?? "";
            $lok_long = $lok;
            if ($lok3 != "") {
                $lok_long = $lok . " " . $lok3;
            }
        } catch (Exception $ex) {
            $lok_long = "Unknown";
            LaravelLog::error("Unable to query for location: ".$ex);
        }
        return $lok_long;
    }

    /**
     * Add new IP.
     *
     * return Location
     */
    public static function add($ip)
    {
        $location = new self();
        $location->ip_address = $ip;

        try {

$reader = new Reader('/usr/share/GeoIP/GeoLite2-City.mmdb');
$record = $reader->city($ip);
$geobj = array('country_name' => $record->country->name, 'country_code' => $record->country->isoCode,
'region_code' => $record->mostSpecificSubdivision->isoCode,'region_name' => $record->mostSpecificSubdivision->name,
'zipcode' => $record->postal->code, 'latitude' => $record->location->latitude, 'longitude' => $record->location->longitude,
 'city'=> $record->city->name);
$location->fill($geobj);

        } catch (\Exception $e) {
            echo $e->getMessage();
            // Note log
            LaravelLog::warning('Cannot get IP location info: ' . $e->getMessage());
        } finally {
            return $location;
        }
    }

    /**
     * Location name.
     *
     * return Location
     */
    public function name()
    {
        $str = [];
        if (!empty($this->city)) {
            $str[] = $this->city;
        }
        if (!empty($this->region_name)) {
            $str[] = $this->region_name;
        }
        if (!empty($this->country_name)) {
            $str[] = $this->country_name;
        }
        $name = implode(', ', $str);
        return (empty($name) ? trans('messages.unknown') : $name);
    }
}
