<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Interceptor;

use Cardyo\SpiralCursorPagination\Attribute\CursorPaginate;
use Cardyo\SpiralCursorPagination\Response\Connection;
use Cardyo\SpiralCursorPagination\Response\ConnectionResponseInterface;
use Cardyo\SpiralCursorPagination\Service\CursorPaginationHelper;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Select;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionParameter;
use Spiral\DataGrid\GridSchema;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\HandlerInterface;
use Spiral\Interceptors\InterceptorInterface;

/**
 * Interceptor for automatic cursor pagination in controller methods.
 *
 * Similar to GridInterceptor, this intercepts controller methods that return Select queries
 * and automatically applies cursor pagination based on the #[CursorPaginate] attribute.
 *
 * Usage:
 * ```php
 * #[CursorPaginate(
 *     schema: CustomerGridSchema::class,
 *     view: [CustomerDTO::class, 'fromEntity'],
 *     countTotal: true
 * )]
 * public function index(TenantUserInterface $user): Select {
 *     return $this->customers
 *         ->forTenant($user->getTenantId())
 *         ->select();
 * }
 * ```
 *
 * The interceptor will:
 * 1. Execute the controller to get the Select query
 * 2. Apply the GridSchema filters/sorting from the request
 * 3. Apply cursor pagination
 * 4. Optionally map results through a view/mapper
 * 5. Return a Connection with paginated results
 */
final class CursorPaginationInterceptor implements InterceptorInterface
{
    private array $cache = [];

    public function __construct(
        private readonly CursorPaginationHelper $paginationHelper,
        private readonly ServerRequestInterface $request,
        private readonly ContainerInterface $container,
        private readonly ConnectionResponseInterface $response,
    ) {}

    public function intercept(CallContextInterface $context, HandlerInterface $handler): mixed
    {
        $reflection = $context->getTarget()->getReflection();

        if (!$reflection instanceof \ReflectionMethod) {
            return $handler->handle($context);
        }

        // Execute controller first to get the Select query
        $result = $handler->handle($context);

        // Only process if result is a Select query
        if (!$result instanceof Select) {
            return $result;
        }

        // Get pagination configuration from method attribute
        $config = $this->getConfig($reflection);
        if ($config === null) {
            return $result;
        }

        // Apply cursor pagination (with view mapper if provided)
        $connection = $this->createConnectionFromSelect($result, $config);

        // Wrap in response object (similar to GridInterceptor)
        return $this->response->withConnection($connection, $config['options']);
    }

    /**
     * Get cached configuration for the method.
     */
    private function getConfig(\ReflectionMethod $method): ?array
    {
        $key = sprintf('%s::%s', $method->getDeclaringClass()->getName(), $method->getName());

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $this->cache[$key] = null;

        $attributes = $method->getAttributes(CursorPaginate::class);
        if (empty($attributes)) {
            return null;
        }

        /** @var CursorPaginate $attribute */
        $attribute = $attributes[0]->newInstance();

        return $this->cache[$key] = $this->makeConfig($attribute);
    }

    /**
     * Create configuration array from attribute.
     *
     * Follows the same resolution logic as GridInterceptor:
     * 1. Resolve schema from container
     * 2. Resolve view/mapper from container if it's a string class name
     * 3. Support callable arrays like [ClassName::class, 'method']
     */
    private function makeConfig(CursorPaginate $attribute): array
    {
        $config = [
            'schema' => $this->container->get($attribute->schema),
            'view' => $attribute->view,
            'countTotal' => $attribute->countTotal,
            'options' => $attribute->options,
        ];

        if (is_string($config['view'])) {
            $config['view'] = $this->container->get($config['view']);
        }

        // Support [ClassName::class, 'method'] format
        if (is_array($config['view']) && count($config['view']) === 2) {
            [$class, $method] = $config['view'];

            // Resolve class from container if it's a string
            if (is_string($class) && $this->container->has($class)) {
                $config['view'] = [$this->container->get($class), $method];
            }
        }

        return $config;
    }

    /**
     * Create Connection from a Select query returned by controller.
     */
    private function createConnectionFromSelect(Select $select, array $config): Connection
    {
        // GridSchema is required
        if ($config['schema'] === null) {
            throw new \RuntimeException(
                'GridSchema is required for cursor pagination. ' .
                'Add schema parameter to #[CursorPaginate] attribute or ensure GridSchema is configured.'
            );
        }

        $gridSchema = $config['schema'];

        // Validate that GridSchema has a cursor paginator
        $paginator = $gridSchema->getPaginator();
        if ($paginator === null) {
            throw new \RuntimeException(
                'GridSchema must have a cursor paginator configured. ' .
                'Use $gridSchema->setPaginator($paginationHelper->createPaginator()) in your GridSchema.'
            );
        }

        // Validate it's a cursor paginator (not offset-based)
        if (!$paginator instanceof \Cardyo\SpiralCursorPagination\Specification\Pagination\CursorPaginator) {
            throw new \RuntimeException(
                'GridSchema must use CursorPaginator, not ' . get_class($paginator) . '. ' .
                'Configure your GridSchema with: $gridSchema->setPaginator($paginationHelper->createPaginator())'
            );
        }

        // Validate cursor pagination has deterministic ordering
        // Check if ORDER BY exists from controller OR sort parameters
        $queryParams = $this->request->getQueryParams();
        $hasSortParams = isset($queryParams['sort']) && !empty($queryParams['sort']);
        $hasOrderBy = $this->hasOrderBy($select);

        if (!$hasOrderBy && !$hasSortParams) {
            // No ordering at all - provide helpful error message
            $message = 'Cursor pagination requires explicit sorting for deterministic ordering. You have two options:' . "\n\n";
            $message .= '1. Apply orderBy() in your controller:' . "\n";
            $message .= '   return $this->select()->orderBy(\'created_at\', \'DESC\');' . "\n\n";
            $message .= '2. Add sort parameter to request:' . "\n";
            $message .= '   ?sort[fieldName]=asc or ?sort[fieldName]=desc';

            if (!empty($gridSchema->getSorters())) {
                $availableSorters = array_keys($gridSchema->getSorters());
                $message .= "\n   Available sorters: " . implode(', ', $availableSorters);
            }

            throw new \RuntimeException($message);
        }

        // Count total if requested
        $totalCount = null;
        if ($config['countTotal']) {
            $totalCount = (clone $select)->count();
        }

        // Use helper to paginate (with mapper if provided)
        return $this->paginationHelper->paginate(
            query: $select,
            request: $this->request,
            gridSchema: $gridSchema,
            mapper: $config['view'],  // Apply view/mapper if configured
            totalCount: $totalCount,
        );
    }

    /**
     * Check if Select query has ORDER BY clause.
     */
    private function hasOrderBy(Select $select): bool
    {
        $tokens = $select->getBuilder()->getQuery()->getTokens();
        return !empty($tokens['orderBy']);
    }

    /**
     * Get primary key from Select query.
     */
    private function getPrimaryKey(Select $select): ?string
    {
        try {
            $orm = $select->getBuilder()->getLoader()->getOrm();
            $role = $select->getBuilder()->getLoader()->getTarget();
            $schema = $orm->getSchema();

            $primaryKey = $schema->define($role, \Cycle\ORM\SchemaInterface::PRIMARY_KEY);

            if (is_string($primaryKey)) {
                return $primaryKey;
            }

            // Composite primary key - use first field
            if (is_array($primaryKey) && !empty($primaryKey)) {
                return $primaryKey[0];
            }
        } catch (\Throwable $e) {
            // Can't determine primary key
        }

        return null;
    }
}
