<?php

namespace Acelle\Jobs;

use Acelle\Library\Log as MailLog;

class RetryCampagnSendJob extends SystemJob
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
        // restart all backround processes by campaign uids
        MailLog::info("Certain Campaigns are queued for RETRY SEND processing in the background");
        \Acelle\Model\Campaign::RetryBackGroundCampaign($this->campaign);
    }
}
