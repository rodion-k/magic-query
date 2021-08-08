<?php

namespace Mouf\Database\QueryWriter;

use SQLParser\SqlRenderInterface;
use function method_exists;
use Mouf\Database\QueryWriter\Utils\DbHelper;
use Mouf\Utils\Value\ValueUtils;
use SQLParser\Query\Select;
use Mouf\Utils\Common\PaginableInterface;
use Mouf\Utils\Value\ArrayValueInterface;
use Mouf\Utils\Value\ValueInterface;
use Doctrine\DBAL\Connection;
use Mouf\Utils\Common\SortableInterface;
use SQLParser\Node\NodeInterface;
use SQLParser\Node\ColRef;

/**
 * A class that can execute a query and expose the results.
 *
 * @Renderer { "smallLogo":"vendor/mouf/database.querywriter/icons/database_query.png" }
 *
 * @author David Negrier
 */
class QueryResult implements ArrayValueInterface, PaginableInterface, SortableInterface
{
    /**
     * The Select statement.
     *
     * @var Select
     */
    private $select;

    /**
     * The connection to the database.
     *
     * @var Connection
     */
    private $connection;

    /**
     * The list of parameters to apply to the SQL request.
     *
     * @var array<string, string>|array<string, ValueInterface>|ArrayValueInterface
     */
    private $parameters = array();

    /** @var int|null */
    private $offset;
    /** @var int|null */
    private $limit;
    private int $conditionsMode;
    private bool $extrapolateParameters;

    /**
     * @param Select     $select
     * @param Connection $connection
     * @param int        $conditionsMode
     * @param bool       $extrapolateParameters
     */
    public function __construct(
        Select $select,
        Connection $connection,
        int $conditionsMode = SqlRenderInterface::CONDITION_APPLY,
        bool $extrapolateParameters = true
    ) {
        $this->select = $select;
        $this->connection = $connection;
        $this->conditionsMode = $conditionsMode;
        $this->extrapolateParameters = $extrapolateParameters;
    }

    /**
     * The list of parameters to apply to the SQL request.
     *
     * @param array<string, string>|array<string, ValueInterface>|ArrayValueInterface $parameters
     */
    public function setParameters($parameters): void
    {
        $this->parameters = $parameters;
    }

    /**
     * (non-PHPdoc).
     *
     * @see \Mouf\Utils\Value\ArrayValueInterface::val()
     */
    public function val()
    {
        $pdoStatement = $this->connection->query($this->toSql().DbHelper::getFromLimitString($this->offset, $this->limit));

        return new ResultSet($pdoStatement);
    }

    /**
     * Returns the SQL for this query-result (without pagination, but with parameters accounted for).
     *
     * @return string|null
     */
    public function toSql()
    {
        $parameters = ValueUtils::val($this->parameters);

        return $this->select->toSql(
            $parameters,
            $this->connection->getDatabasePlatform(),
            0,
            $this->conditionsMode,
            $this->extrapolateParameters
        );
    }

    /**
     * Paginates the result set.
     *
     * @param int $limit
     * @param int $offset
     */
    public function paginate($limit, $offset = 0): void
    {
        $this->limit = $limit;
        $this->offset = $offset;
    }

    /* (non-PHPdoc)
     * @see \Mouf\Utils\Common\SortableInterface::sort()
     */
    public function sort($key, $direction = SortableInterface::ASC): void
    {
        $result = $this->findColumnByKey($key);
        if ($result != null) {
            $columnObj = clone($result);
            if (method_exists($columnObj, 'setAlias')) {
                $columnObj->setAlias(null);
            }
            if (method_exists($columnObj, 'setDirection')) {
                $columnObj->setDirection($direction);
            }
        } else {
            $columnObj = new ColRef();
            $columnObj->setColumn($key);
            $columnObj->setDirection($direction);
        }
        $this->select->setOrder(array($columnObj));
    }

    /**
     * Returns the object representing a column from the key passed in parameter.
     * It will first scan the column aliases to find if an alias match the key, then the column names, etc...
     * It will throw an exception if the column cannot be found.
     *
     * @param string $key
     *
     * @return NodeInterface|null
     */
    private function findColumnByKey($key): ?NodeInterface
    {
        $columns = $this->select->getColumns();
        foreach ($columns as $column) {
            if (method_exists($column, 'getAlias')) {
                $alias = $column->getAlias();
                if ($alias === $key) {
                    return $column;
                }
            }
            if ($column instanceof ColRef) {
                if ($column->getColumn() === $key) {
                    return $column;
                }
            }
        }

        return null;
    }
}
