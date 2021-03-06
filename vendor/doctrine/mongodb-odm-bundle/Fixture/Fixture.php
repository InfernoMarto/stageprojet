<?php

namespace Doctrine\Bundle\MongoDBBundle\Fixture;

use Doctrine\Common\DataFixtures\AbstractFixture;

/**
 * Base class designed for data fixtures so they don't have to extend and
 * implement different classes/interfaces according to their needs.
 */
abstract class Fixture extends AbstractFixture implements ODMFixtureInterface
{
}
