<?php
namespace Acelle\Helpers\MySQL;

use Closure;
use Exception;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Log;
use PDOException;

/**
 * Class DeadlockReadyMySqlConnection
 *
 * @package App\Helpers
 */
class DeadlockReadyMySqlConnection extends MySqlConnection
{
    /**
     * Error code of deadlock exception
     */
    const DEADLOCK_ERROR_CODE = 40001;

    /**
     * Number of attempts to retry
     */
    const ATTEMPTS_COUNT = 3;

    /**
     * Run a SQL statement.
     *
     * @param  string    $query
     * @param  array     $bindings
     * @param  \Closure  $callback
     * @return mixed
     *
     * @throws \Illuminate\Database\QueryException
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        $attempts_count = self::ATTEMPTS_COUNT;

        for ($attempt = 1; $attempt <= $attempts_count; $attempt++) {
            try {
                return $callback($this, $query, $bindings);
            } catch (Exception $e) {
                if (((int)$e->getCode() !== self::DEADLOCK_ERROR_CODE) || ($attempt >= $attempts_count)) {
                    throw new QueryException(
                        $query, $this->prepareBindings($bindings), $e
                    );
                } else {
                    $sql = str_replace_array('\?', $this->prepareBindings($bindings), $query);
                    Log::warning("Transaction has been restarted. Attempt {$attempt}/{$attempts_count}. SQL: {$sql}");
                }
            }
        }

    }
}