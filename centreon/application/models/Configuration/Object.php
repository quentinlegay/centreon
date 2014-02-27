<?php

namespace Models\Configuration;

/**
 * Abstract Centreon Object class
 *
 * @author sylvestre
 */
abstract class Object
{
    /**
     * Database Connector
     */
    protected $db;

    /**
     * Table name of the object
     */
    protected $table = null;

    /**
     * Primary key name
     */
    protected $primaryKey = null;

    /**
     * Unique label field
     */
    protected $uniqueLabelField = null;


    /**
     * Array of relation objects 
     *
     * @var array of strings
     */
    public static $relations = array();

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->db = \Centreon\Core\Di::getDefault()->get('db_centreon');
    }

    /**
     * Get result from sql query
     *
     * @param string $sqlQuery
     * @param array $sqlParams
     * @param string $fetchMethod
     * @return array
     */
    protected function getResult($sqlQuery, $sqlParams = array(), $fetchMethod = "fetchAll")
    {
        $stmt = $this->db->prepare($sqlQuery);
        $stmt->execute($sqlParams);
        $result = $stmt->{$fetchMethod}(\PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * Used for inserting object into database
     *
     * @param array $params
     * @return int
     */
    public function insert($params = array())
    {
        $sql = "INSERT INTO $this->table ";
        $sqlFields = "";
        $sqlValues = "";
        $sqlParams = array();
        foreach ($params as $key => $value) {
            if ($key == $this->primaryKey || is_null($value)) {
                continue;
            }
            if ($sqlFields != "") {
                $sqlFields .= ",";
            }
            if ($sqlValues != "") {
                $sqlValues .= ",";
            }
            $sqlFields .= $key;
            $sqlValues .= "?";
            $sqlParams[] = trim($value);
        }
        if ($sqlFields && $sqlValues) {
            $sql .= "(".$sqlFields.") VALUES (".$sqlValues.")";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($sqlParams);
            return $this->db->lastInsertId($this->table, $this->primaryKey);
        }
        return null;
    }

    /**
     * Used for deleteing object from database
     *
     * @param int $objectId
     */
    public function delete($objectId)
    {
        $sql = "DELETE FROM  $this->table WHERE $this->primaryKey = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array($objectId));
    }

    /**
     * Used for updating object in database
     *
     * @param int $objectId
     * @param array $params
     * @return void
     */
    public function update($objectId, $params = array())
    {
        $sql = "UPDATE $this->table SET ";
        $sqlUpdate = "";
        $sqlParams = array();
        $not_null_attributes = array();

        if (array_search("", $params)) {
            $sql_attr = "SHOW FIELDS FROM $this->table";
            $res = $this->getResult($sql_attr, array(), "fetchAll");
            foreach ($res as $tab) {
                if ($tab['Null'] == 'NO') {
                    $not_null_attributes[$tab['Field']] = true;
                }
            }
        }

        foreach ($params as $key => $value) {
            if ($key == $this->primaryKey) {
                continue;
            }
            if ($sqlUpdate != "") {
                $sqlUpdate .= ",";
            }
            $sqlUpdate .= $key . " = ? ";
            if ($value == "" && !isset($not_null_attributes[$key])) {
                $value = null;
            }
            $value = str_replace("<br/>", "\n", $value);
            $sqlParams[] = $value;
        }

        if ($sqlUpdate) {
            $sqlParams[] = $objectId;
            $sql .= $sqlUpdate . " WHERE $this->primaryKey = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($sqlParams);
        }
    }

    /**
     * Used for duplicating object
     *
     * @param int $sourceObjectId
     * @param int $duplicateEntries
     * @todo relations
     */
    public function duplicate($sourceObjectId, $duplicateEntries = 1)
    {
        $sourceParams = $this->getParameters($sourceObjectId, "*");
        if (isset($sourceParams[$this->primaryKey])) {
            unset($sourceParams[$this->primaryKey]);
        }
        $originalName = $sourceParams[$this->uniqueLabelField];
        $firstKeyCopy = array();
        $secondKeyCopy = array();
        foreach (static::$relations as $relation) {
            $relationObj = new $relation();
            if ($relation::$firstObject == get_called_class()) {
                $firstKeyCopy[$relation] = $relationObj->getTargetIdFromSourceId(
                    $relationObj->getSecondKey(),
                    $relationObj->getFirstKey(),
                    $sourceObjectId
                );
            } elseif ($relation::$secondObject == get_called_class()) {
                $secondKeyCopy[$relation] = $relationObj->getTargetIdFromSourceId(
                    $relationObj->getFirstKey(),
                    $relationObj->getSecondKey(),
                    $sourceObjectId
                );
            }
            unset($relationObj);
        }
        $i = 1;
        $j = 1;
        while ($i <= $duplicateEntries) {
            if (isset($sourceParams[$this->uniqueLabelField]) && isset($originalName)) {
                $sourceParams[$this->uniqueLabelField] = $originalName . "_" . $j;
            }
            $ids = $this->getIdByParameter($this->uniqueLabelField, array($sourceParams[$this->uniqueLabelField]));
            if (!count($ids)) {
                $lastId = $this->insert($sourceParams);
                $this->db->beginTransaction();
                foreach ($firstKeyCopy as $relation => $idArray) {
                    $relationObj = new $relation();
                    foreach ($idArray as $relationId) {
                        $relationObj->insert($lastId, $relationId);
                    }
                    unset($relationObj);
                }
                foreach ($secondKeyCopy as $relation => $idArray) {
                    $relationObj = new $relation();
                    foreach ($idArray as $relationId) {
                        $relationObj->insert($relationId, $lastId);
                    }
                    unset($relationObj);
                }
                $this->db->commit();
                $i++;
            }
            $j++;
        }
    }

    /**
     * Get object parameters
     *
     * @param int $objectId
     * @param mixed $parameterNames
     * @return array
     */
    public function getParameters($objectId, $parameterNames)
    {
        if (is_array($parameterNames)) {
            $params = implode(",", $parameterNames);
        } else {
            $params = $parameterNames;
        }
        $sql = "SELECT $params FROM $this->table WHERE $this->primaryKey = ?";
        return $this->getResult($sql, array($objectId), "fetch");
    }

    /**
     * List all objects with all their parameters
     * Data heavy, use with as many parameters as possible
     * in order to limit it
     *
     * @param mixed $parameterNames
     * @param int $count
     * @param int $offset
     * @param string $order
     * @param string $sort
     * @param array $filters
     * @param string $filterType
     * @return array
     * @throws Exception
     */
    public function getList($parameterNames = "*", $count = -1, $offset = 0, $order = null, $sort = "ASC", $filters = array(), $filterType = "OR")
    {
        if ($filterType != "OR" && $filterType != "AND") {
            throw new Exception('Unknown filter type');
        }
        if (is_array($parameterNames)) {
            $params = implode(",", $parameterNames);
        } else {
            $params = $parameterNames;
        }
        $sql = "SELECT $params FROM $this->table ";
        $filterTab = array();
        if (count($filters)) {
            foreach ($filters as $key => $rawvalue) {
                if (!count($filterTab)) {
                    $sql .= " WHERE $key LIKE ? ";
                } else {
                    $sql .= " $filterType $key LIKE ? ";
                }
                $value = trim($rawvalue);
                $value = str_replace("\\", "\\\\", $value);
                $value = str_replace("_", "\_", $value);
                $value = str_replace(" ", "\ ", $value);
                $filterTab[] = $value;
            }
        }
        if (isset($order) && isset($sort) && (strtoupper($sort) == "ASC" || strtoupper($sort) == "DESC")) {
            $sql .= " ORDER BY $order $sort ";
        }
        if (isset($count) && $count != -1) {
            $sql = $this->db->limit($sql, $count, $offset);
        }
        return $this->getResult($sql, $filterTab, "fetchAll");
    }

    /**
     * Generic method that allows to retrieve object ids
     * from another object parameter
     *
     * @param string $paramName
     * @param array $paramValues
     * @return array
     */
    public function getIdByParameter($paramName, $paramValues = array())
    {
        $sql = "SELECT $this->primaryKey FROM $this->table WHERE ";
        $condition = "";
        if (!is_array($paramValues)) {
            $paramValues = array($paramValues);
        }
        foreach ($paramValues as $val) {
            if ($condition != "") {
                $condition .= " OR ";
            }
            $condition .= $paramName . " = ? ";
        }
        if ($condition) {
            $sql .= $condition;
            $rows = $this->getResult($sql, $paramValues, "fetchAll");
            $tab = array();
            foreach ($rows as $val) {
                $tab[] = $val[$this->primaryKey];
            }
            return $tab;
        }
        return array();
    }

    /**
     * Generic method that allows to retrieve object ids
     * from another object parameter
     *
     * @param string $name
     * @param array $args
     * @return array
     * @throws Exception
     */
    public function __call($name, $args)
    {
        if (preg_match('/^getIdBy([a-zA-Z0-9_]+)/', $name, $matches)) {
            return $this->getIdByParameter($matches[1], $args);
        } else {
            throw new Exception('Unknown method');
        }
    }

    /**
     * Primary Key Getter
     *
     * @return string
     */
    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    /**
     * Unique label field getter
     *
     * @return string
     */
    public function getUniqueLabelField()
    {
        return $this->uniqueLabelField;
    }

    /**
     * Get Table Name
     *
     * @return string
     */
    public function getTableName()
    {
        return $this->table;
    }
}
