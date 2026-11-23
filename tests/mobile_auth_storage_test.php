<?php
declare(strict_types=1);
// Isolated database; never use or mutate the installation database.
define('DB_DRIVER', 'sqlite');
define('SQLITE_DB_PATH', ':memory:');
require __DIR__ . '/../includes/bootstrap.php';
function mobileAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$tasksBefore = (int) Database::fetchValue('SELECT COUNT(*) FROM tasks');
Database::execute('DROP TABLE mobile_voice_previews');
Database::execute('DROP TABLE mobile_tokens');
Database::execute('DROP TABLE mobile_login_limits');
Schema::ensureInitialized();
mobileAssert((int) Database::fetchValue('SELECT COUNT(*) FROM tasks') === $tasksBefore, 'migration must preserve existing tasks');
mobileAssert(count(array_intersect(['mobile_tokens', 'mobile_login_limits', 'mobile_voice_previews'], Database::listTables())) === 3, 'missing mobile tables created');
$user = (int) Database::fetchValue('SELECT id FROM users ORDER BY id LIMIT 1');
$token = MobileAuth::issue($user);
$row = Database::fetchOne('SELECT * FROM mobile_tokens WHERE user_id = ?', [$user]);
mobileAssert(strlen($token) === 64 && $row['token_hash'] === hash('sha256', $token) && $row['token_hash'] !== $token, 'only hashed token is stored');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$auth = MobileAuth::authenticate();
mobileAssert($auth !== null && $auth['user_id'] === $user, 'valid token');
MobileAuth::recordPreview($auth['id'], 'voice_test.wav');
mobileAssert(MobileAuth::ownsPreview($auth['id'], 'voice_test.wav') && !MobileAuth::ownsPreview($auth['id'], 'other.wav'), 'preview ownership');
Database::execute('UPDATE mobile_tokens SET last_used_at = ? WHERE id = ?', [time() - MobileAuth::IDLE_SECONDS - 1, $auth['id']]);
mobileAssert(MobileAuth::authenticate() === null, 'idle expiry');
Database::execute('UPDATE mobile_tokens SET last_used_at = ? WHERE id = ?', [time(), $auth['id']]);
Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('changed', PASSWORD_DEFAULT), $user]);
mobileAssert(MobileAuth::authenticate() === null, 'password changes invalidate token');
$new = MobileAuth::issue($user); $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $new;
$auth = MobileAuth::authenticate(); mobileAssert($auth !== null, 'new token');
Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$user]);
mobileAssert(MobileAuth::authenticate() === null, 'deactivated account rejected');
Database::execute('UPDATE users SET is_active = 1 WHERE id = ?', [$user]);
MobileAuth::revoke($auth['id']); mobileAssert(MobileAuth::authenticate() === null, 'logout revokes');
$_SERVER['REMOTE_ADDR'] = '192.0.2.4';
for ($i = 0; $i < 8; $i++) mobileAssert(MobileAuth::consumeLoginAttempt('testuser'), 'first eight attempts');
mobileAssert(!MobileAuth::consumeLoginAttempt('testuser'), 'rate limit');
echo "Mobile schema upgrade, token hashing, idle expiry, revocation, preview ownership and throttle: OK\n";
