<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Products extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->call->database();
        $this->call->library('api');
    }


    /*
    |--------------------------------------------------------------------------
    | GET /api/products
    |--------------------------------------------------------------------------
    */

    public function index()
    {
        $this->api->require_method('GET');
        $this->api->require_jwt();

        try {

            $stmt = $this->db->raw(
                "SELECT
                    id,
                    brand,
                    product_name,
                    description,
                    price,
                    quantity,
                    image,
                    created_at
                 FROM products
                 ORDER BY id DESC"
            );

            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($products as &$product) {
                if (!empty($product['image'])) {
                    $product['image'] = $this->image_url($product['image']);
                }
            }

            unset($product);

            $this->api->respond([
                'message' => 'Products retrieved successfully.',
                'data' => $products
            ], 200);

        } catch (Exception $e) {

            $this->fail(
                'Failed to retrieve products: ' . $e->getMessage(),
                500
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | GET /api/products/{id}
    |--------------------------------------------------------------------------
    */

    public function show($id)
    {
        $this->api->require_method('GET');
        $this->api->require_jwt();

        if (!is_numeric($id)) {
            $this->fail('Invalid product ID.', 400);
        }

        try {

            $stmt = $this->db->raw(
                "SELECT
                    id,
                    brand,
                    product_name,
                    description,
                    price,
                    quantity,
                    image,
                    created_at
                 FROM products
                 WHERE id = ?
                 LIMIT 1",
                [$id]
            );

            $product = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {

            $this->fail(
                'Failed to retrieve product: ' . $e->getMessage(),
                500
            );
        }

        if (!$product) {
            $this->fail('Product not found.', 404);
        }

        if (!empty($product['image'])) {
            $product['image'] = $this->image_url($product['image']);
        }

        $this->api->respond([
            'message' => 'Product retrieved successfully.',
            'data' => $product
        ], 200);
    }


    /*
    |--------------------------------------------------------------------------
    | POST /api/products
    |--------------------------------------------------------------------------
    | CREATE PRODUCT
    |--------------------------------------------------------------------------
    */

    public function store()
    {
        $this->api->require_method('POST');
        $this->api->require_jwt();

        $this->check_post_size_exceeded();

        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';

        if (
            stripos($content_type, 'multipart/form-data') !== false ||
            !empty($_POST)
        ) {
            $data = $_POST;
        } else {
            $data = $this->api->body();
        }

        $brand = trim((string)($data['brand'] ?? ''));
        $product_name = trim((string)($data['product_name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $price = $data['price'] ?? '';
        $quantity = $data['quantity'] ?? '';

        if ($brand === '') {
            $this->fail('Brand is required.', 400);
        }

        if ($product_name === '') {
            $this->fail('Product name is required.', 400);
        }

        if ($price === '' || !is_numeric($price)) {
            $this->fail('Valid price is required.', 400);
        }

        if ($quantity === '' || !is_numeric($quantity)) {
            $this->fail('Valid quantity is required.', 400);
        }

        if ((float)$price < 0) {
            $this->fail('Price cannot be negative.', 400);
        }

        if ((int)$quantity < 0) {
            $this->fail('Quantity cannot be negative.', 400);
        }


        /*
         * Upload image
         */

        $image_path = null;

        if (
            isset($_FILES['image']) &&
            isset($_FILES['image']['error']) &&
            $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            $image_path = $this->upload_image($_FILES['image']);

            if ($image_path === false) {
                $this->fail(
                    'Invalid image. Please upload a JPG, JPEG, PNG, WEBP, or GIF image up to 5MB.',
                    400
                );
            }
        }


        /*
         * Insert product
         */

        try {

            $stmt = $this->db->raw(
                "INSERT INTO products
                    (
                        brand,
                        product_name,
                        description,
                        price,
                        quantity,
                        image
                    )
                 VALUES
                    (?, ?, ?, ?, ?, ?)",
                [
                    $brand,
                    $product_name,
                    $description,
                    (float)$price,
                    (int)$quantity,
                    $image_path
                ]
            );

        } catch (Exception $e) {

            if ($image_path) {
                $this->delete_image($image_path);
            }

            $this->fail(
                'Database error: ' . $e->getMessage(),
                500
            );
        }

        if (!$stmt) {

            if ($image_path) {
                $this->delete_image($image_path);
            }

            $this->fail(
                'Failed to create product.',
                500
            );
        }


        /*
         * Get inserted ID
         */

        $product_id = $this->db->last_insert_id();

        if (!$product_id) {

            $this->fail(
                'Product was created but the product ID could not be retrieved.',
                500
            );
        }


        /*
         * Get newly-created product
         */

        try {

            $stmt = $this->db->raw(
                "SELECT
                    id,
                    brand,
                    product_name,
                    description,
                    price,
                    quantity,
                    image,
                    created_at
                 FROM products
                 WHERE id = ?
                 LIMIT 1",
                [$product_id]
            );

            $product = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {

            $this->fail(
                'Product was created but could not be retrieved: ' .
                $e->getMessage(),
                500
            );
        }

        if (!$product) {
            $this->fail(
                'Product was created but could not be retrieved.',
                500
            );
        }

        if (!empty($product['image'])) {
            $product['image'] = $this->image_url($product['image']);
        }

        $this->api->respond([
            'message' => 'Product created successfully.',
            'data' => $product
        ], 201);
    }


    /*
    |--------------------------------------------------------------------------
    | PUT /api/products/{id}
    |--------------------------------------------------------------------------
    | POST /api/products/{id}/update
    |--------------------------------------------------------------------------
    */

    public function update($id)
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        if (
            $method !== 'PUT' &&
            !(
                $method === 'POST' &&
                strtoupper((string)($_POST['_method'] ?? '')) === 'PUT'
            )
        ) {
            $this->fail('Method not allowed.', 405);
        }

        $this->api->require_jwt();

        if (!is_numeric($id)) {
            $this->fail('Invalid product ID.', 400);
        }

        $this->check_post_size_exceeded();


        /*
         * Get existing product
         */

        try {

            $stmt = $this->db->raw(
                "SELECT
                    id,
                    brand,
                    product_name,
                    description,
                    price,
                    quantity,
                    image
                 FROM products
                 WHERE id = ?
                 LIMIT 1",
                [$id]
            );

            $product = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {

            $this->fail(
                'Failed to retrieve product: ' . $e->getMessage(),
                500
            );
        }

        if (!$product) {
            $this->fail('Product not found.', 404);
        }


        /*
         * Get submitted data
         */

        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';

        if (
            stripos($content_type, 'multipart/form-data') !== false ||
            !empty($_POST)
        ) {
            $data = $_POST;
        } else {
            $data = $this->api->body();
        }


        /*
         * Preserve old values if not supplied
         */

        $brand = trim(
            (string)($data['brand'] ?? $product['brand'])
        );

        $product_name = trim(
            (string)($data['product_name'] ?? $product['product_name'])
        );

        $description = trim(
            (string)($data['description'] ?? $product['description'])
        );

        $price = $data['price'] ?? $product['price'];

        $quantity = $data['quantity'] ?? $product['quantity'];


        /*
         * Validation
         */

        if ($brand === '') {
            $this->fail('Brand is required.', 400);
        }

        if ($product_name === '') {
            $this->fail('Product name is required.', 400);
        }

        if ($price === '' || !is_numeric($price)) {
            $this->fail('Valid price is required.', 400);
        }

        if ($quantity === '' || !is_numeric($quantity)) {
            $this->fail('Valid quantity is required.', 400);
        }

        if ((float)$price < 0) {
            $this->fail('Price cannot be negative.', 400);
        }

        if ((int)$quantity < 0) {
            $this->fail('Quantity cannot be negative.', 400);
        }


        /*
         * Keep old image
         */

        $image_path = $product['image'];
        $new_image_uploaded = false;


        /*
         * Upload replacement image
         */

        if (
            isset($_FILES['image']) &&
            isset($_FILES['image']['error']) &&
            $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            $uploaded_image = $this->upload_image($_FILES['image']);

            if ($uploaded_image === false) {
                $this->fail(
                    'Invalid image. Please upload a JPG, JPEG, PNG, WEBP, or GIF image up to 5MB.',
                    400
                );
            }

            $image_path = $uploaded_image;
            $new_image_uploaded = true;
        }


        /*
         * Update product
         */

        try {

            $stmt = $this->db->raw(
                "UPDATE products
                 SET
                    brand = ?,
                    product_name = ?,
                    description = ?,
                    price = ?,
                    quantity = ?,
                    image = ?
                 WHERE id = ?",
                [
                    $brand,
                    $product_name,
                    $description,
                    (float)$price,
                    (int)$quantity,
                    $image_path,
                    $id
                ]
            );

        } catch (Exception $e) {

            if ($new_image_uploaded && $image_path) {
                $this->delete_image($image_path);
            }

            $this->fail(
                'Database error: ' . $e->getMessage(),
                500
            );
        }

        if (!$stmt) {

            if ($new_image_uploaded && $image_path) {
                $this->delete_image($image_path);
            }

            $this->fail(
                'Failed to update product.',
                500
            );
        }


        /*
         * Delete old image after successful update
         */

        if (
            $new_image_uploaded &&
            !empty($product['image']) &&
            $product['image'] !== $image_path
        ) {
            $this->delete_image($product['image']);
        }


        /*
         * Return updated product
         */

        try {

            $stmt = $this->db->raw(
                "SELECT
                    id,
                    brand,
                    product_name,
                    description,
                    price,
                    quantity,
                    image,
                    created_at
                 FROM products
                 WHERE id = ?
                 LIMIT 1",
                [$id]
            );

            $updated_product = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {

            $this->fail(
                'Product was updated but could not be retrieved: ' .
                $e->getMessage(),
                500
            );
        }

        if (!$updated_product) {
            $this->fail(
                'Product was updated but could not be retrieved.',
                500
            );
        }

        if (!empty($updated_product['image'])) {
            $updated_product['image'] =
                $this->image_url($updated_product['image']);
        }

        $this->api->respond([
            'message' => 'Product updated successfully.',
            'data' => $updated_product
        ], 200);
    }


    /*
    |--------------------------------------------------------------------------
    | PATCH /api/products/{id}
    |--------------------------------------------------------------------------
    */

    public function patch($id)
    {
        $this->api->require_method('PATCH');
        $this->api->require_jwt();

        if (!is_numeric($id)) {
            $this->fail('Invalid product ID.', 400);
        }


        /*
         * Get existing product
         */

        $stmt = $this->db->raw(
            "SELECT
                id,
                brand,
                product_name,
                description,
                price,
                quantity,
                image
             FROM products
             WHERE id = ?
             LIMIT 1",
            [$id]
        );

        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $this->fail('Product not found.', 404);
        }


        /*
         * Get request data
         */

        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';

        if (
            stripos($content_type, 'multipart/form-data') !== false ||
            !empty($_POST)
        ) {
            $data = $_POST;
        } else {
            $data = $this->api->body();
        }

        $brand = trim(
            (string)($data['brand'] ?? $product['brand'])
        );

        $product_name = trim(
            (string)($data['product_name'] ?? $product['product_name'])
        );

        $description = trim(
            (string)($data['description'] ?? $product['description'])
        );

        $price = $data['price'] ?? $product['price'];

        $quantity = $data['quantity'] ?? $product['quantity'];

        $image = $product['image'];


        /*
         * Validation
         */

        if ($brand === '') {
            $this->fail('Brand is required.', 400);
        }

        if ($product_name === '') {
            $this->fail('Product name is required.', 400);
        }

        if ($price === '' || !is_numeric($price)) {
            $this->fail('Valid price is required.', 400);
        }

        if ($quantity === '' || !is_numeric($quantity)) {
            $this->fail('Valid quantity is required.', 400);
        }

        if ((float)$price < 0) {
            $this->fail('Price cannot be negative.', 400);
        }

        if ((int)$quantity < 0) {
            $this->fail('Quantity cannot be negative.', 400);
        }


        /*
         * Image
         */

        $new_image_uploaded = false;

        if (
            isset($_FILES['image']) &&
            isset($_FILES['image']['error']) &&
            $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            $uploaded_image = $this->upload_image($_FILES['image']);

            if ($uploaded_image === false) {
                $this->fail(
                    'Invalid image. Please upload a JPG, JPEG, PNG, WEBP, or GIF image up to 5MB.',
                    400
                );
            }

            $image = $uploaded_image;
            $new_image_uploaded = true;
        }


        /*
         * Update
         */

        $stmt = $this->db->raw(
            "UPDATE products
             SET
                brand = ?,
                product_name = ?,
                description = ?,
                price = ?,
                quantity = ?,
                image = ?
             WHERE id = ?",
            [
                $brand,
                $product_name,
                $description,
                (float)$price,
                (int)$quantity,
                $image,
                $id
            ]
        );

        if (!$stmt) {

            if ($new_image_uploaded && $image) {
                $this->delete_image($image);
            }

            $this->fail(
                'Failed to update product.',
                500
            );
        }


        /*
         * Delete old image
         */

        if (
            $new_image_uploaded &&
            !empty($product['image']) &&
            $product['image'] !== $image
        ) {
            $this->delete_image($product['image']);
        }


        /*
         * Return updated product
         */

        $stmt = $this->db->raw(
            "SELECT
                id,
                brand,
                product_name,
                description,
                price,
                quantity,
                image,
                created_at
             FROM products
             WHERE id = ?
             LIMIT 1",
            [$id]
        );

        $updated_product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$updated_product) {
            $this->fail(
                'Product was updated but could not be retrieved.',
                500
            );
        }

        if (!empty($updated_product['image'])) {
            $updated_product['image'] =
                $this->image_url($updated_product['image']);
        }

        $this->api->respond([
            'message' => 'Product updated successfully.',
            'data' => $updated_product
        ], 200);
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE /api/products/{id}
    |--------------------------------------------------------------------------
    */

    public function delete($id)
    {
        $this->api->require_method('DELETE');
        $this->api->require_jwt();

        if (!is_numeric($id)) {
            $this->fail('Invalid product ID.', 400);
        }


        /*
         * Get product first
         */

        $stmt = $this->db->raw(
            "SELECT
                id,
                image
             FROM products
             WHERE id = ?
             LIMIT 1",
            [$id]
        );

        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $this->fail(
                'Product not found.',
                404
            );
        }


        /*
         * Delete database record
         */

        $stmt = $this->db->raw(
            "DELETE FROM products
             WHERE id = ?",
            [$id]
        );

        if (!$stmt) {
            $this->fail(
                'Failed to delete product.',
                500
            );
        }


        /*
         * Delete image file
         */

        if (!empty($product['image'])) {
            $this->delete_image($product['image']);
        }

        $this->api->respond([
            'message' => 'Product deleted successfully.'
        ], 200);
    }


    /*
    |--------------------------------------------------------------------------
    | IMAGE URL
    |--------------------------------------------------------------------------
    */

    private function image_url($image)
    {
        if (empty($image)) {
            return '';
        }

        $value = trim((string)$image);

        /*
         * Data URLs should be returned as-is.
         */

        if (strpos($value, 'data:') === 0) {
            return $value;
        }


        /*
         * Old Render HTTP URL
         *
         * Convert:
         * http://cansino-kathleen-lab6.onrender.com/...
         *
         * to:
         * https://cansino-kathleen-lab6.onrender.com/...
         */

        if (
            strpos(
                $value,
                'http://cansino-kathleen-lab6.onrender.com/'
            ) === 0
        ) {
            return 'https://' . substr($value, 7);
        }


        /*
         * Existing HTTPS URL
         */

        if (strpos($value, 'https://') === 0) {
            return $value;
        }


        /*
         * Existing localhost URL
         *
         * Keep localhost HTTP during local development.
         */

        if (
            strpos(
                $value,
                'http://localhost:3000/'
            ) === 0
        ) {
            return $value;
        }


        /*
         * If another absolute HTTP URL exists,
         * normalize it to a path.
         */

        if (strpos($value, 'http://') === 0) {
            $value = preg_replace(
                '#^http://[^/]+/#i',
                '',
                $value
            );
        }


        /*
         * Normalize path.
         */

        $clean = ltrim(
            str_replace('\\', '/', $value),
            '/'
        );

        $clean = preg_replace(
            '#^public/#i',
            '',
            $clean
        );


        /*
         * Detect protocol.
         *
         * Render uses X-Forwarded-Proto because
         * HTTPS is terminated by the reverse proxy.
         */

        $forwarded_proto = strtolower(
            $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''
        );

        if ($forwarded_proto === 'https') {

            $scheme = 'https';

        } elseif (
            (!empty($_SERVER['HTTPS']) &&
             $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) &&
             (int)$_SERVER['SERVER_PORT'] === 443)
        ) {

            $scheme = 'https';

        } else {

            $scheme = 'http';
        }


        /*
         * Render fallback.
         *
         * This guarantees HTTPS on Render even if
         * the forwarded protocol is not available.
         */

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';

        if (
            strpos(
                strtolower($host),
                '.onrender.com'
            ) !== false
        ) {
            $scheme = 'https';
        }

        return $scheme . '://' . $host . '/' . $clean;
    }


    /*
    |--------------------------------------------------------------------------
    | ERROR HELPER
    |--------------------------------------------------------------------------
    */

    private function fail($message, $code)
    {
        error_log(
            'PRODUCT ERROR [' . $code . ']: ' . $message
        );

        $this->api->respond_error(
            $message,
            $code
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK POST SIZE
    |--------------------------------------------------------------------------
    */

    private function check_post_size_exceeded()
    {
        $length = (int)(
            $_SERVER['CONTENT_LENGTH'] ?? 0
        );

        $content_type =
            $_SERVER['CONTENT_TYPE'] ?? '';

        if (
            $length > 0 &&
            empty($_POST) &&
            empty($_FILES) &&
            stripos(
                $content_type,
                'multipart/form-data'
            ) !== false
        ) {
            $this->fail(
                'Upload is too large for the server. Increase post_max_size and upload_max_filesize in php.ini.',
                413
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | UPLOAD IMAGE
    |--------------------------------------------------------------------------
    */

    private function upload_image($file)
    {
        /*
         * Basic upload validation
         */

        if (
            !isset($file['error']) ||
            $file['error'] !== UPLOAD_ERR_OK
        ) {
            return false;
        }

        if (
            !isset($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            return false;
        }


        /*
         * Maximum 5MB
         */

        $max_size = 5 * 1024 * 1024;

        if (
            !isset($file['size']) ||
            $file['size'] <= 0 ||
            $file['size'] > $max_size
        ) {
            return false;
        }


        /*
         * Allowed MIME types
         */

        $allowed_types = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif'
        ];


        /*
         * Detect real MIME type
         */

        $finfo = finfo_open(
            FILEINFO_MIME_TYPE
        );

        if (!$finfo) {
            return false;
        }

        $mime = finfo_file(
            $finfo,
            $file['tmp_name']
        );

        /*
         * IMPORTANT:
         *
         * Do NOT use finfo_close($finfo).
         *
         * PHP 8.5 deprecated finfo_close()
         * because finfo objects are automatically
         * released when no longer needed.
         */

        if (!isset($allowed_types[$mime])) {
            return false;
        }

        $extension =
            $allowed_types[$mime];


        /*
         * Upload directory
         */

        $upload_dir =
            ROOT_DIR .
            'public' .
            DIRECTORY_SEPARATOR .
            'uploads' .
            DIRECTORY_SEPARATOR .
            'products' .
            DIRECTORY_SEPARATOR;


        /*
         * Create directory if needed
         */

        if (!is_dir($upload_dir)) {

            if (
                !mkdir(
                    $upload_dir,
                    0755,
                    true
                )
            ) {
                return false;
            }
        }


        /*
         * Generate unique filename
         */

        $filename =
            'product_' .
            bin2hex(random_bytes(16)) .
            '.' .
            $extension;

        $destination =
            $upload_dir .
            $filename;


        /*
         * Move uploaded file
         */

        if (
            !move_uploaded_file(
                $file['tmp_name'],
                $destination
            )
        ) {
            return false;
        }


        /*
         * Save relative path in database
         */

        return 'uploads/products/' . $filename;
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE IMAGE
    |--------------------------------------------------------------------------
    */

    private function delete_image($image_path)
    {
        if (empty($image_path)) {
            return;
        }


        /*
         * Remove URL if somehow stored
         */

        $image_path = preg_replace(
            '#^https?://[^/]+/#',
            '',
            $image_path
        );


        /*
         * Normalize path
         */

        $clean_path = ltrim(
            str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                $image_path
            ),
            DIRECTORY_SEPARATOR
        );


        /*
         * Prevent accidental public/public
         */

        if (
            strpos(
                $clean_path,
                'public' . DIRECTORY_SEPARATOR
            ) === 0
        ) {
            $clean_path = substr(
                $clean_path,
                strlen(
                    'public' . DIRECTORY_SEPARATOR
                )
            );
        }


        /*
         * Build full file path
         */

        $full_path =
            ROOT_DIR .
            'public' .
            DIRECTORY_SEPARATOR .
            $clean_path;


        /*
         * Delete file
         */

        if (is_file($full_path)) {
            @unlink($full_path);
        }
    }
}