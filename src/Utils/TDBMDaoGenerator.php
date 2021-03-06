<?php
declare(strict_types=1);

namespace TheCodingMachine\TDBM\Utils;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use function str_replace;
use TheCodingMachine\TDBM\ConfigurationInterface;
use TheCodingMachine\TDBM\TDBMException;
use TheCodingMachine\TDBM\TDBMSchemaAnalyzer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * This class generates automatically DAOs and Beans for TDBM.
 */
class TDBMDaoGenerator
{
    /**
     * @var Schema
     */
    private $schema;

    /**
     * @var TDBMSchemaAnalyzer
     */
    private $tdbmSchemaAnalyzer;

    /**
     * @var GeneratorListenerInterface
     */
    private $eventDispatcher;

    /**
     * @var NamingStrategyInterface
     */
    private $namingStrategy;
    /**
     * @var ConfigurationInterface
     */
    private $configuration;

    /**
     * Constructor.
     *
     * @param ConfigurationInterface $configuration
     * @param TDBMSchemaAnalyzer $tdbmSchemaAnalyzer
     */
    public function __construct(ConfigurationInterface $configuration, TDBMSchemaAnalyzer $tdbmSchemaAnalyzer)
    {
        $this->configuration = $configuration;
        $this->schema = $tdbmSchemaAnalyzer->getSchema();
        $this->tdbmSchemaAnalyzer = $tdbmSchemaAnalyzer;
        $this->namingStrategy = $configuration->getNamingStrategy();
        $this->eventDispatcher = $configuration->getGeneratorEventDispatcher();
    }

    /**
     * Generates all the daos and beans.
     *
     * @throws TDBMException
     */
    public function generateAllDaosAndBeans(): void
    {
        // TODO: check that no class name ends with "Base". Otherwise, there will be name clash.

        $tableList = $this->schema->getTables();

        // Remove all beans and daos from junction tables
        $junctionTables = $this->configuration->getSchemaAnalyzer()->detectJunctionTables(true);
        $junctionTableNames = array_map(function (Table $table) {
            return $table->getName();
        }, $junctionTables);

        $tableList = array_filter($tableList, function (Table $table) use ($junctionTableNames) {
            return !in_array($table->getName(), $junctionTableNames);
        });

        $this->cleanUpGenerated();

        $beanDescriptors = [];

        foreach ($tableList as $table) {
            $beanDescriptors[] = $this->generateDaoAndBean($table);
        }


        $this->generateFactory($tableList);

        // Let's call the list of listeners
        $this->eventDispatcher->onGenerate($this->configuration, $beanDescriptors);
    }

    /**
     * Removes all files from the Generated folders.
     * This is a way to ensure that when a table is deleted, the matching bean/dao are deleted.
     * Note: only abstract generated classes are deleted. We do not delete the code that might have been customized
     * by the user. The user will need to delete this code him/herself
     */
    private function cleanUpGenerated(): void
    {
        $generatedBeanDir = $this->configuration->getPathFinder()->getPath($this->configuration->getBeanNamespace().'\\Generated\\Xxx')->getPath();
        $this->deleteAllPhpFiles($generatedBeanDir);

        $generatedDaoDir = $this->configuration->getPathFinder()->getPath($this->configuration->getDaoNamespace().'\\Generated\\Xxx')->getPath();
        $this->deleteAllPhpFiles($generatedDaoDir);
    }

    private function deleteAllPhpFiles(string $directory): void
    {
        $files = glob($directory.'/*.php');
        $fileSystem = new Filesystem();
        $fileSystem->remove($files);
    }

    /**
     * Generates in one method call the daos and the beans for one table.
     *
     * @param Table $table
     *
     * @return BeanDescriptor
     * @throws TDBMException
     */
    private function generateDaoAndBean(Table $table) : BeanDescriptor
    {
        $tableName = $table->getName();
        $daoName = $this->namingStrategy->getDaoClassName($tableName);
        $beanName = $this->namingStrategy->getBeanClassName($tableName);
        $baseBeanName = $this->namingStrategy->getBaseBeanClassName($tableName);
        $baseDaoName = $this->namingStrategy->getBaseDaoClassName($tableName);

        $beanDescriptor = new BeanDescriptor($table, $this->configuration->getBeanNamespace(), $this->configuration->getBeanNamespace().'\\Generated', $this->configuration->getDaoNamespace(), $this->configuration->getDaoNamespace().'\\Generated', $this->configuration->getSchemaAnalyzer(), $this->schema, $this->tdbmSchemaAnalyzer, $this->namingStrategy, $this->configuration->getAnnotationParser(), $this->configuration->getCodeGeneratorListener(), $this->configuration);
        $this->generateBean($beanDescriptor, $beanName, $baseBeanName, $table);
        $this->generateDao($beanDescriptor, $daoName, $baseDaoName, $beanName, $table);
        return $beanDescriptor;
    }

