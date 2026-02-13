<?php

namespace Acelle\Events;

use Acelle\Events\Event;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Acelle\Library\Log as MailLog;

class MailListUpdated extends Event
{
    use SerializesModels;

    public $mailList;
    public $delayed;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($mailList, $delayed = true)
    {
        MailLog::info("DEPRECATED Mail list cache update event is detected! Please remove any code presence of these messages!");
        $this->mailList = $mailList;
        $this->delayed = $delayed;
    }

    /**
     * Get the channels the event should be broadcast on.
     *
     * @return array
     */
    public function broadcastOn()
    {
        return [];
    }
}
