<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->call->database();
        $this->call->library('cache');
        $this->call->library('api');
    }

    /* =========================================================
       LOGIN
    ========================================================= */

    public function login()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('login', 20, 60);

        $data = $this->api->body();

        $login = $data['login'] ?? '';
        $password = $data['password'] ?? '';

        if ($login === '' || $password === '') {
            $this->api->respond_error(
                'Username/email and password are required.',
                400
            );
        }

        $safe_login = preg_replace(
            '/[^a-zA-Z0-9_\-@.]/',
            '_',
            strtolower($login)
        );

        $attempt_key = 'login_attempts_' . $safe_login;
        $lock_key = 'login_locked_' . $safe_login;

        /* -----------------------------------------------------
           CHECK LOGIN LOCK
        ----------------------------------------------------- */

        $locked_until = $this->cache->get($lock_key);

        if (
            is_numeric($locked_until) &&
            (int)$locked_until > time()
        ) {
            $remaining =
                (int)$locked_until - time();

            $this->api->respond([
                'error' =>
                    'Too many failed login attempts. Please try again later.',
                'status' => 429,
                'locked' => true,
                'retry_after' => $remaining
            ], 429);
        }

        /* -----------------------------------------------------
           FIND USER
        ----------------------------------------------------- */

        $stmt = $this->db->raw(
            "SELECT id, username, email, password, role, is_active
             FROM users
             WHERE username = ? OR email = ?
             LIMIT 1",
            [$login, $login]
        );

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        /* -----------------------------------------------------
           INVALID LOGIN
        ----------------------------------------------------- */

        if (
            !$user ||
            !password_verify(
                $password,
                $user['password']
            )
        ) {
            $attempts =
                $this->cache->get($attempt_key);

            $attempts =
                is_numeric($attempts)
                    ? (int)$attempts
                    : 0;

            $attempts++;

            /* 3 FAILED ATTEMPTS = 5 MINUTE LOCK */

            if ($attempts >= 3) {
                $lock_duration = 300;

                $this->cache->write(
                    time() + $lock_duration,
                    $lock_key,
                    $lock_duration
                );

                $this->cache->delete(
                    $attempt_key
                );

                $this->api->respond([
                    'error' =>
                        'Too many failed login attempts. Please try again in 5 minutes.',
                    'status' => 429,
                    'locked' => true,
                    'retry_after' =>
                        $lock_duration
                ], 429);
            }

            $remaining_attempts =
                3 - $attempts;

            $this->cache->write(
                $attempts,
                $attempt_key,
                300
            );

            $this->api->respond([
                'error' =>
                    'Invalid username/email or password.',
                'status' => 401,
                'remaining_attempts' =>
                    $remaining_attempts
            ], 401);
        }

        /* -----------------------------------------------------
           CHECK ACCOUNT STATUS
        ----------------------------------------------------- */

        if (
            (int)$user['is_active'] !== 1
        ) {
            $this->api->respond_error(
                'Your account is inactive. Please contact the administrator.',
                403
            );
        }

        /* -----------------------------------------------------
           CLEAR FAILED LOGIN ATTEMPTS
        ----------------------------------------------------- */

        $this->cache->delete(
            $attempt_key
        );

        $this->cache->delete(
            $lock_key
        );

        /* -----------------------------------------------------
           ISSUE JWT TOKENS
        ----------------------------------------------------- */

        $scopes =
            $this->get_scopes(
                $user['role']
            );

        $tokens =
            $this->api->issue_tokens([
                'id' => $user['id'],
                'role' => $user['role'],
                'scopes' => $scopes
            ]);

        $this->api->respond([
            'message' =>
                'Login successful.',

            'user' => [
                'id' =>
                    $user['id'],

                'username' =>
                    $user['username'],

                'email' =>
                    $user['email'],

                'role' =>
                    $user['role']
            ],

            'tokens' => $tokens
        ], 200);
    }


    /* =========================================================
       REGISTER
    ========================================================= */

    public function register()
    {
        $this->api->require_method('POST');

        $data = $this->api->body();

        $username =
            trim($data['username'] ?? '');

        $email =
            trim($data['email'] ?? '');

        $password =
            $data['password'] ?? '';

        /* -----------------------------------------------------
           REQUIRED FIELDS
        ----------------------------------------------------- */

        if (
            $username === '' ||
            $email === '' ||
            $password === ''
        ) {
            $this->api->respond_error(
                'Username, email, and password are required.',
                400
            );
        }

        /* -----------------------------------------------------
           USERNAME VALIDATION
        ----------------------------------------------------- */

        if (
            !preg_match(
                '/^[a-zA-Z0-9_]{3,30}$/',
                $username
            )
        ) {
            $this->api->respond_error(
                'Username must be 3-30 characters and may only contain letters, numbers, and underscores.',
                400
            );
        }

        /* -----------------------------------------------------
           EMAIL VALIDATION
        ----------------------------------------------------- */

        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $this->api->respond_error(
                'Please enter a valid email address.',
                400
            );
        }

        /* -----------------------------------------------------
           PASSWORD VALIDATION
        ----------------------------------------------------- */

        if (
            strlen($password) < 6
        ) {
            $this->api->respond_error(
                'Password must be at least 6 characters.',
                400
            );
        }

        /* -----------------------------------------------------
           CHECK EXISTING USERNAME / EMAIL
        ----------------------------------------------------- */

        $check = $this->db->raw(
            "SELECT id
             FROM users
             WHERE username = ? OR email = ?
             LIMIT 1",
            [$username, $email]
        );

        $existing =
            $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $this->api->respond_error(
                'Username or email is already registered.',
                409
            );
        }

        /* -----------------------------------------------------
           HASH PASSWORD
        ----------------------------------------------------- */

        $hashed_password =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        /* -----------------------------------------------------
           CREATE USER
           REGISTERED USERS ARE NORMAL USERS
        ----------------------------------------------------- */

        $stmt = $this->db->raw(
            "INSERT INTO users
             (username, email, password, role, is_active)
             VALUES (?, ?, ?, 'user', 1)",
            [
                $username,
                $email,
                $hashed_password
            ]
        );

        if (!$stmt) {
            $this->api->respond_error(
                'Unable to create your account.',
                500
            );
        }

        $this->api->respond([
            'message' =>
                'Account created successfully. You can now log in.'
        ], 201);
    }


    /* =========================================================
       CURRENT USER
    ========================================================= */

    public function me()
    {
        $this->api->require_method('GET');

        $payload =
            $this->api->require_jwt();

        $stmt = $this->db->raw(
            "SELECT
                id,
                username,
                email,
                role,
                is_active,
                created_at
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$payload['sub']]
        );

        $user =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$user) {
            $this->api->respond_error(
                'Unauthorized',
                401
            );
        }

        $this->api->respond([
            'message' =>
                'Authenticated user.',
            'user' => $user
        ], 200);
    }


    /* =========================================================
       REFRESH TOKEN
    ========================================================= */

    public function refresh()
    {
        $this->api->require_method('POST');

        $data =
            $this->api->body();

        $refresh_token =
            $data['refresh_token'] ?? '';

        if (
            $refresh_token === ''
        ) {
            $this->api->respond_error(
                'Refresh token is required.',
                400
            );
        }

        $this->api->refresh_access_token(
            $refresh_token
        );
    }


    /* =========================================================
       LOGOUT
    ========================================================= */

    public function logout()
    {
        $this->api->require_method('POST');

        $this->api->require_jwt();

        $data =
            $this->api->body();

        $refresh_token =
            $data['refresh_token'] ?? '';

        if (
            $refresh_token !== ''
        ) {
            $this->api->revoke_refresh_token(
                $refresh_token
            );
        }

        $this->api->respond([
            'message' =>
                'Logout successful.'
        ], 200);
    }


    /* =========================================================
       ROLE SCOPES
    ========================================================= */

    private function get_scopes($role)
    {
        switch ($role) {

            case 'admin':
                return [
                    'read',
                    'write',
                    'delete'
                ];

            case 'editor':
                return [
                    'read',
                    'write'
                ];

            case 'user':
            default:
                return [
                    'read'
                ];
        }
    }
}