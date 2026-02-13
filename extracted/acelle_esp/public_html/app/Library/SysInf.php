<?php
/* Sysinf class (c) 2020 justinas@eofnet.lt
*/
namespace Acelle\Library;

use Acelle\Library\Log as MailLog;
use Redis;


class SysInf
{
    const SYSTEMO_INFORMEROMO = 1;

    public function __construct()
    {

    }

    // Returns used memory (either in percent (without percent sign) or free and overall in bytes)
    private function getServerMemoryUsage($getPercentage=true)
    {
        $memoryTotal = null;
        $memoryFree = null;

        if (stristr(PHP_OS, "win")) {
            // Get total physical memory (this is in bytes)
            $cmd = "wmic ComputerSystem get TotalPhysicalMemory";
            @exec($cmd, $outputTotalPhysicalMemory);

            // Get free physical memory (this is in kibibytes!)
            $cmd = "wmic OS get FreePhysicalMemory";
            @exec($cmd, $outputFreePhysicalMemory);

            if ($outputTotalPhysicalMemory && $outputFreePhysicalMemory) {
                // Find total value
                foreach ($outputTotalPhysicalMemory as $line) {
                    if ($line && preg_match("/^[0-9]+\$/", $line)) {
                        $memoryTotal = $line;
                        break;
                    }
                }

                // Find free value
                foreach ($outputFreePhysicalMemory as $line) {
                    if ($line && preg_match("/^[0-9]+\$/", $line)) {
                        $memoryFree = $line;
                        $memoryFree *= 1024;  // convert from kibibytes to bytes
                        break;
                    }
                }
            }
        }
        else
        {
            if (is_readable("/proc/meminfo"))
            {
                $stats = @file_get_contents("/proc/meminfo");

                if ($stats !== false) {
                    // Separate lines
                    $stats = str_replace(array("\r\n", "\n\r", "\r"), "\n", $stats);
                    $stats = explode("\n", $stats);

                    // Separate values and find correct lines for total and free mem
                    foreach ($stats as $statLine) {
                        $statLineData = explode(":", trim($statLine));

                        //
                        // Extract size (TODO: It seems that (at least) the two values for total and free memory have the unit "kB" always. Is this correct?
                        //

                        // Total memory
                        if (count($statLineData) == 2 && trim($statLineData[0]) == "MemTotal") {
                            $memoryTotal = trim($statLineData[1]);
                            $memoryTotal = explode(" ", $memoryTotal);
                            $memoryTotal = $memoryTotal[0];
                            $memoryTotal *= 1024;  // convert from kibibytes to bytes
                        }

                        // Free memory
                        if (count($statLineData) == 2 && trim($statLineData[0]) == "MemFree") {
                            $memoryFree = trim($statLineData[1]);
                            $memoryFree = explode(" ", $memoryFree);
                            $memoryFree = $memoryFree[0];
                            $memoryFree *= 1024;  // convert from kibibytes to bytes
                        }
                    }
                }
            }
        }

        if (is_null($memoryTotal) || is_null($memoryFree)) {
            return null;
        } else {
            if ($getPercentage) {
                return (100 - ($memoryFree * 100 / $memoryTotal));
            } else {
                return array(
                    "total" => $memoryTotal,
                    "free" => $memoryFree,
                );
            }
        }
    }

   private function getNiceFileSize($bytes, $binaryPrefix=true) {
        if ($binaryPrefix) {
            $unit=array('B','KiB','MiB','GiB','TiB','PiB');
            if ($bytes==0) return '0 ' . $unit[0];
            return @round($bytes/pow(1024,($i=floor(log($bytes,1024)))),2) .' '. (isset($unit[$i]) ? $unit[$i] : 'B');
        } else {
            $unit=array('B','KB','MB','GB','TB','PB');
            if ($bytes==0) return '0 ' . $unit[0];
            return @round($bytes/pow(1000,($i=floor(log($bytes,1000)))),2) .' '. (isset($unit[$i]) ? $unit[$i] : 'B');
        }
    }

