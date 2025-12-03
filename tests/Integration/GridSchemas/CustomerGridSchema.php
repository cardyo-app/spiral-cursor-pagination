<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Integration\GridSchemas;

use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Specification\Sorter\Sorter;

/**
 * Example GridSchema for Customer entity used in tests.
 */
class CustomerGridSchema extends GridSchema
{
    public function __construct()
    {
        $this->addSorter('name', new Sorter('name'));
        $this->addSorter('created', new Sorter('created_at'));
        $this->addSorter('activity', new Sorter('last_activity_at'));
        $this->addSorter('logins', new Sorter('login_count'));
    }
}
