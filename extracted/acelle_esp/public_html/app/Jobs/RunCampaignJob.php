<?php

namespace Acelle\Jobs;

use Acelle\Library\Log as MailLog;

class RunCampaignJob extends CampaignJob
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
     //   MailLog::info("123456789");
        // This line must go after the constructor
        $this->linkJobToCampaign();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function linkJobToCampaign()
    {
        try {
            $systemJob = $this->getSystemJob();
            $systemJob->data = $this->campaign->id;
            $systemJob->save();
        } catch (\Exception $ex) {
            MailLog::error('Problem in campaign queue job: '.$ex);
            MailLog::error('This campaign has uid:' .$this->campaign->uid);
        }
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // start campaign
     //   MailLog::info("run campaign");
        $this->campaign->start();
    }
}
