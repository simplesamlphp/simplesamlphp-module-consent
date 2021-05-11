<?php

declare(strict_types=1);

namespace SimpleSAML\Module\consent\Consent\Store;

use Exception;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Logger;
use SimpleSAML\Utils;

/**
 * Cookie storage for consent
 *
 * This class implements a consent store which stores the consent information in cookies on the users computer.
 *
 * Example - Consent module with cookie store:
 *
 * <code>
 * 'authproc' => array(
 *   array(
 *     'consent:Consent',
 *     'store' => 'consent:Cookie',
 *     ),
 *   ),
 * </code>
 *
 * @package SimpleSAMLphp
 */

class Cookie extends \SimpleSAML\Module\consent\Store
{
    /**
     * Check for consent.
     *
     * This function checks whether a given user has authorized the release of the attributes identified by
     * $attributeSet from $source to $destination.
     *
     * @param string $userId        The hash identifying the user at an IdP.
     * @param string $destinationId A string which identifies the destination.
     * @param string $attributeSet  A hash which identifies the attributes.
     *
     * @return bool True if the user has given consent earlier, false if not (or on error).
     */
    public function hasConsent(string $userId, string $destinationId, string $attributeSet): bool
    {
        $cookieName = self::getCookieName($userId, $destinationId);

        $data = $userId . ':' . $attributeSet . ':' . $destinationId;

        Logger::debug('Consent cookie - Get [' . $data . ']');

        if (!array_key_exists($cookieName, $_COOKIE)) {
            Logger::debug(
                'Consent cookie - no cookie with name \'' . $cookieName . '\'.'
            );
            return false;
        }
        if (!is_string($_COOKIE[$cookieName])) {
            Logger::warning(
                'Value of consent cookie wasn\'t a string. Was: ' .
                var_export($_COOKIE[$cookieName], true)
            );
            return false;
        }

        $data = self::sign($data);

        if ($_COOKIE[$cookieName] !== $data) {
            Logger::info(
                'Attribute set changed from the last time consent was given.'
            );
            return false;
        }

        Logger::debug(
            'Consent cookie - found cookie with correct name and value.'
        );

        return true;
    }


    /**
     * Save consent.
     *
     * Called when the user asks for the consent to be saved. If consent information for the given user and destination
     * already exists, it should be overwritten.
     *
     * @param string $userId        The hash identifying the user at an IdP.
     * @param string $destinationId A string which identifies the destination.
     * @param string $attributeSet  A hash which identifies the attributes.
     *
     * @return bool
     */
    public function saveConsent(string $userId, string $destinationId, string $attributeSet): bool
    {
        $name = self::getCookieName($userId, $destinationId);
        $value = $userId . ':' . $attributeSet . ':' . $destinationId;

        Logger::debug('Consent cookie - Set [' . $value . ']');

        $value = self::sign($value);
        return $this->setConsentCookie($name, $value);
    }


    /**
     * Delete consent.
     *
     * Called when a user revokes consent for a given destination.
     *
     * @param string $userId        The hash identifying the user at an IdP.
     * @param string $destinationId A string which identifies the destination.
     *
     */
    public function deleteConsent(string $userId, string $destinationId): void
    {
        $name = self::getCookieName($userId, $destinationId);
        $this->setConsentCookie($name, null);
    }


    /**
     * Delete consent.
     *
     * @param string $userId The hash identifying the user at an IdP.
     *
     *
     * @throws \Exception This method always throws an exception indicating that it is not possible to delete all given
     * consents with this handler.
     */
    public function deleteAllConsents(string $userId): void
    {
        throw new Exception(
            'The cookie consent handler does not support delete of all consents...'
        );
    }


    /**
     * Retrieve consents.
     *
     * This function should return a list of consents the user has saved.
     *
     * @param string $userId The hash identifying the user at an IdP.
     *
     * @return array Array of all destination ids the user has given consent for.
     */
    public function getConsents(string $userId): array
    {
        $ret = [];

        $cookieNameStart = '\SimpleSAML\Module\consent:';
        $cookieNameStartLen = strlen($cookieNameStart);
        foreach ($_COOKIE as $name => $value) {
            if (substr($name, 0, $cookieNameStartLen) !== $cookieNameStart) {
                continue;
            }

            $value = self::verify($value);
            if ($value === false) {
                continue;
            }

            $tmp = explode(':', $value, 3);
            if (count($tmp) !== 3) {
                Logger::warning(
                    'Consent cookie with invalid value: ' . $value
                );
                continue;
            }

            if ($userId !== $tmp[0]) {
                // Wrong user
                continue;
            }

            $destination = $tmp[2];
            $ret[] = $destination;
        }

        return $ret;
    }


    /**
     * Calculate a signature of some data.
     *
     * This function calculates a signature of the data.
     *
     * @param string $data The data which should be signed.
     *
     * @return string The signed data.
     */
    private static function sign(string $data): string
    {
        $configUtils = new Utils\Config();
        $secretSalt = $configUtils->getSecretSalt();

        return sha1($secretSalt . $data . $secretSalt) . ':' . $data;
    }


    /**
     * Verify signed data.
     *
     * This function verifies signed data.
     *
     * @param string $signedData The data which is signed.
     *
     * @return string|false The data, or false if the signature is invalid.
     */
    private static function verify(string $signedData)
    {
        $data = explode(':', $signedData, 2);
        if (count($data) !== 2) {
            Logger::warning('Consent cookie: Missing signature.');
            return false;
        }
        $data = $data[1];

        $newSignedData = self::sign($data);
        if ($newSignedData !== $signedData) {
            Logger::warning('Consent cookie: Invalid signature.');
            return false;
        }

        return $data;
    }


    /**
     * Get cookie name.
     *
     * This function gets the cookie name for the given user & destination.
     *
     * @param string $userId        The hash identifying the user at an IdP.
     * @param string $destinationId A string which identifies the destination.
     *
     * @return string The cookie name
     */
    private static function getCookieName(string $userId, string $destinationId): string
    {
        return '\SimpleSAML\Module\consent:' . sha1($userId . ':' . $destinationId);
    }


    /**
     * Helper function for setting a cookie.
     *
     * @param string      $name  Name of the cookie.
     * @param string|null $value Value of the cookie. Set this to null to delete the cookie.
     *
     * @return bool
     */
    private function setConsentCookie(string $name, ?string $value): bool
    {
        $globalConfig = Configuration::getInstance();
        $httpUtils = new Utils\HTTP();
        $params = [
            'lifetime' => 7776000, // (90*24*60*60)
            'path' => ($globalConfig->getBasePath()),
            'httponly' => true,
            'secure' => $httpUtils->isHTTPS(),
        ];

        try {
            $httpUtils->setCookie($name, $value, $params, false);
            return true;
        } catch (Error\CannotSetCookie $e) {
            return false;
        }
    }
}
