<?php

/**
 nustatymai class 2021.08.04
 */

namespace Acelle\Model;

use Illuminate\Database\Eloquent\Model;

class Nustatymai extends Model
{
    protected $table = 'nustatymai';
    /**
     * Get all items.
     *
     * @return collect
     */
    public static function getAll()
    {
        $settings = self::select('*')->get();
        foreach ($settings as $setting) {
            $result[$setting->pavad]['reiksm'] = $setting->reiksm;
        }
        return $result;
    }

    /**
     * Get all items.
     *
     * @return collect
     */
    public static function updateAll()
    {
        $settings = self::getAll();

        foreach ($settings as $key => $setting) {
            self::set($key, $setting['reiksm']);
        }
    }

    /**
     * Get setting.
     *
     * @return object
     */
    public static function get($name)
    {
        $setting = self::where('pavad', $name)->first();
        if (is_object($setting)) {
            return $setting->reiksm;
        } else {
            return NULL;
        }
    }

    /**
     * Set setting value.
     *
     * @return object
     */
    public static function set($name, $val)
    {
        $option = self::where('pavad', $name)->first();

        if (is_object($option)) {
            $option->reiksm = $val;
        } else {
            $option = new self();
            $option->pavad = $name;
            $option->reiksm = $val;
        }
        $option->save();
        return $option;
    }

    /**
     * Get setting rules.
     *
     * @return object
     */
    public static function rules()
    {
        $rules = [];
        $settings = self::getAll();

        foreach ($settings as $name => $setting) {
            if (!isset($setting['not_required'])) {
                $rules[$name] = 'required';
            }
        }

        return $rules;
    }


}
