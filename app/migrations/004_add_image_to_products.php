<?php

class Add_image_to_products
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $this->_lava->dbforge->add_column('products', [
            'image' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => TRUE,
                'after'      => 'description'
            ]
        ]);
    }

    public function down()
    {
        $this->_lava->dbforge->drop_column(
            'products',
            'image'
        );
    }
}