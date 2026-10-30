<?php

namespace App\Models;

use PDO;

/**
 * Pure PHP User Model (Hostinger Production)
 * Zero framework dependencies (no Laravel or XAMPP required).
 */
class User
{
    public int $id;
    public string $name;
    public string $email;
    public ?string $seller_name = null;
    public ?string $phone = null;
    public ?string $address = null;
    public ?string $landmark = null;
    public ?string $state = null;
    public ?string $country = null;
    public ?string $avatar_url = null;
    public string $role = 'seller';
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $val) {
            if (property_exists($this, $key)) {
                $this->{$key} = $val;
            }
        }
    }

    public static function find(PDO $pdo, int $id): ?self
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? new self($row) : null;
    }

    public static function findByPhone(PDO $pdo, string $phone): ?self
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = :phone LIMIT 1");
        $stmt->execute([':phone' => $phone]);
        $row = $stmt->fetch();
        return $row ? new self($row) : null;
    }

    public static function findByEmail(PDO $pdo, string $email): ?self
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        return $row ? new self($row) : null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id ?? null,
            'seller_name' => $this->seller_name ?? $this->name ?? null,
            'name' => $this->name ?? $this->seller_name ?? null,
            'email' => $this->email ?? null,
            'phone' => $this->phone,
            'address' => $this->address,
            'landmark' => $this->landmark,
            'state' => $this->state,
            'country' => $this->country,
            'role' => $this->role,
            'avatar_url' => $this->avatar_url ?? null,
            'created_at' => $this->created_at ?? null,
            'updated_at' => $this->updated_at ?? null,
        ];
    }
}
