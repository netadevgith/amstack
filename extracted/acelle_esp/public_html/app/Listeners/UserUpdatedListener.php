<?php

namespace Acelle\Listeners;

use Acelle\Events\UserUpdated;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Acelle\Model\SystemJob as SystemJobModel;
use Acelle\Library\Log as MailLog;

class UserUpdatedListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  UserUpdated  $event
     * @return void
     */
    public function handle(UserUpdated $event)
    {
        if ($event->delayed) {
            $existed = SystemJobModel::getNewJobs()
                           ->where('name', \Acelle\Jobs\UpdateUserJob::class)
                           ->where('data', $event->customer->id)
                           ->exists();
            if (!$existed) {
                //dispatch(new \Acelle\Jobs\UpdateUserJob($event->customer));
                MailLog::info("FIXME UPDATE USER CACHE HAVE BEEN TEMP DISABLED, look at app/Listeners/UserUpdatedListener.php:handle()");
            }
        } else {
            //$event->customer->updateCache();
            MailLog::info("FIXME UPDATE USER CACHE HAVE BEEN TEMP DISABLED, look at app/Listeners/UserUpdatedListener.php:handle()");
        }
    }
}
