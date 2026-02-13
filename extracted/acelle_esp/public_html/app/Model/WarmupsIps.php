<?php

/*
 * 2021.08.03
 */

namespace Acelle\Model;

use Illuminate\Database\Eloquent\Model;

class WarmupsIps extends Model
{
    protected $fillable = ['uid','ip_address','warmup_id'];



    public function warmup(){
        return $this->belongsTo('Acelle\Model\Warmups','id');
    }


    public static function getAll()
    {
        return self::select('warmups_ips.*');
    }



    /**

    public static function search($request, $campaign = null)
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
