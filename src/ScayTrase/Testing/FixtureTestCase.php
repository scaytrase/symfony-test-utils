<?php
/**
 * Created by PhpStorm.
 * User: Pavel
 * Date: 4/18/14
 * Time: 12:03 AM
 */

namespace ScayTrase\Testing;


use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\HttpKernel\KernelInterface;


abstract class FixtureTestCase extends WebTestCase
{
    /** @var  EntityManager */
    protected static $em;
    /** @var  KernelInterface */
    protected static $kernel;
    /** @var  Client */
    protected $client;
    /** @var  FixtureInterface[] */
    private $fixtures = array();

    /**
     * @throws \Doctrine\ORM\Tools\ToolsException
     */
    public static function setUpBeforeClass()
    {
        static::$kernel = static::createKernel();
        static::$kernel->boot();

        $metadata = static::getMetadata();

        $tool = new SchemaTool(static::$em);
        $tool->dropDatabase();
        $tool->createSchema($metadata);

        $validator = new SchemaValidator(static::$em);
        $errors = $validator->validateMapping();

        static::assertCount(
            0,
            $errors,
            implode(
                "\n\n",
                array_map(
                    function ($l) {
                        return implode("\n\n", $l);
                    },
                    $errors
                )
            )
        );
    }

    /**
     * @param array $options
     * @return KernelInterface
     */
    protected static function createKernel(array $options = array())
    {
        return parent::createKernel($options);
    }

    public static function getMetadata()
    {
        /** @var EntityManagerInterface $em */
        static::$em = static::$kernel->getContainer()->get('doctrine')->getManager();

        $metadata = static::$em->getMetadataFactory()->getAllMetadata();

        return $metadata;
    }

    /**
     * @param $class
     * @param array $known_fixtures
     * @return FixtureInterface[]
     */
    private function buildFixtureDependencies($class, $known_fixtures = array())
    {
        /** @var FixtureInterface[] $fixtures */
        $fixtures = array();

        // ignore cyclic dependencies
        if (array_key_exists($class, $known_fixtures)) {
            return $fixtures;
        }

        $fixture = new $class;
        if (!($fixture instanceof FixtureInterface)) {
            return $fixtures;
        }

        if ($fixture instanceof ContainerAwareInterface) {
            $fixture->setContainer(static::$kernel->getContainer());
        }

        $fixtures[$class] = $fixture;

        if ($fixture instanceof DependentFixtureInterface) {
            foreach ($fixture->getDependencies() as $dependend_class) {
                $fixtures = array_merge($fixtures, $this->buildFixtureDependencies($dependend_class, $fixtures));
            }
        }

        return $fixtures;
    }

    public function setUp()
    {
        $this->fixtures = array();
        $annotations = $this->getAnnotations();

        if (isset($annotations['method']['dataset'])) {
            $dataset_classes = $annotations['method']['dataset'];
            foreach ($dataset_classes as $dataset_class) {
                $this->fixtures = array_merge(
                    $this->fixtures,
                    $this->buildFixtureDependencies($dataset_class, $this->fixtures)
                );
            }
        }

        uasort(
            $this->fixtures,
            function (FixtureInterface $a, FixtureInterface $b) {
                if ($a instanceof OrderedFixtureInterface && $b instanceof OrderedFixtureInterface) {
                    return ($a->getOrder() < $b->getOrder() ? -1 : 1);
                }

                if ($a instanceof OrderedFixtureInterface) {
                    return -1;
                }
                if ($b instanceof OrderedFixtureInterface) {
                    return 1;
                }

                return 0;
            }
        );

        $this->loadTestData($this->fixtures);
    }

    /**
     * @param FixtureInterface|FixtureInterface[] $data
     */
    protected function loadTestData($data)
    {
        $loader = new Loader();

        if (!is_array($data)) {
            $data = array($data);
        }

        foreach ($data as $dataSet) {
            $loader->addFixture($dataSet);
        }

        $purger = new ORMPurger();
        $executor = new ORMExecutor(
            static::$em,
            $purger
        );
        $executor->execute($loader->getFixtures());
    }

    /**
     * @return FixtureInterface[]
     */
    public function getFixtures()
    {
        return $this->fixtures;
    } // parseDocBlock

}