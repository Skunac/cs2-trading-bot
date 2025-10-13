<?php

namespace App\Repository;

use App\Entity\SystemConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemConfig>
 */
class SystemConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemConfig::class);
    }

    /**
     * Get config value by key
     */
    public function findByKey(string $key): ?SystemConfig
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Get typed value by key (convenience method)
     */
    public function getValue(string $key, mixed $default = null): mixed
    {
        $config = $this->findByKey($key);
        return $config ? $config->getTypedValue() : $default;
    }

    /**
     * Get value as string (for bcmath operations)
     */
    public function getStringValue(string $key, string $default = '0.00'): string
    {
        $config = $this->findByKey($key);
        
        if (!$config) {
            return $default;
        }
        
        $value = $config->getValue();
        return $value ?: $default;
    }

    /**
     * Set value by key (convenience method)
     */
    public function setValue(string $key, mixed $value): void
    {
        $config = $this->findByKey($key);
        
        if (!$config) {
            $config = new SystemConfig();
            $config->setKey($key);
        }
        
        $config->setTypedValue($value);
        
        $em = $this->getEntityManager();
        $em->persist($config);
        $em->flush();
    }

    /**
     * Get all editable configs
     */
    public function findEditable(): array
    {
        return $this->findBy(['isEditable' => true], ['key' => 'ASC']);
    }
}