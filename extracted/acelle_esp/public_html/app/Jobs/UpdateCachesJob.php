<?php

namespace Acelle\Jobs;

use Acelle\Library\Log as MailLog;

class UpdateCachesJob extends SystemJob
{
    protected $caches;

    /**
     * Create a new job instance.
     * @note: Parent constructors are not called implicitly if the child class defines a constructor.
     *        In order to run a parent constructor, a call to parent::__construct() within the child constructor is required.
     *
     * @return void
     */
    public function __construct($caches)
    {
        $this->caches = $caches;
        parent::__construct();
        //$this->linkJobToAutomation();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        foreach($this->caches as $cache) {
        MailLog::info("Doing background cache update for: ".$cache);

        }
    }
}
