<?php

namespace Directus\Database\Schema;

use Directus\Database\Exception\CollectionNotFoundException;
use Directus\Database\Schema\Object\Field;
use Directus\Database\Schema\Object\Collection;
use Directus\Database\Schema\Sources\SchemaInterface;
use Directus\Exception\Exception;
use Directus\Util\ArrayUtils;

class SchemaManager
{
    // Tables
    const COLLECTION_ACTIVITY            = 'directus_activity';
    const COLLECTION_COLLECTIONS         = 'directus_collections';
    const COLLECTION_COLLECTION_PRESETS  = 'directus_collection_presets';
    const COLLECTION_FIELDS              = 'directus_fields';
    const COLLECTION_FILES               = 'directus_files';
    const COLLECTION_GROUPS              = 'directus_groups';
    const COLLECTION_PERMISSIONS         = 'directus_permissions';
    const COLLECTION_RELATIONS           = 'directus_relations';
    const COLLECTION_REVISIONS           = 'directus_revisions';
    const COLLECTION_SETTINGS            = 'directus_settings';
    const COLLECTION_USERS               = 'directus_users';

    /**
     * Schema source instance
     *
     * @var \Directus\Database\Schema\Sources\SchemaInterface
     */
    protected $source;

    /**
     * Schema data information
     *
     * @var array
     */
    protected $data = [];

    /**
     * System table prefix
     *
     * @var string
     */
    protected $prefix = 'directus_';

    /**
     * Directus System tables
     *
     * @var array
     */
    protected $directusTables = [
        'activity',
        'activity_read',
        'collections',
        'collection_presets',
        'fields',
        'files',
        'folders',
        'groups',
        'migrations',
        'permissions',
        'relations',
        'revisions',
        'settings',
        'users'
    ];

    public function __construct(SchemaInterface $source)
    {
        $this->source = $source;
    }

    /**
     * Adds a primary key to the given column
     *
     * @param $table
     * @param $column
     *
     * @return bool
     */
    public function addPrimaryKey($table, $column)
    {
        return $this->source->addPrimaryKey($table, $column);
    }

    /**
     * Removes the primary key of the given column
     *
     * @param $table
     * @param $column
     *
     * @return bool
     */
    public function dropPrimaryKey($table, $column)
    {
        return $this->source->dropPrimaryKey($table, $column);
    }

    /**
     * Get the table schema information
     *
     * @param string $collectionName
     * @param array  $params
     * @param bool   $skipCache
     *
     * @throws CollectionNotFoundException
     *
     * @return \Directus\Database\Schema\Object\Collection
     */
    public function getCollection($collectionName, $params = [], $skipCache = false)
    {
        $collection = ArrayUtils::get($this->data, 'collections.' . $collectionName, null);
        if (!$collection || $skipCache) {
            // Get the table schema data from the source
            $collectionData = $this->source->getCollection($collectionName);

            if (!$collectionData) {
                throw new CollectionNotFoundException($collectionName);
            }

            // Create a table object based of the table schema data
            $collection = $this->createCollectionFromArray(array_merge($collectionData, [
                'schema' => $this->source->getSchemaName()
            ]));
            $this->addCollection($collectionName, $collection);
        }

        // =============================================================================
        // Set table columns
        // -----------------------------------------------------------------------------
        // @TODO: Do not allow to add duplicate column names
        // =============================================================================
        if (empty($collection->getFields())) {
            $fields = $this->getFields($collectionName);
            $collection->setFields($fields);
        }

        return $collection;
    }

    /**
     * Gets column schema
     *
     * @param $tableName
     * @param $columnName
     * @param bool $skipCache
     *
     * @return Field
     */
    public function getField($tableName, $columnName, $skipCache = false)
    {
        $columnSchema = ArrayUtils::get($this->data, 'fields.' . $tableName . '.' . $columnName, null);

        if (!$columnSchema || $skipCache) {
            // Get the column schema data from the source
            $columnResult = $this->source->getFields($tableName, ['column_name' => $columnName]);
            $columnData = $columnResult->current();

            // Create a column object based of the table schema data
            $columnSchema = $this->createFieldFromArray($columnData);
            $this->addField($columnSchema);
        }

        return $columnSchema;
    }

