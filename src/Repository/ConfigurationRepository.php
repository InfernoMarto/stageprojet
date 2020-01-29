<?php

namespace App\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
/**
 * AboutRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ConfigurationRepository extends DocumentRepository
{
	public function findConfiguration()
	{
	  $qb = $this->createQueryBuilder('BiopenCoreBundle:Configuration');
     return $qb->getQuery()->getSingleResult();
	}
}