    /**
     * Writes the PHP bean file with all getters and setters from the table passed in parameter.
     *
     * @param BeanDescriptor  $beanDescriptor
     * @param string          $className       The name of the class
     * @param string          $baseClassName   The name of the base class which will be extended (name only, no directory)
     * @param Table           $table           The table
     *
     * @throws TDBMException
     */
    public function generateBean(BeanDescriptor $beanDescriptor, string $className, string $baseClassName, Table $table): void
    {
        $beannamespace = $this->configuration->getBeanNamespace();
        $file = $beanDescriptor->generatePhpCode();
        if ($file === null) {
            return;
        }

        $possibleBaseFileName = $this->configuration->getPathFinder()->getPath($beannamespace.'\\Generated\\'.$baseClassName)->getPathname();

        $fileContent = $file->generate();

        // Hard code PSR-2 fix
        $fileContent = str_replace("\n\n}\n", '}', $fileContent);
        // Add the declare strict-types directive
        $commentEnd = strpos($fileContent, ' */') + 3;
        $fileContent = substr($fileContent, 0, $commentEnd) . "\n\ndeclare(strict_types=1);" . substr($fileContent, $commentEnd + 1);

        $this->dumpFile($possibleBaseFileName, $fileContent);

        $possibleFileName = $this->configuration->getPathFinder()->getPath($beannamespace.'\\'.$className)->getPathname();

        if (!file_exists($possibleFileName)) {
            $tableName = $table->getName();
            $str = "<?php
/*
 * This file has been automatically generated by TDBM.
 * You can edit this file as it will not be overwritten.
 */

declare(strict_types=1);

namespace {$beannamespace};

use {$beannamespace}\\Generated\\{$baseClassName};

/**
 * The $className class maps the '$tableName' table in database.
 */
class $className extends $baseClassName
{
}
";

            $this->dumpFile($possibleFileName, $str);
        }
    }

    /**
     * Writes the PHP bean DAO with simple functions to create/get/save objects.
     *
     * @param BeanDescriptor  $beanDescriptor
     * @param string          $className       The name of the class
     * @param string          $baseClassName
     * @param string          $beanClassName
     * @param Table           $table
     *
     * @throws TDBMException
     */
    private function generateDao(BeanDescriptor $beanDescriptor, string $className, string $baseClassName, string $beanClassName, Table $table): void
    {
        $file = $beanDescriptor->generateDaoPhpCode();
        if ($file === null) {
            return;
        }
        $daonamespace = $this->configuration->getDaoNamespace();
        $tableName = $table->getName();

        $beanClassWithoutNameSpace = $beanClassName;

        $possibleBaseFileName = $this->configuration->getPathFinder()->getPath($daonamespace.'\\Generated\\'.$baseClassName)->getPathname();

        $fileContent = $file->generate();

        // Hard code PSR-2 fix
        $fileContent = str_replace("\n\n}\n", '}', $fileContent);
        // Add the declare strict-types directive
        $commentEnd = strpos($fileContent, ' */') + 3;
        $fileContent = substr($fileContent, 0, $commentEnd) . "\n\ndeclare(strict_types=1);" . substr($fileContent, $commentEnd + 1);

        $this->dumpFile($possibleBaseFileName, $fileContent);


        $possibleFileName = $this->configuration->getPathFinder()->getPath($daonamespace.'\\'.$className)->getPathname();

        // Now, let's generate the "editable" class
        if (!file_exists($possibleFileName)) {
            $str = "<?php
/*
 * This file has been automatically generated by TDBM.
 * You can edit this file as it will not be overwritten.
 */

declare(strict_types=1);

namespace {$daonamespace};

use {$daonamespace}\\Generated\\{$baseClassName};

/**
 * The $className class will maintain the persistence of $beanClassWithoutNameSpace class into the $tableName table.
 */
class $className extends $baseClassName
{
}
";
            $this->dumpFile($possibleFileName, $str);
        }
    }

