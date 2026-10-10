<?php

namespace App\Repository;

use App\Entity\CatalogItem;
use App\Entity\ExternalReference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalReference>
 */
class ExternalReferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalReference::class);
    }

    /**
     * @template T of CatalogItem
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function findItem(string $class, string $provider, string $externalId): ?CatalogItem
    {
        $item = $this->findOneBy(['provider' => $provider, 'externalId' => $externalId])?->getItem();

        return $item instanceof $class ? $item : null;
    }
}
