<?php

namespace Directus\Application;

use Cache\Adapter\Apc\ApcCachePool;
use Cache\Adapter\Apcu\ApcuCachePool;
use Cache\Adapter\Common\PhpCachePool;
use Cache\Adapter\Filesystem\FilesystemCachePool;
use Cache\Adapter\Memcached\MemcachedCachePool;
use Cache\Adapter\PHPArray\ArrayCachePool;
use Cache\Adapter\Redis\RedisCachePool;
use Cache\Adapter\Void\VoidCachePool;
use Directus\Application\ErrorHandlers\ErrorHandler;
use Directus\Authentication\FacebookProvider;
use Directus\Authentication\GitHubProvider;
use Directus\Authentication\GoogleProvider;
use Directus\Authentication\Provider;
use Directus\Authentication\Social;
use Directus\Authentication\TwitterProvider;
use Directus\Authentication\User\Provider\UserTableGatewayProvider;
use Directus\Cache\Response;
use Directus\Config\StatusMapping;
use Directus\Database\Connection;
use Directus\Database\Exception\ConnectionFailedException;
use Directus\Database\Schema\Object\Field;
use Directus\Database\Schema\SchemaFactory;
use Directus\Database\Schema\SchemaManager;
use Directus\Database\Schema\Sources\SQLiteSchema;
use Directus\Database\TableGateway\BaseTableGateway;
use Directus\Database\TableGateway\DirectusPermissionsTableGateway;
use Directus\Database\TableGateway\DirectusSettingsTableGateway;
use Directus\Database\TableGateway\DirectusUsersTableGateway;
use Directus\Database\TableGateway\RelationalTableGateway;
use Directus\Database\SchemaService;
use Directus\Embed\EmbedManager;
use Directus\Exception\ForbiddenException;
use Directus\Exception\RuntimeException;
use Directus\Filesystem\Files;
use Directus\Filesystem\Filesystem;
use Directus\Filesystem\FilesystemFactory;
use Directus\Filesystem\Thumbnail;
use Directus\Hash\HashManager;
use Directus\Hook\Emitter;
use Directus\Hook\Payload;
use Directus\Mail\Adapters\AbstractMailerAdapter;
use Directus\Mail\Adapters\SimpleFileMailAdapter;
use Directus\Mail\Adapters\SwiftMailerAdapter;
use Directus\Mail\MailerManager;
use Directus\Permissions\Acl;
use Directus\Services\AuthService;
use Directus\Session\Session;
use Directus\Session\Storage\NativeSessionStorage;
use Directus\Util\ArrayUtils;
use Directus\Util\DateUtils;
use Directus\Util\StringUtils;
use Directus\View\Twig\DirectusTwigExtension;
use League\Flysystem\Adapter\Local;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Slim\Views\Twig;
use Zend\Db\TableGateway\TableGateway;

class CoreServicesProvider
{
    public function register($container)
    {
        $container['database']          = $this->getDatabase();
        $container['logger']            = $this->getLogger();
        $container['hook_emitter']      = $this->getEmitter();
        $container['auth']              = $this->getAuth();
        $container['external_auth']     = $this->getExternalAuth();
        // $container['session']           = $this->getSession();
        $container['acl']               = $this->getAcl();
        $container['errorHandler']      = $this->getErrorHandler();
        $container['phpErrorHandler']   = $this->getErrorHandler();
        $container['schema_adapter']    = $this->getSchemaAdapter();
        $container['schema_manager']    = $this->getSchemaManager();
        $container['schema_factory']    = $this->getSchemaFactory();
        $container['hash_manager']      = $this->getHashManager();
        $container['embed_manager']     = $this->getEmbedManager();
        $container['filesystem']        = $this->getFileSystem();
        $container['files']             = $this->getFiles();
        $container['mailer_manager']    = $this->getMailerManager();
        $container['mail_view']         = $this->getMailView();
        $container['app_settings']      = $this->getSettings();
        $container['status_mapping']    = $this->getStatusMapping();

        // Move this separately to avoid clogging one class
        $container['cache']             = $this->getCache();
        $container['response_cache']    = $this->getResponseCache();

        $container['services']          = $this->getServices($container);
    }

    /**
     * @return \Closure
     */
    protected function getLogger()
    {
        /**
         * @param Container $container
         * @return Logger
         */
        $logger = function ($container) {
            $logger = new Logger('app');
            $formatter = new LineFormatter();
            $formatter->allowInlineLineBreaks();
            $formatter->includeStacktraces();
            // TODO: Move log configuration outside "slim app" settings
            $path = $container->get('path_base') . '/logs';
            $config = $container->get('config');
            if ($config->has('settings.logger.path')) {
                $path = $config->get('settings.logger.path');
            }

            $handler = new StreamHandler(
                $path . '/debug.' . date('Y-m') . '.log',
                Logger::DEBUG,
                false
            );

            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);

            $handler = new StreamHandler(
                $path . '/error.' . date('Y-m') . '.log',
                Logger::CRITICAL,
                false
            );

            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);

