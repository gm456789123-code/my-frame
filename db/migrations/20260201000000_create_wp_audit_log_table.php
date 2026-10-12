<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateWpAuditLogTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('wp_audit_log');
        $table
            ->addColumn('prev_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('current_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('user_id', 'biginteger', ['null' => false])
            ->addColumn('action', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('object_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('payload_json', 'text', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['current_hash'])
            ->addIndex(['user_id'])
            ->addIndex(['action'])
            ->addIndex(['created_at'])
            ->create();
    }
}
