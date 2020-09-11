<?php
declare(strict_types=1);

namespace TheCodingMachine\TDBM\QueryFactory;

use Doctrine\Common\Cache\Cache;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Schema;
use TheCodingMachine\TDBM\OrderByAnalyzer;
use TheCodingMachine\TDBM\TDBMService;
use function implode;

/**
 * This class is in charge of creating the MagicQuery SQL based on parameters passed to findObjects method.
 */
class FindObjectsQueryFactory extends AbstractQueryFactory
{
    private $additionalTablesFetch;
    private $filterString;
    /**
     * @var Cache
     */
    private $cache;
    /** @var bool */
    private $hasExcludedColumns;

    public function __construct(string $mainTable, array $additionalTablesFetch, $filterString, $orderBy, TDBMService $tdbmService, Schema $schema, OrderByAnalyzer $orderByAnalyzer, Cache $cache)
    {
        parent::__construct($tdbmService, $schema, $orderByAnalyzer, $mainTable, $orderBy);
        $this->additionalTablesFetch = $additionalTablesFetch;
        $this->filterString = $filterString;
        $this->cache = $cache;
    }

    protected function compute(): void
    {
        $key = 'FindObjectsQueryFactory_'.$this->mainTable.'__'.implode('_/_', $this->additionalTablesFetch).'__'.$this->filterString.'__'.$this->orderBy;
        if ($this->cache->contains($key)) {
            [
                $this->magicSql,
                $this->magicSqlCount,
                $this->columnDescList,
                $this->hasExcludedColumns
            ] = $this->cache->fetch($key);
            return;
        }

        list($columnDescList, $columnsList, $orderString, $hasExcludedColumns) = $this->getColumnsList($this->mainTable, $this->additionalTablesFetch, $this->orderBy, true);

        $sql = 'SELECT DISTINCT '.implode(', ', $columnsList).' FROM MAGICJOIN('.$this->mainTable.')';

        $pkColumnNames = $this->tdbmService->getPrimaryKeyColumns($this->mainTable);
        $mysqlPlatform = new MySqlPlatform();
        $pkColumnNames = array_map(function ($pkColumn) use ($mysqlPlatform) {
            return $mysqlPlatform->quoteIdentifier($this->mainTable).'.'.$mysqlPlatform->quoteIdentifier($pkColumn);
        }, $pkColumnNames);

        $subQuery = 'SELECT DISTINCT '.implode(', ', $pkColumnNames).' FROM MAGICJOIN('.$this->mainTable.')';

        if (count($pkColumnNames) === 1 || $this->tdbmService->getConnection()->getDatabasePlatform() instanceof MySqlPlatform) {
            $countSql = 'SELECT COUNT(DISTINCT '.implode(', ', $pkColumnNames).') FROM MAGICJOIN('.$this->mainTable.')';
        } else {
            $countSql = 'SELECT COUNT(*) FROM ('.$subQuery.') tmp';
        }

        if (!empty($this->filterString)) {
            $sql .= ' WHERE '.$this->filterString;
            $countSql .= ' WHERE '.$this->filterString;
            $subQuery .= ' WHERE '.$this->filterString;
        }

        if (!empty($orderString)) {
            $sql .= ' ORDER BY '.$orderString;
        }

        $this->magicSql = $sql;
        $this->magicSqlCount = $countSql;
        $this->magicSqlSubQuery = $subQuery;
        $this->columnDescList = $columnDescList;
        $this->hasExcludedColumns = $hasExcludedColumns;

        $this->cache->save($key, [
            $this->magicSql,
            $this->magicSqlCount,
            $this->columnDescList,
            $this->hasExcludedColumns
        ]);
    }

    public function hasExcludedColumns(): bool
    {
        return $this->hasExcludedColumns;
    }
}
