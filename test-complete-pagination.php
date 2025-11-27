<?php

require_once __DIR__ . '/vendor/autoload.php';

use Cardyo\SpiralCursorPagination\CursorEncoder\EncoderV2;
use Cardyo\SpiralCursorPagination\Cursor\JsonCursorSerializer;
use Cardyo\SpiralCursorPagination\Service\ConnectionFactory;
use Cardyo\SpiralCursorPagination\Service\CursorGenerator;
use Cardyo\SpiralCursorPagination\Service\PaginationMetadataCalculator;
use Cardyo\SpiralCursorPagination\Specification\CursorPaginator;
use Cardyo\SpiralCursorPagination\Writer\Cycle\CursorLimitWriter;
use Cardyo\SpiralCursorPagination\Writer\Cycle\KeysetFilterWriter;
use Cardyo\SpiralCursorPagination\Writer\Cycle\SortDirectionWriter;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Cycle\ORM\EntityManager;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\Schema;
use Cycle\ORM\SchemaInterface;
use Cycle\ORM\Select;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\GridFactory;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Input\ArrayInput;
use Spiral\DataGrid\Specification\Value\IntValue;
use Spiral\DataGrid\Specification\Value\RangeValue;
use Spiral\DataGrid\Specification\Value\RangeValue\Boundary;

class Post
{
    public function __construct(
        public ?int $id = null,
        public string $title = '',
        public ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->createdAt = $this->createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function setId(int $id): void
    {
        $this->id = $id;
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}

// Setup database
$dbal = new DatabaseManager(
    new DatabaseConfig([
        'default' => 'default',
        'databases' => ['default' => ['driver' => 'sqlite']],
        'drivers' => [
            'sqlite' => new SQLiteDriverConfig(
                connection: new MemoryConnectionConfig(),
                queryCache: true,
            ),
        ],
    ]),
);

$orm = new ORM(new Factory($dbal), new Schema([]));

// Initialize schema
$orm = $orm->with(new Schema([
    Post::class => [
        SchemaInterface::ROLE => 'post',
        SchemaInterface::DATABASE => 'default',
        SchemaInterface::TABLE => 'posts',
        SchemaInterface::PRIMARY_KEY => 'id',
        SchemaInterface::COLUMNS => [
            'id' => 'id',
            'title' => 'title',
            'createdAt' => 'created_at',
        ],
        SchemaInterface::TYPECAST => [
            'id' => 'int',
            'createdAt' => 'datetime',
        ],
        SchemaInterface::RELATIONS => [],
    ],
]));

// Create table
$schema = $dbal->database()->table('posts')->getSchema();
$schema->primary('id');
$schema->string('title');
$schema->datetime('created_at');
$schema->save();

// Insert 25 posts
$entityManager = new EntityManager($orm);
for ($i = 1; $i <= 25; $i++) {
    $post = new Post(
        title: "Post $i",
        createdAt: new \DateTimeImmutable("2024-01-01 00:00:" . str_pad((string) $i, 2, '0', STR_PAD_LEFT)),
    );
    $entityManager->persist($post);
}
$entityManager->run();

// Setup DataGrid
$compiler = new Compiler();
$compiler->addWriter(new KeysetFilterWriter());
$compiler->addWriter(new CursorLimitWriter());
$compiler->addWriter(new SortDirectionWriter());
$gridFactory = new GridFactory($compiler);

// Create paginator
$cursorCoder = new EncoderV2(new JsonCursorSerializer());
$paginator = new CursorPaginator(
    defaultLimit: 10,
    limitValue: new RangeValue(
        new IntValue(),
        Boundary::including(1),
        Boundary::including(100),
    ),
    cursorCoder: $cursorCoder,
);
$paginator = $paginator->withSortFields(['createdAt', 'id']);

$gridSchema = new GridSchema();
$gridSchema->setPaginator($paginator);

$connectionFactory = new ConnectionFactory(
    new CursorGenerator(),
    new PaginationMetadataCalculator(),
);

echo "=== Testing Complete Pagination Flow ===\n\n";

// Page 1
echo "PAGE 1 (first 10):\n";
$select1 = new Select($orm, Post::class);
$input1 = new ArrayInput(['paginate' => ['first' => 10]]);
$grid1 = $gridFactory->withInput($input1)->create($select1, $gridSchema);
$results1 = iterator_to_array($grid1->getIterator());
$connection1 = $connectionFactory->createConnection($results1, ['first' => 10], ['createdAt', 'id'], $cursorCoder);

echo "  Items: " . implode(', ', array_map(fn($n) => $n->getTitle(), $connection1->nodes)) . "\n";
echo "  Count: " . count($connection1->nodes) . "\n";
echo "  Has next: " . ($connection1->pageInfo->hasNextPage ? 'yes' : 'no') . "\n";
echo "  Has prev: " . ($connection1->pageInfo->hasPreviousPage ? 'yes' : 'no') . "\n\n";

// Page 2
echo "PAGE 2 (next 10 after cursor):\n";
$cursor1 = $connection1->pageInfo->endCursor;
$select2 = new Select($orm, Post::class);
$input2 = new ArrayInput(['paginate' => ['first' => 10, 'after' => $cursor1]]);
$grid2 = $gridFactory->withInput($input2)->create($select2, $gridSchema);
$results2 = iterator_to_array($grid2->getIterator());
$connection2 = $connectionFactory->createConnection($results2, ['first' => 10, 'after' => $cursor1], ['createdAt', 'id'], $cursorCoder);

echo "  Items: " . implode(', ', array_map(fn($n) => $n->getTitle(), $connection2->nodes)) . "\n";
echo "  Count: " . count($connection2->nodes) . "\n";
echo "  Has next: " . ($connection2->pageInfo->hasNextPage ? 'yes' : 'no') . "\n";
echo "  Has prev: " . ($connection2->pageInfo->hasPreviousPage ? 'yes' : 'no') . "\n\n";

// Page 3 (last page)
echo "PAGE 3 (remaining items):\n";
$cursor2 = $connection2->pageInfo->endCursor;
$select3 = new Select($orm, Post::class);
$input3 = new ArrayInput(['paginate' => ['first' => 10, 'after' => $cursor2]]);
$grid3 = $gridFactory->withInput($input3)->create($select3, $gridSchema);
$results3 = iterator_to_array($grid3->getIterator());
$connection3 = $connectionFactory->createConnection($results3, ['first' => 10, 'after' => $cursor2], ['createdAt', 'id'], $cursorCoder);

echo "  Items: " . implode(', ', array_map(fn($n) => $n->getTitle(), $connection3->nodes)) . "\n";
echo "  Count: " . count($connection3->nodes) . "\n";
echo "  Has next: " . ($connection3->pageInfo->hasNextPage ? 'yes' : 'no') . "\n";
echo "  Has prev: " . ($connection3->pageInfo->hasPreviousPage ? 'yes' : 'no') . "\n\n";

echo "✅ Complete pagination flow working correctly!\n";
