<?php
namespace Szurubooru\Dao;

abstract class AbstractDao implements ICrudDao
{
	protected $pdo;
	protected $fpdo;
	protected $tableName;
	protected $entityConverter;
	protected $driver;

	public function __construct(
		\Szurubooru\DatabaseConnection $databaseConnection,
		$tableName,
		\Szurubooru\Dao\EntityConverters\IEntityConverter $entityConverter)
	{
		$this->setDatabaseConnection($databaseConnection);
		$this->tableName = $tableName;
		$this->entityConverter = $entityConverter;
		$this->entityConverter->setEntityDecorator(function($entity)
			{
				$this->afterLoad($entity);
			});
	}

	public function getTableName()
	{
		return $this->tableName;
	}

	public function getEntityConverter()
	{
		return $this->entityConverter;
	}

	public function save(&$entity)
	{
		$entity = $this->upsert($entity);
		$this->afterSave($entity);
		$this->afterBatchSave([$entity]);
		return $entity;
	}

	public function batchSave(array $entities)
	{
		foreach ($entities as $key => $entity)
		{
			$entities[$key] = $this->upsert($entity);
			$this->afterSave($entity);
		}
		if (count($entities) > 0)
			$this->afterBatchSave([$entity]);
		return $entities;
	}

	public function findAll()
	{
		$query = $this->fpdo->from($this->tableName);
		$arrayEntities = iterator_to_array($query);
		return $this->arrayToEntities($arrayEntities);
	}

	public function findById($entityId)
	{
		return $this->findOneBy($this->getIdColumn(), $entityId);
	}

	public function findByIds($entityIds)
	{
		return $this->findBy($this->getIdColumn(), $entityIds);
	}

	public function findFiltered(\Szurubooru\SearchServices\Filters\IFilter $searchFilter)
	{
		$query = $this->fpdo->from($this->tableName)->disableSmartJoin();

		$orderByString = self::compileOrderBy($searchFilter->getOrder());
		if ($orderByString)
			$query->orderBy($orderByString);

		$this->decorateQueryFromFilter($query, $searchFilter);
		if ($searchFilter->getPageSize() > 0)
		{
			$query->limit($searchFilter->getPageSize());
			$query->offset($searchFilter->getPageSize() * max(0, $searchFilter->getPageNumber() - 1));
		}
		$entities = $this->arrayToEntities(iterator_to_array($query));

		$query = $this->fpdo->from($this->tableName)->disableSmartJoin();
		$this->decorateQueryFromFilter($query, $searchFilter);
		$totalRecords = count($query);

		$searchResult = new \Szurubooru\SearchServices\Result();
		$searchResult->setSearchFilter($searchFilter);
		$searchResult->setEntities($entities);
		$searchResult->setTotalRecords($totalRecords);
		$searchResult->setPageNumber($searchFilter->getPageNumber());
		$searchResult->setPageSize($searchFilter->getPageSize());
		return $searchResult;
	}

	public function deleteAll()
	{
		foreach ($this->findAll() as $entity)
		{
			$this->beforeDelete($entity);
		}
		$this->fpdo->deleteFrom($this->tableName)->execute();
	}

	public function deleteById($entityId)
	{
		return $this->deleteBy($this->getIdColumn(), $entityId);
	}

	protected function update(\Szurubooru\Entities\Entity $entity)
	{
		$arrayEntity = $this->entityConverter->toArray($entity);
		$this->fpdo->update($this->tableName)->set($arrayEntity)->where($this->getIdColumn(), $entity->getId())->execute();
		return $entity;
	}

	protected function create(\Szurubooru\Entities\Entity $entity)
	{
		$arrayEntity = $this->entityConverter->toArray($entity);
		$this->fpdo->insertInto($this->tableName)->values($arrayEntity)->execute();
		$entity->setId(intval($this->pdo->lastInsertId()));
		return $entity;
	}

	protected function getIdColumn()
	{
		return 'id';
	}

	protected function hasAnyRecords()
	{
		return count(iterator_to_array($this->fpdo->from($this->tableName)->limit(1))) > 0;
	}

	protected function findBy($columnName, $value)
	{
		if (is_array($value) and empty($value))
			return [];
		$query = $this->fpdo->from($this->tableName)->where($columnName, $value);
		$arrayEntities = iterator_to_array($query);
		return $this->arrayToEntities($arrayEntities);
	}

