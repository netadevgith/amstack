<?php

/**
 * UnsubscribeLog class.
 *
 * Model class for unsubscribe log
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

use Illuminate\Database\Eloquent\Model;

class ConversionLog extends Model
{
    protected $table = 'campaigns_conversions';


    /**
     * Get all items.
     *
     * @return collect
     */
    public static function getAll()
    {
        return self::select('campaigns_conversions.*');
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
        $query = self::select('campaigns_conversions.*','subscribers.email');
        $query = $query->join('subscribers', 'campaigns_conversions.subscriber_uid', '=', 'subscribers.uid');
        $query = $query->leftJoin('campaigns', 'campaigns.uid', '=', 'campaigns_conversions.campaign_uid');

        // Keyword
        if (!empty(trim($request->keyword))) {
            foreach (explode(' ', trim($request->keyword)) as $keyword) {
                $query = $query->where(function ($q) use ($keyword) {
                    $q->orwhere('campaigns.name', 'like', '%'.$keyword.'%')
                        ->orwhere('subscribers.email', 'like', '%'.$keyword.'%');
                });
            }
        }

        // filters
        $filters = $request->filters;
        if (!empty($filters)) {
            if (!empty($filters['campaign_uid'])) {
                $query = $query->where('campaigns.uid', '=', $filters['campaign_uid']);
            }
        }

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

        if (isset($campaign)) {
            $query = $query->where('campaigns_conversions.campaign_uid', '=', $campaign->uid);
        }

        //$query = $query->orderBy($request->sort_order, $request->sort_direction);

        return $query;
    }

    /**
     * Items per page.
     *
     * @var array
     */
    public static $itemsPerPage = 25;
}
