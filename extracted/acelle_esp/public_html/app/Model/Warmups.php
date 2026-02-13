<?php

/**
 2021.08.03
 */

namespace Acelle\Model;

use Illuminate\Database\Eloquent\Model;

class Warmups extends Model
{
    protected $fillable = ['uid', 'name', 'email','server_ip','server_pass'];


    public function ips(){
        return $this->hasMany('Acelle\Model\WarmupsIps', 'warmup_id','id');
    }

    public function phrases() {
        return $this->hasMany('Acelle\Model\WarmupsPhrases', 'warmup_id','id');
    }

    public static function findByUid($uid)
    {
        return self::where('uid', '=', $uid)->first();
    }


    /**
     * Get all items.
     *
     * @return collect
     */
    public static function getAll()
    {
        return self::select('warmups.*');
    }


/*    public static function search($request, $campaign = null)
    {
        $query = self::filter($request);

        if (isset($campaign)) {
            $query = $query->where('tracking_logs.campaign_id', '=', $campaign->id);
        }

        $query = $query->orderBy($request->sort_order, $request->sort_direction);

        return $query;
    }


     */
    public static $itemsPerPage = 25;
}
