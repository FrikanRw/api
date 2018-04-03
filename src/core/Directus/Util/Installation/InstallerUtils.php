<?php

namespace Directus\Util\Installation;

use Directus\Application\Application;
use Directus\Bootstrap;
use Directus\Util\ArrayUtils;
use Directus\Util\StringUtils;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Zend\Db\TableGateway\TableGateway;

class InstallerUtils
{
    /**
     * Create a config and configuration file into $path
     * @param $data
     * @param $path
     */
    public static function createConfig($data, $path)
    {
        switch (ArrayUtils::get($data, 'db_type')) {
            case 'sqlite':
                $requiredAttributes = ['db_name'];
                break;
            case 'mysql':
            default:
                $requiredAttributes = ['db_host', 'db_name', 'db_user'];
                break;
        }

        if (!ArrayUtils::contains($data, $requiredAttributes)) {
            throw new \InvalidArgumentException(
                'Creating config files required: ' . implode(', ', $requiredAttributes)
            );
        }

        static::createConfigFile($data, $path);
    }

    public static function createConfigFileContent($data)
    {
        $configStub = file_get_contents(__DIR__ . '/stubs/config.stub');

        return static::replacePlaceholderValues($configStub, $data);
    }

    /**
     * Create config file into $path
     * @param array $data
     * @param string $path
     */
    protected static function createConfigFile(array $data, $path)
    {
        if (!isset($data['default_language'])) {
            $data['default_language'] = 'en';
        }

        $data = ArrayUtils::defaults([
            'directus_path' => '/',
            'directus_email' => 'root@localhost',
            'default_language' => 'en',
            'feedback_token' => sha1(gmdate('U') . StringUtils::randomString(32)),
            'feedback_login' => true
        ], $data);

        $configStub = static::createConfigFileContent($data);

        $configName = static::getConfigName(ArrayUtils::get($data, 'env', '_'));
        $configPath = rtrim($path, '/') . '/' . $configName . '.php';
        file_put_contents($configPath, $configStub);
    }

    /**
     * Replace placeholder wrapped by {{ }} with $data array
     * @param string $content
     * @param array $data
     * @return string
     */
    public static function replacePlaceholderValues($content, $data)
    {
        if (is_array($data)) {
            $data = ArrayUtils::dot($data);
        }

        $content = StringUtils::replacePlaceholder($content, $data);

        return $content;
    }

    /**
     * Create Directus Tables from Migrations
     *
     * @param string $directusPath
     * @param string $env
     *
     * @throws \Exception
     */
    public static function createTables($directusPath, $env = null)
    {
        $directusPath = rtrim($directusPath, '/');
        /**
         * Check if configuration files exists
         *
         * @throws \InvalidArgumentException
         */
        static::checkConfigurationFile($directusPath);

        $configPath = $directusPath . '/config';
        $configName = static::getConfigName($env);

        $apiConfig = require $configPath . '/' . $configName . '.php';
        $configArray = require $configPath . '/migrations.php';
        $configArray['paths']['migrations'] = $directusPath . '/migrations/db/schemas';
        $configArray['paths']['seeds'] = $directusPath . '/migrations/db/seeds';
        $configArray['environments']['development'] = [
            'adapter' => ArrayUtils::get($apiConfig, 'database.type'),
            'host' => ArrayUtils::get($apiConfig, 'database.host'),
            'port' => ArrayUtils::get($apiConfig, 'database.port'),
            'name' => ArrayUtils::get($apiConfig, 'database.name'),
            'user' => ArrayUtils::get($apiConfig, 'database.username'),
            'pass' => ArrayUtils::get($apiConfig, 'database.password'),
            'charset' => ArrayUtils::get($apiConfig, 'database.charset', 'utf8')
        ];
        $config = new Config($configArray);

        $manager = new Manager($config, new StringInput(''), new NullOutput());
        $manager->migrate('development');
        $manager->seed('development');
    }

    /**
     * Add Directus default settings
     *
     * @param array $data
     * @param string $directusPath
     * @param string $env
     *
     * @throws \Exception
     */
    public static function addDefaultSettings($data, $directusPath, $env = null)
    {
        $directusPath = rtrim($directusPath, '/');
        /**
         * Check if configuration files exists
         * @throws \InvalidArgumentException
         */
        static::checkConfigurationFile($directusPath);

        $configName = static::getConfigName($env);
        $app = new Application($directusPath, require $directusPath . '/config/' . $configName . '.php');
        $db = $app->getContainer()->get('database');

        $defaultSettings = static::getDefaultSettings($data);

        $tableGateway = new TableGateway('directus_settings', $db);
        foreach ($defaultSettings as $setting) {
            $tableGateway->insert($setting);
        }
    }

