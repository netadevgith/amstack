<?php
/* DNS helper class (c) 2018 justinas@eofnet.lt
*/
namespace Acelle\Library;

use Acelle\Library\Log as MailLog;
//use Aws\Ec2\Exception\Ec2Exception;


class UserAgentHelper
{
    public function get_operating_system($agent)
    {
        if (!empty($agent) ) {
            if ( stripos($agent, 'Firefox') !== false ) {
                $agent = 'Firefox';
            } elseif ( stripos($agent, 'MSIE') !== false ) {
                $agent = 'Internet Exploder';
            } elseif ( stripos($agent, 'iPad') !== false ) {
                $agent = 'iPad';
            } elseif ( stripos($agent, 'iPhone')) {
                $agent = 'iPhone';
            } elseif (stripos($agent,'Macintosh')) {
                $agent = 'Macintosh';
            } elseif ( stripos($agent, 'Android') !== false ) {
                $agent = 'Android';
            } elseif ( stripos($agent, 'Chrome') !== false ) {
                $agent = 'Chrome';
            } elseif ( stripos($agent, 'Safari') !== false ) {
                $agent = 'Safari';
            } elseif ( stripos($agent, 'AIR') !== false ) {
                $agent = 'air';
            } elseif ( stripos($agent, 'Fluid') !== false ) {
                $agent = 'fluid';
            } elseif ( stripos($agent, 'Outlook')) {
                $agent = 'Outlook';
            } else if ( stripos($agent, 'Windows')) {
                $agent = 'Windows';
            }

        }

        return $agent;

    }
    

}
