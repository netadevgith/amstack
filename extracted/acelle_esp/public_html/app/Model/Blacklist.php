<?php

/**
 * Blacklist class.
 *
 * Model for blacklisted email addresses
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

use Acelle\Http\Controllers\Auth\LoginController;
use Illuminate\Database\Eloquent\Model;
use Acelle\Library\Log as MailLog;

class Blacklist extends Model
{
    // Subscribers to import every time
    const IMPORT_STATUS_NEW = 'new';
    const IMPORT_STATUS_RUNNING = 'running';
    const IMPORT_STATUS_FAILED = 'failed';
    const IMPORT_STATUS_DONE = 'done';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'email', 'reason'
    ];

    /**
     * Get all items.
     *
     * @return collect
     */
    public static function getAll()
    {
        return self::select('blacklists.*')->whereNull('external');
    }

    /**
     * Filter items.
     *
     * @return collect
     */
    public static function filter($request)
    {
        $user = $request->user();
        $query = self::select('blacklists.*');

        // Keyword
        if (!empty(trim($request->keyword))) {
            foreach (explode(' ', trim($request->keyword)) as $keyword) {
                $query = $query->where(function ($q) use ($keyword) {
                    $q->orwhere('blacklists.email', 'like', '%'.$keyword.'%')->whereNull('external');
                });
            }
        }

        // Other filter
        if(!empty($request->customer_id)) {
            $query = $query->where('blacklists.customer_id', '=', $request->customer_id);
        }

        if(!empty($request->admin_id)) {
            $query = $query->whereNull('customer_id');
        }

        return $query;
    }

    /**
     * Search items.
     *
     * @return collect
     */
    public static function search($request)
    {
        $query = self::filter($request);

        if(!empty($request->sort_order)) {
            $query = $query->orderBy($request->sort_order, $request->sort_direction)->whereNull('external');
        }

        return $query;
    }

    /**
     * Items per page.
     *
     * @var array
     */
    public static $itemsPerPage = 25;

    /**
     * Import from file.
     *
     * @return collect
     */
    public static function import_old($file, $comment, $system_job, $customer=NULL, $admin=NULL)
    {
        $content = \File::get($file);
        $lines = preg_split('/\r\n|\r|\n/', $content);

        $total = count($lines);

        // init the status
        $system_job->updateStatus([
            'status' => self::IMPORT_STATUS_RUNNING,
        ]);




        // update status, line count
        $system_job->updateStatus([ 'total' => $total ]);

        // demo process
        $success = 0;
        MailLog::info("handlinam importa: darom foreacha");
        foreach ($lines as $number => $line) {
            $email = trim(strtolower($line));

            // update status, finish one batch
            $system_job->updateStatus([ 'processed' => $number+1 ]);

            // Add to blacklist

            if (\Acelle\Library\Tool::isValidEmail($email)) {
                $success++;
                $system_job->updateStatus([ 'success' => $success ]);

                // Add to blacklist
                if (isset($customer)) {
                    $customer->addEmaillToBlacklist($email,$comment);
                }
                if (isset($admin)) {
                    $admin->addEmaillToBlacklist($email);
                }
            }

         //   sleep(0.5);
        }

        // Update status, finish all batches
        $system_job->updateStatus([ 'status' => self::IMPORT_STATUS_DONE ]);
    }


    public static function import($file, $comment, $system_job, $customer=NULL, $admin=NULL)
    {
        $content = \File::get($file);
        $lines = preg_split('/\r\n|\r|\n/', $content);

        $total = count($lines);

        // init the status
        $system_job->updateStatus([
            'status' => self::IMPORT_STATUS_RUNNING,
        ]);






        // demo process
        $blacklisted_contacts = [];
        $blacklisted_domains = [];
        $blacklisted_names = [];
        $blacklisted_numbers = [];
        $success = 0;
        MailLog::info("handlinam importa: darom foreacha");
        $datetime = date("Y-m-d H:i:s");
        if (!$comment) $comment = "Import";
        $customer = 1; // Ugly hack, FIXME Should be \Auth::user()->customer->id;
        foreach ($lines as $number => $line) {
            $email = trim(strtolower($line));
            $pieces = explode("@", $email);
            if (\Acelle\Library\Tool::isValidEmail($email)) {
                $blacklisted_contacts[] = "('".$email."',now(),now(),'".$comment."',$customer)";
            } elseif (isset($pieces[0])&&isset($pieces[1]) && strlen($pieces[0]) == 0 && strlen($pieces[1]) >0) {
                // only domains
                $blacklisted_domains[] = "('".$pieces[1]."',now(),now(),'".$comment."',$customer)";
            } elseif (isset($pieces[0])&&isset($pieces[1]) && strlen($pieces[1]) == 0 && strlen($pieces[0]) >0) {
                // only names
                $blacklisted_names[] = "('".$pieces[0]."',now(),now(),'".$comment."',$customer)";
            } elseif (substr($email, 0, 1) === '+') {
                // phone number, we wont do anything to it
                // $blacklisted_numbers[] = "";
            }
            // update status, finish one batch

        }


        // domains insert
        if (count($blacklisted_domains) > 0) {
            $partsd = array_chunk($blacklisted_domains, 500);
            $part_total = count($partsd);
            $part_cnt = 0;
            foreach ($partsd as $part => $value) {
                $part_cnt++;
                MailLog::info("Processing domain blacklist import part: $part_cnt of $part_total...");
                $sql = "INSERT IGNORE INTO blacklist_domains (domain,created_at,updated_at,reason,customer_id) VALUES ".implode( ',', $value );
                \DB::unprepared($sql);
            }

            $blacklist = \Acelle\Model\Blacklist_domains::getAll()->get();
            \Redis::del('blacklist_domains');
            foreach ($blacklist as $bl) {
                \Redis::hset('blacklist_domains',$bl->domain,$bl->reason);
            }

        }
        // names insert
        if (count($blacklisted_names) > 0) {
            $partsc = array_chunk($blacklisted_names, 500);
            $part_total = count($partsc);
            $part_cnt = 0;
            foreach ($partsc as $part => $value) {
                $part_cnt++;
                MailLog::info("Processing name blacklist import part: $part_cnt of $part_total...");
                $sql = "INSERT IGNORE INTO blacklist_names (name,created_at,updated_at,reason,customer_id) VALUES ".implode( ',', $value );
                \DB::unprepared($sql);
            }
            $blacklist = \Acelle\Model\Blacklist_names::getAll()->get();
            \Redis::del('blacklist_names');
            foreach ($blacklist as $bl) {
                \Redis::hset('blacklist_names',$bl->name,$bl->reason);
            }
        }




        if (count($blacklisted_contacts) > 0) {
            $parts = array_chunk($blacklisted_contacts, 500);
            $part_total = count($parts);
            // update status, line count
            $system_job->updateStatus([ 'total' => $part_total ]);
            $part_cnt = 0;
            foreach ($parts as $part => $value) {
                $part_cnt++;
                MailLog::info("Processing blacklist import part: $part_cnt of $part_total...");
                //\DB::table('blacklists')->insert($value);
                $sql = "INSERT IGNORE INTO blacklists (email,created_at,updated_at,reason,customer_id) VALUES ".implode( ',', $value );
                \DB::unprepared($sql);
         //       echo $sql ."\n";
       //         $new = array_merge(...array_map('array_values', $value));



               // \DB::select(\DB::raw("INSERT IGNORE INTO blacklists (".implode(',',array_keys($value)).") VALUES(implode(',',$value))"));
               // MailLog::info("SQL: INSERT IGNORE INTO blacklists (".implode(',',array_keys($value)).") VALUES(implode(',',$value))");
                //$query = 'INSERT IGNORE INTO blacklists (' . implode(',', array_keys($value)) . ') VALUES ' . $value;
                //\DB::insert($query, $value);



                $success++;
                $system_job->updateStatus([ 'processed' => $part+1 ]);
            }
        }
MailLog::info("Blacklist import just finished!");
        // Update status, finish all batches
        $system_job->updateStatus([ 'status' => self::IMPORT_STATUS_DONE ]);
    }


}
