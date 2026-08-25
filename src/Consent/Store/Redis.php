<?php

declare(strict_types=1);

namespace SimpleSAML\Module\consent\Consent\Store;

use Exception;
use Predis\Client;
use SimpleSAML\Assert\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Logger;

use function count;

/**
 * Store consent in Redis.
 *
 * This class implements a consent store which stores the consent information in
 * Redis. It follows the configuration model used by the main SimpleSAMLphp Redis
 * store, but has keys scoped to the consent module and supports optionally using
 * a dedicated Redis configuration for this backend.
 *
 * Options are the same as for the main SimpleSAMLphp Redis store (without the
 * 'store.redis' prefix), with the following local options:
 * - inheritGlobal: Whether to inherit configuration from the global SimpleSAMLphp
 *   Redis store. Optional, defaults to true.
 *
 * @package SimpleSAMLphp
 */
class Redis extends \SimpleSAML\Module\consent\Store
{
    /**
     * Redis server hostname.
     */
    private string $host;

    /**
     * Redis server port.
     */
    private int $port;

    /**
     * Redis database number.
     */
    private int $database;

    /**
     * Redis key prefix.
     */
    private string $prefix;

    /**
     * Redis username (ACL mode).
     */
    private ?string $username = null;

    /**
     * Redis password.
     */
    private ?string $password = null;

    /**
     * Connect over TLS. Array of TLS context options.
     * @var array<string, mixed>
     */
    private array $tls = [];

    /**
     * Optional list of sentinel endpoints.
     *
     * @var string[]
     */
    private array $sentinels = [];

    /**
     * Sentinel master group.
     */
    private ?string $masterGroup = null;

    /**
     * Optional consent lifetime in seconds.
     */
    private ?int $lifetime = null;

    /**
     * Redis client handle.
     */
    private ?Client $redis = null;


    /**
     * Parse configuration.
     *
     * @param array<mixed> $config Configuration for Redis consent store.
     *
     * @throws \Exception in case of a configuration error.
     */
    public function __construct(array $config = [], ?Client $redis = null)
    {
        parent::__construct($config);
        Assert::isArray($config, 'consent:Redis - Configuration should be an array.');
        Assert::classExists(Client::class, 'consent:Redis - predis/predis is not available.');

        $globalConfig = Configuration::getInstance();
        $cfg = Configuration::loadFromArray($config, 'consent:Consent');
        $inheritGlobal = $cfg->getOptionalBoolean('inheritGlobal', true,);

        /**
         * anonymous helper function to resolve configuration values with optional inheritance from global config.
         */
        $resolveOption = function (
            string $key,
            mixed $default,
        ) use (
            $globalConfig,
            $cfg,
            $inheritGlobal,
        ) {
            if ($cfg->hasValue($key)) {
                return $cfg->getOptionalValue($key, $default);
            }

            $globalKey = 'store.redis.' . $key;
            if ($inheritGlobal && $globalConfig->hasValue($globalKey)) {
                return $globalConfig->getOptionalValue($globalKey, $default);
            }

            return $default;
        };

        $this->host = $resolveOption('host', 'localhost');
        $this->port = $resolveOption('port', 6379);
        $this->prefix = $resolveOption('prefix', 'SimpleSAMLphp');
        $this->password = $resolveOption('password', null);
        $this->username = $resolveOption('username', null);
        $this->database = $resolveOption('database', 0);
        $tls = $resolveOption('tls', false);
        $this->sentinels = $resolveOption('sentinels', []);

        /* a maximum lifetime for consent can be configured, after which the consent will be automatically deleted */
        if ($cfg->hasValue('lifetime')) {
            $lifetime = $cfg->getOptionalInteger('lifetime', 0);
            Assert::greaterThan(
                $lifetime,
                0,
                'consent:Redis - "lifetime" must be a positive integer when configured.',
            );
            $this->lifetime = $lifetime;
        }

        if ($tls) {
            if ($resolveOption('insecure', false) === true) {
                $this->tls['verify_peer'] = false;
                $this->tls['verify_peer_name'] = false;
            } else {
                $ca = $resolveOption('ca_certificate', null);
                if ($ca !== null) {
                    $this->tls['cafile'] = $ca;
                }
            }

            $key = $resolveOption('privatekey', null);
            $cert = $resolveOption('certificate', null);
            if ($cert !== null && $key !== null) {
                $this->tls['local_cert'] = $cert;
                $this->tls['local_pk'] = $key;
            }
        }

        if (!empty($this->sentinels)) {
            $this->masterGroup = $resolveOption('mastergroup', 'mymaster');
        }

        if ($redis !== null) {
            $this->redis = $redis;
        }
    }


