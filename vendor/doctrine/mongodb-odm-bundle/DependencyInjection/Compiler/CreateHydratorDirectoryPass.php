<?php


namespace Doctrine\Bundle\MongoDBBundle\DependencyInjection\Compiler;

use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class CreateHydratorDirectoryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasParameter('doctrine_mongodb.odm.hydrator_dir')) {
            return;
        }
        // Don't do anything if auto_generate_hydrator_classes is false
        if (!$container->getParameter('doctrine_mongodb.odm.auto_generate_hydrator_classes')) {
            return;
        }
        // Create document proxy directory
        $hydratorCacheDir = $container->getParameter('doctrine_mongodb.odm.hydrator_dir');
        if (!is_dir($hydratorCacheDir)) {
            if (false === @mkdir($hydratorCacheDir, 0775, true)) {
                throw new RuntimeException(
                    sprintf('Unable to create the Doctrine Hydrator directory (%s)', dirname($hydratorCacheDir))
                );
            }
        } elseif (!is_writable($hydratorCacheDir)) {
            throw new RuntimeException(
                sprintf('Unable to write in the Doctrine Hydrator directory (%s)', $hydratorCacheDir)
            );
        }
    }

}