    private function _getServerLoadLinuxData()
    {
        if (is_readable("/proc/stat"))
        {
            $stats = @file_get_contents("/proc/stat");

            if ($stats !== false)
            {
                // Remove double spaces to make it easier to extract values with explode()
                $stats = preg_replace("/[[:blank:]]+/", " ", $stats);

                // Separate lines
                $stats = str_replace(array("\r\n", "\n\r", "\r"), "\n", $stats);
                $stats = explode("\n", $stats);

                // Separate values and find line for main CPU load
                foreach ($stats as $statLine)
                {
                    $statLineData = explode(" ", trim($statLine));

                    // Found!
                    if
                    (
                        (count($statLineData) >= 5) &&
                        ($statLineData[0] == "cpu")
                    )
                    {
                        return array(
                            $statLineData[1],
                            $statLineData[2],
                            $statLineData[3],
                            $statLineData[4],
                        );
                    }
                }
            }
        }

        return null;
    }

    // Returns server load in percent (just number, without percent sign)
    private function getServerLoad()
    {
        $load = null;

        if (stristr(PHP_OS, "win"))
        {
            $cmd = "wmic cpu get loadpercentage /all";
            @exec($cmd, $output);

            if ($output)
            {
                foreach ($output as $line)
                {
                    if ($line && preg_match("/^[0-9]+\$/", $line))
                    {
                        $load = $line;
                        break;
                    }
                }
            }
        }
        else
        {
            if (is_readable("/proc/stat"))
            {
                // Collect 2 samples - each with 1 second period
                // See: https://de.wikipedia.org/wiki/Load#Der_Load_Average_auf_Unix-Systemen
                $statData1 = $this->_getServerLoadLinuxData();
                sleep(1);
                $statData2 = $this->_getServerLoadLinuxData();

                if
                (
                    (!is_null($statData1)) &&
                    (!is_null($statData2))
                )
                {
                    // Get difference
                    $statData2[0] -= $statData1[0];
                    $statData2[1] -= $statData1[1];
                    $statData2[2] -= $statData1[2];
                    $statData2[3] -= $statData1[3];

                    // Sum up the 4 values for User, Nice, System and Idle and calculate
                    // the percentage of idle time (which is part of the 4 values!)
                    $cpuTime = $statData2[0] + $statData2[1] + $statData2[2] + $statData2[3];

                    // Invert percentage to get CPU time, not idle time
                    $load = 100 - ($statData2[3] * 100 / $cpuTime);
                }
            }
        }

        return $load;
    }

    private function convertSize( $bytes )
    {
        $sizes = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        for( $i = 0; $bytes >= 1024 && $i < ( count( $sizes ) -1 ); $bytes /= 1024, $i++ );
        return( round( $bytes, 2 ) . " " . $sizes[$i] );
    }

    public function DiskUsage() {
        // folder to check
        $dir = '/';

// get disk space free (in bytes)
        $disk_free = disk_free_space($dir);

// get disk space total (in bytes)
        $disk_total = disk_total_space($dir);

// calculate the disk space used (in bytes)
        $disk_used = $disk_total - $disk_free;

// percentage of disk used
        $disk_used_p = sprintf('%.2f',($disk_used / $disk_total) * 100);

// this function will convert bytes value to KB, MB, GB and TB


// format the disk sizes using the function (B, KB, MB, GB and TB)
        $disk_free = $this->convertSize($disk_free);
        $disk_used = $this->convertSize($disk_used);
        $disk_total = $this->convertSize($disk_total);

        return array($disk_total,$disk_used,$disk_used_p,$disk_free);

    }

    public function load_info() {
        $cpu = $this->getServerLoad();
        return $cpu;
    }

    public static function GetNiceInfo() {
     if (\Redis::exists("sysinfo")) {
         try {
             $json = json_decode(\Redis::get("sysinfo"));
             $info = array();
             $info[] = "Mem: ".round($json->mem->percent,2)."%";
             $info[] = "Load: ".round($json->load,2)."%";
             $info[] = "Disk: ".$json->disk->used_p."%";
         return $info;
         } catch (\Exception $ex) {
             MailLog::error("Unable to retrieve system info from redis sysinfo set");
         }
     } else {
         return "";
     }
    }



    public function memory_infosas()
    {
        // Memory usage: 4.55 GiB / 23.91 GiB (19.013557664178%)
        $memUsage = $this->getServerMemoryUsage(false);
        $memUsage_percent = $this->getServerMemoryUsage(true);

        return array($memUsage,$memUsage_percent);
//        echo sprintf("Memory usage: %s / %s (%s%%)",
//            getNiceFileSize($memUsage["total"] - $memUsage["free"]),
//            getNiceFileSize($memUsage["total"]),
//            getServerMemoryUsage(true)
//        );

    }

}