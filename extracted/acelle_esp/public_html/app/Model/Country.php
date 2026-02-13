<?php

/**
 * Country class.
 *
 * Model class for countries
 *
 * LICENSE: This product includes software developed at
 * the Acelle Co., Ltd. (http://acellemail.com/).
 *
 * @category   MVC Model
 *
 * @author     N. Pham <n.pham@acellemail.com>
 * @author     L. Pham <l.pham@acellemail.com>
 * @copyright  Acelle Co., Ltd
 * @license    Acelle Co., Ltd
 *
 * @version    1.0
 *
 * @link       http://acellemail.com
 */

namespace Acelle\Model;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    /**
     * Get all languages.
     *
     * @return collect
     */
    public static function getAll()
    {
        return self::all();
    }

    /**
     * Get select options.
     *
     * @return array
     */
    public static function getSelectOptions()
    {
        $options = self::getAll()->map(function ($item) {
            return ['value' => $item->id, 'text' => $item->name];
        });

        return $options;
    }

    public static function getSpecOptions()
    {
        $options = self::getAll()->map(function ($item) {
            return ['value' => $item->id, 'text' => $item->name];
        });

        return $options;
    }

    public static function getFilterOptions()
    {
        $options = self::getAll()->map(function ($item) {
            return print '<option value="'.$item->id.'">'.$item->name.'</option>';
            //['value' => $item->id, 'text' => $item->name];
        });

        return $options;
    }

    /**
     * Get all countries.
     *
     * @return array
     */
    public static function countries()
    {   // we only need:
        // Australia, Netherlands, France, Finland, Netherlands, Norway, Sweden, Ireland, United Kingdom, Belgium, Sweden, Poland, Italy
        $countries = array();

        $countries[] = array('code' => 'AU', 'name' => 'Australia', 'd_code' => '+61');
        $countries[] = array('code' => 'AUO', 'name' => 'Australia openers', 'd_code' => '+61');
        $countries[] = array('code' => 'BE', 'name' => 'Belgium', 'd_code' => '+32');
        $countries[] = array('code' => 'BEO', 'name' => 'Belgium openers', 'd_code' => '+32');
        $countries[] = array('code' => 'FI', 'name' => 'Finland', 'd_code' => '+358');
        $countries[] = array('code' => 'FIO', 'name' => 'Finland openers', 'd_code' => '+358');
        $countries[] = array('code' => 'FR', 'name' => 'France', 'd_code' => '+33');
        $countries[] = array('code' => 'FRO', 'name' => 'France openers', 'd_code' => '+33');
        $countries[] = array('code' => 'DE', 'name' => 'Germany', 'd_code' => '+49');
        $countries[] = array('code' => 'DEO', 'name' => 'Germany openers', 'd_code' => '+49');
        $countries[] = array('code' => 'IT', 'name' => 'Italy', 'd_code' => '+39');
        $countries[] = array('code' => 'ITO', 'name' => 'Italy openers', 'd_code' => '+39');
        $countries[] = array('code' => 'NL', 'name' => 'Netherlands', 'd_code' => '+31');
        $countries[] = array('code' => 'NLO', 'name' => 'Netherlands openers', 'd_code' => '+31');
        $countries[] = array('code' => 'NO', 'name' => 'Norway', 'd_code' => '+47');
        $countries[] = array('code' => 'NOO', 'name' => 'Norway openers', 'd_code' => '+47');
        $countries[] = array('code' => 'PL', 'name' => 'Poland', 'd_code' => '+48');
        $countries[] = array('code' => 'PLO', 'name' => 'Poland openers', 'd_code' => '+48');
        $countries[] = array('code' => 'SE', 'name' => 'Sweden', 'd_code' => '+46');
        $countries[] = array('code' => 'SEO', 'name' => 'Sweden openers', 'd_code' => '+46');
        $countries[] = array('code' => 'GB', 'name' => 'United Kingdom', 'd_code' => '+44');
        $countries[] = array('code' => 'GBO', 'name' => 'United Kingdom openers', 'd_code' => '+44');
        $countries[] = array('code' => 'US', 'name' => 'United States', 'd_code' => '+1');
        $countries[] = array('code' => 'USO', 'name' => 'United States openers', 'd_code' => '+1');
        return $countries;
    }

    /**
     * Update all countries.
     *
     * @return array
     */
    public static function updateCountries()
    {
        foreach (self::countries() as $country) {
            $c = new self();
            $c->name = $country['name'];
            $c->code = $country['code'];
            $c->status = 'active';
            $c->save();
        }
    }
}