    /**
     * Add the system table prefix to to a table name.
     *
     * @param string|array $names
     *
     * @return array
     */
    public function addSystemCollectionPrefix($names)
    {
        if (!is_array($names)) {
            $names = [$names];
        }

        return array_map(function ($name) {
            // TODO: Directus tables prefix _probably_ will be dynamic
            return $this->prefix . $name;
        }, $names);
    }

    /**
     * Get Directus System tables name
     *
     * @return array
     */
    public function getSystemCollections()
    {
        return $this->addSystemCollectionPrefix($this->directusTables);
    }

    /**
     * Check if the given name is a system table
     *
     * @param $name
     *
     * @return bool
     */
    public function isSystemCollection($name)
    {
        return in_array($name, $this->getSystemCollections());
    }

    /**
     * Check if a table name exists
     *
     * @param $tableName
     * @return bool
     */
    public function tableExists($tableName)
    {
        return $this->source->collectionExists($tableName);
    }

    /**
     * Gets list of table
     *
     * @param array $params
     *
     * @return Collection[]
     */
    public function getCollections(array $params = [])
    {
        // TODO: Filter should be outsite
        // $schema = Bootstrap::get('schema');
        // $config = Bootstrap::get('config');

        // $ignoredTables = static::getDirectusTables(DirectusPreferencesTableGateway::$IGNORED_TABLES);
        // $blacklistedTable = $config['tableBlacklist'];
        // array_merge($ignoredTables, $blacklistedTable)
        $collections = $this->source->getCollections();

        $tables = [];
        foreach ($collections as $collection) {
            // Create a table object based of the table schema data
            $tableSchema = $this->createCollectionFromArray(array_merge($collection, [
                'schema' => $this->source->getSchemaName()
            ]));
            $tableName = $tableSchema->getName();
            $this->addCollection($tableName, $tableSchema);

            $tables[$tableName] = $tableSchema;
        }

        return $tables;
    }

    /**
     * Get all columns in the given table name
     *
     * @param $collectionName
     * @param array $params
     *
     * @return \Directus\Database\Schema\Object\Field[]
     */
    public function getFields($collectionName, $params = [])
    {
        // TODO: filter black listed fields on services level

        $columnsSchema = ArrayUtils::get($this->data, 'fields.' . $collectionName, null);
        if (!$columnsSchema) {
            $fieldsData = $this->source->getFields($collectionName, $params);
            $relationsData = $this->source->getRelations($collectionName);

            // TODO: Improve this logic
            $relationsA = [];
            $relationsB = [];
            foreach ($relationsData as $relation) {
                $relationsA[$relation['field_a']] = $relation;

                if (isset($relation['field_b'])) {
                    $relationsB[$relation['field_b']] = $relation;
                }
            }

            $columnsSchema = [];
            foreach ($fieldsData as $field) {
                $field = $this->createFieldFromArray($field);

                if (array_key_exists($field->getName(), $relationsA)) {
                    $field->setRelationship($relationsA[$field->getName()]);
                } else if (array_key_exists($field->getName(), $relationsB)) {
                    $field->setRelationship($relationsB[$field->getName()]);
                }

                $columnsSchema[] = $field;
            }

            $this->data['columns'][$collectionName] = $columnsSchema;
        }

        return $columnsSchema;
    }

    public function getFieldsName($tableName)
    {
        $columns = $this->getFIELDS($tableName);

        $columnNames = [];
        foreach ($columns as $column) {
            $columnNames[] = $column->getName();
        }

        return $columnNames;
    }

    /**
     * Get all the columns
     *
     * @return Field[]
     */
    public function getAllFields()
    {
        $allColumns = $this->source->getAllFields();

        $columns = [];
        foreach($allColumns as $column) {
            $columns[] = $this->createFieldFromArray($column);
        }

        return $columns;
    }

    /**
     * Get a list of columns table grouped by table name
     *
     * @return array
     */
    public function getAllFieldsByCollection()
    {
        $fields = [];
        foreach ($this->getAllFields() as $field) {
            $collectionName = $field->getCollectionName();
            if (!isset($fields[$collectionName])) {
                $fields[$collectionName] = [];
            }

            $columns[$collectionName][] = $field;
        }

        return $fields;
    }