    /**
     * Add Directus default user
     *
     * @param array $data
     * @param string $env
     *
     * @return array
     */
    public static function addDefaultUser($data, $directusPath, $env = null)
    {
        $configName = static::getConfigName($env);
        $app = new Application($directusPath, require $directusPath . '/config/' . $configName . '.php');
        $db = $app->getContainer()->get('database');
        $tableGateway = new TableGateway('directus_users', $db);

        $hash = password_hash($data['directus_password'], PASSWORD_DEFAULT, ['cost' => 12]);

        if (!isset($data['directus_token'])) {
            $data['directus_token'] = StringUtils::randomString(32);
        }

        $tableGateway->insert([
            'status' => 1,
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => $data['directus_email'],
            'password' => $hash,
            'group' => 1,
            'token' => $data['directus_token'],
            'locale' => ArrayUtils::get($data, 'app.default_locale', 'en-US')
        ]);

        return $data;
    }

    /**
     * Check if the given name is schema template.
     * @param $name
     * @param $directusPath
     * @return bool
     */
    public static function schemaTemplateExists($name, $directusPath)
    {
        $directusPath = rtrim($directusPath, '/');
        $schemaTemplatePath = $directusPath . '/api/migrations/templates/' . $name;

        if (!file_exists($schemaTemplatePath)) {
            return false;
        }

        $isEmpty = count(array_diff(scandir($schemaTemplatePath), ['..', '.'])) > 0 ? false : true;
        if (is_readable($schemaTemplatePath) && !$isEmpty) {
            return true;
        }

        return false;
    }

    /**
     * Install the given schema template name
     *
     * @param $name
     * @param $directusPath
     *
     * @throws \Exception
     */
    public static function installSchema($name, $directusPath)
    {
        $directusPath = rtrim($directusPath, '/');
        $templatePath = $directusPath . '/api/migrations/templates/' . $name;
        $sqlImportPath = $templatePath . '/import.sql';

        if (file_exists($sqlImportPath)) {
            static::installSchemaFromSQL(file_get_contents($sqlImportPath));
        } else {
            static::installSchemaFromMigration($name, $directusPath);
        }
    }

    /**
     * Executes the template migration
     *
     * @param $name
     * @param $directusPath
     *
     * @throws \Exception
     */
    public static function installSchemaFromMigration($name, $directusPath)
    {
        $directusPath = rtrim($directusPath, '/');

        /**
         * Check if configuration files exists
         * @throws \InvalidArgumentException
         */
        static::checkConfigurationFile($directusPath);

        // TODO: Install schema templates
    }

    /**
     * Execute a sql query string
     *
     * NOTE: This is not recommended at all
     *       we are doing this because we are trained pro
     *       soon to be deprecated
     *
     * @param $sql
     *
     * @throws \Exception
     */
    public static function installSchemaFromSQL($sql)
    {
        $dbConnection = Bootstrap::get('ZendDb');

        $dbConnection->execute($sql);
    }

    /**
     * Returns the config name based on its environment
     *
     * @param $env
     *
     * @return string
     */
    public static function getConfigName($env)
    {
        $name = 'api';
        if ($env && $env !== '_') {
            $name = sprintf('api.%s', $env);
        }

        return $name;
    }

    /**
     * Get Directus default settings
     * @param $data
     * @return array
     */
    private static function getDefaultSettings($data)
    {
        return [
            [
                'scope' => 'global',
                'key' => 'cms_user_auto_sign_out',
                'value' => '60'
            ],
            [
                'scope' => 'global',
                'key' => 'project_name',
                'value' => $data['directus_name']
            ],
            [
                'scope' => 'global',
                'key' => 'project_url',
                'value' => get_url()
            ],
            [
                'scope' => 'global',
                'key' => 'rows_per_page',
                'value' => '200'
            ],
            [
                'scope' => 'files',
                'key' => 'thumbnail_quality',
                'value' => '100'
            ],
            [
                'scope' => 'files',
                'key' => 'thumbnail_size',
                'value' => '200'
            ],
            [
                'scope' => 'global',
                'key' => 'cms_thumbnail_url',
                'value' => ''
            ],
            [
                'scope' => 'files',
                'key' => 'file_naming',
                'value' => 'file_id'
            ],
            [
                'scope' => 'files',
                'key' => 'thumbnail_crop_enabled',
                'value' => '1'
            ],
            [
                'scope' => 'files',
                'key' => 'youtube_api_key',
                'value' => ''
            ]
        ];
    }

    /**
     * Check if config and configuration file exists
     * @param $directusPath
     * @throws \Exception
     */
    private static function checkConfigurationFile($directusPath)
    {
        $directusPath = rtrim($directusPath, '/');
        if (!file_exists($directusPath . '/config/api.php')) {
            throw new \Exception('Config file does not exists, run [directus config]');
        }

        if (!file_exists($directusPath . '/config/migrations.php')) {
            throw new \Exception('Migration configuration file does not exists');
        }
    }
}
