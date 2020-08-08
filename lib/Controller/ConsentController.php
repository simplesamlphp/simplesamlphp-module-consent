<?php

declare(strict_types=1);

namespace SimpleSAML\Module\consent\Controller;

use Exception;
use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\IdP;
use SimpleSAML\Locale\Translate;
use SimpleSAML\Logger;
use SimpleSAML\Module;
use SimpleSAML\Session;
use SimpleSAML\Stats;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller class for the consent module.
 *
 * This class serves the consent views available in the module.
 *
 * @package SimpleSAML\Module\consent
 */
class ConsentController
{
    /** @var \SimpleSAML\Configuration */
    protected $config;

    /** @var \SimpleSAML\Session */
    protected $session;


    /**
     * ConsentController constructor.
     *
     * @param \SimpleSAML\Configuration $config The configuration to use.
     * @param \SimpleSAML\Session $session The current user session.
     */
    public function __construct(Configuration $config, Session $session)
    {
        $this->config = $config;
        $this->session = $session;
    }



    /**
     * Display consent form.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\XHTML\Template
     */
    public function getconsent(Request $request): Template
    {
        session_cache_limiter('nocache');

        Logger::info('Consent - getconsent: Accessing consent interface');

        $stateId = $request->get('StateId', false);
        if ($stateId === false) {
            throw new Error\BadRequest('Missing required StateId query parameter.');
        }

        $state = Auth\State::loadState($stateId, 'consent:request');

        if (is_null($state)) {
            throw new Error\NoState();
        } elseif (array_key_exists('core:SP', $state)) {
            $spentityid = $state['core:SP'];
        } elseif (array_key_exists('saml:sp:State', $state)) {
            $spentityid = $state['saml:sp:State']['core:SP'];
        } else {
            $spentityid = 'UNKNOWN';
        }

        // The user has pressed the yes-button
        if (array_key_exists('yes', $_REQUEST)) {
            if (array_key_exists('saveconsent', $_REQUEST)) {
                Logger::stats('consentResponse remember');
            } else {
                Logger::stats('consentResponse rememberNot');
            }

            $statsInfo = [
                'remember' => array_key_exists('saveconsent', $_REQUEST),
            ];
            if (isset($state['Destination']['entityid'])) {
                $statsInfo['spEntityID'] = $state['Destination']['entityid'];
            }
            Stats::log('consent:accept', $statsInfo);

            if (
                array_key_exists('consent:store', $state)
                && array_key_exists('saveconsent', $_REQUEST)
                && $_REQUEST['saveconsent'] === '1'
            ) {
                // Save consent
                $store = $state['consent:store'];
                $userId = $state['consent:store.userId'];
                $targetedId = $state['consent:store.destination'];
                $attributeSet = $state['consent:store.attributeSet'];

                Logger::debug(
                    'Consent - saveConsent() : [' . $userId . '|' . $targetedId . '|' . $attributeSet . ']'
                );
                try {
                    $store->saveConsent($userId, $targetedId, $attributeSet);
                } catch (Exception $e) {
                    Logger::error('Consent: Error writing to storage: ' . $e->getMessage());
                }
            }

            Auth\ProcessingChain::resumeProcessing($state);
        }

        // Prepare attributes for presentation
        $attributes = $state['Attributes'];
        $noconsentattributes = $state['consent:noconsentattributes'];

        // Remove attributes that do not require consent
        foreach ($attributes as $attrkey => $attrval) {
            if (in_array($attrkey, $noconsentattributes, true)) {
                unset($attributes[$attrkey]);
            }
        }
        $para = [
            'attributes' => &$attributes
        ];

        // Reorder attributes according to attributepresentation hooks
        Module::callHooks('attributepresentation', $para);

        // Parse parameters
        if (array_key_exists('name', $state['Source'])) {
            $srcName = $state['Source']['name'];
        } elseif (array_key_exists('OrganizationDisplayName', $state['Source'])) {
            $srcName = $state['Source']['OrganizationDisplayName'];
        } else {
            $srcName = $state['Source']['entityid'];
        }

        if (array_key_exists('name', $state['Destination'])) {
            $dstName = $state['Destination']['name'];
        } elseif (array_key_exists('OrganizationDisplayName', $state['Destination'])) {
            $dstName = $state['Destination']['OrganizationDisplayName'];
        } else {
            $dstName = $state['Destination']['entityid'];
        }

        // Make, populate and layout consent form
        $t = new Template($this->config, 'consent:consentform.twig');
        $translator = $t->getTranslator();
        $t->data['srcMetadata'] = $state['Source'];
        $t->data['dstMetadata'] = $state['Destination'];
        $t->data['yesTarget'] = Module::getModuleURL('consent/getconsent');
        $t->data['yesData'] = ['StateId' => $stateId];
        $t->data['noTarget'] = Module::getModuleURL('consent/noconsent');
        $t->data['noData'] = ['StateId' => $stateId];
        $t->data['attributes'] = $attributes;
        $t->data['checked'] = $state['consent:checked'];
        $t->data['stateId'] = $stateId;

        $t->data['srcName'] = htmlspecialchars(is_array($srcName) ? $translator->getPreferredTranslation($srcName) : $srcName);
        $t->data['dstName'] = htmlspecialchars(is_array($dstName) ? $translator->getPreferredTranslation($dstName) : $dstName);

        if (array_key_exists('descr_purpose', $state['Destination'])) {
            $t->data['dstDesc'] = $translator->getPreferredTranslation(
                Utils\Arrays::arrayize(
                    $state['Destination']['descr_purpose'],
                    'en'
                )
            );
        }

        // Fetch privacypolicy
        if (
            array_key_exists('UIInfo', $state['Destination']) &&
            array_key_exists('PrivacyStatementURL', $state['Destination']['UIInfo']) &&
            (!empty($state['Destination']['UIInfo']['PrivacyStatementURL']))
        ) {
            $privacypolicy = reset($state['Destination']['UIInfo']['PrivacyStatementURL']);
        } elseif (
            array_key_exists('UIInfo', $state['Source']) &&
            array_key_exists('PrivacyStatementURL', $state['Source']['UIInfo']) &&
            (!empty($state['Source']['UIInfo']['PrivacyStatementURL']))
        ) {
            $privacypolicy = reset($state['Source']['UIInfo']['PrivacyStatementURL']);
        } else {
            $privacypolicy = false;
        }
        if ($privacypolicy !== false) {
            $privacypolicy = str_replace(
                '%SPENTITYID%',
                urlencode($spentityid),
                $privacypolicy
            );
        }
        $t->data['sppp'] = $privacypolicy;

        // Set focus element
        switch ($state['consent:focus']) {
            case 'yes':
                $t->data['autofocus'] = 'yesbutton';
                break;
            case 'no':
                $t->data['autofocus'] = 'nobutton';
                break;
            case null:
            default:
                break;
        }

        $t->data['usestorage'] = array_key_exists('consent:store', $state);

        if (array_key_exists('consent:hiddenAttributes', $state)) {
            $t->data['hiddenAttributes'] = $state['consent:hiddenAttributes'];
        } else {
            $t->data['hiddenAttributes'] = [];
        }

        return $t;
    }


    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\XHTML\Template
     */
    public function noconsent(Request $request): Template
    {
        $stateId = $request->get('StateId', false);
        if ($stateId === false) {
            throw new Error\BadRequest('Missing required StateId query parameter.');
        }

        /** @psalm-var array $state */
        $state = Auth\State::loadState($stateId, 'consent:request');

        $resumeFrom = Module::getModuleURL(
            'consent/getconsent',
            ['StateId' => $stateId]
        );

        $logoutLink = Module::getModuleURL(
            'consent/logout',
            ['StateId' => $stateId]
        );

        $aboutService = null;
        if (!isset($state['consent:showNoConsentAboutService']) || $state['consent:showNoConsentAboutService']) {
            if (isset($state['Destination']['url.about'])) {
                $aboutService = $state['Destination']['url.about'];
            }
        }

        $statsInfo = [];
        if (isset($state['Destination']['entityid'])) {
            $statsInfo['spEntityID'] = $state['Destination']['entityid'];
        }
        Stats::log('consent:reject', $statsInfo);

        if (array_key_exists('name', $state['Destination'])) {
            $dstName = $state['Destination']['name'];
        } elseif (array_key_exists('OrganizationDisplayName', $state['Destination'])) {
            $dstName = $state['Destination']['OrganizationDisplayName'];
        } else {
            $dstName = $state['Destination']['entityid'];
        }

        $t = new Template($this->config, 'consent:noconsent.twig');
        $translator = $t->getTranslator();
        $t->data['dstMetadata'] = $state['Destination'];
        $t->data['resumeFrom'] = $resumeFrom;
        $t->data['aboutService'] = $aboutService;
        $t->data['logoutLink'] = $logoutLink;
        $t->data['dstName'] = htmlspecialchars(is_array($dstName) ? $translator->getPreferredTranslation($dstName) : $dstName);
        return $t;
    }


    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\HTTP\RunnableResponse
     */
    public function logout(Request $request): RunnableResponse
    {
        $stateId = $request->get('StateId', false);
        if ($stateId === false) {
            throw new Error\BadRequest('Missing required StateId query parameter.');
        }

        $state = Auth\State::loadState($stateId, 'consent:request');

        $state['Responder'] = ['\SimpleSAML\Module\consent\Logout', 'postLogout'];

        $idp = IdP::getByState($state);
        return new RunnableResponse([$idp, 'handleLogoutRequest'], [$state, null]);
    }


    /**
     * @param \Symfony\Component\HttpFoundation\Request $request The current request.
     *
     * @return \SimpleSAML\XHTML\Template
     */
    public function logoutcompleted(Request $request): Template
    {
        return new Template($this->config, 'consent:logout_completed.twig');
    }
}