    public function getPrimaryKey($tableName)
    {
        $collection = $this->getCollection($tableName);
        if ($collection) {
            return $collection->getPrimaryKeyName();
        }

        return false;
    }

    public function hasSystemDateField($tableName)
    {
        $tableObject = $this->getCollection($tableName);

        return $tableObject->getDateCreateField() || $tableObject->getDateUpdateField();
    }

    /**
     * Cast records values by its column data type
     *
     * @param array    $records
     * @param Field[] $fields
     *
     * @return array
     */
    public function castRecordValues(array $records, $fields)
    {
        // hotfix: records sometimes are no set as an array of rows.
        $singleRecord = false;
        if (!ArrayUtils::isNumericKeys($records)) {
            $records = [$records];
            $singleRecord = true;
        }

        foreach ($fields as $field) {
            foreach ($records as $index => $record) {
                $fieldName = $field->getName();
                if (ArrayUtils::has($record, $fieldName)) {
                    $records[$index][$fieldName] = $this->castValue($record[$fieldName], $field->getType());
                }
            }
        }

        return $singleRecord ? reset($records) : $records;
    }

    /**
     * Cast string values to its database type.
     *
     * @param $data
     * @param $type
     * @param $length
     *
     * @return mixed
     */
    public function castValue($data, $type = null, $length = false)
    {
        $type = strtolower($type);

        switch ($type) {
            case 'bool':
            case 'boolean':
                $data = boolval($data);
                break;
            case 'blob':
            case 'mediumblob':
                // NOTE: Do we really need to encode the blob?
                $data = base64_encode($data);
                break;
            case 'year':
            case 'bigint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'integer':
            case 'long':
            case 'tinyint':
                $data = ($data === null) ? null : (int)$data;
                break;
            case 'float':
                $data = (float)$data;
                break;
            case 'date':
            case 'datetime':
                $format = 'Y-m-d';
                $zeroData = '0000-00-00';
                if ($type === 'datetime') {
                    $format .= ' H:i:s';
                    $zeroData .= ' 00:00:00';
                }

                if ($data === $zeroData) {
                    $data = null;
                }
                $datetime = \DateTime::createFromFormat($format, $data);
                $data = $datetime ? $datetime->format($format) : null;
                break;
            case 'time':
                // NOTE: Assuming this are all valid formatted data
                $data = !empty($data) ? $data : null;
                break;
            case 'char':
            case 'varchar':
            case 'text':
            case 'tinytext':
            case 'mediumtext':
            case 'longtext':
            case 'var_string':
                break;
        }

        return $data;
    }

    /**
     * Checks whether the given type is numeric type
     *
     * @param $type
     *
     * @return bool
     */
    public function isNumericType($type)
    {
        return DataTypes::isNumericType($type);
    }

    /**
     * Checks whether the given type is string type
     *
     * @param $type
     *
     * @return bool
     */
    public function isStringType($type)
    {
        return DataTypes::isStringType($type);
    }

    /**
     * Checks whether the given type is integer type
     *
     * @param $type
     *
     * @return bool
     */
    public function isIntegerType($type)
    {
        return DataTypes::isIntegerType($type);
    }

    /**
     * Checks whether the given type is decimal type
     *
     * @param $type
     *
     * @return bool
     */
    public function isFloatingPointType($type)
    {
        return static::isFloatingPointType($type);
    }

    /**
     * Cast default value
     *
     * @param $value
     * @param $type
     * @param $length
     *
     * @return mixed
     */
    public function castDefaultValue($value, $type, $length = null)
    {
        if (strtolower($value) === 'null') {
            $value = null;
        } else {
            $value = $this->castValue($value, $type, $length);
        }

        return $value;
    }

    /**
     * Get all Directus system tables name
     *
     * @param array $filterNames
     *
     * @return array
     */
    public function getDirectusCollections(array $filterNames = [])
    {
        $tables = $this->directusTables;
        if ($filterNames) {
            foreach ($tables as $i => $table) {
                if (!in_array($table, $filterNames)) {
                    unset($tables[$i]);
                }
            }
        }

        return $this->addSystemCollectionPrefix($tables);
    }