	protected function findOneBy($columnName, $value)
	{
		$arrayEntities = $this->findBy($columnName, $value);
		if (!$arrayEntities)
			return null;
		return array_shift($arrayEntities);
	}

	protected function deleteBy($columnName, $value)
	{
		foreach ($this->findBy($columnName, $value) as $entity)
		{
			$this->beforeDelete($entity);
		}
		$this->fpdo->deleteFrom($this->tableName)->where($columnName, $value)->execute();
	}

	protected function afterLoad(\Szurubooru\Entities\Entity $entity)
	{
	}

	protected function afterSave(\Szurubooru\Entities\Entity $entity)
	{
	}

	protected function afterBatchSave(array $entities)
	{
	}

	protected function beforeDelete(\Szurubooru\Entities\Entity $entity)
	{
	}

	protected function decorateQueryFromRequirement($query, \Szurubooru\SearchServices\Requirements\Requirement $requirement)
	{
		$value = $requirement->getValue();
		$sqlColumn = $requirement->getType();

		if ($value instanceof \Szurubooru\SearchServices\Requirements\RequirementCompositeValue)
		{
			$sql = $sqlColumn;
			$bindings = [$value->getValues()];
		}

		else if ($value instanceof \Szurubooru\SearchServices\Requirements\RequirementRangedValue)
		{
			if ($value->getMinValue() and $value->getMaxValue())
			{
				$sql = $sqlColumn . ' >= ? AND ' . $sqlColumn . ' <= ?';
				$bindings = [$value->getMinValue(), $value->getMaxValue()];
			}
			elseif ($value->getMinValue())
			{
				$sql = $sqlColumn . ' >= ?';
				$bindings = [$value->getMinValue()];
			}
			elseif ($value->getMaxValue())
			{
				$sql = $sqlColumn . ' <= ?';
				$bindings = [$value->getMaxValue()];
			}
			else
				throw new \RuntimeException('Neither min or max value was supplied');
		}

		else if ($value instanceof \Szurubooru\SearchServices\Requirements\RequirementSingleValue)
		{
			$sql = $sqlColumn;
			$bindings = [$value->getValue()];
		}

		else
			throw new \Exception('Bad value: ' . get_class($value));

		if ($requirement->isNegated())
			$sql = 'NOT (' . $sql . ')';
		call_user_func_array([$query, 'where'], array_merge([$sql], $bindings));
	}

	protected function arrayToEntities(array $arrayEntities)
	{
		$entities = [];
		foreach ($arrayEntities as $arrayEntity)
		{
			$entity = $this->entityConverter->toEntity($arrayEntity);
			$entities[$entity->getId()] = $entity;
		}
		return $entities;
	}

	private function setDatabaseConnection(\Szurubooru\DatabaseConnection $databaseConnection)
	{
		$this->pdo = $databaseConnection->getPDO();
		$this->fpdo = new \FluentPDO($this->pdo);
		$this->driver = $databaseConnection->getDriver();
	}

	private function decorateQueryFromFilter($query, \Szurubooru\SearchServices\Filters\IFilter $filter)
	{
		foreach ($filter->getRequirements() as $requirement)
		{
			$this->decorateQueryFromRequirement($query, $requirement);
		}
	}

	private function compileOrderBy($order)
	{
		$orderByString = '';
		foreach ($order as $orderColumn => $orderDir)
		{
			if ($orderColumn === \Szurubooru\SearchServices\Filters\BasicFilter::ORDER_RANDOM)
			{
				$driver = $this->driver;
				if ($driver === 'sqlite')
				{
					$orderColumn = 'RANDOM()';
				}
				else
				{
					$orderColumn = 'RAND()';
				}
			}
			$orderByString .= $orderColumn . ' ' . ($orderDir === \Szurubooru\SearchServices\Filters\IFilter::ORDER_DESC ? 'DESC' : 'ASC') . ', ';
		}
		return substr($orderByString, 0, -2);
	}

	private function upsert(\Szurubooru\Entities\Entity $entity)
	{
		if ($entity->getId())
		{
			return $this->update($entity);
		}
		else
		{
			return $this->create($entity);
		}
	}
}
