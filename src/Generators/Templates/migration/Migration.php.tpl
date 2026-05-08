<?php

declare(strict_types=1);

namespace {ns};

use {migrationFqcn};

class Create{resourcePlural}Table extends {migrationShort}
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
{fieldsContent}            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
{deletedAtField}        ]);

        $this->forge->addKey('id', true);
{indexes}{foreignKeys}        $this->forge->createTable('{table}');
    }

    public function down()
    {
{dropForeignKeys}        $this->forge->dropTable('{table}');
    }
}
