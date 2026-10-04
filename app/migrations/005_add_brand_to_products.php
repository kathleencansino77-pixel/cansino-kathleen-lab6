<?php

class Add_brand_to_products {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $this->_lava->dbforge->add_column('products', [
            'brand' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => FALSE,
                'after'      => 'product_name'
            ]
        ]);
    }

    public function down()
    {
        $this->_lava->dbforge->drop_column('products', 'brand');
    }
}