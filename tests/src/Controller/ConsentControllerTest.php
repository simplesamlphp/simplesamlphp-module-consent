<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\consent\Controller;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Auth\State;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Logger;
use SimpleSAML\Module\consent\Controller;
use SimpleSAML\Module\consent\Consent\Store\Cookie;
use SimpleSAML\Session;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Set of tests for the controllers in the "consent" module.
 *
 * @package SimpleSAML\Test
 */
class ConsentControllerTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected static Configuration $config;

    /** @var \SimpleSAML\Logger */
    protected static Logger $logger;

    /** @var \SimpleSAML\Session */
    protected static Session $session;


    /**
     * Set up for each test.
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$config = Configuration::loadFromArray(
            [
                'module.enable' => ['consent' => true],
                'secretsalt' => 'abc123',
                'enable.saml20-idp' => true,
            ],
            '[ARRAY]',
            'simplesaml'
        );

        self::$session = Session::getSessionFromRequest();

        self::$logger = new class () extends Logger {
            public static function info(string $string): void
            {
                // do nothing
            }
        };
    }


    /**
     * @return void
     */
    public function testGetconsentAccept(): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/getconsent';
        $request = Request::create(
            '/getconsent',
            'GET',
            ['yes' => '', 'saveconsent' => '1', 'StateId' => 'someStateId']
        );

        $c = new Controller\ConsentController(self::$config, self::$session);
        $c->setAuthState(new class () extends State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'Destination' => [
                        'entityid' => 'urn:some:sp',
                    ],
                    'Source' => [
                        'entityid' => 'urn:some:idp'
                    ],
                    'Attributes' => ['uid' => 'jdoe'],
                    'consent:store.userId' => 'jdoe@example.org',
                    'consent:store.destination' => 'urn:some:sp',
                    'consent:store.attributeSet' => 'some hash',
                    'consent:store' => new class () extends Cookie {
                        public function __construct(array &$config = [])
                        {
                        }

                        public function saveConsent(string $userId, string $destinationId, string $attributeSet): bool
                        {
                            // stub
                            return true;
                        }
                    },
                ];
            }
        });
        $response = $c->getconsent($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }


    /**
     * @return void
     */
    public function testGetconsentDecline(): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/getconsent';
        $request = Request::create(
            '/getconsent',
            'GET',
            ['no' => '', 'StateId' => 'someStateId']
        );

        $c = new Controller\ConsentController(self::$config, self::$session);
        $c->setAuthState(new class () extends State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'StateId' => 'someStateId',
                    'Destination' => [
                        'name' => 'Some destination',
                        'descr_purpose' => 'All your base are belong to us',
                    ],
                    'Source' => [
                        'entityid' => 'urn:some:idp',
                        'UIInfo' => [
                            'PrivacyStatementURL' => ['https://example.org/privacy']
                        ]
                    ],
                    'Attributes' => ['uid' => 'jdoe', 'filteredAttribute' => 'this attribute should be filtered'],
                    'consent:noconsentattributes' => ['filteredAttribute'],
                    'consent:checked' => 'check',
                    'consent:focus' => 'yes',
                ];
            }
        });
        $response = $c->getconsent($request);
        $this->assertInstanceOf(Template::class, $response);
        $this->assertTrue($response->isSuccessful());
    }


    /**
     * @return void
     */
    public function testNoconsent(): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/noconsent';
        $request = Request::create(
            '/noconsent',
            'GET',
            ['StateId' => 'someStateId']
        );

        $c = new Controller\ConsentController(self::$config, self::$session);
        $c->setAuthState(new class () extends State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'consent:showNoConsentAboutService' => 'something that evals to true',
                    'Destination' => [
                        'entityid' => 'urn:some:sp',
                        'name' => 'Some destination',
                        'UIInfo' => [
                            'InformationURL' => ['https://example.org/about' ],
                        ],
                    ],
                ];
            }
        });
        $response = $c->noconsent($request);

        $this->assertTrue($response->isSuccessful());
    }


    /**
     * @return void
    public function testLogout(): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/logout';
        $request = Request::create(
            '/logout',
            'GET',
            ['StateId' => 'someStateId']
        );

        $c = new Controller\ConsentController(self::$config, self::$session);
        $c->setAuthState(new class () extends State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    'core:IdP' => 'saml2:something'
                ];
            }
        });
        $response = $c->logout($request);
        $this->assertInstanceOf(RunnableResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
    }
     */


    /**
     * @return void
     */
    public function testLogoutcompleted(): void
    {
        $c = new Controller\ConsentController(self::$config, self::$session);
        $response = $c->logoutcompleted();

        $this->assertTrue($response->isSuccessful());
    }


    /**
     * @dataProvider stateTestsProvider
     *
     * @param string $controller The name of the controller to test
     */
    public function testMissingStateId(string $controller): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/' . $controller;
        $request = Request::create(
            '/' . $controller,
            'GET'
        );

        $c = new Controller\ConsentController(self::$config, self::$session);
        $c->setLogger(self::$logger);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage('Missing required StateId query parameter.');

        call_user_func([$c, $controller], $request);
    }


    /**
     * @dataProvider stateTestsProvider
     *
     * @param string $controller The name of the controller to test
     */
    public function testNoState(string $controller): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/' . $controller;
        $request = Request::create(
            '/' . $controller,
            'GET',
            ['StateId' => 'someStateId']
        );

        $c = new Controller\ConsentController(self::$config, self::$session);
        $c->setLogger(self::$logger);

        $this->expectException(Error\NoState::class);
        $this->expectExceptionMessage('NOSTATE');

        call_user_func([$c, $controller], $request);
    }


    /**
     * @return array
     */
    public static function stateTestsProvider(): array
    {
        return [
            ['getconsent'],
            ['noconsent'],
            ['logout'],
        ];
    }
}
