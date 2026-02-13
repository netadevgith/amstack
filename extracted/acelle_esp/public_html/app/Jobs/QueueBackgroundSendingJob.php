<?php

namespace Acelle\Jobs;

use Acelle\Library\Log as MailLog;

class QueueBackgroundSendingJob extends SystemJob
{
    protected $campaign;

    /**
     * Create a new job instance.
     * @note: Parent constructors are not called implicitly if the child class defines a constructor.
     *        In order to run a parent constructor, a call to parent::__construct() within the child constructor is required.
     * 
     * @return void
     */
    public function __construct($campaign)
    {
        $this->campaign = $campaign;
        parent::__construct();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
       // $sysJobMod = $this->getSystemJob();
      //  $status =json_decode($sysJobMod->data)->status;
      //  if ($status != "running") {
        MailLog::info("BACKGROUND QUEUE - Certain Campaigns are queued for QUEUE SEND in the background job: ".$this->campaign);
        $this->setDone();
        \Acelle\Model\Campaign::QueueBackgroundSending($this->campaign);

      //  } else {
         //   MailLog::info("BACKGROUND QUEUE - job is already running!");
       // }
    }
}
