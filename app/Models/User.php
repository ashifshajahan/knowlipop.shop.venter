<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'seller_name', 'phone', 'address', 'landmark', 'state', 'country', 'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    public function paymentAccount()
    {
        return $this->hasOne(SellerPaymentAccount::class, 'seller_id');
    }
}
