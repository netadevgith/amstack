<?php

namespace Acelle\Providers;

use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Validator;
use Acelle\Library\Log as MailLog;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        ini_set('memory_limit', '-1');
        ini_set('pcre.backtrack_limit', 1000000000);

       try {
           app('view')->composer('*', function ($view) {
                       if (!method_exists(app('request')->route(),'getAction')) {
                           MailLog::info("No such method found FIXME!!! at AppServiceProvider.php:24");
                           return;
                       }
                       if (app('request')->route()->getAction() === null) {
                           MailLog::info("Method return dummy null FIXME!!! at AppServiceProvider.php:28");
                           return;
                       }

                       $action = app('request')->route()->getAction();

                       if (is_null($action)) {
                       MailLog::info("yaya, it's erruoras");
                       return;
                       }
                       //if ($action !== null) {
                           $controller = class_basename($action['controller']);
                           list($controller, $action) = explode('@', $controller);

                           $view->with(compact('controller', 'action'));
                       //}


           });
      } catch (\Exception $ex) {
           MailLog::info("Just some kind of error, but maybe it's false positive on AppServiceProvider.php:27");
       }

        // extend substring validator
        Validator::extend('substring', function ($attribute, $value, $parameters, $validator) {
            $tag = $parameters[0];
            if (strpos($value, $tag) === false) {
                return false;
            }

            return true;
        });
        Validator::replacer('substring', function ($message, $attribute, $rule, $parameters) {
            return str_replace(':tag', $parameters[0], $message);
        });

        // License validator
        Validator::extend('license', function($attribute, $value, $parameters, $validator) {
            return $value == '' || true;
        });

        // License error validator
        Validator::extend('license_error', function($attribute, $value, $parameters, $validator) {
            return false;
        });
        Validator::replacer('license_error', function ($message, $attribute, $rule, $parameters) {
            return str_replace(':error', $parameters[0], $message);
        });
    }

    /**
     * Register any application services.
     */
    public function register()
    {

    }
}
