<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class TenantUserSecurityService
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    /** @return array{min_length: int, require_letter: bool, require_number: bool, forbid_whitespace: bool} */
    public function passwordPolicy(): array
    {
        return [
            'min_length' => 8,
            'require_letter' => true,
            'require_number' => true,
            'forbid_whitespace' => true,
        ];
    }

    /**
     * @return array{ok: bool, message: string, errors: array<string, string>}
     */
    public function changePassword(
        string $tenantKey,
        int $userId,
        string $oldPassword,
        string $newPassword,
        string $confirmPassword
    ): array {
        $tenantKey = trim($tenantKey);
        if ($tenantKey === '' || $userId <= 0) {
            return $this->result(false, '账号信息无效。', ['user' => '账号信息无效。']);
        }

        $user = $this->store->user($tenantKey, $userId);
        if (!$user || ($user['status'] ?? '') !== 'active') {
            return $this->result(false, '账号不存在或已停用。', ['user' => '账号不存在或已停用。']);
        }

        $errors = $this->validatePasswordChange($oldPassword, $newPassword, $confirmPassword, $user);
        if ($errors !== []) {
            return $this->result(false, '请修正密码表单后再提交。', $errors);
        }

        $currentHash = (string) ($user['password_hash'] ?? '');
        if ($currentHash === '') {
            return $this->result(false, '当前账号还没有安全密码哈希，请先由管理员重置密码。', [
                'old_password' => '当前账号还没有安全密码哈希，请先由管理员重置密码。',
            ]);
        }

        if (!password_verify($oldPassword, $currentHash)) {
            return $this->result(false, '旧密码不正确。', ['old_password' => '旧密码不正确。']);
        }

        if (password_verify($newPassword, $currentHash)) {
            return $this->result(false, '新密码不能与旧密码相同。', ['new_password' => '新密码不能与旧密码相同。']);
        }

        $this->store->updateTenantUserPassword($tenantKey, $userId, $this->hashPassword($newPassword));

        return $this->result(true, '密码已更新。', []);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, string>
     */
    public function validatePasswordChange(
        string $oldPassword,
        string $newPassword,
        string $confirmPassword,
        array $user = []
    ): array {
        $errors = [];

        if ($oldPassword === '') {
            $errors['old_password'] = '请输入旧密码。';
        }
        if ($newPassword === '') {
            $errors['new_password'] = '请输入新密码。';
        }
        if ($confirmPassword === '') {
            $errors['confirm_password'] = '请再次输入新密码。';
        }
        if ($newPassword !== '' && $confirmPassword !== '' && !hash_equals($newPassword, $confirmPassword)) {
            $errors['confirm_password'] = '两次输入的新密码不一致。';
        }

        foreach ($this->validatePasswordComplexity($newPassword, $user) as $key => $message) {
            $errors[$key] = $message;
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, string>
     */
    public function validatePasswordComplexity(string $password, array $user = []): array
    {
        if ($password === '') {
            return [];
        }

        $policy = $this->passwordPolicy();
        $errors = [];

        if (strlen($password) < $policy['min_length']) {
            $errors['new_password'] = '新密码至少需要 ' . $policy['min_length'] . ' 位。';
        }
        if ($policy['forbid_whitespace'] && preg_match('/\s/', $password) === 1) {
            $errors['new_password'] = '新密码不能包含空白字符。';
        }
        if ($policy['require_letter'] && preg_match('/[A-Za-z]/', $password) !== 1) {
            $errors['new_password'] = '新密码至少需要包含一个字母。';
        }
        if ($policy['require_number'] && preg_match('/[0-9]/', $password) !== 1) {
            $errors['new_password'] = '新密码至少需要包含一个数字。';
        }

        $username = strtolower(trim((string) ($user['username'] ?? '')));
        if ($username !== '' && strlen($username) >= 3 && str_contains(strtolower($password), $username)) {
            $errors['new_password'] = '新密码不能包含登录名。';
        }

        return $errors;
    }

    private function hashPassword(string $password): string
    {
        return password_hash($password, $this->passwordAlgorithm());
    }

    private function passwordAlgorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * @param array<string, string> $errors
     * @return array{ok: bool, message: string, errors: array<string, string>}
     */
    private function result(bool $ok, string $message, array $errors): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'errors' => $errors,
        ];
    }
}