            return $logger;
        };

        return $logger;
    }

    /**
     * @return \Closure
     */
    protected function getErrorHandler()
    {
        /**
         * @param Container $container
         *
         * @return ErrorHandler
         */
        $errorHandler = function (Container $container) {
            $hookEmitter = $container['hook_emitter'];
            return new ErrorHandler($hookEmitter);
        };

        return $errorHandler;
    }

    /**
     * @return \Closure
     */
    protected function getEmitter()
    {
        return function (Container $container) {
            $emitter = new Emitter();
            $cachePool = $container->get('cache');

            // TODO: Move this separately, this is temporary while we move things around
            $emitter->addFilter('load.relational.onetomany', function (Payload $payload) {
                $rows = $payload->getData();
                /** @var Field $column */
                $column = $payload->attribute('column');

                if ($column->getInterface() !== 'translation') {
                    return $payload;
                }

                $options = $column->getOptions();
                $code = ArrayUtils::get($options, 'languages_code_column', 'id');
                $languagesTable = ArrayUtils::get($options, 'languages_table');
                $languageIdColumn = ArrayUtils::get($options, 'left_column_name');

                if (!$languagesTable) {
                    throw new \Exception('Translations language table not defined for ' . $languageIdColumn);
                }

                $tableSchema = SchemaService::getCollection($languagesTable);
                $primaryKeyColumn = 'id';
                foreach($tableSchema->getColumns() as $column) {
                    if ($column->isPrimary()) {
                        $primaryKeyColumn = $column->getName();
                        break;
                    }
                }

                $newData = [];
                foreach($rows as $row) {
                    $index = $row[$languageIdColumn];
                    if (is_array($row[$languageIdColumn])) {
                        $index = $row[$languageIdColumn][$code];
                        $row[$languageIdColumn] = $row[$languageIdColumn][$primaryKeyColumn];
                    }

                    $newData[$index] = $row;
                }

                $payload->replace($newData);

                return $payload;
            }, $emitter::P_HIGH);

            // Cache subscriptions
            $emitter->addAction('postUpdate', function (RelationalTableGateway $gateway, $data) use ($cachePool) {
                if(isset($data[$gateway->primaryKeyFieldName])) {
                    $cachePool->invalidateTags(['entity_'.$gateway->getTable().'_'.$data[$gateway->primaryKeyFieldName]]);
                }
            });

            $cacheTableTagInvalidator = function ($tableName) use ($cachePool) {
                $cachePool->invalidateTags(['table_'.$tableName]);
            };

            foreach (['collection.update:after', 'collection.drop:after'] as $action) {
                $emitter->addAction($action, $cacheTableTagInvalidator);
            }

            $emitter->addAction('collection.delete:after', function ($tableName, $ids) use ($cachePool){
                foreach ($ids as $id) {
                    $cachePool->invalidateTags(['entity_'.$tableName.'_'.$id]);
                }
            });

            $emitter->addAction('collection.update.directus_permissions:after', function ($data) use($container, $cachePool) {
                $acl = $container->get('acl');
                $dbConnection = $container->get('database');
                $privileges = new DirectusPermissionsTableGateway($dbConnection, $acl);
                $record = $privileges->fetchById($data['id']);
                $cachePool->invalidateTags(['permissions_collection_'.$record['collection'].'_group_'.$record['group']]);
            });
            // /Cache subscriptions

            $emitter->addAction('application.error', function ($e) use($container) {
                /** @var \Throwable|\Exception $exception */
                $exception = $e;
                /** @var Logger $logger */
                $logger = $container->get('logger');

                $logger->error($exception->getMessage());
            });
            $emitter->addFilter('response', function (Payload $payload) use ($container) {
                /** @var Acl $acl */
                $acl = $container->get('acl');
                if ($acl->isPublic() || !$acl->getUserId()) {
                    $payload->set('public', true);
                }
                return $payload;
            });
            $emitter->addAction('collection.insert.directus_groups', function ($data) use ($container) {
                $acl = $container->get('acl');
                $zendDb = $container->get('database');
                $privilegesTable = new DirectusPermissionsTableGateway($zendDb, $acl);
                $privilegesTable->insertPrivilege([
                    'group' => $data['id'],
                    'collection' => 'directus_users',
                    'create' => 0,
                    'read' => 1,
                    'update' => 1,
                    'delete' => 0,
                    'read_field_blacklist' => 'token',
                    'write_field_blacklist' => 'group,token'
                ]);
            });
            $emitter->addFilter('collection.insert:before', function (Payload $payload) use ($container) {
                $collectionName = $payload->attribute('collection_name');
                $collection = SchemaService::getCollection($collectionName);
                /** @var Acl $acl */
                $acl = $container->get('acl');


                if ($dateCreated = $collection->getDateCreateField()) {
                    $payload[$dateCreated] = DateUtils::now();
                }

                if ($dateCreated = $collection->getDateUpdateField()) {
                    $payload[$dateCreated] = DateUtils::now();
                }

                // Directus Users created user are themselves (primary key)
                // populating that field will be a duplicated primary key violation
                if ($collection->getName() === 'directus_users') {
                    return $payload;
                }

                $userCreated = $collection->getUserCreateField();
                $userModified = $collection->getUserUpdateField();

                if ($userCreated) {
                    $payload[$userCreated->getName()] = $acl->getUserId();
                }

                if ($userModified) {
                    $payload[$userModified->getName()] = $acl->getUserId();
                }

                return $payload;
            }, Emitter::P_HIGH);
            $emitter->addFilter('collection.update:before', function (Payload $payload) use ($container) {
                $collection = SchemaService::getCollection($payload->attribute('collection_name'));
                /** @var Acl $acl */
                $acl = $container->get('acl');
                if ($dateModified = $collection->getDateUpdateField()) {
                    $payload[$dateModified] = DateUtils::now();
                }
                if ($userModified = $collection->getUserUpdateField()) {
                    $payload[$userModified] = $acl->getUserId();
                }
                // NOTE: exclude date_uploaded from updating a file record
                if ($collection->getName() === 'directus_files') {
                    $payload->remove('date_uploaded');
                }
                return $payload;
            }, Emitter::P_HIGH);
            $emitter->addFilter('collection.insert:before', function (Payload $payload) use ($container) {
                if ($payload->attribute('collection_name') === 'directus_files') {
                    /** @var Acl $auth */
                    $acl = $container->get('acl');
                    $data = $payload->getData();

                    /** @var \Directus\Filesystem\Files $files */
                    $files = $container->get('files');

                    if (array_key_exists('data', $data) && filter_var($data['data'], FILTER_VALIDATE_URL)) {
                        $dataInfo = $files->getLink($data['data']);
                    } else {
                        $dataInfo = $files->getDataInfo($data['data']);
                    }

                    $type = ArrayUtils::get($dataInfo, 'type', ArrayUtils::get($data, 'type'));

                    if (strpos($type, 'embed/') === 0) {
                        $recordData = $files->saveEmbedData($dataInfo);
                    } else {
                        $recordData = $files->saveData($payload['data'], $payload['filename']);
                    }

                    $payload->replace(array_merge($recordData, ArrayUtils::omit($data, 'filename')));
                    $payload->remove('data');
                    $payload->set('upload_user', $acl->getUserId());
                    $payload->set('upload_date', DateUtils::now());
                }

                return $payload;
            });
            $addFilesUrl = function ($rows) use ($container) {
                foreach ($rows as &$row) {
                    $config = $container->get('config');
                    $fileURL = $config['filesystem']['root_url'];
                    $thumbnailURL = $config['filesystem']['root_thumb_url'];
                    $thumbnailFilenameParts = explode('.', $row['filename']);
                    $thumbnailExtension = array_pop($thumbnailFilenameParts);
                    $row['url'] = $fileURL . '/' . $row['filename'];
                    if (Thumbnail::isNonImageFormatSupported($thumbnailExtension)) {
                        $thumbnailExtension = Thumbnail::defaultFormat();
                    }
                    $thumbnailFilename = $row['id'] . '.' . $thumbnailExtension;
                    $row['thumbnail_url'] = $thumbnailURL . '/' . $thumbnailFilename;
                    // filename-ext-100-100-true.jpg
                    // @TODO: This should be another hook listener
                    $filename = implode('.', $thumbnailFilenameParts);
                    if (isset($row['type']) && $row['type'] == 'embed/vimeo') {
                        $oldThumbnailFilename = $row['filename'] . '-vimeo-220-124-true.jpg';
                    } else {
                        $oldThumbnailFilename = $filename . '-' . $thumbnailExtension . '-160-160-true.jpg';
                    }
                    // 314551321-vimeo-220-124-true.jpg
                    // hotfix: there's not thumbnail for this file
                    $row['old_thumbnail_url'] = $thumbnailURL . '/' . $oldThumbnailFilename;
                    $embedManager = $container->get('embed_manager');
                    $provider = isset($row['type']) ? $embedManager->getByType($row['type']) : null;
                    $row['html'] = null;
                    if ($provider) {
                        $row['html'] = $provider->getCode($row);
                        $row['embed_url'] = $provider->getUrl($row);
                    }
                }
                return $rows;
            };
            $emitter->addFilter('collection.select.directus_files:before', function (Payload $payload) {
                $columns = $payload->get('columns');
                if (!in_array('filename', $columns)) {
                    $columns[] = 'filename';
                    $payload->set('columns', $columns);
                }
                return $payload;
            });

            // -- Data types -----------------------------------------------------------------------------
            // TODO: improve Parse boolean/json/array almost similar code
            $parseArray = function ($collection, $data) use ($container) {
                /** @var SchemaManager $schemaManager */
                $schemaManager = $container->get('schema_manager');
                $collectionObject = $schemaManager->getCollection($collection);

                foreach ($collectionObject->getFields(array_keys($data)) as $field) {
                    if (!$field->isArray()) {
                        continue;
                    }

                    $key = $field->getName();
                    $value = $data[$key];

                    // NOTE: If the array has value with comma it will be treat as a separate value
                    // should we encode the commas to "hide" the comma when splitting the values?
                    if (is_array($value)) {
                        $value = implode(',', $value);
                    } else {
                        $value = explode(',', $value);
                    }

                    $data[$key] = $value;
                }

                return $data;
            };

            $parseBoolean = function ($collection, $data) use ($container) {
                /** @var SchemaManager $schemaManager */
                $schemaManager = $container->get('schema_manager');
                $collectionObject = $schemaManager->getCollection($collection);

                foreach ($collectionObject->getFields(array_keys($data)) as $field) {
                    if (!$field->isBoolean()) {
                        continue;
                    }

                    $key = $field->getName();
                    $data[$key] = boolval($data[$key]);
                }

                return $data;
            };
            $parseJson = function ($collection, $data) use ($container) {
                /** @var SchemaManager $schemaManager */
                $schemaManager = $container->get('schema_manager');
                $collectionObject = $schemaManager->getCollection($collection);

                foreach ($collectionObject->getFields(array_keys($data)) as $field) {
                    if (!$field->isJson()) {
                        continue;
                    }

                    $key = $field->getName();
                    $value = $data[$key];

                    // NOTE: If the array has value with comma it will be treat as a separate value
                    // should we encode the commas to "hide" the comma when splitting the values?
                    if (is_string($value)) {
                        $value = json_decode($value);
                    } else if ($value) {
                        $value = json_encode($value);
                    }

                    $data[$key] = $value;
                }

                return $data;
            };

            $emitter->addFilter('collection.insert:before', function (Payload $payload) use ($parseJson, $parseArray) {
                $payload->replace($parseJson($payload->attribute('collection_name'), $payload->getData()));
                $payload->replace($parseArray($payload->attribute('collection_name'), $payload->getData()));

                return $payload;
            });
            $emitter->addFilter('collection.update:before', function (Payload $payload) use ($parseJson, $parseArray) {
                $payload->replace($parseJson($payload->attribute('collection_name'), $payload->getData()));
                $payload->replace($parseArray($payload->attribute('collection_name'), $payload->getData()));

                return $payload;
            });
            $emitter->addFilter('collection.select', function (Payload $payload) use ($parseJson, $parseArray, $parseBoolean) {
                $rows = $payload->getData();
                $collectionName = $payload->attribute('collection_name');

                foreach ($rows as $key => $row) {
                    $row = $parseJson($collectionName, $row);
                    $row = $parseBoolean($collectionName, $row);
                    $rows[$key] = $parseArray($collectionName, $row);
                }

                $payload->replace($rows);

                return $payload;
            });
            // -------------------------------------------------------------------------------------------
            // Add file url and thumb url
            $emitter->addFilter('collection.select', function (Payload $payload) use ($addFilesUrl, $container) {
                $selectState = $payload->attribute('selectState');
                $rows = $payload->getData();
                if ($selectState['table'] == 'directus_files') {
                    $rows = $addFilesUrl($rows);
                } else if ($selectState['table'] === 'directus_messages') {
                    $filesIds = [];
                    foreach ($rows as &$row) {
                        if (!ArrayUtils::has($row, 'attachment')) {
                            continue;
                        }
                        $ids = array_filter(StringUtils::csv((string) $row['attachment'], true));
                        $row['attachment'] = ['data' => []];
                        foreach ($ids as  $id) {
                            $row['attachment']['data'][$id] = [];
                            $filesIds[] = $id;
                        }
                    }
                    $filesIds = array_filter($filesIds);
                    if ($filesIds) {
                        $ZendDb = $container->get('database');
                        $acl = $container->get('acl');
                        $table = new RelationalTableGateway('directus_files', $ZendDb, $acl);
                        $filesEntries = $table->loadItems([
                            'in' => ['id' => $filesIds]
                        ]);
                        $entries = [];
                        foreach($filesEntries as $id => $entry) {
                            $entries[$entry['id']] = $entry;
                        }
                        foreach ($rows as &$row) {
                            if (ArrayUtils::has($row, 'attachment') && $row['attachment']) {
                                foreach ($row['attachment']['data'] as $id => $attachment) {
                                    $row['attachment']['data'][$id] = $entries[$id];
                                }
                                $row['attachment']['data'] = array_values($row['attachment']['data']);
                            }
                        }
                    }
                }
                $payload->replace($rows);
                return $payload;
            });
            $emitter->addFilter('collection.select.directus_users', function (Payload $payload) use ($container) {
                $acl = $container->get('acl');
                $rows = $payload->getData();
                $userId = $acl->getUserId();
                $groupId = $acl->getGroupId();
                foreach ($rows as &$row) {
                    $omit = [
                        'password'
                    ];
                    // Authenticated user can see their private info
                    // Admin can see all users private info
                    if ($groupId !== 1 && $userId !== $row['id']) {
                        $omit = array_merge($omit, [
                            'token',
                            'email_notifications',
                            'last_access',
                            'last_page'
                        ]);
                    }
                    $row = ArrayUtils::omit($row, $omit);
                }
                $payload->replace($rows);
                return $payload;
            });
            $hashUserPassword = function (Payload $payload) use ($container) {
                if ($payload->has('password')) {
                    $auth = $container->get('auth');
                    $payload['password'] = $auth->hashPassword($payload['password']);
                }
                return $payload;
            };
            $slugifyString = function ($insert, Payload $payload) {
                $collection = SchemaService::getCollection($payload->attribute('collection_name'));
                $data = $payload->getData();
                foreach ($collection->getFields() as $column) {
                    if ($column->getInterface() !== 'slug') {
                        continue;
                    }

                    $parentColumnName = $column->getOptions('mirrored_field');
                    if (!ArrayUtils::has($data, $parentColumnName)) {
                        continue;
                    }

                    $onCreationOnly = boolval($column->getOptions('only_on_creation'));
                    if (!$insert && $onCreationOnly) {
                        continue;
                    }

                    $payload->set($column->getName(), slugify(ArrayUtils::get($data, $parentColumnName, '')));
                }

                return $payload;
            };
            $emitter->addFilter('collection.insert:before', function (Payload $payload) use ($slugifyString) {
                return $slugifyString(true, $payload);
            });
            $emitter->addFilter('collection.update:before', function (Payload $payload) use ($slugifyString) {
                return $slugifyString(false, $payload);
            });
            // TODO: Merge with hash user password
            $onInsertOrUpdate = function (Payload $payload) use ($container) {
                /** @var Provider $auth */
                $auth = $container->get('auth');
                $collectionName = $payload->attribute('collection_name');

                if (SchemaService::isSystemCollection($collectionName)) {
                    return $payload;
                }

                $collection = SchemaService::getCollection($collectionName);
                $data = $payload->getData();
                foreach ($data as $key => $value) {
                    $column = $collection->getField($key);
                    if (!$column) {
                        continue;
                    }

                    if ($column->getInterface() === 'password') {
                        // TODO: Use custom password hashing method
                        $payload->set($key, $auth->hashPassword($value));
                    }
                }

                return $payload;
            };
            $emitter->addFilter('collection.update.directus_users:before', function (Payload $payload) use ($container) {
                $acl = $container->get('acl');
                $currentUserId = $acl->getUserId();
                if ($currentUserId != $payload->get('id')) {
                    return $payload;
                }
                // ----------------------------------------------------------------------------
                // TODO: Add enforce method to ACL
                $adapter = $container->get('database');
                $userTable = new BaseTableGateway('directus_users', $adapter);
                $groupTable = new BaseTableGateway('directus_groups', $adapter);
                $user = $userTable->find($payload->get('id'));
                $group = $groupTable->find($user['group']);
                if (!$group || !$acl->canUpdate('directus_users')) {
                    throw new ForbiddenException('you are not allowed to update your user information');
                }
                // ----------------------------------------------------------------------------
                return $payload;
            });
            $emitter->addFilter('collection.insert.directus_users:before', $hashUserPassword);
            $emitter->addFilter('collection.update.directus_users:before', $hashUserPassword);
            // Hash value to any non system table password interface column
            $emitter->addFilter('collection.insert:before', $onInsertOrUpdate);
            $emitter->addFilter('collection.update:before', $onInsertOrUpdate);
            $preventUsePublicGroup = function (Payload $payload) use ($container) {
                $data = $payload->getData();
                if (!ArrayUtils::has($data, 'group')) {
                    return $payload;
                }
                $groupId = ArrayUtils::get($data, 'group');
                if (is_array($groupId)) {
                    $groupId = ArrayUtils::get($groupId, 'id');
                }
                if (!$groupId) {
                    return $payload;
                }
                $zendDb = $container->get('database');
                $acl = $container->get('acl');
                $tableGateway = new BaseTableGateway('directus_groups', $zendDb, $acl);
                $row = $tableGateway->select(['id' => $groupId])->current();
                if (strtolower($row->name) == 'public') {
                    throw new ForbiddenException('Users cannot be added into the public group');
                }
                return $payload;
            };
            $emitter->addFilter('collection.insert.directus_users:before', $preventUsePublicGroup);
            $emitter->addFilter('collection.update.directus_users:before', $preventUsePublicGroup);
            $beforeSavingFiles = function ($payload) use ($container) {
                $acl = $container->get('acl');
                $currentUserId = $acl->getUserId();
                // ----------------------------------------------------------------------------
                // TODO: Add enforce method to ACL
                $adapter = $container->get('database');
                $userTable = new BaseTableGateway('directus_users', $adapter);
                $groupTable = new BaseTableGateway('directus_groups', $adapter);
                $user = $userTable->find($currentUserId);
                $group = $groupTable->find($user['group']);
                if (!$group || !$acl->canUpdate('directus_files')) {
                    throw new ForbiddenException('you are not allowed to upload, edit or delete files');
                }
                // ----------------------------------------------------------------------------
                return $payload;
            };
            $emitter->addAction('files.saving', $beforeSavingFiles);
            $emitter->addAction('files.thumbnail.saving', $beforeSavingFiles);
            // TODO: Make insert actions and filters
            $emitter->addFilter('collection.insert.directus_files:before', $beforeSavingFiles);
            $emitter->addFilter('collection.update.directus_files:before', $beforeSavingFiles);
            $emitter->addFilter('collection.delete.directus_files:before', $beforeSavingFiles);

            return $emitter;
        };
    }

    /**
     * @return \Closure
     */
    protected function getDatabase()
    {
        return function (Container $container) {
            $config = $container->get('config');
            $dbConfig = $config->get('database');

            // TODO: enforce/check required params

            $charset = ArrayUtils::get($dbConfig, 'charset', 'utf8mb4');

            $dbConfig = [
                'driver' => 'Pdo_' . $dbConfig['type'],
                'host' => $dbConfig['host'],
                'port' => $dbConfig['port'],
                'database' => $dbConfig['name'],
                'username' => $dbConfig['username'],
                'password' => $dbConfig['password'],
                'charset' => $charset,
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                \PDO::MYSQL_ATTR_INIT_COMMAND => sprintf('SET NAMES "%s"', $charset)
            ];

            try {
                $db = new Connection($dbConfig);
                $db->connect();
            } catch (\Exception $e) {
                throw new ConnectionFailedException($e);
            }

            return $db;
        };
    }

    /**
     * @return \Closure
     */
    protected function getAuth()
    {
        return function (Container $container) {
            $db = $container->get('database');

            return new Provider(
                new UserTableGatewayProvider(
                    new DirectusUsersTableGateway($db)
                ),
                [
                    'secret_key' => $container->get('config')->get('auth.secret_key')
                ]
            );
        };
    }

    /**
     * @return \Closure
     */
    protected function getExternalAuth()
    {
        return function (Container $container) {
            $config = $container->get('config');
            $providersConfig = $config->get('auth.social_providers', []);

            $socialAuth = new Social();

            $socialAuthServices = [
                'github' => GitHubProvider::class,
                'facebook' => FacebookProvider::class,
                'twitter' => TwitterProvider::class,
                'google' => GoogleProvider::class
            ];

            foreach ($providersConfig as $providerConfig) {
                if (!is_array($providerConfig)) {
                    continue;
                }

                if (ArrayUtils::get($providerConfig, 'enabled') !== true) {
                    continue;
                }

                $name = ArrayUtils::get($providerConfig, 'provider');
                if (!$name) {
                    continue;
                }

                if (array_key_exists($name, $socialAuthServices)) {
                    $class = $socialAuthServices[$name];
                    $socialAuth->register(new $class($container, $providerConfig));
                }
            }

            return $socialAuth;
        };
    }

    /**
     * @return \Closure
     */
    protected function getSession()
    {
        return function (Container $container) {
            $config = $container->get('config');

            $session = new Session(new NativeSessionStorage($config->get('session', [])));
            $session->getStorage()->start();

            return $session;
        };
    }

    /**
     * @return \Closure
     */
    protected function getAcl()
    {
        return function (Container $container) {
            $acl = new Acl();
            /** @var Provider $auth */
            $auth = $container->get('auth');
            $dbConnection = $container->get('database');

            // TODO: Move this to a method
            if ($auth->check()) {
                $privilegesTable = new DirectusPermissionsTableGateway($dbConnection, $acl);
                $acl->setPermissions(
                    $privilegesTable->getGroupPrivileges(
                        $auth->getUserAttributes('group')
                    )
                );
            }

            return $acl;
        };
    }

    /**
     * @return \Closure
     */
    protected function getCache()
    {
        return function (Container $container) {
            $config = $container->get('config');
            $poolConfig = $config->get('cache.pool');

            if (!$poolConfig || (!is_object($poolConfig) && empty($poolConfig['adapter']))) {
                $poolConfig = ['adapter' => 'void'];
            }

            if (is_object($poolConfig) && $poolConfig instanceof PhpCachePool) {
                $pool = $poolConfig;
            } else {
                if (!in_array($poolConfig['adapter'], ['apc', 'apcu', 'array', 'filesystem', 'memcached', 'redis', 'void'])) {
                    throw new \Exception("Valid cache adapters are 'apc', 'apcu', 'filesystem', 'memcached', 'redis'");
                }

                $pool = new VoidCachePool();

                $adapter = $poolConfig['adapter'];

                if ($adapter == 'apc') {
                    $pool = new ApcCachePool();
                }

                if ($adapter == 'apcu') {
                    $pool = new ApcuCachePool();
                }

                if ($adapter == 'array') {
                    $pool = new ArrayCachePool();
                }

                if ($adapter == 'filesystem') {
                    if (empty($poolConfig['path'])) {
                        throw new \Exception("'cache.pool.path' parameter is required for 'filesystem' adapter");
                    }

                    $filesystemAdapter = new Local(__DIR__ . '/../../' . $poolConfig['path']);
                    $filesystem = new \League\Flysystem\Filesystem($filesystemAdapter);

                    $pool = new FilesystemCachePool($filesystem);
                }

                if ($adapter == 'memcached') {
                    $host = (isset($poolConfig['host'])) ? $poolConfig['host'] : 'localhost';
                    $port = (isset($poolConfig['port'])) ? $poolConfig['port'] : 11211;

                    $client = new \Memcached();
                    $client->addServer($host, $port);
                    $pool = new MemcachedCachePool($client);
                }

                if ($adapter == 'redis') {
                    $host = (isset($poolConfig['host'])) ? $poolConfig['host'] : 'localhost';
                    $port = (isset($poolConfig['port'])) ? $poolConfig['port'] : 6379;

                    $client = new \Redis();
                    $client->connect($host, $port);
                    $pool = new RedisCachePool($client);
                }
            }

            return $pool;
        };
    }

    /**
     * @return \Closure
     */
    protected function getSchemaAdapter()
    {
        return function (Container $container) {
            $adapter = $container->get('database');
            $platformName = $adapter->getPlatform()->getName();

            switch (strtolower($platformName)) {
                case 'mysql':
                    return new \Directus\Database\Schema\Sources\MySQLSchema($adapter);
                // case 'SQLServer':
                //    return new SQLServerSchema($adapter);
                case 'sqlite':
                //     return new \Directus\Database\Schemas\Sources\SQLiteSchema($adapter);
                    return new SQLiteSchema($adapter);
                // case 'PostgreSQL':
                //     return new PostgresSchema($adapter);
            }

            throw new \Exception('Unknown/Unsupported database: ' . $platformName);
        };
    }

    /**
     * @return \Closure
     */
    protected function getSchemaManager()
    {
        return function (Container $container) {
            return new SchemaManager(
                $container->get('schema_adapter')
            );
        };
    }

    /**
     * @return \Closure
     */
    protected function getSchemaFactory()
    {
        return function (Container $container) {
            return new SchemaFactory(
                $container->get('schema_manager')
            );
        };
    }

    /**
     * @return \Closure
     */
    protected function getResponseCache()
    {
        return function (Container $container) {
            return new Response($container->get('cache'), $container->get('config')->get('cache.response_ttl'));
        };
    }

    /**
     * @return \Closure
     */
    protected function getHashManager()
    {
        return function (Container $container) {
            $hashManager = new HashManager();
            $basePath = $container->get('path_base');

            $path = implode(DIRECTORY_SEPARATOR, [
                $basePath,
                'customs',
                'hashers',
                '*.php'
            ]);

            $customHashersFiles = glob($path);
            $hashers = [];

            if ($customHashersFiles) {
                foreach ($customHashersFiles as $filename) {
                    $name = basename($filename, '.php');
                    // filename starting with underscore are skipped
                    if (StringUtils::startsWith($name, '_')) {
                        continue;
                    }

                    $hashers[] = '\\Directus\\Customs\\Hasher\\' . $name;
                }
            }

            foreach ($hashers as $hasher) {
                $hashManager->register(new $hasher());
            }

            return $hashManager;
        };
    }

    protected function getFileSystem()
    {
        return function (Container $container) {
            $config = $container->get('config');

            return new Filesystem(FilesystemFactory::createAdapter($config->get('filesystem')));
        };
    }

    /**
     * @return \Closure
     */
    protected function getMailerManager()
    {
        return function (Container $container) {
            $config = $container->get('config');
            $manager = new MailerManager();

            $adapters = [
                'simple_file' => SimpleFileMailAdapter::class,
                'swift_mailer' => SwiftMailerAdapter::class
            ];

            $mailConfigs = $config->get('mail');
            foreach ($mailConfigs as $name => $mailConfig) {
                $adapter = ArrayUtils::get($mailConfig, 'adapter');
                $object = null;

                if (class_exists($adapter)) {
                    $object = new $adapter($mailConfig);
                } else if (array_key_exists($adapter, $adapters)) {
                    $class = $adapters[$adapter];
                    $object = new $class($mailConfig);
                }

                if (!($object instanceof AbstractMailerAdapter)) {
                    throw new RuntimeException(
                        sprintf('%s is not a instance of %s', $adapter, AbstractMailerAdapter::class)
                    );
                }

                $manager->register($name, $object);
            }

            return $manager;
        };
    }

    /**
     * @return \Closure
     */
    protected function getSettings()
    {
        return function (Container $container) {
            $dbConnection = $container->get('database');
            $settingsTable = new TableGateway(SchemaManager::COLLECTION_SETTINGS, $dbConnection);

            return $settingsTable->select()->toArray();
        };
    }

    /**
     * @return \Closure
     */
    protected function getStatusMapping()
    {
        return function (Container $container) {
            $settings = $container->get('app_settings');

            $statusMapping = [];
            foreach ($settings as $setting) {
                if (
                    ArrayUtils::get($setting, 'scope') == 'status'
                    && ArrayUtils::get($setting, 'group') == 'global'
                    && ArrayUtils::get($setting, 'key') == 'status_mapping'
                ) {
                    $statusMapping = json_decode($setting['value'], true);
                    break;
                }
            }

            if (!is_array($statusMapping)) {
                $statusMapping = [];
            }

            return new StatusMapping($statusMapping);
        };
    }

    /**
     * @return \Closure
     */
    protected function getMailView()
    {
        return function (Container $container) {
            $basePath = $container->get('path_base');
            $view = new Twig($basePath . '/src/mail');

            $view->addExtension(new DirectusTwigExtension());

            return $view;
        };
    }

    /**
     * @return \Closure
     */
    protected function getFiles()
    {
        return function (Container $container) {
            $container['settings.files'] = function () use ($container) {
                $dbConnection = $container->get('database');
                $settingsTable = new TableGateway('directus_settings', $dbConnection);

                return $settingsTable->select([
                    'scope' => 'files'
                ])->toArray();
            };

            $filesystem = $container->get('filesystem');
            $config = $container->get('config');
            $config = $config->get('filesystem', []);
            $settings = $container->get('settings.files');
            $emitter = $container->get('hook_emitter');

            return new Files($filesystem, $config, $settings, $emitter);
        };
    }

    protected function getEmbedManager()
    {
        return function (Container $container) {
            $app = Application::getInstance();
            $embedManager = new EmbedManager();
            $acl = $container->get('acl');
            $adapter = $container->get('database');

            // Fetch files settings
            $settingsTableGateway = new DirectusSettingsTableGateway($adapter, $acl);
            try {
                $settings = $settingsTableGateway->loadItems([
                    'filter' => ['scope' => 'files']
                ]);
            } catch (\Exception $e) {
                $settings = [];
                /** @var Logger $logger */
                $logger = $container->get('logger');
                $logger->warning($e->getMessage());
            }

            $providers = [
                '\Directus\Embed\Provider\VimeoProvider',
                '\Directus\Embed\Provider\YoutubeProvider'
            ];

            $path = implode(DIRECTORY_SEPARATOR, [
                $app->getContainer()->get('path_base'),
                'customs',
                'embeds',
                '*.php'
            ]);

            $customProvidersFiles = glob($path);
            if ($customProvidersFiles) {
                foreach ($customProvidersFiles as $filename) {
                    $providers[] = '\\Directus\\Embed\\Provider\\' . basename($filename, '.php');
                }
            }

            foreach ($providers as $providerClass) {
                $provider = new $providerClass($settings);
                $embedManager->register($provider);
            }

            return $embedManager;
        };
    }

    /**
     * Register all services
     *
     * @param Container $mainContainer
     *
     * @return \Closure
     *
     * @internal param Container $container
     *
     */
    protected function getServices(Container $mainContainer)
    {
        // A services container of all Directus services classes
        return function () use ($mainContainer) {
            $container = new Container();

            // =============================================================================
            // Register all services
            // -----------------------------------------------------------------------------
            // TODO: Set a place to load all the services
            // =============================================================================
            $container['auth'] = $this->getAuthService($mainContainer);

            return $container;
        };
    }

    /**
     * @param Container $container Application container
     *
     * @return \Closure
     */
    protected function getAuthService(Container $container)
    {
        return function () use ($container) {
            return new AuthService($container);
        };
    }
}

