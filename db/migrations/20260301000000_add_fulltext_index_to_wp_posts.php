<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddFulltextIndexToWpPosts extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('wp_posts');
        $table
            ->addIndex(['post_title', 'post_content'], ['type' => 'fulltext', 'name' => 'ft_posts_title_content'])
            ->update();
    }
}