    /**
     * Called before serialization.
     *
     * @return string[] The variables which should be serialized.
     */
    public function __sleep(): array
    {
        return [
            'host',
            'port',
            'database',
            'prefix',
            'username',
            'password',
            'tls',
            'sentinels',
            'masterGroup',
            'lifetime',
        ];
    }


    /**
     * Clean up the Redis connection when this object is destroyed.
     */
    public function __destruct()
    {
        if ($this->redis !== null) {
            $this->redis->disconnect();
        }
    }


    /**
     * Check for consent.
     *
     * @param string $userId        The hash identifying the user at an IdP.
     * @param string $destinationId A string which identifies the destination.
     * @param string $attributeSet  A hash which identifies the attributes.
     *
     * @return bool True if the user has given consent earlier, false if not.
     */
    public function hasConsent(string $userId, string $destinationId, string $attributeSet): bool
    {
        $storedAttributeSet = $this->getRedis()->hget($this->getUserKey($userId), $destinationId);
        if ($storedAttributeSet === null) {
            Logger::debug('consent:Redis - No consent found.');
            return false;
        }

        if ($storedAttributeSet === $attributeSet) {
            Logger::debug('consent:Redis - Consent found.');
            return true;
        }

        Logger::info('consent:Redis - Attribute set changed from the last time consent was given.');
        return false;
    }


    /**
     * Save consent.
     *
     * @param string $userId        The hash identifying the user at an IdP.
     * @param string $destinationId A string which identifies the destination.
     * @param string $attributeSet  A hash which identifies the attributes.
     *
     * @return bool True if consent is saved, false if it was updated.
     */
    public function saveConsent(string $userId, string $destinationId, string $attributeSet): bool
    {
        $userKey = $this->getUserKey($userId);
        $storedAttributeSet = $this->getRedis()->hget($userKey, $destinationId);

        if ($storedAttributeSet !== null && $storedAttributeSet === $attributeSet) {
            Logger::debug('consent:Redis - Consent already stored.');
            return true;
        }

        try {
            $result = $this->getRedis()->hset($userKey, $destinationId, $attributeSet);
            if ($result) {
                Logger::debug('consent:Redis - Saved new consent.');
            } else {
                Logger::debug('consent:Redis - Updated old consent.');
            }

            if ($this->lifetime !== null) {
                $this->getRedis()->expire($userKey, $this->lifetime);
            }

            return true;
        } catch (Exception $e) {
            Logger::error('consent:Redis - Failed to save consent: ' . $e->getMessage());
            return false;
        }
    }


    /**
     * Delete consent.
     *
     * Called when a user revokes consent for a given destination.
     *
     * @param string $userId        The hash identifying the user at an IdP.
     * @param string $destinationId A string which identifies the destination.
     *
     * @return int Number of consents deleted.
     */
    public function deleteConsent(string $userId, string $destinationId): int
    {
        try {
            $deleted = $this->getRedis()->hdel($this->getUserKey($userId), [$destinationId]);
        } catch (\Exception $e) {
            Logger::error('consent:Redis - Failed to delete consent: ' . $e->getMessage());
            return 0;
        }

        if ($deleted > 0) {
            Logger::debug('consent:Redis - Deleted consent.');
            return $deleted;
        }

        Logger::warning('consent:Redis - Attempted to delete nonexistent consent');
        return 0;
    }


