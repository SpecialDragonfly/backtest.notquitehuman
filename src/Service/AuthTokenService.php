<?php

namespace App\Service;

use DateTimeImmutable;
use PDO;

class AuthTokenService
{
    private const LIFETIME_DAYS = 30;

    public function __construct(private PDO $db) {}

    public function generateToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable())
            ->modify('+' . self::LIFETIME_DAYS . ' days')
            ->format('Y-m-d H:i:s');

        $this->db->prepare(
            'INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (?, ?, ?)'
        )->execute([$userId, $token, $expiresAt]);

        return $token;
    }

    public function validateToken(string $token): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT user_id FROM auth_tokens WHERE token = ? AND expires_at > ?'
        );
        $stmt->execute([$token, (new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !isset($row['user_id']) || !is_numeric($row['user_id'])) {
            return null;
        }

        return (int) $row['user_id'];
    }

    public function revokeToken(string $token): void
    {
        $this->db->prepare('DELETE FROM auth_tokens WHERE token = ?')->execute([$token]);
    }
}
