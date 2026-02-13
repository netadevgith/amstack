<?php

namespace Acelle\Exceptions;

use Exception;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Database\DetectsLostConnections;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param \Exception $e
     */
    public function report(Exception $e)
    {
        parent::report($e);

        // avoid MySQL gone away error hack
        if ('cli' === php_sapi_name() && $e instanceof PDOException && $this->causedByLostConnection($e)) {
            sleep(1);
            try {
                DB::reconnect('connection_name');
            } catch (Exception $exceptionOnReConnect) {
            }
        }
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Exception               $e
     *
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $e)
    {
        //$error = $this->convertExceptionToResponse($e);
        //if (($request->ajax() || $request->wantsJson()) && $error->getStatusCode() == 500) {
        //    var_dump($e);
        //    return \Response::json($e->getMessage(), 422);
        //}
        $debug = config('app.debug');
        if ($debug == true) {
            return parent::render($request, $e);
        } else {
            return redirect('http://www.google.com');
        }
    }

    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Illuminate\Http\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        return redirect()->guest('login');
    }
}
