<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSoftDeleteToWpPosts extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('wp_posts');
        $table
            ->addColumn('deleted_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('deleted_by', 'biginteger', ['null' => true, 'default' => null])
            ->addColumn('locale', 'string', ['limit' => 5, 'null' => false, 'default' => 'en'])
            ->addIndex(['deleted_at'])
            ->update();
    }
}
