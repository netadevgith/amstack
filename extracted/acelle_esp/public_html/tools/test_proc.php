<?php

function processExists($processName) {
    $exists= false;
    exec("ps ux | grep $processName | grep -v grep", $pids);
    if (count($pids) > 0) {
        $exists = true;
    }
    return $exists;
}

while (processExists("5ad866a700150") == True) {
                    echo "Waiting for parent mailsender processed to finish in background and pausing any further operations\n";
                    sleep(2);
                }
echo "Done waiting\n";
