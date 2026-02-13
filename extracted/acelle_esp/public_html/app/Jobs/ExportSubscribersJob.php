<?php

namespace Acelle\Jobs;

use Acelle\Jobs\Job;
use Acelle\Library\Log as MailLog;

class ExportSubscribersJob extends ImportExportJob
{
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($mailList, $customer)
    {
        // call parent's constructor
        parent::__construct($mailList, $customer);
     //   MailLog::info("1234");
        
        $systemJob = $this->getSystemJob();
        // set failed
        $systemJob->data = json_encode([
            "mail_list_uid" => $mailList->uid,
            "status" => "new",
            "message" => trans('messages.starting'),
            "total" => 0,
            "success" => 0,
            "error" => 0,
            "percent" => 0
        ]);
        $systemJob->save();
    }
    
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $job = $this;
            $status =json_decode($this->getSystemJob()->data)->status;
            if ($status != "running") {
                \Acelle\Model\MailList::export($job->mailList, $job->customer, $job);
            }  else {
        MailLog::info("Export job is already running!");
    }
        } catch (\Exception $e) {
            $systemJob = $this->getSystemJob();
        
            // set failed
            $systemJob->data = json_encode([
                "mail_list_uid" => $job->mailList->uid,
                "status" => "failed",
                "message" => $e->getMessage(),
                "total" => 0,
                "success" => 0,
                "error" => 0,
                "percent" => 0
            ]);
            $systemJob->save();
        }
    }
}
