<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Interceptor;

use Cardyo\SpiralCursorPagination\Attribute\CursorPaginate;
use Cardyo\SpiralCursorPagination\Response\Connection;
use Cardyo\SpiralCursorPagination\Service\CursorPaginationHelper;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Select;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionParameter;
use Spiral\Core\CoreInterceptorInterface;
use Spiral\Core\CoreInterface;
use Spiral\DataGrid\GridSchema;

/**
 * Interceptor for automatic cursor pagination in controller methods.
 *
 * Detects #[CursorPaginate] attribute on controller parameters and automatically
 * injects a Connection response with paginated data.
 *
 * Example controller:
 * ```php
 * class CustomerController
 * {
 *     public function index(
 *         #[CursorPaginate(
 *             entity: Customer::class,
 *             schema: CustomerGridSchema::class,
 *             mapper: [CustomerDTO::class, 'fromEntity']
 *         )]
 *         Connection $connection
 *     ): Connection {
 *         return $connection;
 *     }
 * }
 * ```
 */
final class CursorPaginationInterceptor implements CoreInterceptorInterface
{
    public function __construct(
        private readonly CursorPaginationHelper $paginationHelper,
        private readonly ORMInterface $orm,
        private readonly ServerRequestInterface $request,
        private readonly ContainerInterface $container,
    ) {}

    public function process(string $controller, string $action, array $parameters, CoreInterface $core): mixed
    {
        $reflection = new \ReflectionMethod($controller, $action);

        foreach ($reflection->getParameters() as $parameter) {
            $attributes = $parameter->getAttributes(CursorPaginate::class);

            if (empty($attributes)) {
                continue;
            }

            /** @var CursorPaginate $attribute */
            $attribute = $attributes[0]->newInstance();

            // Create the Connection and inject it
            $connection = $this->createConnection($attribute, $parameter);
            $parameters[$parameter->getName()] = $connection;
        }

        return $core->callAction($controller, $action, $parameters);
    }

    private function createConnection(CursorPaginate $attribute, ReflectionParameter $parameter): Connection
    {
        // Get base query from repository
        $select = $this->orm->getRepository($attribute->entity)->select();

        // Resolve GridSchema and apply default sorting if needed
        [$gridSchema, $select] = $this->resolveGridSchemaAndApplyDefaultSort($attribute, $select);

        // Resolve mapper
        $mapper = $this->resolveMapper($attribute);

        // Count total if requested
        $totalCount = null;
        if ($attribute->countTotal) {
            $totalCount = (clone $select)->count();
        }

        // Use helper to paginate
        return $this->paginationHelper->paginate(
            query: $select,
            request: $this->request,
            gridSchema: $gridSchema,
            mapper: $mapper,
            totalCount: $totalCount,
        );
    }

    /**
     * Resolve GridSchema and apply default sorting if needed.
     *
     * @return array{GridSchema, Select}
     */
    private function resolveGridSchemaAndApplyDefaultSort(CursorPaginate $attribute, Select $select): array
    {
        // If schema class is explicitly provided, instantiate it
        if ($attribute->schema !== null) {
            return [$this->container->get($attribute->schema), $select];
        }

        // Try to auto-detect schema by naming convention
        // e.g., Customer -> CustomerGridSchema
        $entityClass = $attribute->entity;
        $schemaClass = $entityClass . 'GridSchema';

        if (class_exists($schemaClass)) {
            return [$this->container->get($schemaClass), $select];
        }

        // Fallback: create a minimal GridSchema with default sorting and pagination
        // Use the primary key from the schema for stable ordering
        $schema = $this->orm->getSchema();
        $primaryKey = $schema->define($attribute->entity, \Cycle\ORM\SchemaInterface::PRIMARY_KEY);

        // Apply default ORDER BY on primary key to ensure stable ordering
        $select = $select->orderBy($primaryKey, 'ASC');

        $gridSchema = new GridSchema();
        $gridSchema->setPaginator(
            $this->paginationHelper->createPaginator(
                $attribute->pageSize,
                $attribute->maxPageSize,
            )
        );

        return [$gridSchema, $select];
    }

    private function resolveMapper(CursorPaginate $attribute): ?callable
    {
        if ($attribute->mapper === null) {
            return null;
        }

        // If it's already callable, return as-is
        if (is_callable($attribute->mapper)) {
            return $attribute->mapper;
        }

        // If it's an array like [ClassName::class, 'method']
        if (is_array($attribute->mapper)) {
            return $attribute->mapper;
        }

        // If it's a string like 'ClassName::method'
        if (is_string($attribute->mapper) && str_contains($attribute->mapper, '::')) {
            [$class, $method] = explode('::', $attribute->mapper, 2);
            return [$class, $method];
        }

        return null;
    }
}
