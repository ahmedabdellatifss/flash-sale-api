<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $price
 * @property int $total_stock
 * @property int $stock_remaining
 */
class Product extends Model
{
    protected $fillable = ['name', 'price', 'total_stock', 'stock_remaining'];
    //
}
