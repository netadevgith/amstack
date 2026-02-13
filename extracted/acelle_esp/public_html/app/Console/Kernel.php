<?php

namespace Acelle\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Acelle\Model\Automation;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //Commands\Inspire::class,
        // Commands\TestCampaign::class,
        // Commands\UpgradeTranslation::class,
        Commands\RunHandler::class,
        Commands\ImportList::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     */
    protected function schedule(Schedule $schedule)
    {
        // Automation
//        $schedule->call(function () {
//            Automation::run();
//        })->name('automation:run')->everyFiveMinutes();
//        $schedule->command("testas")->when(function () {
//            echo "test";
//            return true;
//        })->everyMinute()->sendOutputTo("koutput.log");

        // Bounce/feedback handler
        $schedule->command('handler:run')->everyMinute();

        // Queued import/export/campaign
        $schedule->command('queue:work --once')->everyMinute();
        // high queue
        //$schedule->command('queue:work --queue=high --once')->everyMinute();
    }
}
