<?php

/*
 * 2021.08.03
 */

namespace Acelle\Model;

use Illuminate\Database\Eloquent\Model;

class WarmupsPhrases extends Model
{
    protected $fillable = ['uid','warmup_id','total','perh','phrase'];



    public function warmup(){
        return $this->belongsTo('Acelle\Model\Warmups','id');
    }


    public static function getAll()
    {
        return self::select('warmups_phrases.*');
    }

    public static $itemsPerPage = 25;
}
