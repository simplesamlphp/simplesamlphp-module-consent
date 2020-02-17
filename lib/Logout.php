<?php

namespace SimpleSAML\Module\consent;

use SimpleSAML\Module;
use SimpleSAML\Utils;

/**
 * Class defining the logout completed handler for the consent page.
 *
 * @package SimpleSAMLphp
 */

class Logout
{
    /**
     * @param \SimpleSAML\IdP $idp
     * @param array $state
     * @return void
     */
    public static function postLogout(\SimpleSAML\IdP $idp, array $state): void
    {
        $url = Module::getModuleURL('consent/logout_completed.php');
        Utils\HTTP::redirectTrustedURL($url);
    }
}
