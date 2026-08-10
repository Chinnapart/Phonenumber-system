<?php

declare(strict_types=1);

/**
 * ConnectPro Authentication Service
 *
 * File: api/classes/AuthService.php
 *
 * Responsibilities:
 * - Authenticate active users with password_hash/password_verify
 * - Apply account lockout after repeated login failures
 * - Regenerate session identifiers after authentication
 * - Store a minimal, normalized user payload in the session
 * - Return the current authenticated user and resolved roles
 * - Change passwords securely and invalidate other sessions when supported
 * - Log login, logout, password, and security events
 *
 * Required tables:
 * - users
 * - activity_logs
 *
 * Optional tables:
 * - user_sessions
 */
final class AuthService
{
    private const SESSION_USER_KEY = 'auth_user';
    private const SESSION_AUTHENTICATED_AT = '_authenticated_at';
    private const SESSION_PASSWORD_CONFIRMED_AT = '_password_confirmed_at';

    public function __construct(
        private readonly PDO $db,
        private readonly array $config = []
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * Authenticate a user with username or email and password.
     *
     * @return array<string, mixed>
     */
    public function login(
        string $login,
        string $password,
        bool $remember = false
    ): array {
        $login = trim($login);

        if ($login === '' || $password === '') {
            throw new InvalidArgumentException(
                'กรุณาระบุชื่อผู้ใช้และรหัสผ่าน'
            );
        }

        $user = $this->findUserForAuthentication($login);

        if ($user === null) {
            $this->performTimingSafeDummyVerification($password);
            $this->writeActivityLog(
                null,
                'login_failed',
                null,
                'เข้าสู่ระบบไม่สำเร็จ: ไม่พบบัญชีผู้ใช้',
                ['login' => $this->maskLogin($login)]
            );

            throw new DomainException('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
        }

        $userId = (int) $user['id'];
        $this->assertAccountCanAuthenticate($user);

        $passwordHash = (string) ($user['password_hash'] ?? '');

        if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
            $this->recordFailedAttempt($userId);
            $this->writeActivityLog(
                $userId,
                'login_failed',
                $userId,
                'เข้าสู่ระบบไม่สำเร็จ: รหัสผ่านไม่ถูกต้อง'
            );

            throw new DomainException('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
        }

        if (password_needs_rehash(
            $passwordHash,
            $this->passwordAlgorithm(),
            $this->passwordOptions()
        )) {
            $this->updatePasswordHash(
                $userId,
                password_hash(
                    $password,
                    $this->passwordAlgorithm(),
                    $this->passwordOptions()
                )
            );
        }

        return $this->transactional(function () use (
            $user,
            $userId,
            $remember
        ): array {
            $this->clearFailedAttempts($userId);
            $this->updateSuccessfulLogin($userId);
            $this->startAuthenticatedSession($user, $remember);
            $this->registerSession($userId, $remember);

            $this->writeActivityLog(
                $userId,
                'login',
                $userId,
                'เข้าสู่ระบบสำเร็จ'
            );

            $currentUser = $this->currentUser(true);

            if ($currentUser === null) {
                throw new RuntimeException(
                    'Authenticated user could not be loaded.'
                );
            }

            return $currentUser;
        });
    }

    public function logout(bool $allDevices = false): void
    {
        $user = $this->currentUser(false);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        if ($userId > 0) {
            if ($allDevices) {
                $this->revokeAllSessions($userId);
            } else {
                $this->revokeCurrentSession($userId);
            }

            $this->writeActivityLog(
                $userId,
                'logout',
                $userId,
                $allDevices
                    ? 'ออกจากระบบทุกอุปกรณ์'
                    : 'ออกจากระบบ'
            );
        }

        $this->destroySession();
    }

    /** @return array<string, mixed>|null */
    public function currentUser(bool $refresh = false): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $sessionUser = $_SESSION[self::SESSION_USER_KEY] ?? null;

        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            return null;
        }

        if (!$refresh) {
            return $sessionUser;
        }

        $user = $this->findUserById((int) $sessionUser['id']);

        if ($user === null || ($user['status'] ?? '') !== 'active') {
            $this->destroySession();

            return null;
        }

        $normalized = $this->normalizeSessionUser($user);
        $_SESSION[self::SESSION_USER_KEY] = $normalized;

        return $normalized;
    }

