<?php


//    Acelle\Library\Log as MailLog;
/**
 * Server group class.
 */

namespace Acelle\Model;

use Illuminate\Database\Eloquent\Model;
use Acelle\Library\Log as MailLog;

class ServerGroups extends Model
{
    protected $table = 'servergroups';
    public static $itemsPerPage = 25;

    /**
     * Get all groups.
     *
     * @return collect
     */
    public static function getAll()
    {
        return self::all();
    }

    public static function search($request)
    {
        $query = self::filter($request);

//        if(!empty($request->sort_order)) {
//            $query = $query->orderBy($request->sort_order, $request->sort_direction)->whereNull('external');
//        }

        return $query;
    }

    public static function filter($request)
    {
        $user = $request->user();
        $query = self::select('servergroups.*');

        // Keyword
        if (!empty(trim($request->keyword))) {
            foreach (explode(' ', trim($request->keyword)) as $keyword) {
                $query = $query->where(function ($q) use ($keyword) {
                    $q->orwhere('servergroups.name', 'like', '%'.$keyword.'%');
                });
            }
        }

        // Other filter
//        if(!empty($request->customer_id)) {
//            $query = $query->where('blacklists.customer_id', '=', $request->customer_id);
//        }
//
//        if(!empty($request->admin_id)) {
//            $query = $query->whereNull('customer_id');
//        }

        return $query;
    }


    public static function getSelectOptions()
    {
        $options = collect([['value' => 0, 'text' => 'Default']]);
        $items = self::getAll();
        foreach ($items as $item) {
            $options->push(['value' => $item->id, 'text' => $item->name]);
        }
        return $options;
    }

    public static function getSpecOptions()
    {
        $options = self::getAll()->map(function ($item) {
            return ['value' => $item->id, 'text' => $item->name];
        });

        return $options;
    }

    public static function getFilterOptions()
    {
        $options = self::getAll()->map(function ($item) {
            return print '<option value="'.$item->id.'">'.$item->name.'</option>';
            //['value' => $item->id, 'text' => $item->name];
        });

        return $options;
    }

}