    /**
     * Generates the factory bean.
     *
     * @param Table[] $tableList
     * @throws TDBMException
     */
    private function generateFactory(array $tableList) : void
    {
        $daoNamespace = $this->configuration->getDaoNamespace();
        $daoFactoryClassName = $this->namingStrategy->getDaoFactoryClassName();

        // For each table, let's write a property.

        $str = "<?php
declare(strict_types=1);

/*
 * This file has been automatically generated by TDBM.
 * DO NOT edit this file, as it might be overwritten.
 */

namespace {$daoNamespace}\\Generated;

";
        foreach ($tableList as $table) {
            $tableName = $table->getName();
            $daoClassName = $this->namingStrategy->getDaoClassName($tableName);
            $str .= "use {$daoNamespace}\\".$daoClassName.";\n";
        }

        $str .= "
/**
 * The $daoFactoryClassName provides an easy access to all DAOs generated by TDBM.
 *
 */
class $daoFactoryClassName
{
";

        foreach ($tableList as $table) {
            $tableName = $table->getName();
            $daoClassName = $this->namingStrategy->getDaoClassName($tableName);
            $daoInstanceName = self::toVariableName($daoClassName);

            $str .= '    /**
     * @var '.$daoClassName.'
     */
    private $'.$daoInstanceName.';

    /**
     * Returns an instance of the '.$daoClassName.' class.
     *
     * @return '.$daoClassName.'
     */
    public function get'.$daoClassName.'() : '.$daoClassName.'
    {
        return $this->'.$daoInstanceName.';
    }

    /**
     * Sets the instance of the '.$daoClassName.' class that will be returned by the factory getter.
     *
     * @param '.$daoClassName.' $'.$daoInstanceName.'
     */
    public function set'.$daoClassName.'('.$daoClassName.' $'.$daoInstanceName.') : void
    {
        $this->'.$daoInstanceName.' = $'.$daoInstanceName.';
    }';
        }

        $str .= '
}
';

        $possibleFileName = $this->configuration->getPathFinder()->getPath($daoNamespace.'\\Generated\\'.$daoFactoryClassName)->getPathname();

        $this->dumpFile($possibleFileName, $str);
    }

    /**
     * Transforms a string to camelCase (except the first letter will be uppercase too).
     * Underscores and spaces are removed and the first letter after the underscore is uppercased.
     * Quoting is removed if present.
     *
     * @param string $str
     *
     * @return string
     */
    public static function toCamelCase(string $str) : string
    {
        $str = str_replace(array('`', '"', '[', ']'), '', $str);

        $str = strtoupper(substr($str, 0, 1)).substr($str, 1);
        while (true) {
            $pos = strpos($str, '_');
            if ($pos === false) {
                $pos = strpos($str, ' ');
                if ($pos === false) {
                    break;
                }
            }

            $before = substr($str, 0, $pos);
            $after = substr($str, $pos + 1);
            $str = $before.strtoupper(substr($after, 0, 1)).substr($after, 1);
        }

        return $str;
    }

    /**
     * Tries to put string to the singular form (if it is plural).
     * We assume the table names are in english.
     *
     * @param string $str
     *
     * @return string
     */
    public static function toSingular(string $str): string
    {
        return Inflector::singularize($str);
    }

    /**
     * Put the first letter of the string in lower case.
     * Very useful to transform a class name into a variable name.
     *
     * @param string $str
     *
     * @return string
     */
    public static function toVariableName(string $str): string
    {
        return strtolower(substr($str, 0, 1)).substr($str, 1);
    }

    /**
     * Ensures the file passed in parameter can be written in its directory.
     *
     * @param string $fileName
     *
     * @throws TDBMException
     */
    private function ensureDirectoryExist(string $fileName): void
    {
        $dirName = dirname($fileName);
        if (!file_exists($dirName)) {
            $old = umask(0);
            $result = mkdir($dirName, 0775, true);
            umask($old);
            if ($result === false) {
                throw new TDBMException("Unable to create directory: '".$dirName."'.");
            }
        }
    }

    private function dumpFile(string $fileName, string $content) : void
    {
        $this->ensureDirectoryExist($fileName);
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile($fileName, $content);
        @chmod($fileName, 0664);
    }

    /**
     * Transforms a DBAL type into a PHP type (for PHPDoc purpose).
     *
     * @param Type $type The DBAL type
     *
     * @return string The PHP type
     */
    public static function dbalTypeToPhpType(Type $type) : string
    {
        $map = [
            Type::TARRAY => 'array',
            Type::SIMPLE_ARRAY => 'array',
            'json' => 'array',  // 'json' is supported from Doctrine DBAL 2.6 only.
            Type::JSON_ARRAY => 'array',
            Type::BIGINT => 'string',
            Type::BOOLEAN => 'bool',
            Type::DATETIME_IMMUTABLE => '\DateTimeImmutable',
            Type::DATETIMETZ_IMMUTABLE => '\DateTimeImmutable',
            Type::DATE_IMMUTABLE => '\DateTimeImmutable',
            Type::TIME_IMMUTABLE => '\DateTimeImmutable',
            Type::DECIMAL => 'string',
            Type::INTEGER => 'int',
            Type::OBJECT => 'string',
            Type::SMALLINT => 'int',
            Type::STRING => 'string',
            Type::TEXT => 'string',
            Type::BINARY => 'resource',
            Type::BLOB => 'resource',
            Type::FLOAT => 'float',
            Type::GUID => 'string',
        ];

        return isset($map[$type->getName()]) ? $map[$type->getName()] : $type->getName();
    }

    /**
     * @param Table $table
     * @return string[]
     * @throws TDBMException
     */
    public static function getPrimaryKeyColumnsOrFail(Table $table): array
    {
        if ($table->getPrimaryKey() === null) {
            // Security check: a table MUST have a primary key
            throw new TDBMException(sprintf('Table "%s" does not have any primary key', $table->getName()));
        }
        return $table->getPrimaryKey()->getUnquotedColumns();
    }
}
