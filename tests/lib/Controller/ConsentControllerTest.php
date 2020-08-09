<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\consent\Controller;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Auth\State;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Logger;
use SimpleSAML\Module\consent\Controller;
use SimpleSAML\Session;
use SimpleSAML\Utils;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set of tests for the controllers in the "consent" module.
 *
 * @package SimpleSAML\Test
 */
class ConsentTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected $config;

    /** @var \SimpleSAML\Session */
    protected $session;


    /**
     * Set up for each test.
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => ['consent' => true],
                'secretsalt' => 'abc123'
            ],
            '[ARRAY]',
            'simplesaml'
        );

        $this->session = Session::getSessionFromRequest();

        $this->logger = new class () extends Logger {
            public static function info(string $str): void
            {
                // do nothing
            }
        };
    }


    /**
     * @return void
    public function testGetconsent(): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/getconsent';
        $request = Request::create(
            '/getconsent',
            'GET'
        );

        $c = new Controller\ConsentController($this->config, $this->session);
        $c->setAuthState(new class () extends State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                ];
            }
        });
        $response = $c->getconsent($request);

        $this->assertTrue($response->isSuccessful());
    }
     */


    /**
     * @return void
    public function testNoconsent(): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/noconsent';
        $request = Request::create(
            '/noconsent',
            'GET'
        );

        $c = new Controller\ConsentController($this->config, $this->session);
        $c->setAuthState(new class () extends State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                ];
            }
        });
        $response = $c->noconsent($request);

        $this->assertTrue($response->isSuccessful());
    }
     */


    /**
     * @return void
    public function testLogout(): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/logout';
        $request = Request::create(
            '/logout',
            'GET'
        );

        $c = new Controller\ConsentController($this->config, $this->session);
        $c->setAuthState(new class () extends State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                ];
            }
        });
        $response = $c->logout($request);

        $this->assertTrue($response->isSuccessful());
    }
     */


    /**
     * @return void
     */
    public function testLogoutcompleted(): void
    {
        $_SERVER['REQUEST_URI'] = '/module.php/consent/logoutcompleted';
        $request = Request::create(
            '/logoutcompleted',
            'GET'
        );

        $c = new Controller\ConsentController($this->config, $this->session);
        $response = $c->logoutcompleted($request);

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

        $c = new Controller\ConsentController($this->config, $this->session);
        $c->setLogger($this->logger);

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

        $c = new Controller\ConsentController($this->config, $this->session);
        $c->setAuthState(new class () extends State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return null;
            }
        });
        $c->setLogger($this->logger);

        $this->expectException(Error\NoState::class);
        $this->expectExceptionMessage('NOSTATE');

        call_user_func([$c, $controller], $request);
    }


    /**
     * @return array
     */
    public function stateTestsProvider(): array
    {
        return [
            ['getconsent'],
            ['noconsent'],
            ['logout'],
        ];
    }
}