    public function isAuthenticated(): bool
    {
        return $this->currentUser(false) !== null;
    }

    /**
     * Confirm the current user's password for sensitive operations.
     */
    public function confirmPassword(string $password): bool
    {
        $user = $this->currentUser(false);

        if ($user === null) {
            throw new RuntimeException('Authentication is required.');
        }

        $statement = $this->db->prepare(
            'SELECT password_hash FROM users WHERE id = :user_id LIMIT 1'
        );
        $statement->bindValue(':user_id', (int) $user['id'], PDO::PARAM_INT);
        $statement->execute();
        $passwordHash = $statement->fetchColumn();

        $verified = is_string($passwordHash)
            && password_verify($password, $passwordHash);

        if ($verified) {
            $_SESSION[self::SESSION_PASSWORD_CONFIRMED_AT] = time();
        }

        return $verified;
    }

    public function passwordRecentlyConfirmed(int $withinSeconds = 900): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $confirmedAt = (int) (
            $_SESSION[self::SESSION_PASSWORD_CONFIRMED_AT] ?? 0
        );

        return $confirmedAt > 0
            && (time() - $confirmedAt) <= max(1, $withinSeconds);
    }

    public function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        bool $revokeOtherSessions = true
    ): void {
        if ($userId < 1) {
            throw new InvalidArgumentException('Invalid user ID.');
        }

        $user = $this->findUserForAuthenticationById($userId);

        if ($user === null) {
            throw new OutOfBoundsException('User not found.');
        }

        if (!password_verify(
            $currentPassword,
            (string) ($user['password_hash'] ?? '')
        )) {
            throw new DomainException('รหัสผ่านปัจจุบันไม่ถูกต้อง');
        }

        $errors = $this->validatePassword($newPassword, $user);

        if ($errors !== []) {
            throw new InvalidArgumentException(json_encode(
                ['validation_errors' => ['password' => implode(' ', $errors)]],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        }

        if (password_verify(
            $newPassword,
            (string) ($user['password_hash'] ?? '')
        )) {
            throw new DomainException(
                'รหัสผ่านใหม่ต้องไม่เหมือนรหัสผ่านปัจจุบัน'
            );
        }

        $this->transactional(function () use (
            $userId,
            $newPassword,
            $revokeOtherSessions
        ): void {
            $newHash = password_hash(
                $newPassword,
                $this->passwordAlgorithm(),
                $this->passwordOptions()
            );
            $this->updatePasswordHash($userId, $newHash, true);

            if ($revokeOtherSessions) {
                $this->revokeOtherSessions($userId);
            }

            $this->writeActivityLog(
                $userId,
                'password_changed',
                $userId,
                'เปลี่ยนรหัสผ่านสำเร็จ'
            );
        });

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_PASSWORD_CONFIRMED_AT] = time();
        }
    }

    /**
     * Reset another user's password. Permission checks belong in the API
     * controller before this method is called.
     */
    public function resetPassword(
        int $targetUserId,
        string $newPassword,
        int $actorUserId,
        bool $mustChangePassword = true
    ): void {
        $target = $this->findUserById($targetUserId);

        if ($target === null) {
            throw new OutOfBoundsException('User not found.');
        }

        $errors = $this->validatePassword($newPassword, $target);

        if ($errors !== []) {
            throw new InvalidArgumentException(json_encode(
                ['validation_errors' => ['password' => implode(' ', $errors)]],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        }

        $this->transactional(function () use (
            $targetUserId,
            $newPassword,
            $actorUserId,
            $mustChangePassword
        ): void {
            $hash = password_hash(
                $newPassword,
                $this->passwordAlgorithm(),
                $this->passwordOptions()
            );
            $statement = $this->db->prepare(
                'UPDATE users SET password_hash = :password_hash, '
                . 'must_change_password = :must_change_password, '
                . 'password_changed_at = CURRENT_TIMESTAMP, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :user_id'
            );
            $statement->bindValue(':password_hash', $hash, PDO::PARAM_STR);
            $statement->bindValue(
                ':must_change_password',
                $mustChangePassword ? 1 : 0,
                PDO::PARAM_INT
            );
            $statement->bindValue(':user_id', $targetUserId, PDO::PARAM_INT);
            $statement->execute();

            $this->revokeAllSessions($targetUserId);
            $this->writeActivityLog(
                $actorUserId,
                'password_reset',
                $targetUserId,
                'รีเซ็ตรหัสผ่านบัญชีผู้ใช้'
            );
        });
    }

    /** @return list<string> */
    public function validatePassword(string $password, array $user = []): array
    {
        $errors = [];
        $minimumLength = max(
            8,
            (int) ($this->config['password_min_length'] ?? 12)
        );

        if (mb_strlen($password) < $minimumLength) {
            $errors[] = sprintf(
                'รหัสผ่านต้องมีอย่างน้อย %d ตัวอักษร',
                $minimumLength
            );
        }

        if (preg_match('/[A-Z]/', $password) !== 1) {
            $errors[] = 'ต้องมีตัวอักษรภาษาอังกฤษพิมพ์ใหญ่อย่างน้อย 1 ตัว';
        }

        if (preg_match('/[a-z]/', $password) !== 1) {
            $errors[] = 'ต้องมีตัวอักษรภาษาอังกฤษพิมพ์เล็กอย่างน้อย 1 ตัว';
        }

        if (preg_match('/\d/', $password) !== 1) {
            $errors[] = 'ต้องมีตัวเลขอย่างน้อย 1 ตัว';
        }

        if (preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
            $errors[] = 'ต้องมีอักขระพิเศษอย่างน้อย 1 ตัว';
        }

        $lowerPassword = mb_strtolower($password);

        foreach (['username', 'email', 'display_name'] as $field) {
            $value = trim((string) ($user[$field] ?? ''));

            if ($value === '') {
                continue;
            }

            $candidates = [$value];

            if ($field === 'email' && str_contains($value, '@')) {
                $candidates[] = strstr($value, '@', true) ?: '';
            }

            foreach ($candidates as $candidate) {
                if (
                    mb_strlen($candidate) >= 4
                    && str_contains($lowerPassword, mb_strtolower($candidate))
                ) {
                    $errors[] = 'รหัสผ่านต้องไม่มีข้อมูลส่วนตัวของผู้ใช้';
                    break 2;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return array<string, mixed>|null */
    private function findUserForAuthentication(string $login): ?array
    {
        $sql = <<<SQL
            SELECT
                u.id,
                u.username,
                u.email,
                u.display_name,
                u.password_hash,
                u.status,
                u.role,
                u.department_id,
                d.name AS department_name,
                u.failed_login_attempts,
                u.locked_until,
                u.must_change_password,
                u.last_login_at,
                u.password_changed_at
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.username = :login OR u.email = :login
            LIMIT 1
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':login', $login, PDO::PARAM_STR);
        $statement->execute();
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null */
    private function findUserForAuthenticationById(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, username, email, display_name, password_hash, '
            . 'status, role, department_id, failed_login_attempts, '
            . 'locked_until, must_change_password, password_changed_at '
            . 'FROM users WHERE id = :user_id LIMIT 1'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null */
    private function findUserById(int $userId): ?array
    {
        $sql = <<<SQL
            SELECT
                u.id,
                u.username,
                u.email,
                u.display_name,
                u.status,
                u.role,
                u.department_id,
                d.name AS department_name,
                u.must_change_password,
                u.last_login_at,
                u.password_changed_at
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.id = :user_id
            LIMIT 1
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    private function assertAccountCanAuthenticate(array $user): void
    {
        if (($user['status'] ?? '') !== 'active') {
            throw new DomainException('บัญชีนี้ถูกปิดใช้งาน');
        }

        $lockedUntil = $user['locked_until'] ?? null;

        if (is_string($lockedUntil) && $lockedUntil !== '') {
            $lockTimestamp = strtotime($lockedUntil);

            if ($lockTimestamp !== false && $lockTimestamp > time()) {
                throw new DomainException(
                    'บัญชีถูกล็อกชั่วคราว กรุณาลองใหม่ภายหลัง'
                );
            }
        }
    }

    private function recordFailedAttempt(int $userId): void
    {
        $maximumAttempts = max(
            1,
            (int) ($this->config['login_max_attempts'] ?? 5)
        );
        $lockoutMinutes = max(
            1,
            (int) ($this->config['login_lockout_minutes'] ?? 15)
        );

        $sql = <<<SQL
            UPDATE users
            SET
                failed_login_attempts = failed_login_attempts + 1,
                locked_until = CASE
                    WHEN failed_login_attempts + 1 >= :maximum_attempts
                    THEN DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :lockout_minutes MINUTE)
                    ELSE locked_until
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :user_id
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(
            ':maximum_attempts',
            $maximumAttempts,
            PDO::PARAM_INT
        );
        $statement->bindValue(
            ':lockout_minutes',
            $lockoutMinutes,
            PDO::PARAM_INT
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function clearFailedAttempts(int $userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :user_id'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function updateSuccessfulLogin(int $userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP, '
            . 'last_login_ip = :ip_address, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :user_id'
        );
        $statement->bindValue(':ip_address', $this->resolveClientIp());
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function updatePasswordHash(
        int $userId,
        string $passwordHash,
        bool $markChanged = false
    ): void {
        $sql = 'UPDATE users SET password_hash = :password_hash, '
            . 'updated_at = CURRENT_TIMESTAMP';

        if ($markChanged) {
            $sql .= ', password_changed_at = CURRENT_TIMESTAMP, '
                . 'must_change_password = 0';
        }

        $sql .= ' WHERE id = :user_id';
        $statement = $this->db->prepare($sql);
        $statement->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function startAuthenticatedSession(array $user, bool $remember): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException(
                'Secure session must be started before authentication.'
            );
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate session ID.');
        }

        $_SESSION[self::SESSION_USER_KEY] = $this->normalizeSessionUser($user);
        $_SESSION[self::SESSION_AUTHENTICATED_AT] = time();
        $_SESSION['_session_regenerated_at'] = time();
        $_SESSION['_remember_login'] = $remember;
        unset($_SESSION[self::SESSION_PASSWORD_CONFIRMED_AT]);
    }

    /** @return array<string, mixed> */
    private function normalizeSessionUser(array $user): array
    {
        $role = strtolower(trim((string) ($user['role'] ?? 'user')));
        $roles = $user['roles'] ?? [$role];

        if (is_string($roles)) {
            $roles = [$roles];
        }

        $roles = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $item): string => strtolower(trim((string) $item)),
                is_array($roles) ? $roles : []
            ),
            static fn (string $item): bool => $item !== ''
        )));

        return [
            'id' => (int) $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? ''),
            'status' => (string) ($user['status'] ?? 'active'),
            'role' => $role,
            'roles' => $roles === [] ? ['user'] : $roles,
            'department_id' => empty($user['department_id'])
                ? null
                : (int) $user['department_id'],
            'department_name' => $user['department_name'] ?? null,
            'must_change_password' => (bool) (
                $user['must_change_password'] ?? false
            ),
            'last_login_at' => $user['last_login_at'] ?? null,
            'password_changed_at' => $user['password_changed_at'] ?? null,
        ];
    }

    private function registerSession(int $userId, bool $remember): void
    {
        if (!$this->sessionTableEnabled()) {
            return;
        }

        $sql = <<<SQL
            INSERT INTO user_sessions (
                id, user_id, ip_address, user_agent, remember_login,
                last_activity_at, expires_at, created_at
            ) VALUES (
                :id, :user_id, :ip_address, :user_agent, :remember_login,
                CURRENT_TIMESTAMP, :expires_at, CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                remember_login = VALUES(remember_login),
                last_activity_at = CURRENT_TIMESTAMP,
                expires_at = VALUES(expires_at)
            SQL;

        $lifetimeMinutes = $remember
            ? max(60, (int) ($this->config['remember_lifetime_minutes'] ?? 43200))
            : max(1, (int) ($this->config['session_lifetime_minutes'] ?? 30));
        $expiresAt = (new DateTimeImmutable())
            ->modify('+' . $lifetimeMinutes . ' minutes')
            ->format('Y-m-d H:i:s');

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'id' => hash('sha256', session_id()),
            'user_id' => $userId,
            'ip_address' => $this->resolveClientIp(),
            'user_agent' => mb_substr(
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                500
            ),
            'remember_login' => $remember ? 1 : 0,
            'expires_at' => $expiresAt,
        ]);
    }

    private function revokeCurrentSession(int $userId): void
    {
        if (!$this->sessionTableEnabled() || session_id() === '') {
            return;
        }

        $statement = $this->db->prepare(
            'DELETE FROM user_sessions WHERE id = :session_id '
            . 'AND user_id = :user_id'
        );
        $statement->execute([
            'session_id' => hash('sha256', session_id()),
            'user_id' => $userId,
        ]);
    }

    private function revokeOtherSessions(int $userId): void
    {
        if (!$this->sessionTableEnabled()) {
            return;
        }

        $statement = $this->db->prepare(
            'DELETE FROM user_sessions WHERE user_id = :user_id '
            . 'AND id <> :session_id'
        );
        $statement->execute([
            'user_id' => $userId,
            'session_id' => hash('sha256', session_id()),
        ]);
    }

    private function revokeAllSessions(int $userId): void
    {
        if (!$this->sessionTableEnabled()) {
            return;
        }

        $statement = $this->db->prepare(
            'DELETE FROM user_sessions WHERE user_id = :user_id'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function sessionTableEnabled(): bool
    {
        return (bool) ($this->config['session_table_enabled'] ?? false);
    }

    private function destroySession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if ((bool) ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'] ?: '/',
                'domain' => $cookie['domain'] ?? '',
                'secure' => (bool) ($cookie['secure'] ?? false),
                'httponly' => (bool) ($cookie['httponly'] ?? true),
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    private function performTimingSafeDummyVerification(string $password): void
    {
        $dummyHash = (string) ($this->config['dummy_password_hash'] ?? '');

        if ($dummyHash === '') {
            $dummyHash = '$2y$12$8A4n9LKmJYI6XhOe6QHCQe5wW3U5z8U7i7GmPjXM8pQ2bL7QxgNaa';
        }

        password_verify($password, $dummyHash);
    }

    private function passwordAlgorithm(): string|int|null
    {
        return $this->config['password_algorithm'] ?? PASSWORD_DEFAULT;
    }

    /** @return array<string, int> */
    private function passwordOptions(): array
    {
        $options = [];

        if (PASSWORD_DEFAULT === PASSWORD_BCRYPT) {
            $options['cost'] = max(
                10,
                min(14, (int) ($this->config['bcrypt_cost'] ?? 12))
            );
        }

        return $options;
    }

    private function writeActivityLog(
        ?int $actorUserId,
        string $action,
        ?int $entityId,
        string $description,
        array $metadata = []
    ): void {
        if (empty($this->config['activity_log_enabled'])) {
            return;
        }

        $sql = <<<SQL
            INSERT INTO activity_logs (
                user_id, action, entity_type, entity_id, description,
                new_values, ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :action, 'user', :entity_id, :description,
                :metadata, :ip_address, :user_agent, CURRENT_TIMESTAMP
            )
            SQL;

        $statement = $this->db->prepare($sql);
        $statement->bindValue(
            ':user_id',
            $actorUserId,
            $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':action', $action, PDO::PARAM_STR);
        $statement->bindValue(
            ':entity_id',
            $entityId,
            $entityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':description', $description, PDO::PARAM_STR);
        $statement->bindValue(
            ':metadata',
            $metadata === [] ? null : json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            ),
            $metadata === [] ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(':ip_address', $this->resolveClientIp());
        $statement->bindValue(
            ':user_agent',
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)
        );
        $statement->execute();
    }

    private function resolveClientIp(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private function maskLogin(string $login): string
    {
        if (str_contains($login, '@')) {
            [$local, $domain] = array_pad(explode('@', $login, 2), 2, '');

            return mb_substr($local, 0, 2) . '***@' . $domain;
        }

        return mb_substr($login, 0, 2) . '***';
    }

    /** @return mixed */
    private function transactional(callable $callback): mixed
    {
        $startedTransaction = !$this->db->inTransaction();

        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $callback();

            if ($startedTransaction) {
                $this->db->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }
}