    /**
     * Check if a given table is a directus system table name
     *
     * @param $tableName
     *
     * @return bool
     */
    public function isDirectusCollection($tableName)
    {
        return in_array($tableName, $this->getDirectusCollections());
    }

    /**
     * Get the schema adapter
     *
     * @return SchemaInterface
     */
    public function getSchema()
    {
        return $this->source;
    }

    /**
     * List of supported databases
     *
     * @return array
     */
    public static function getSupportedDatabases()
    {
        return [
            'mysql' => [
                'id' => 'mysql',
                'name' => 'MySQL/Percona'
            ],
        ];
    }

    public static function getTemplates()
    {
        // @TODO: SchemaManager shouldn't be a class with static methods anymore
        // the UI templates list will be provided by a container or bootstrap.
        $path = implode(DIRECTORY_SEPARATOR, [
            base_path(),
            'api',
            'migrations',
            'templates',
            '*'
        ]);

        $templatesDirs = glob($path, GLOB_ONLYDIR);
        $templatesData = [];
        foreach ($templatesDirs as $dir) {
            $key = basename($dir);
            $templatesData[$key] = [
                'id' => $key,
                'name' => uc_convert($key)
            ];
        }

        return $templatesData;
    }

    /**
     * Gets a collection object from an array attributes data
     * @param $data
     *
     * @return Collection
     */
    public function createCollectionFromArray($data)
    {
        return new Collection($data);
    }

    /**
     * Creates a column object from the given array
     *
     * @param array $column
     *
     * @return Field
     */
    public function createFieldFromArray($column)
    {
        // PRIMARY KEY must be required
        if ($column['key'] === 'PRI') {
            $column['required'] = true;
            $column['interface'] = SystemInterface::INTERFACE_PRIMARY_KEY;
        }

        if (!isset($column['interface'])) {
            $column['interface'] = $this->getFieldDefaultInterface($column['type']);
        }

        $options = json_decode(isset($column['options']) ? $column['options'] : '', true);
        $column['options'] = $options ? $options : null;

        // NOTE: Alias column must are nullable
        if (strtoupper($column['type']) === 'ALIAS') {
            $column['nullable'] = 1;
        }

        // NOTE: MariaDB store "NULL" as a string on some data types such as VARCHAR.
        // We reserved the word "NULL" on nullable data type to be actually null
        if ($column['nullable'] === 1 && $column['default_value'] == 'NULL') {
            $column['default_value'] = null;
        }

        return new Field($column);
    }

    /**
     * Checks whether the interface is a system interface
     *
     * @param $interface
     *
     * @return bool
     */
    public function isSystemField($interface)
    {
        return SystemInterface::isSystem($interface);
    }

    /**
     * Checks whether the interface is primary key interface
     *
     * @param $interface
     *
     * @return bool
     */
    public function isPrimaryKeyInterface($interface)
    {
        return $interface === SystemInterface::INTERFACE_PRIMARY_KEY;
    }

    protected function addCollection($name, $schema)
    {
        // save the column into the data
        // @NOTE: this is the early implementation of cache
        // soon this will be change to cache
        $this->data['tables'][$name] = $schema;
    }

    protected function addField(Field $column)
    {
        $tableName = $column->getCollectionName();
        $columnName = $column->getName();
        $this->data['fields'][$tableName][$columnName] = $column;
    }

    /**
     * Gets the data types default interfaces
     *
     * @return array
     */
    public function getDefaultInterfaces()
    {
        return $this->source->getDefaultInterfaces();
    }

    /**
     * Gets the given data type default interface
     *
     * @param $type
     *
     * @return string
     */
    public function getFieldDefaultInterface($type)
    {
        return $this->source->getColumnDefaultInterface($type);
    }

    /**
     *
     *
     * @param $type
     *
     * @return integer
     */
    public function getFieldDefaultLength($type)
    {
        return $this->source->getColumnDefaultLength($type);
    }

    /**
     * Gets the column type based the schema adapter
     *
     * @param string $type
     *
     * @return string
     */
    public function getDataType($type)
    {
        return $this->source->getDataType($type);
    }

    /**
     * Gets the source schema adapter
     *
     * @return SchemaInterface
     */
    public function getSource()
    {
        return $this->source;
    }
}
