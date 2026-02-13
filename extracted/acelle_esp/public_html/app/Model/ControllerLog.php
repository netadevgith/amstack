<?php


namespace Acelle\Model;

use Illuminate\Database\Eloquent\Model;

class ControllerLog extends Model
{
    protected $fillable = ['created_at', 'text'];

    /**
     * Get all items.
     *
     * @return collect
     */
    public static function getAll()
    {
        return self::select('controller_logs.*');
    }

    /**
     * Filter items.
     *
     * @return collect
     */
    public static function filter($request)
    {
        $user = $request->user();
        $customer = $user->customer;
        $query = self::select('controller_logs.*');

        // Keyword
        if (!empty(trim($request->keyword))) {
            foreach (explode(' ', trim($request->keyword)) as $keyword) {
                $query = $query->where(function ($q) use ($keyword) {
                    $q->orwhere('text', 'like', '%'.$keyword.'%');
                });
            }
        }

        // filters
        $filters = $request->filters;



        return $query;
    }

    /**
     * Search items.
     *
     * @return collect
     */
    public static function search($request, $campaign = null)
    {
        $query = self::filter($request);

//        if (isset($campaign)) {
//            $query = $query->where('tracking_logs.campaign_id', '=', $campaign->id);
//        }

        $query = $query->orderBy($request->sort_order, $request->sort_direction);

        return $query;
    }

    /**
     * Items per page.
     *
     * @var array
     */
    public static $itemsPerPage = 25;
}
