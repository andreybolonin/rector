<?php

declare(strict_types=1);

namespace Rector\CakePHP\Rector\MethodCall;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use Rector\CakePHP\ValueObject\ArrayItemsAndFluentClass;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;

/**
 * @see \Rector\CakePHP\Tests\Rector\MethodCall\ArrayToFluentCallRector\ArrayToFluentCallRectorTest
 */
final class ArrayToFluentCallRector extends AbstractRector implements ConfigurableRectorInterface
{
    /**
     * @var string
     */
    public const CONFIGURABLE_CLASSES = '$configurableClasses';

    /**
     * @var string
     */
    public const FACTORY_METHODS = '$factoryMethods';

    /**
     * @var string
     */
    private const ARGUMENT_POSITION = 'argumentPosition';

    /**
     * @var string
     */
    private const CLASSNAME = 'class';

    /**
     * @var mixed[]
     */
    private $configurableClasses = [];

    /**
     * @var mixed[]
     */
    private $factoryMethods = [];

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Moves array options to fluent setter method calls.', [
            new CodeSample(
                <<<'PHP'
class ArticlesTable extends \Cake\ORM\Table
{
    public function initialize(array $config)
    {
        $this->belongsTo('Authors', [
            'foreignKey' => 'author_id',
            'propertyName' => 'person'
        ]);
    }
}
PHP
                ,
                <<<'PHP'
class ArticlesTable extends \Cake\ORM\Table
{
    public function initialize(array $config)
    {
        $this->belongsTo('Authors')
            ->setForeignKey('author_id')
            ->setProperty('person');
    }
}
PHP
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    /**
     * @param MethodCall $node
     */
    public function refactor(Node $node): ?Node
    {
        $config = $this->matchTypeAndMethodName($node);
        if ($config === null) {
            return null;
        }

        [$argumentPosition, $class] = $config;

        $fluentMethods = $this->configurableClasses[$class] ?? [];
        if (! $fluentMethods) {
            return null;
        }

        return $this->replaceArrayToFluentMethodCalls($node, $argumentPosition, $fluentMethods);
    }

    public function configure(array $configuration): void
    {
        $this->configurableClasses = $configuration[self::CONFIGURABLE_CLASSES] ?? [];
        $this->factoryMethods = $configuration[self::FACTORY_METHODS] ?? [];
    }

    /**
     * @return mixed[]|null
     */
    private function matchTypeAndMethodName(MethodCall $methodCall): ?array
    {
        foreach ($this->factoryMethods as $className => $methodName) {
            if (! $this->isObjectType($methodCall->var, $className)) {
                continue;
            }

            /** @var string[] $methodNames */
            $methodNames = array_keys($methodName);
            if (! $this->isNames($methodCall->name, $methodNames)) {
                continue;
            }

            $currentMethodName = $this->getName($methodCall->name);
            if ($currentMethodName === null) {
                continue;
            }

            $config = $methodName[$currentMethodName];
            $argumentPosition = $config[self::ARGUMENT_POSITION] ?? 1;
            $class = $config[self::CLASSNAME];

            return [$argumentPosition, $class];
        }

        return null;
    }

    /**
     * @param string[] $fluentMethods
     */
    private function replaceArrayToFluentMethodCalls(
        MethodCall $methodCall,
        int $argumentPosition,
        array $fluentMethods
    ): ?MethodCall {
        if (count($methodCall->args) !== $argumentPosition) {
            return null;
        }

        $argumentValue = $methodCall->args[$argumentPosition - 1]->value;
        if (! $argumentValue instanceof Array_) {
            return null;
        }

        $arrayItemsAndFluentClass = $this->extractFluentMethods($argumentValue->items, $fluentMethods);

        if ($arrayItemsAndFluentClass->getArrayItems() !== []) {
            $argumentValue->items = $arrayItemsAndFluentClass->getArrayItems();
        } else {
            unset($methodCall->args[$argumentPosition - 1]);
        }

        if ($arrayItemsAndFluentClass->getFluentCalls() === []) {
            return null;
        }

        $node = $methodCall;

        foreach ($arrayItemsAndFluentClass->getFluentCalls() as $method => $arg) {
            $args = $this->createArgs([$arg]);
            $node = $this->createMethodCall($node, $method, $args);
        }

        return $node;
    }

    /**
     * @param array<ArrayItem|null> $originalArrayItems
     * @param string[] $arrayMap
     */
    private function extractFluentMethods(array $originalArrayItems, array $arrayMap): ArrayItemsAndFluentClass
    {
        $newArrayItems = [];
        $fluentCalls = [];

        foreach ($originalArrayItems as $arrayItem) {
            if ($arrayItem === null) {
                continue;
            }

            /** @var ArrayItem $arrayItem */
            $key = $arrayItem->key;

            if ($key instanceof String_ && isset($arrayMap[$key->value])) {
                /** @var string $methodName */
                $methodName = $arrayMap[$key->value];
                $fluentCalls[$methodName] = $arrayItem->value;
            } else {
                $newArrayItems[] = $arrayItem;
            }
        }

        return new ArrayItemsAndFluentClass($newArrayItems, $fluentCalls);
    }
}