    /**
     * Delete all consents for a user.
     *
     * @param string $userId The hash identifying the user at an IdP.
     *
     * @return int Number of consents deleted.
     */
    public function deleteAllConsents(string $userId): int
    {
        $userKey = $this->getUserKey($userId);
        $count = $this->getRedis()->hlen($userKey);

        if ($count === 0) {
            Logger::warning('consent:Redis - Attempted to delete nonexistent consent');
            return 0;
        }

        try {
            $deleted = $this->getRedis()->del($userKey);
        } catch (\Exception $e) {
            Logger::error('consent:Redis - Failed to delete consent(s): ' . $e->getMessage());
            return 0;
        }
        if ($deleted === 0) {
            Logger::warning('consent:Redis - Failed to delete consent(s).');
            return 0;
        }

        Logger::debug('consent:Redis - Deleted (' . $count . ') consent(s).');
        return $count;
    }


    /**
     * Retrieve consents.
     *
     * @param string $userId The hash identifying the user at an IdP.
     *
     * @return string[] Array of all destination ids the user has given consent for.
     */
    public function getConsents(string $userId): array
    {
        $key = $this->getUserKey($userId);
        if (!$this->getRedis()->exists($key)) {
            return [];
        }

        return $this->getRedis()->hkeys($key);
    }


    /**
     * Get statistics for all consent given in the consent store.
     *
     * @return array<mixed> Statistics from the consent store.
     */
    public function getStatistics(): array
    {
        $redisKeys = $this->getRedis()->keys($this->getKeyPattern());
        $ret = [
            'total' => 0,
            'users' => count($redisKeys),
            'services' => 0,
        ];

        foreach ($redisKeys as $key) {
            $consents = $this->getRedis()->hkeys($key);
            $ret['total'] += count($consents);
            foreach ($consents as $destination) {
                $ret['services'] = max($ret['services'], 0);
                $ret['services']++;
            }
        }

        $uniqueServices = [];
        foreach ($redisKeys as $key) {
            foreach ($this->getRedis()->hkeys($key) as $destination) {
                $uniqueServices[$destination] = true;
            }
        }
        $ret['services'] = count($uniqueServices);

        return $ret;
    }


    /**
     * Get a connected Redis client, initializing lazily when needed.
     *
     * @return \Predis\Client The configured client.
     */
    private function getRedis(): Client
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        $connection = [
            'scheme' => empty($this->tls) ? 'tcp' : 'tls',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
        ];

        if (!empty($this->tls)) {
            $connection['ssl'] = $this->tls;
        }
        if ($this->username !== null && $this->username !== '') {
            $connection['username'] = $this->username;
        }
        if ($this->password !== null && $this->password !== '') {
            $connection['password'] = $this->password;
        }

        if (empty($this->sentinels)) {
            // single redis instance
            $this->redis = new Client(
                $connection,
                ['prefix' => $this->prefix],
            );
        } else {
            // redis sentinel setup
            $this->redis = new Client(
                $this->sentinels,
                [
                    'replication' => 'sentinel',
                    'service' => $this->masterGroup,
                    'prefix' => $this->prefix,
                    'parameters' => [
                        'scheme' => empty($this->tls) ? 'tcp' : 'tls',
                        'database' => $this->database,
                    ] + (empty($this->tls) ? [] : ['ssl' => $this->tls])
                      + ($this->username !== null && $this->username !== '' ? ['username' => $this->username] : [])
                      + ($this->password !== null && $this->password !== '' ? ['password' => $this->password] : []),
                ],
            );
        }
        return $this->redis;
    }


    /**
     * The key used for consent data for a specific user.
     *
     * @param string $userId The hash identifying the user at an IdP.
     *
     * @return string Redis key.
     */
    private function getUserKey(string $userId): string
    {
        return 'consent:' . $userId;
    }


    /**
     * Pattern used to list all consent hashes.
     *
     * @return string Redis key glob.
     */
    private function getKeyPattern(): string
    {
        return 'consent:*';
    }
}
